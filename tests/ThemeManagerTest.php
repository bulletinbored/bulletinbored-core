<?php

/**
 * ThemeManagerTest — tests for ThemeManager install, update, delete, activate operations.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/ThemeManager.php';
require_once __DIR__ . '/../lib/PackageInstaller.php';

function tm_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_tm_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function tm_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($dir);
}

function tm_make_zip(array $entries, string $path): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

function test_theme_manager_discover(): Test
{
    $t = new Test('ThemeManager - Discover Themes');

    $tmpDir = tm_test_tmp_dir('discover');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    // Create freshbored (default)
    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body { color: #111; }');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0', 'author' => 'Test']));

    // Create another theme
    @mkdir($themesDir . '/darkmode', 0755, true);
    file_put_contents($themesDir . '/darkmode/style.css', 'body { color: #fff; background: #000; }');
    file_put_contents($themesDir . '/darkmode/manifest.json', json_encode(['name' => 'darkmode', 'version' => '2.0.0', 'author' => 'Test']));

    // Create directory without style.css (should be ignored)
    @mkdir($themesDir . '/not_a_theme', 0755, true);
    file_put_contents($themesDir . '/not_a_theme/readme.txt', 'Not a theme');

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $themes = $tm->discover();

    $t->assertCount('Discovers 2 valid themes', 2, $themes);
    $t->assertTrue('freshbored discovered', isset($themes['freshbored']));
    $t->assertTrue('darkmode discovered', isset($themes['darkmode']));
    $t->assertFalse('not_a_theme not discovered', isset($themes['not_a_theme']));
    $t->assertEquals('freshbored version', '1.0.0', $themes['freshbored']['version']);
    $t->assertEquals('darkmode version', '2.0.0', $themes['darkmode']['version']);
    $t->assertEquals('freshbored active', true, $themes['freshbored']['active']);
    $t->assertEquals('darkmode not active', false, $themes['darkmode']['active']);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_get_all(): Test
{
    $t = new Test('ThemeManager - Get All Themes');

    $tmpDir = tm_test_tmp_dir('getall');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $all = $tm->getAll();

    $t->assertCount('Returns all themes', 1, $all);
    $t->assertTrue('freshbored in list', isset($all['freshbored']));

    // Second call should use cache
    $all2 = $tm->getAll();
    $t->assertEquals('Cached result same', $all, $all2);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_get_active(): Test
{
    $t = new Test('ThemeManager - Get Active Theme');

    $tmpDir = tm_test_tmp_dir('getactive');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    @mkdir($themesDir . '/darkmode', 0755, true);
    file_put_contents($themesDir . '/darkmode/style.css', 'body {}');
    file_put_contents($themesDir . '/darkmode/manifest.json', json_encode(['name' => 'darkmode', 'version' => '1.0.0']));

    // Test default active
    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $active = $tm->getActive();
    $t->assertEquals('Default active is freshbored', 'freshbored', $active);

    // Test persisted active
    file_put_contents($manifestPath, json_encode(['active' => 'darkmode']));
    $tm2 = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $active2 = $tm2->getActive();
    $t->assertEquals('Persisted active is darkmode', 'darkmode', $active2);

    // Test fallback when active theme missing
    file_put_contents($manifestPath, json_encode(['active' => 'missing']));
    $tm3 = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $active3 = $tm3->getActive();
    $t->assertEquals('Fallback to first available', 'freshbored', $active3);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_activate(): Test
{
    $t = new Test('ThemeManager - Activate Theme');

    $tmpDir = tm_test_tmp_dir('activate');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    @mkdir($themesDir . '/darkmode', 0755, true);
    file_put_contents($themesDir . '/darkmode/style.css', 'body {}');
    file_put_contents($themesDir . '/darkmode/manifest.json', json_encode(['name' => 'darkmode', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $tm->discover();

    // Activate darkmode
    $result = $tm->activate('darkmode');
    $t->assertTrue('Activate returns true', $result);
    $t->assertEquals('Active theme is darkmode', 'darkmode', $tm->getActive());

    $all = $tm->getAll();
    $t->assertEquals('darkmode marked active', true, $all['darkmode']['active']);
    $t->assertEquals('freshbored not active', false, $all['freshbored']['active']);

    // Verify manifest persisted
    $manifest = json_decode(file_get_contents($manifestPath), true);
    $t->assertEquals('Manifest has active darkmode', 'darkmode', $manifest['active']);

    // Activate non-existent theme
    $result = $tm->activate('nonexistent');
    $t->assertFalse('Activate non-existent returns false', $result);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_get_css_url(): Test
{
    $t = new Test('ThemeManager - Get CSS URL');

    $tmpDir = tm_test_tmp_dir('cssurl');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');

    $url = $tm->getCssUrl();
    $t->assertTrue('CSS URL contains base_url', str_starts_with($url, 'http://localhost/test/themes/'));
    $t->assertTrue('CSS URL contains theme name', str_contains($url, 'freshbored'));
    $t->assertTrue('CSS URL contains version param', str_contains($url, 'v='));

    // Test with specific theme
    @mkdir($themesDir . '/darkmode', 0755, true);
    file_put_contents($themesDir . '/darkmode/style.css', 'body {}');
    file_put_contents($themesDir . '/darkmode/manifest.json', json_encode(['name' => 'darkmode', 'version' => '1.0.0']));

    $url2 = $tm->getCssUrl('darkmode');
    $t->assertTrue('Specific theme URL contains darkmode', str_contains($url2, 'darkmode'));

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_get_css_path(): Test
{
    $t = new Test('ThemeManager - Get CSS Path');

    $tmpDir = tm_test_tmp_dir('csspath');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');

    $path = $tm->getCssPath();
    $t->assertEquals('CSS path is correct', $themesDir . '/freshbored/style.css', $path);

    // Test fallback for missing theme
    $path2 = $tm->getCssPath('nonexistent');
    $t->assertEquals('Fallback to freshbored', $themesDir . '/freshbored/style.css', $path2);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_get_version(): Test
{
    $t = new Test('ThemeManager - Get Version');

    $tmpDir = tm_test_tmp_dir('version');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '2.5.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');

    $version = $tm->getVersion('freshbored');
    $t->assertEquals('Returns version from manifest', '2.5.0', $version);

    // Non-existent theme
    $version2 = $tm->getVersion('nonexistent');
    $t->assertEquals('Default version for missing theme', '1.0.0', $version2);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_install_from_zip(): Test
{
    $t = new Test('ThemeManager - Install From ZIP');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $tmpDir = tm_test_tmp_dir('install_zip');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');

    // Create test theme ZIP
    $zipPath = $tmpDir . '/testtheme.zip';
    tm_make_zip([
        'testtheme/style.css' => 'body { color: blue; }',
        'testtheme/manifest.json' => json_encode(['name' => 'testtheme', 'version' => '1.0.0', 'author' => 'Test Author']),
    ], $zipPath);

    $result = $tm->installFromZip($zipPath);

    $t->assertTrue('Install succeeds', !empty($result['success']));
    $t->assertEquals('Message indicates success', 'Theme installed', $result['message'] ?? '');
    $t->assertTrue('Theme directory created', is_dir($themesDir . '/testtheme'));
    $t->assertTrue('style.css exists', file_exists($themesDir . '/testtheme/style.css'));
    $t->assertTrue('manifest.json exists', file_exists($themesDir . '/testtheme/manifest.json'));

    $manifest = json_decode(file_get_contents($themesDir . '/testtheme/manifest.json'), true);
    $t->assertEquals('Manifest name correct', 'testtheme', $manifest['name']);
    $t->assertEquals('Manifest version correct', '1.0.0', $manifest['version']);

    // Verify discovered
    $tm->discover();
    $t->assertTrue('Theme discovered after install', isset($tm->getAll()['testtheme']));

    // Test duplicate install fails
    $result2 = $tm->installFromZip($zipPath);
    $t->assertFalse('Duplicate install fails', !empty($result2['success']));
    $t->assertTrue('Error message about existing', str_contains($result2['message'] ?? '', 'already exists'));

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_update_from_zip(): Test
{
    $t = new Test('ThemeManager - Update From ZIP');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $tmpDir = tm_test_tmp_dir('update_zip');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    // Pre-install testtheme v1
    @mkdir($themesDir . '/testtheme', 0755, true);
    file_put_contents($themesDir . '/testtheme/style.css', 'body { color: red; }');
    file_put_contents($themesDir . '/testtheme/manifest.json', json_encode(['name' => 'testtheme', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $tm->discover();

    // Create v2 ZIP
    $zipPath = $tmpDir . '/testtheme_v2.zip';
    tm_make_zip([
        'testtheme/style.css' => 'body { color: green; }',
        'testtheme/manifest.json' => json_encode(['name' => 'testtheme', 'version' => '2.0.0', 'author' => 'Test Author']),
    ], $zipPath);

    $result = $tm->updateFromZip('testtheme', $zipPath);

    $t->assertTrue('Update succeeds', !empty($result['success']));
    $t->assertEquals('Message indicates update', 'Theme updated', $result['message'] ?? '');

    // Verify version updated
    $manifest = json_decode(file_get_contents($themesDir . '/testtheme/manifest.json'), true);
    $t->assertEquals('Version updated to 2.0.0', '2.0.0', $manifest['version']);

    // Verify content updated
    $css = file_get_contents($themesDir . '/testtheme/style.css');
    $t->assertEquals('CSS content updated', 'body { color: green; }', $css);

    // Verify no backup left behind
    $backups = glob($themesDir . '/_old_*');
    $t->assertCount('No backup directories left', 0, $backups);

    // Test update non-existent theme
    $result2 = $tm->updateFromZip('nonexistent', $zipPath);
    $t->assertFalse('Update non-existent fails', !empty($result2['success']));

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_update_rollback_on_failure(): Test
{
    $t = new Test('ThemeManager - Update Rollback on Failure');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $tmpDir = tm_test_tmp_dir('update_rb');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    // Pre-install testtheme v1 with sentinel file
    @mkdir($themesDir . '/testtheme', 0755, true);
    file_put_contents($themesDir . '/testtheme/style.css', 'body { color: red; }');
    file_put_contents($themesDir . '/testtheme/manifest.json', json_encode(['name' => 'testtheme', 'version' => '1.0.0']));
    file_put_contents($themesDir . '/testtheme/SENTINEL.txt', 'original-content');

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $tm->discover();

    // Create invalid ZIP (missing style.css)
    $zipPath = $tmpDir . '/bad.zip';
    tm_make_zip([
        'testtheme/manifest.json' => json_encode(['name' => 'testtheme', 'version' => '2.0.0']),
        'testtheme/readme.txt' => 'No style.css',
    ], $zipPath);

    $result = $tm->updateFromZip('testtheme', $zipPath);

    $t->assertFalse('Update fails with invalid package', !empty($result['success']));

    // Verify original files restored
    $t->assertTrue('Original style.css restored', file_exists($themesDir . '/testtheme/style.css'));
    $css = file_get_contents($themesDir . '/testtheme/style.css');
    $t->assertEquals('Original CSS content restored', 'body { color: red; }', $css);

    $t->assertTrue('Sentinel file restored', file_exists($themesDir . '/testtheme/SENTINEL.txt'));
    $sentinel = file_get_contents($themesDir . '/testtheme/SENTINEL.txt');
    $t->assertEquals('Sentinel content unchanged', 'original-content', $sentinel);

    $manifest = json_decode(file_get_contents($themesDir . '/testtheme/manifest.json'), true);
    $t->assertEquals('Original version restored', '1.0.0', $manifest['version']);

    // Verify no backup left behind
    $backups = glob($themesDir . '/_old_*');
    $t->assertCount('No backup directories left', 0, $backups);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_delete(): Test
{
    $t = new Test('ThemeManager - Delete Theme');

    $tmpDir = tm_test_tmp_dir('delete');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    @mkdir($themesDir . '/testtheme', 0755, true);
    file_put_contents($themesDir . '/testtheme/style.css', 'body {}');
    file_put_contents($themesDir . '/testtheme/manifest.json', json_encode(['name' => 'testtheme', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $tm->discover();

    // Delete testtheme
    $result = $tm->delete('testtheme');
    $t->assertTrue('Delete succeeds', !empty($result['success']));
    $t->assertFalse('Theme directory removed', is_dir($themesDir . '/testtheme'));
    $t->assertFalse('Theme no longer discovered', isset($tm->getAll()['testtheme']));

    // Test delete non-existent
    $result2 = $tm->delete('nonexistent');
    $t->assertFalse('Delete non-existent fails', !empty($result2['success']));

    // Test delete freshbored (default) blocked
    $result3 = $tm->delete('freshbored');
    $t->assertFalse('Delete freshbored blocked', !empty($result3['success']));
    $t->assertTrue('Error message about default', str_contains($result3['message'] ?? '', 'default'));
    $t->assertTrue('freshbored still exists', is_dir($themesDir . '/freshbored'));

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_delete_active_switches(): Test
{
    $t = new Test('ThemeManager - Delete Active Theme Switches to Another');

    $tmpDir = tm_test_tmp_dir('delete_active');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    @mkdir($themesDir . '/testtheme', 0755, true);
    file_put_contents($themesDir . '/testtheme/style.css', 'body {}');
    file_put_contents($themesDir . '/testtheme/manifest.json', json_encode(['name' => 'testtheme', 'version' => '1.0.0']));

    // Set testtheme as active
    file_put_contents($manifestPath, json_encode(['active' => 'testtheme']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $tm->discover();
    $t->assertEquals('Active is testtheme', 'testtheme', $tm->getActive());

    // Delete active theme
    $result = $tm->delete('testtheme');
    $t->assertTrue('Delete succeeds', !empty($result['success']));

    // Should fall back to freshbored
    $t->assertEquals('Falls back to freshbored', 'freshbored', $tm->getActive());

    $manifest = json_decode(file_get_contents($manifestPath), true);
    $t->assertEquals('Manifest active updated', 'freshbored', $manifest['active']);

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_remove_missing(): Test
{
    $t = new Test('ThemeManager - Remove Missing Themes');

    $tmpDir = tm_test_tmp_dir('remove_missing');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    // Create theme entry in manifest but no directory
    file_put_contents($manifestPath, json_encode([
        'active' => 'freshbored',
        'themes' => [
            'ghost' => ['name' => 'ghost', 'version' => '1.0.0', 'dir' => $themesDir . '/ghost', 'css_file' => $themesDir . '/ghost/style.css']
        ]
    ]));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $removed = $tm->removeMissing();

    $t->assertEquals('Ghost theme removed', ['ghost'], $removed);
    $t->assertFalse('Ghost no longer in themes', isset($tm->getAll()['ghost']));

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_install_from_repo(): Test
{
    $t = new Test('ThemeManager - Install From Repo (Structure)');

    // This test verifies the method exists and has correct structure
    // Actual repo install requires network/git which we don't test here
    $tmpDir = tm_test_tmp_dir('install_repo');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');

    // Verify method exists
    $t->assertTrue('installFromRepo method exists', method_exists($tm, 'installFromRepo'));

    // Test invalid name rejected
    $result = $tm->installFromRepo('https://github.com/test/repo', null, 'Invalid Name!');
    $t->assertFalse('Invalid name rejected', !empty($result['success']));

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_load_translations(): Test
{
    $t = new Test('ThemeManager - Load Translations');

    $tmpDir = tm_test_tmp_dir('translations');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    @mkdir($themesDir . '/freshbored/lang', 0755, true);
    file_put_contents($themesDir . '/freshbored/lang/en.json', json_encode(['theme.greeting' => 'Hello from theme']));

    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $tm->discover();

    // Need App instance for i18n
    require_once __DIR__ . '/../src/App.php';
    App::reset();
    $app = App::getInstance();
    $app->i18n = [];

    $tm->loadTranslations('en');

    $t->assertTrue('Translation scope created', isset($app->i18n['theme:freshbored']));
    $t->assertEquals('Translation loaded', 'Hello from theme', $app->i18n['theme:freshbored']['theme.greeting'] ?? '');

    tm_cleanup($tmpDir);
    return $t;
}

function test_theme_manager_verify_files_enabled(): Test
{
    $t = new Test('ThemeManager - Verify Files Enabled Check');

    $tmpDir = tm_test_tmp_dir('verify_files');
    $themesDir = $tmpDir . '/themes';
    @mkdir($themesDir, 0755, true);
    $manifestPath = $tmpDir . '/themes.json';

    @mkdir($themesDir . '/freshbored', 0755, true);
    file_put_contents($themesDir . '/freshbored/style.css', 'body {}');
    file_put_contents($themesDir . '/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));

    // Test with verification enabled (default)
    global $config;
    $config = ['theme_verify_files' => true];
    $tm = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $ref = new ReflectionMethod($tm, 'verifyFilesEnabled');
    $ref->setAccessible(true);
    $enabled = $ref->invoke($tm);
    $t->assertTrue('Verification enabled by default', $enabled);

    // Test with verification disabled
    $config = ['theme_verify_files' => false];
    $tm2 = new ThemeManager($themesDir, $manifestPath, 'freshbored');
    $enabled2 = $ref->invoke($tm2);
    $t->assertFalse('Verification disabled when config false', $enabled2);

    tm_cleanup($tmpDir);
    return $t;
}

register_tests(
    'test_theme_manager_discover',
    'test_theme_manager_get_all',
    'test_theme_manager_get_active',
    'test_theme_manager_activate',
    'test_theme_manager_get_css_url',
    'test_theme_manager_get_css_path',
    'test_theme_manager_get_version',
    'test_theme_manager_install_from_zip',
    'test_theme_manager_update_from_zip',
    'test_theme_manager_update_rollback_on_failure',
    'test_theme_manager_delete',
    'test_theme_manager_delete_active_switches',
    'test_theme_manager_remove_missing',
    'test_theme_manager_install_from_repo',
    'test_theme_manager_load_translations',
    'test_theme_manager_verify_files_enabled'
);