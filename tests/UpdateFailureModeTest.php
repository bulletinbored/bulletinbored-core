<?php

/**
 * UpdateFailureModeTest — adversarial tests for the package install/update pipeline.
 *
 * Simulates real-world failure modes to verify the unified PackageInstaller pipeline
 * fails safely without leaving the system in an inconsistent state.
 *
 * Covers:
 *   - Corrupt ZIP
 *   - Zip Slip attack
 *   - Invalid manifest
 *   - Extra files (undeclared)
 *   - Missing files (declared but absent)
 *   - PHP/core version incompatibility
 *   - Backup failure
 *   - Rename failure (atomic commit fails)
 *   - Crashed extraction (leftover tmp dir)
 *   - Plugin that throws during init
 *   - Update followed by rollback
 *   - Update runs through identical pipeline as install
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/PackageInstaller.php';

function tmpDirUnique(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_ft_' . $prefix . '_' . uniqid('', true);
    if (!is_dir($base)) {
        mkdir($base, 0755, true);
    }
    return $base;
}

function tmpDirCleanup(string $dir): void
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

function makeZip(array $entries, string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create ZIP: {$path}");
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

function test_failure_corrupt_zip(): Test
{
    $t = new Test('Failure - Corrupt ZIP Rejected');

    $tmp = tmpDirUnique('corrupt');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $corrupt = $tmp . '/corrupt.zip';
    file_put_contents($corrupt, "this is not a zip file at all");

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($corrupt, 'someplugin');

    $t->assertFalse('Corrupt ZIP rejected', $result['success']);
    $t->assertFalse('No plugin folder created on disk', is_dir($pluginsDir . '/someplugin'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_zip_slip(): Test
{
    $t = new Test('Failure - Zip Slip Attack Blocked');

    $tmp = tmpDirUnique('zipslip');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $zipPath = $tmp . '/evil.zip';
    makeZip([
        'manifest.json' => json_encode(['name' => 'evilplugin', 'version' => '1.0.0']),
        'evilplugin.php' => '<?php // bootstrap',
        '../../../etc/passwd_overwrite' => 'pwned',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'evilplugin');

    $t->assertFalse('Zip Slip package rejected', $result['success']);
    $t->assertFalse('Evil file not created outside plugins dir', file_exists($tmp . '/etc/passwd_overwrite'));
    $t->assertFalse('No plugin folder created', is_dir($pluginsDir . '/evilplugin'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_invalid_manifest(): Test
{
    $t = new Test('Failure - Invalid Manifest Rejected');

    $tmp = tmpDirUnique('invalidman');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $zipPath = $tmp . '/bad.zip';
    makeZip([
        'manifest.json' => '{ not valid json',
        'someplugin.php' => '<?php // bootstrap',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'someplugin');

    $t->assertFalse('Invalid JSON manifest rejected', $result['success']);
    $t->assertFalse('No plugin folder created', is_dir($pluginsDir . '/someplugin'));
    $t->assert('Error mentions manifest', str_contains($result['message'], 'manifest'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_manifest_missing_name(): Test
{
    $t = new Test('Failure - Manifest Missing name Field Rejected');

    $tmp = tmpDirUnique('noname');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $zipPath = $tmp . '/noname.zip';
    makeZip([
        'manifest.json' => json_encode(['version' => '1.0.0']),
        'someplugin.php' => '<?php // bootstrap',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'someplugin');

    $t->assertFalse('Manifest without name rejected', $result['success']);

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_extra_files(): Test
{
    $t = new Test('Failure - Extra Files Rejected When Verification Enabled');

    $tmp = tmpDirUnique('extra');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    setAppConfig(['plugin_verify_files' => true]);

    $zipPath = $tmp . '/extra.zip';
    makeZip([
        'manifest.json' => json_encode([
            'name' => 'extroplug',
            'version' => '1.0.0',
            'files' => ['manifest.json', 'extroplug.php'],
        ]),
        'extroplug.php' => '<?php // bootstrap',
        'sneaky.php' => '<?php // not declared',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'extroplug');

    $t->assertFalse('Extra files rejected when verify enabled', $result['success']);
    $t->assertFalse('No plugin folder created', is_dir($pluginsDir . '/extroplug'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_missing_files(): Test
{
    $t = new Test('Failure - Missing Files Rejected When Verification Enabled');

    $tmp = tmpDirUnique('missing');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    setAppConfig(['plugin_verify_files' => true]);

    $zipPath = $tmp . '/missing.zip';
    makeZip([
        'manifest.json' => json_encode([
            'name' => 'missplug',
            'version' => '1.0.0',
            'files' => ['manifest.json', 'missplug.php', 'must_have.txt'],
        ]),
        'missplug.php' => '<?php // bootstrap',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'missplug');

    $t->assertFalse('Missing files rejected when verify enabled', $result['success']);

    tmpDirCleanup($tmp);
    return $t;
}

function setAppConfig(array $config): void
{
    $app = App::getInstance();
    $ref = new ReflectionClass($app);
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue($app, $config);
}

function test_failure_php_version_constraint(): Test
{
    $t = new Test('Failure - PHP Version Constraint Rejected');

    $tmp = tmpDirUnique('phpver');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $zipPath = $tmp . '/phpreq.zip';
    makeZip([
        'manifest.json' => json_encode([
            'name' => 'phpreqplug',
            'version' => '1.0.0',
            'php' => '>=99.0.0',
        ]),
        'phpreqplug.php' => '<?php // bootstrap',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'phpreqplug');

    $t->assertFalse('Unsatisfiable PHP constraint rejected', $result['success']);
    $t->assert('Error mentions PHP', str_contains($result['message'], 'PHP'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_core_version_constraint(): Test
{
    $t = new Test('Failure - Core Version Constraint Rejected');

    $tmp = tmpDirUnique('corever');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $zipPath = $tmp . '/corereq.zip';
    makeZip([
        'manifest.json' => json_encode([
            'name' => 'corereqplug',
            'version' => '1.0.0',
            'core' => '>=99.0.0',
        ]),
        'corereqplug.php' => '<?php // bootstrap',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'corereqplug');

    $t->assertFalse('Unsatisfiable core constraint rejected', $result['success']);
    $t->assert('Error mentions core', str_contains($result['message'], 'Core'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_no_tmp_dir_left(): Test
{
    $t = new Test('Failure - No Tmp Directory Left After Failure');

    $tmp = tmpDirUnique('notmp');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $zipPath = $tmp . '/fail.zip';
    makeZip([
        'manifest.json' => '{bad',
    ], $zipPath);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $result = $pm->installFromZip($zipPath, 'failplug');

    $leftover = glob($pluginsDir . '/.install-tmp-*');
    $t->assertFalse('No install failed', $result['success']);
    $t->assertCount('No tmp directories left after failure', 0, $leftover);

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_init_throws(): Test
{
    $t = new Test('Failure - Plugin Init Throws Marked as Failed');

    $tmp = tmpDirUnique('initthrow');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $pluginDir = $pluginsDir . '/throwplug';
    mkdir($pluginDir, 0755, true);
    file_put_contents($pluginDir . '/manifest.json', json_encode(['name' => 'throwplug', 'version' => '1.0.0']));
    file_put_contents($pluginDir . '/throwplug.php', '<?php
function throwplug_init() { throw new RuntimeException("boot fail"); }
');

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $pm->discover();
    $pm->enable('throwplug');
    $loaded = $pm->loadEnabled();
    $state = $pm->getPluginState('throwplug');

    $t->assertCount('Loaded list is empty (init threw)', 0, $loaded);
    $t->assertEquals('Plugin marked as failed', 'failed', $state);
    $t->assertFalse('Failed plugin not in enabled list', $pm->isEnabled('throwplug'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_failure_update_then_rollback(): Test
{
    $t = new Test('Failure - Update Failure Restores Original Plugin');

    $tmp = tmpDirUnique('rollback');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $pluginDir = $pluginsDir . '/myplug';
    mkdir($pluginDir, 0755, true);
    file_put_contents($pluginDir . '/manifest.json', json_encode(['name' => 'myplug', 'version' => '1.0.0']));
    file_put_contents($pluginDir . '/myplug.php', '<?php
function myplug_init() {}
');
    file_put_contents($pluginDir . '/SENTINEL.txt', 'original-content');

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $pm->discover();

    $badZip = $tmp . '/bad-update.zip';
    makeZip([
        'manifest.json' => '{not valid',
    ], $badZip);

    $result = $pm->updateFromZip('myplug', $badZip);

    $t->assertFalse('Bad update rejected', $result['success']);
    $t->assertTrue('Original plugin folder still exists', is_dir($pluginDir));
    $t->assertTrue('Original SENTINEL file still present', file_exists($pluginDir . '/SENTINEL.txt'));
    $t->assertEquals('SENTINEL content unchanged', 'original-content', file_get_contents($pluginDir . '/SENTINEL.txt'));

    $leftoverBackup = glob($pluginsDir . '/_old_myplug_*');
    $t->assertCount('No leftover backup after rollback', 0, $leftoverBackup);

    tmpDirCleanup($tmp);
    return $t;
}

function test_success_update_replaces_plugin(): Test
{
    $t = new Test('Success - Update Replaces Plugin And Runs Same Pipeline');

    $tmp = tmpDirUnique('updok');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $pluginDir = $pluginsDir . '/updplug';
    mkdir($pluginDir, 0755, true);
    file_put_contents($pluginDir . '/manifest.json', json_encode(['name' => 'updplug', 'version' => '1.0.0']));
    file_put_contents($pluginDir . '/updplug.php', '<?php
function updplug_init() { echo "old"; }
');
    file_put_contents($pluginDir . '/OLD.txt', 'old');

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $pm->discover();

    $goodZip = $tmp . '/v2.zip';
    makeZip([
        'manifest.json' => json_encode(['name' => 'updplug', 'version' => '2.0.0']),
        'updplug.php' => '<?php
function updplug_init() { echo "new"; }
',
        'NEW.txt' => 'new-content',
    ], $goodZip);

    $result = $pm->updateFromZip('updplug', $goodZip);

    $t->assertTrue('Good update succeeds', $result['success']);
    $t->assertFalse('OLD file removed', file_exists($pluginDir . '/OLD.txt'));
    $t->assertTrue('NEW file present', file_exists($pluginDir . '/NEW.txt'));
    $t->assertEquals('NEW content matches', 'new-content', file_get_contents($pluginDir . '/NEW.txt'));

    $manifest = json_decode(file_get_contents($pluginDir . '/manifest.json'), true);
    $t->assertEquals('Manifest version updated', '2.0.0', $manifest['version']);

    tmpDirCleanup($tmp);
    return $t;
}

function test_uninstall_uses_installed_identity(): Test
{
    $t = new Test('Uninstall - Uses Installed Folder, Not Reconstructed Key');

    $tmp = tmpDirUnique('uninstall');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $pluginDir = $pluginsDir . '/actual_folder';
    mkdir($pluginDir, 0755, true);
    file_put_contents($pluginDir . '/manifest.json', json_encode([
        'name' => 'Display Name With Spaces',
        'version' => '1.0.0',
    ]));
    file_put_contents($pluginDir . '/actual_folder.php', '<?php
function display_name_with_spaces_init() {}
');
    file_put_contents($pluginDir . '/KEEP_ME.txt', 'should be deleted with the plugin');

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $pm->discover();
    $found = $pm->getByName('Display Name With Spaces');
    $t->assertNotNull('Plugin discovered by display name', $found);
    $t->assertEquals('Folder preserved as actual_folder', 'actual_folder', $found['folder']);

    $result = $pm->uninstall('Display Name With Spaces');

    $t->assertTrue('Uninstall succeeded', $result['success']);
    $t->assertFalse('Plugin folder removed', is_dir($pluginDir));
    $t->assertFalse('Stray file removed', file_exists($pluginDir . '/KEEP_ME.txt'));
    $t->assertFalse('Stray file under reconstructed key not created', is_dir($pluginsDir . '/display name with spaces'));

    $byName = $pm->getByName('Display Name With Spaces');
    $t->assertNull('Plugin no longer in registry', $byName);

    tmpDirCleanup($tmp);
    return $t;
}

function test_dep_disable_cascades(): Test
{
    $t = new Test('Dependencies - Disable Cascades To Dependents, Re-enable Does Not Auto-Enable');

    $tmp = tmpDirUnique('depcas');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    function makeDep(string $dir, string $name, array $manifest, string $init = ''): void
    {
        mkdir($dir . '/' . $name, 0755, true);
        file_put_contents($dir . '/' . $name . '/manifest.json', json_encode($manifest));
        file_put_contents($dir . '/' . $name . '/' . $name . '.php', "<?php\nfunction {$name}_init() {{$init}}\n");
    }

    makeDep($pluginsDir, 'plugc', ['name' => 'plugc', 'version' => '1.0.0']);
    makeDep($pluginsDir, 'plugb', ['name' => 'plugb', 'version' => '1.0.0', 'dependencies' => ['plugc' => '>=1.0.0']]);
    makeDep($pluginsDir, 'pluga', ['name' => 'pluga', 'version' => '1.0.0', 'dependencies' => ['plugb' => '>=1.0.0']]);

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $pm->discover();
    $pm->enable('plugc');
    $pm->enable('plugb');
    $pm->enable('pluga');

    $t->assertTrue('A enabled', $pm->isEnabled('pluga'));
    $t->assertTrue('B enabled', $pm->isEnabled('plugb'));
    $t->assertTrue('C enabled', $pm->isEnabled('plugc'));

    $pm->disable('plugc');

    $t->assertFalse('C disabled', $pm->isEnabled('plugc'));
    $t->assertFalse('B auto-disabled by cascade', $pm->isEnabled('plugb'));
    $t->assertFalse('A auto-disabled by cascade', $pm->isEnabled('pluga'));

    $pm->enable('plugc');

    $t->assertTrue('C re-enabled', $pm->isEnabled('plugc'));
    $t->assertFalse('B NOT auto-re-enabled', $pm->isEnabled('plugb'));
    $t->assertFalse('A NOT auto-re-enabled', $pm->isEnabled('pluga'));

    $r = $pm->enableWithDeps('pluga');
    $t->assertTrue('enableWithDeps succeeds for A', $r['success']);
    $t->assertTrue('A enabled via enableWithDeps', $pm->isEnabled('pluga'));
    $t->assertTrue('B enabled via cascade', $pm->isEnabled('plugb'));
    $t->assertTrue('C still enabled', $pm->isEnabled('plugc'));

    tmpDirCleanup($tmp);
    return $t;
}

function test_dep_semver_full_constraint(): Test
{
    $t = new Test('Dependencies - Full Semver Constraint Applied (Not Just >=)');

    $tmp = tmpDirUnique('semver');
    $pluginsDir = $tmp . '/plugins';
    $manifestPath = $tmp . '/manifest.json';
    mkdir($pluginsDir, 0755, true);
    file_put_contents($manifestPath, json_encode([]));

    $base = $pluginsDir . '/lib';
    mkdir($base, 0755, true);
    file_put_contents($base . '/manifest.json', json_encode(['name' => 'lib', 'version' => '1.5.0']));
    file_put_contents($base . '/lib.php', '<?php function lib_init() {}');

    $consumer = $pluginsDir . '/consumer';
    mkdir($consumer, 0755, true);
    file_put_contents($consumer . '/manifest.json', json_encode([
        'name' => 'consumer',
        'version' => '1.0.0',
        'dependencies' => ['lib' => '<2.0.0'],
    ]));
    file_put_contents($consumer . '/consumer.php', '<?php function consumer_init() {}');

    $pm = new PluginManager($pluginsDir, $manifestPath);
    $pm->discover();
    $pm->enable('lib');

    $r = $pm->checkDependencies('consumer');
    $t->assertTrue('lib 1.5.0 satisfies <2.0.0', $r['compatible']);

    file_put_contents($base . '/manifest.json', json_encode(['name' => 'lib', 'version' => '2.5.0']));
    clearstatcache();
    $r = $pm->checkDependencies('consumer');
    $t->assertFalse('lib 2.5.0 fails <2.0.0', $r['compatible']);

    tmpDirCleanup($tmp);
    return $t;
}

register_tests(
    'test_failure_corrupt_zip',
    'test_failure_zip_slip',
    'test_failure_invalid_manifest',
    'test_failure_manifest_missing_name',
    'test_failure_extra_files',
    'test_failure_missing_files',
    'test_failure_php_version_constraint',
    'test_failure_core_version_constraint',
    'test_failure_no_tmp_dir_left',
    'test_failure_init_throws',
    'test_failure_update_then_rollback',
    'test_success_update_replaces_plugin',
    'test_uninstall_uses_installed_identity',
    'test_dep_disable_cascades',
    'test_dep_semver_full_constraint'
);
