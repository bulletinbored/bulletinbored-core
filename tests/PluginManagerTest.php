<?php

/**
 * PluginManager tests — tests hook system, discovery, and lifecycle.
 */

require_once __DIR__ . '/../lib/PluginManager.php';

function test_plugin_manager_hooks(): Test
{
    $t = new Test('PluginManager - Hook System');

    // Create a mock PluginManager (we can't fully test without file system)
    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest.json');

    // Test: addHook and runHook
    $fired = false;
    $pm->addHook('test_event', function() use (&$fired) {
        $fired = true;
    });
    $pm->runHook('test_event');
    $t->assertTrue('runHook fires callback', $fired);

    // Test: runHook with args
    $received = null;
    $pm->addHook('test_args', function($a, $b) use (&$received) {
        $received = [$a, $b];
    });
    $pm->runHook('test_args', 'hello', 42);
    $t->assertEquals('runHook passes args', ['hello', 42], $received);

    // Test: applyHook returns first non-null
    $pm2 = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest2.json');
    $pm2->addHook('apply_test', function() { return null; });
    $pm2->addHook('apply_test2', function() { return 'first'; });
    $pm2->addHook('apply_test3', function() { return 'second'; });
    $result = $pm2->applyHook('apply_test', 'apply_test2', 'apply_test3');
    // Note: applyHook only checks one event, let's test properly
    $result = $pm2->applyHook('apply_test');
    $t->assertNull('applyHook returns null when all return null', $result);
    $result = $pm2->applyHook('apply_test2');
    $t->assertEquals('applyHook returns first non-null', 'first', $result);

    return $t;
}

function test_plugin_manager_filter(): Test
{
    $t = new Test('PluginManager - Filter');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest3.json');

    // Test: filter chains value through callbacks
    $pm->addHook('filter_test', function($value) {
        return $value . 'A';
    });
    $pm->addHook('filter_test', function($value) {
        return $value . 'B';
    });
    $pm->addHook('filter_test', function($value) {
        return $value . 'C';
    });

    $result = $pm->filter('filter_test', 'start-');
    $t->assertEquals('Filter chains callbacks', 'start-ABC', $result);

    // Test: filter with null return (passes through)
    $pm2 = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest4.json');
    $pm2->addHook('filter_null', function($value) {
        return null; // should not change value
    });
    $pm2->addHook('filter_null', function($value) {
        return $value . '-modified';
    });
    $result = $pm2->filter('filter_null', 'original');
    $t->assertEquals('Filter skips null returns', 'original-modified', $result);

    return $t;
}

function test_plugin_manager_check(): Test
{
    $t = new Test('PluginManager - Check Hooks');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest5.json');

    // Test: checkHook returns true if ANY callback returns true
    $pm->addHook('check_test', function() { return false; });
    $pm->addHook('check_test', function() { return true; });
    $pm->addHook('check_test', function() { return false; });
    $t->assertTrue('checkHook returns true if any true', $pm->checkHook('check_test'));

    // Test: checkHook returns false if none return true
    $pm2 = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest6.json');
    $pm2->addHook('check_false', function() { return false; });
    $pm2->addHook('check_false', function() { return false; });
    $t->assertFalse('checkHook returns false if none true', $pm2->checkHook('check_false'));

    // Test: checkHook with no callbacks
    $t->assertFalse('checkHook returns false with no callbacks', $pm2->checkHook('nonexistent'));

    // Test: checkHookAll returns true only if ALL return true
    $pm3 = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest7.json');
    $pm3->addHook('check_all', function() { return true; });
    $pm3->addHook('check_all', function() { return true; });
    $t->assertTrue('checkHookAll returns true if all true', $pm3->checkHookAll('check_all'));

    // Test: checkHookAll returns false if any returns false
    $pm4 = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest8.json');
    $pm4->addHook('check_all_false', function() { return true; });
    $pm4->addHook('check_all_false', function() { return false; });
    $t->assertFalse('checkHookAll returns false if any false', $pm4->checkHookAll('check_all_false'));

    // Test: checkHookAll with no callbacks returns true
    $t->assertTrue('checkHookAll returns true with no callbacks', $pm4->checkHookAll('nonexistent'));

    return $t;
}

