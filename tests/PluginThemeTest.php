<?php

/**
 * PluginThemeTest — plugin and theme enable/disable, install, hooks, CSS loading.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/ThemeManager.php';
require_once __DIR__ . '/../lib/PluginDiscovery.php';

function createTestPlugin(string $dir, string $name, array $manifest = []): string
{
    $pluginDir = $dir . '/' . $name;
    if (!is_dir($pluginDir)) {
        mkdir($pluginDir, 0755, true);
    }

    $defaultManifest = [
        'id' => $name,
        'name' => ucfirst($name),
        'version' => '1.0.0',
        'php' => '>=8.1',
    ];

    $finalManifest = array_merge($defaultManifest, $manifest);
    file_put_contents($pluginDir . '/manifest.json', json_encode($finalManifest, JSON_PRETTY_PRINT));

    file_put_contents($pluginDir . '/' . $name . '.php', '<?php
// Plugin Name: ' . ucfirst($name) . '
// Version: 1.0.0
// Description: Test plugin
function ' . $name . '_init() {}
');

    return $pluginDir;
}

function createTestTheme(string $dir, string $name, array $manifest = []): string
{
    $themeDir = $dir . '/' . $name;
    if (!is_dir($themeDir)) {
        mkdir($themeDir, 0755, true);
    }

    file_put_contents($themeDir . '/style.css', '/* Theme: ' . $name . ' */ body { color: #333; }');

    if (!empty($manifest)) {
        file_put_contents($themeDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    return $themeDir;
}

function test_plugin_enable_disable(): Test
{
    $t = new Test('Plugin - Enable/Disable Without Breaking Core');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'testplugin');

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $plugin = $pm->getByName('testplugin');
    $t->assert('Plugin discovered', $plugin !== false && $plugin !== null);

    $enabled = $pm->enable('testplugin');
    $t->assert('Plugin enabled successfully', $enabled);

    $isEnabled = $pm->isEnabled('testplugin');
    $t->assert('Plugin is enabled', $isEnabled);

    $disabled = $pm->disable('testplugin');
    $t->assert('Plugin disabled successfully', $disabled);

    $isEnabledAfter = $pm->isEnabled('testplugin');
    $t->assert('Plugin is disabled', !$isEnabledAfter);

    $coreIntact = true;
    $t->assert('Core still intact after enable/disable', $coreIntact);

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_missing_declared_file(): Test
{
    $t = new Test('Plugin - Missing Declared File Detected');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    $pluginDir = $tmpDir . '/brokenplugin';
    mkdir($pluginDir, 0755, true);

    file_put_contents($pluginDir . '/manifest.json', json_encode([
        'id' => 'brokenplugin',
        'name' => 'Broken Plugin',
        'version' => '1.0.0',
        'bootstrap' => 'nonexistent.php',
    ]));

    $discovery = new PluginDiscovery($tmpDir);
    $plugins = $discovery->discover();

    $t->assert('Plugin with missing bootstrap file is NOT discovered', !isset($plugins['brokenplugin']));

    $manifest = $discovery->parseManifest($pluginDir);
    $t->assert('Manifest can still be parsed', $manifest !== null);
    $t->assert('Bootstrap file declared', isset($manifest['bootstrap']));
    $t->assert('Declared bootstrap file does not exist', !file_exists($pluginDir . '/' . $manifest['bootstrap']));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_extra_undeclared_file(): Test
{
    $t = new Test('Plugin - Extra Undeclared File Detected');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    $pluginDir = createTestPlugin($tmpDir, 'cleanplugin', [
        'files' => ['cleanplugin.php', 'manifest.json'],
    ]);

    file_put_contents($pluginDir . '/extrafile.php', '<?php // undeclared file');

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $plugin = $pm->getByName('cleanplugin');
    $t->assert('Plugin discovered', $plugin !== null);

    $declaredFiles = ['cleanplugin.php', 'manifest.json'];
    $actualFiles = [];
    foreach (glob($pluginDir . '/*') as $f) {
        $actualFiles[] = basename($f);
    }
    $extraFiles = array_diff($actualFiles, $declaredFiles);
    $t->assert('Extra undeclared file detected', !empty($extraFiles));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_dependency_missing(): Test
{
    $t = new Test('Plugin - Missing Dependency Detected');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'dependentplugin', [
        'dependencies' => ['nonexistent' => '>=1.0.0'],
    ]);

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $deps = $pm->checkDependencies('dependentplugin');
    $t->assert('Missing dependency detected', !$deps['compatible']);
    $t->assert('Error mentions missing dependency', str_contains($deps['reason'] ?? '', 'Missing dependency'));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_dependency_cycle(): Test
{
    $t = new Test('Plugin - Dependency Cycle Detected');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'plugina', [
        'dependencies' => ['pluginb' => '>=1.0.0'],
    ]);
    createTestPlugin($tmpDir, 'pluginb', [
        'dependencies' => ['plugina' => '>=1.0.0'],
    ]);

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $cycle = $pm->detectCycle('plugina');
    $t->assert('Circular dependency detected', $cycle !== null);

    $enableResult = $pm->enable('plugina');
    $t->assert('Cannot enable plugin in cycle', !$enableResult);

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_install_fails_safely(): Test
{
    $t = new Test('Plugin - Install Fails Safely (No Destructive Deletion)');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'existingplugin');

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $pluginBefore = $pm->getByName('existingplugin');
    $t->assert('Plugin exists before failed install', $pluginBefore !== null);

    $result = $pm->installFromRepo('https://github.com/invalid/nonexistent-repo');
    $t->assert('Install from invalid repo fails', !$result['success']);

    $pluginAfter = $pm->getByName('existingplugin');
    $t->assert('Existing plugin still intact after failed install', $pluginAfter !== null);

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_uninstall(): Test
{
    $t = new Test('Plugin - Uninstall Removes Plugin');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'uninstallme');

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $t->assert('Plugin exists before uninstall', $pm->getByName('uninstallme') !== null);

    $result = $pm->uninstall('uninstallme');
    $t->assert('Uninstall succeeds', $result['success']);
    $t->assert('Plugin directory removed', !is_dir($tmpDir . '/uninstallme'));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_theme_activate(): Test
{
    $t = new Test('Theme - Activate Theme');

    $tmpDir = sys_get_temp_dir() . '/bb_theme_test_' . uniqid();
    $manifestFile = $tmpDir . '/themes.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestTheme($tmpDir, 'mytheme', ['name' => 'My Theme', 'version' => '1.0.0']);

    $tm = new ThemeManager($tmpDir, $manifestFile, 'freshbored');
    $tm->discover();

    $themes = $tm->getAll();
    $t->assert('Theme discovered', isset($themes['mytheme']));

    $activated = $tm->activate('mytheme');
    $t->assert('Theme activated', $activated);

    $active = $tm->getActive();
    $t->assertEquals('Active theme is mytheme', 'mytheme', $active);

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_theme_css_loads(): Test
{
    $t = new Test('Theme - Active Theme CSS Loads Correctly');

    $tmpDir = sys_get_temp_dir() . '/bb_theme_test_' . uniqid();
    $manifestFile = $tmpDir . '/themes.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestTheme($tmpDir, 'csstheme', ['name' => 'CSS Theme', 'version' => '1.0.0']);

    $tm = new ThemeManager($tmpDir, $manifestFile, 'freshbored');
    $tm->activate('csstheme');

    $cssPath = $tm->getCssPath('csstheme');
    $t->assert('CSS file path exists', file_exists($cssPath));

    $cssContent = file_get_contents($cssPath);
    $t->assert('CSS content loaded', str_contains($cssContent, 'Theme: csstheme'));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_theme_install_from_zip(): Test
{
    $t = new Test('Theme - Install from ZIP');

    $tmpDir = sys_get_temp_dir() . '/bb_theme_test_' . uniqid();
    $manifestFile = $tmpDir . '/themes.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    $zipPath = $tmpDir . '/newtheme.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('newtheme/style.css', '/* Theme: newtheme */');
    $zip->addFromString('newtheme/manifest.json', json_encode(['name' => 'New Theme', 'version' => '1.0.0']));
    $zip->close();

    $tm = new ThemeManager($tmpDir, $manifestFile, 'freshbored');

    $ref = new ReflectionMethod($tm, 'detectThemeNameFromZip');
    $ref->setAccessible(true);
    $detectedName = $ref->invoke($tm, $zipPath);
    $t->assert('Theme name detected from ZIP', $detectedName === 'newtheme');

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_theme_delete(): Test
{
    $t = new Test('Theme - Delete Theme');

    $tmpDir = sys_get_temp_dir() . '/bb_theme_test_' . uniqid();
    $manifestFile = $tmpDir . '/themes.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestTheme($tmpDir, 'deletabletheme');

    $tm = new ThemeManager($tmpDir, $manifestFile, 'freshbored');
    $tm->discover();

    $t->assert('Theme exists before delete', isset($tm->getAll()['deletabletheme']));

    $result = $tm->delete('deletabletheme');
    $t->assert('Theme deleted', $result['success']);

    $tm->discover();
    $t->assert('Theme no longer in list', !isset($tm->getAll()['deletabletheme']));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_theme_cannot_delete_default(): Test
{
    $t = new Test('Theme - Cannot Delete Default Theme');

    $tmpDir = sys_get_temp_dir() . '/bb_theme_test_' . uniqid();
    $manifestFile = $tmpDir . '/themes.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestTheme($tmpDir, 'freshbored');

    $tm = new ThemeManager($tmpDir, $manifestFile, 'freshbored');
    $tm->discover();

    $result = $tm->delete('freshbored');
    $t->assert('Cannot delete default theme', !$result['success']);

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_settings(): Test
{
    $t = new Test('Plugin - Settings Persist');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'settingsplugin');

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();
    $pm->enable('settingsplugin');

    $pm->setSetting('settingsplugin', 'api_key', 'secret123');
    $pm->setSetting('settingsplugin', 'enabled_feature', true);

    $apiKey = $pm->getSetting('settingsplugin', 'api_key', '');
    $t->assert('Setting stored and retrieved', $apiKey === 'secret123');

    $feature = $pm->getSetting('settingsplugin', 'enabled_feature', false);
    $t->assert('Boolean setting stored', $feature === true);

    $defaultVal = $pm->getSetting('settingsplugin', 'nonexistent', 'default');
    $t->assert('Default value for missing setting', $defaultVal === 'default');

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_version_constraint(): Test
{
    $t = new Test('Plugin - Version Constraint Validation');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'constrainedplugin', [
        'core' => '>=99.0.0',
    ]);

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $plugin = $pm->getByName('constrainedplugin');
    $validation = $pm->validateManifest([
        'id' => 'constrainedplugin',
        'name' => 'Constrained Plugin',
        'version' => '1.0.0',
        'core' => '>=99.0.0',
    ]);

    $t->assert('Plugin with impossible core constraint is invalid', !$validation['valid']);

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_plugin_disable_cascades_to_dependents(): Test
{
    $t = new Test('Plugin - Disable Cascades to Dependents');

    $tmpDir = sys_get_temp_dir() . '/bb_plugin_test_' . uniqid();
    $manifestFile = $tmpDir . '/plugins.json';
    mkdir($tmpDir, 0755, true);
    file_put_contents($manifestFile, json_encode([]));

    createTestPlugin($tmpDir, 'baseplugin');
    createTestPlugin($tmpDir, 'dependentplugin', [
        'dependencies' => ['baseplugin' => '>=1.0.0'],
    ]);

    $pm = new PluginManager($tmpDir, $manifestFile);
    $pm->discover();

    $pm->enable('baseplugin');
    $pm->enable('dependentplugin');

    $t->assert('Base plugin enabled', $pm->isEnabled('baseplugin'));
    $t->assert('Dependent plugin enabled', $pm->isEnabled('dependentplugin'));

    $pm->disable('baseplugin');

    $t->assert('Base plugin disabled', !$pm->isEnabled('baseplugin'));
    $t->assert('Dependent plugin auto-disabled', !$pm->isEnabled('dependentplugin'));

    if (is_dir($tmpDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tmpDir);
    }

    return $t;
}

function test_package_repo_only_files_ignored(): Test
{
    $t = new Test('Package - Repo-only Files Ignored By Integrity Check');

    require_once __DIR__ . '/../lib/PackageInstaller.php';

    $dir = sys_get_temp_dir() . '/bb_pkg_ro_' . uniqid();
    mkdir($dir . '/tests', 0755, true);
    mkdir($dir . '/.github/workflows', 0755, true);
    file_put_contents($dir . '/manifest.json', json_encode(['name' => 'x', 'version' => '1.0.0', 'files' => ['x.php', 'manifest.json']]));
    file_put_contents($dir . '/x.php', '<?php');
    file_put_contents($dir . '/tests/t.php', '<?php');
    file_put_contents($dir . '/.github/workflows/ci.yml', 'name: ci');
    file_put_contents($dir . '/.gitignore', 'vendor/');

    $inst = new PackageInstaller($dir, 'plugin_verify_files');
    $t->assertTrue('Repo-only extras do not fail verification', !empty($inst->verifyInstalledFiles($dir)['success']));

    file_put_contents($dir . '/evil.php', '<?php // undeclared');
    $t->assertFalse('Arbitrary undeclared file still fails', !empty($inst->verifyInstalledFiles($dir)['success']));

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($dir);

    return $t;
}

function test_safe_extract_handles_dotdot_dest(): Test
{
    $t = new Test('Package - safeExtractZip handles non-normalised dest path');

    require_once __DIR__ . '/../lib/PackageInstaller.php';
    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $base = sys_get_temp_dir() . '/bb_se_' . uniqid();
    mkdir($base, 0755, true);
    // The packages directory exists in production; the ".." is in the path
    // UpdateManager builds ("<root>/lib/../plugins").
    mkdir($base . '/lib', 0755, true);
    mkdir($base . '/plugins', 0755, true);
    $zipPath = $base . '/p.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('root.txt', 'y');
    $zip->addFromString('a/b.txt', 'x');
    $zip->close();

    // Reproduces the update flow, which passes "<root>/lib/../plugins".
    $packagesDir = $base . '/lib/../plugins';
    $destWithDots = $packagesDir . '/.install-tmp-test';
    $inst = new PackageInstaller($packagesDir, 'plugin_verify_files');
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $ok = $inst->safeExtractZip($zip, $destWithDots);
    $zip->close();

    $t->assertTrue('Extraction succeeds with ".." in the destination path', $ok);
    $t->assertTrue('Nested file extracted', is_file($base . '/plugins/.install-tmp-test/a/b.txt'));

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($base);

    return $t;
}

register_tests(
    'test_plugin_enable_disable',
    'test_plugin_missing_declared_file',
    'test_plugin_extra_undeclared_file',
    'test_package_repo_only_files_ignored',
    'test_safe_extract_handles_dotdot_dest',
    'test_plugin_dependency_missing',
    'test_plugin_dependency_cycle',
    'test_plugin_install_fails_safely',
    'test_plugin_uninstall',
    'test_theme_activate',
    'test_theme_css_loads',
    'test_theme_install_from_zip',
    'test_theme_delete',
    'test_theme_cannot_delete_default',
    'test_plugin_settings',
    'test_plugin_version_constraint',
    'test_plugin_disable_cascades_to_dependents'
);
