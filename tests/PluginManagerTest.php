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
$suite = new TestSuite();
$suite->addTest(test_plugin_manager_hooks());
$suite->addTest(test_plugin_manager_filter());
$suite->addTest(test_plugin_manager_check());
$suite->addTest(test_plugin_manager_priority());
$suite->addTest(test_plugin_manager_remove_hook());
$suite->addTest(test_plugin_manager_delete_dir());
$suite->addTest(test_plugin_manager_validate_manifest());
$suite->run();

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