function test_plugin_manager_priority(): Test
{
    $t = new Test('PluginManager - Hook Priority');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest9.json');

    $order = [];
    $pm->addHook('priority_test', function() use (&$order) { $order[] = 'default'; }, 10);
    $pm->addHook('priority_test', function() use (&$order) { $order[] = 'high'; }, 5);
    $pm->addHook('priority_test', function() use (&$order) { $order[] = 'low'; }, 15);

    $pm->runHook('priority_test');

    $t->assertEquals('Priority 5 runs first', 'high', $order[0] ?? '');
    $t->assertEquals('Priority 10 runs second', 'default', $order[1] ?? '');
    $t->assertEquals('Priority 15 runs last', 'low', $order[2] ?? '');

    return $t;
}

function test_plugin_manager_remove_hook(): Test
{
    $t = new Test('PluginManager - Remove Hook');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest10.json');

    $callback = function() { return 'should be removed'; };
    $pm->addHook('remove_test', $callback);
    $pm->addHook('remove_test', function() { return 'should stay'; });

    // Before removal
    $result = $pm->applyHook('remove_test');
    $t->assertEquals('Before removal returns first callback', 'should be removed', $result);

    // Remove
    $pm->removeHook('remove_test', $callback);

    // After removal
    $result = $pm->applyHook('remove_test');
    $t->assertEquals('After removal returns remaining callback', 'should stay', $result);

    return $t;
}

// Run all PluginManager tests
register_tests(
    'test_plugin_manager_hooks',
    'test_plugin_manager_filter',
    'test_plugin_manager_check',
    'test_plugin_manager_priority',
    'test_plugin_manager_remove_hook',
    'test_plugin_manager_delete_dir',
    'test_plugin_manager_validate_manifest',
    'test_plugin_lifecycle_install_uninstall',
    'test_plugin_lifecycle_failed_update_preserves_original',
    'test_plugin_dependency_cycle_and_missing',
    'test_plugin_verify_extracted_manifest',
    'test_plugin_lifecycle_on_install_failure_rolls_back',
    'test_plugin_lifecycle_on_update_failure_rolls_back'
);

function test_plugin_manager_validate_manifest(): Test
{
    $t = new Test('PluginManager - Manifest Validation');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_manifest_test.json');

    // Valid v1 manifest (with id)
    $valid = [
        'id' => 'test-plugin',
        'name' => 'Test Plugin',
        'version' => '1.0.0',
        'php' => '>=8.1',
    ];
    $result = $pm->validateManifest($valid);
    $t->assertTrue('Valid v1 manifest passes', $result['valid']);

    // Legacy manifest (name only, no id) — backward compatible
    $legacy = [
        'name' => 'Test Plugin',
        'version' => '1.0.0',
    ];
    $result = $pm->validateManifest($legacy);
    $t->assertTrue('Legacy manifest (no id) passes', $result['valid']);

    // Normalize legacy: id derived from name
    $normalized = $pm->normalizeManifest($legacy);
    $t->assertEquals('id derived from name', 'test-plugin', $normalized['id']);

    // Missing name fails
    $invalid = $valid;
    unset($invalid['name']);
    $result = $pm->validateManifest($invalid);
    $t->assertFalse('Missing name fails', $result['valid']);
    $t->assert('Error mentions name', str_contains($result['errors'][0], 'name'));

    // Invalid id format (uppercase)
    $invalid = $valid;
    $invalid['id'] = 'Test_Plugin';
    $result = $pm->validateManifest($invalid);
    $t->assertFalse('Uppercase id fails', $result['valid']);

    // Missing name
    $invalid = $valid;
    unset($invalid['name']);
    $result = $pm->validateManifest($invalid);
    $t->assertFalse('Missing name fails', $result['valid']);

    return $t;
}

function pm_test_tmp_dir(string $suffix): string
{
    $dir = sys_get_temp_dir() . '/bb_pm_' . $suffix . '_' . uniqid('', true);
    @mkdir($dir, 0755, true);
    return $dir;
}

function pm_test_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function pm_test_make_zip(string $zipPath, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create test ZIP');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

function pm_test_write_plugin(string $pluginsDir, string $folder, string $name, array $extra = []): void
{
    $dir = rtrim($pluginsDir, '/') . '/' . $folder;
    @mkdir($dir, 0755, true);
    $manifest = array_merge([
        'name' => $name,
        'version' => '1.0.0',
        'bootstrap' => $name . '.php',
    ], $extra);
    file_put_contents($dir . '/manifest.json', json_encode($manifest));
    file_put_contents($dir . '/' . $name . '.php', "<?php\n");
}

function test_plugin_lifecycle_install_uninstall(): Test
{
    $t = new Test('PluginManager - Install/Uninstall lifecycle');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $base = pm_test_tmp_dir('inst');
    $pluginsDir = $base . '/plugins';
    @mkdir($pluginsDir, 0755, true);
    $manifestPath = $base . '/plugins.json';
    $pm = new PluginManager($pluginsDir, $manifestPath);

    $zip = $base . '/lifecycle.zip';
    pm_test_make_zip($zip, [
        'manifest.json' => json_encode(['name' => 'lifecycle-plug', 'version' => '1.0.0', 'bootstrap' => 'lifecycle-plug.php']),
        'lifecycle-plug.php' => "<?php\n",
    ]);

    $result = $pm->installFromZip($zip);
    $t->assertTrue('Install from ZIP succeeds', !empty($result['success']));
    $t->assertNotNull('Plugin discovered after install', $pm->getByName('lifecycle-plug'));
    $t->assertTrue('Plugin enabled by default', $pm->isEnabled('lifecycle-plug'));

    $installedFile = dirname($manifestPath) . '/installed.json';
    $installed = json_decode((string)@file_get_contents($installedFile), true);
    $t->assertTrue('installed.json records the plugin', isset($installed['plugins']['lifecycle-plug']));

    $uninstall = $pm->uninstall('lifecycle-plug');
    $t->assertTrue('Uninstall succeeds', !empty($uninstall['success']));
    $t->assertNull('Plugin gone after uninstall', $pm->getByName('lifecycle-plug'));
    $t->assertFalse('Plugin directory removed', is_dir($pluginsDir . '/lifecycle-plug'));

    $installed = json_decode((string)@file_get_contents($installedFile), true);
    $t->assertFalse('installed.json record removed', isset($installed['plugins']['lifecycle-plug']));

    pm_test_rmtree($base);
    return $t;
}

function test_plugin_lifecycle_failed_update_preserves_original(): Test
{
    $t = new Test('PluginManager - Failed update restores original');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $base = pm_test_tmp_dir('update');
    $pluginsDir = $base . '/plugins';
    @mkdir($pluginsDir, 0755, true);
    $pm = new PluginManager($pluginsDir, $base . '/plugins.json');

    $goodZip = $base . '/good.zip';
    pm_test_make_zip($goodZip, [
        'manifest.json' => json_encode(['name' => 'update-plug', 'version' => '1.0.0', 'bootstrap' => 'update-plug.php']),
        'update-plug.php' => "<?php\n",
    ]);
    $pm->installFromZip($goodZip);

    // Invalid replacement (manifest missing required name) with a higher version.
    $badZip = $base . '/bad.zip';
    pm_test_make_zip($badZip, [
        'manifest.json' => json_encode(['version' => '2.0.0', 'bootstrap' => 'update-plug.php']),
        'update-plug.php' => "<?php\n",
    ]);

    $result = $pm->updateFromZip('update-plug', $badZip);
    $t->assertFalse('Update with invalid manifest fails', !empty($result['success']));

    $manifestFile = $pluginsDir . '/update-plug/manifest.json';
    $manifest = json_decode((string)@file_get_contents($manifestFile), true);
    $t->assertEquals('Original version restored', '1.0.0', $manifest['version'] ?? null);
    $plugin = $pm->getByName('update-plug');
    $t->assertEquals('Original plugin still present', '1.0.0', $plugin['version'] ?? null);

    pm_test_rmtree($base);
    return $t;
}

function test_plugin_dependency_cycle_and_missing(): Test
{
    $t = new Test('PluginManager - Dependencies (cycle / missing)');

    $base = pm_test_tmp_dir('deps');
    $pluginsDir = $base . '/plugins';
    @mkdir($pluginsDir, 0755, true);

    pm_test_write_plugin($pluginsDir, 'a', 'dev-a', ['dependencies' => ['dev-b' => '>=1.0.0']]);
    pm_test_write_plugin($pluginsDir, 'b', 'dev-b', ['dependencies' => ['dev-a' => '>=1.0.0']]);
    pm_test_write_plugin($pluginsDir, 'c', 'dev-c', ['dependencies' => ['ghost' => '>=1.0.0']]);
    pm_test_write_plugin($pluginsDir, 'e', 'dev-e');
    pm_test_write_plugin($pluginsDir, 'd', 'dev-d', ['dependencies' => ['dev-e' => '>=1.0.0']]);

    $pm = new PluginManager($pluginsDir, $base . '/plugins.json');
    $pm->discover();

    $cycle = $pm->detectCycle('dev-a');
    $t->assertNotNull('Circular dependency detected', $cycle);

    $missing = $pm->checkDependencies('dev-c');
    $t->assertFalse('Missing dependency reported incompatible', $missing['compatible']);

    $ok = $pm->checkDependencies('dev-d');
    $t->assertTrue('Satisfied dependency is compatible', $ok['compatible']);

    $dependents = $pm->getDependents('dev-e');
    $t->assertTrue('getDependents finds dev-d', in_array('dev-d', $dependents, true));

    pm_test_rmtree($base);
    return $t;
}

function test_plugin_verify_extracted_manifest(): Test
{
    $t = new Test('PluginManager - verifyExtractedPackage()');

    $base = pm_test_tmp_dir('verify');
    $pluginsDir = $base . '/plugins';
    @mkdir($pluginsDir, 0755, true);
    $pm = new PluginManager($pluginsDir, $base . '/plugins.json');

    // Missing manifest.json
    $empty = $base . '/empty';
    @mkdir($empty, 0755, true);
    $result = $pm->verifyExtractedPackage($empty);
    $t->assertTrue('Missing manifest is rejected', is_array($result) && empty($result['success']));

    // Manifest missing name
    $bad = $base . '/bad';
    @mkdir($bad, 0755, true);
    file_put_contents($bad . '/manifest.json', json_encode(['version' => '1.0.0']));
    $result = $pm->verifyExtractedPackage($bad);
    $t->assertTrue('Manifest without name rejected', is_array($result) && empty($result['success']));

    // Valid manifest
    $good = $base . '/good';
    @mkdir($good, 0755, true);
    file_put_contents($good . '/manifest.json', json_encode(['name' => 'ok-plug', 'version' => '1.0.0']));
    $t->assertNull('Valid manifest accepted', $pm->verifyExtractedPackage($good));

    pm_test_rmtree($base);
    return $t;
}

function test_plugin_lifecycle_on_install_failure_rolls_back(): Test
{
    $t = new Test('PluginManager - on_install failure rolls back (fresh install)');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    // Simulate a loaded plugin whose on_install hook throws.
    if (!function_exists('oninstallplug_on_install')) {
        function oninstallplug_on_install() { throw new RuntimeException('boom'); }
    }

    $base = pm_test_tmp_dir('oninstall');
    $pluginsDir = $base . '/plugins';
    @mkdir($pluginsDir, 0755, true);
    $pm = new PluginManager($pluginsDir, $base . '/plugins.json');

    $zip = $base . '/oninstallplug.zip';
    pm_test_make_zip($zip, [
        'manifest.json' => json_encode(['name' => 'oninstallplug', 'version' => '1.0.0', 'bootstrap' => 'oninstallplug.php']),
        'oninstallplug.php' => "<?php\n",
    ]);

    $result = $pm->installFromZip($zip);

    $t->assertFalse('Install reported as failed', !empty($result['success']));
    $t->assertFalse('Plugin directory removed after rollback', is_dir($pluginsDir . '/oninstallplug'));
    $t->assertNull('Plugin not discoverable after rollback', $pm->getByName('oninstallplug'));

    $installedFile = $base . '/installed.json';
    $installed = json_decode((string)@file_get_contents($installedFile), true);
    $t->assertFalse('installed.json has no record after rollback', isset($installed['plugins']['oninstallplug']));

    pm_test_rmtree($base);
    return $t;
}

function test_plugin_lifecycle_on_update_failure_rolls_back(): Test
{
    $t = new Test('PluginManager - on_update failure restores files + metadata');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        return $t;
    }

    $base = pm_test_tmp_dir('onupdate');
    $pluginsDir = $base . '/plugins';
    @mkdir($pluginsDir, 0755, true);
    $pm = new PluginManager($pluginsDir, $base . '/plugins.json');
    $installedFile = $base . '/installed.json';

    // Install v1.0.0 successfully.
    $v1 = $base . '/onupdateplug_v1.zip';
    pm_test_make_zip($v1, [
        'manifest.json' => json_encode(['name' => 'onupdateplug', 'version' => '1.0.0', 'bootstrap' => 'onupdateplug.php']),
        'onupdateplug.php' => "<?php\n",
    ]);
    $first = $pm->installFromZip($v1);
    $t->assertTrue('v1 installs', !empty($first['success']));
    $installed = json_decode((string)@file_get_contents($installedFile), true);
    $t->assertEquals('installed.json starts at 1.0.0', '1.0.0', $installed['plugins']['onupdateplug']['version'] ?? null);

    // Simulate a loaded plugin whose on_update hook throws.
    if (!function_exists('onupdateplug_on_update')) {
        function onupdateplug_on_update() { throw new RuntimeException('update boom'); }
    }

    $v2 = $base . '/onupdateplug_v2.zip';
    pm_test_make_zip($v2, [
        'manifest.json' => json_encode(['name' => 'onupdateplug', 'version' => '2.0.0', 'bootstrap' => 'onupdateplug.php']),
        'onupdateplug.php' => "<?php\n",
    ]);

    $result = $pm->updateFromZip('onupdateplug', $v2);
    $t->assertFalse('Update reported as failed', !empty($result['success']));

    $manifest = json_decode((string)@file_get_contents($pluginsDir . '/onupdateplug/manifest.json'), true);
    $t->assertEquals('Files rolled back to 1.0.0', '1.0.0', $manifest['version'] ?? null);

    $installed = json_decode((string)@file_get_contents($installedFile), true);
    $t->assertEquals('installed.json rolled back to 1.0.0', '1.0.0', $installed['plugins']['onupdateplug']['version'] ?? null);

    pm_test_rmtree($base);
    return $t;
}

function test_plugin_manager_delete_dir(): Test
{
    $t = new Test('PluginManager - deleteDir()');

    $tmpDir = __DIR__ . '/tmp_delete_dir';
    if (is_dir($tmpDir)) {
        // Clean up from previous runs
        foreach (glob($tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);
    }

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_delete_manifest.json');

    // Use PackageInstaller's deleteDir via reflection on the installer property
    $ref = new ReflectionProperty($pm, 'installer');
    $ref->setAccessible(true);
    $installer = $ref->getValue($pm);

    // Test 1: non-existent directory does not throw
    $installer->deleteDir($tmpDir . '/nonexistent');
    $t->assert('deleteDir handles non-existent dir gracefully', !is_dir($tmpDir . '/nonexistent'));

    // Test 2: create nested directory structure and delete it
    mkdir($tmpDir . '/sub1/sub2/sub3', 0755, true);
    file_put_contents($tmpDir . '/file1.txt', 'hello');
    file_put_contents($tmpDir . '/sub1/file2.txt', 'world');
    file_put_contents($tmpDir . '/sub1/sub2/file3.txt', 'nested');
    file_put_contents($tmpDir . '/sub1/sub2/sub3/file4.txt', 'deep');

    $installer->deleteDir($tmpDir);
    $t->assert('deleteDir removes nested directory', !is_dir($tmpDir));

    // Test 3: delete empty directory
    mkdir($tmpDir, 0755);
    $installer->deleteDir($tmpDir);
    $t->assert('empty dir removed', !is_dir($tmpDir));

    return $t;
}
