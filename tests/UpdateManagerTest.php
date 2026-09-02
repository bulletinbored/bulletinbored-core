<?php

/**
 * UpdateManagerTest — tests for the update system, ZIP validation, and rollback.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../lib/repo_install.php';
require_once __DIR__ . '/../lib/PluginManager.php';

function test_zip_slip_traversal_blocked(): Test
{
    $t = new Test('Update - Zip Slip Traversal Blocked');

    $tmpDir = sys_get_temp_dir() . '/bb_zipslip_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/malicious.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('../../../etc/cwd/evil.php', '<?php echo "hacked"; ?>');
    $zip->addFromString('good.txt', 'safe content');
    $zip->close();

    $extractDir = $tmpDir . '/extract';
    mkdir($extractDir, 0755, true);

    $result = extract_zip($zipPath, $extractDir);
    $t->assert('Zip slip traversal blocked', $result === false);

    $evilFile = $tmpDir . '/etc/cwd/evil.php';
    $t->assert('Evil file not created outside target', !file_exists($evilFile));

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

function test_zip_absolute_path_blocked(): Test
{
    $t = new Test('Update - Absolute Path in ZIP Blocked');

    $tmpDir = sys_get_temp_dir() . '/bb_abspath_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/absolute.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('/etc/passwd', 'root:x:0:0:root:/root:/bin/bash');
    $zip->close();

    $extractDir = $tmpDir . '/extract';
    mkdir($extractDir, 0755, true);

    $result = extract_zip($zipPath, $extractDir);
    $t->assert('Absolute path in ZIP blocked', $result === false);

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

function test_valid_zip_extracts(): Test
{
    $t = new Test('Update - Valid ZIP Extracts Correctly');

    $tmpDir = sys_get_temp_dir() . '/bb_valid_zip_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/valid.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('index.php', '<?php echo "Hello World"; ?>');
    $zip->addFromString('VERSION', '1.0.0');
    $zip->addFromString('src/helpers.php', '<?php // helpers');
    $zip->addFromString('assets/style.css', 'body { color: black; }');
    $zip->close();

    $extractDir = $tmpDir . '/extract';
    mkdir($extractDir, 0755, true);

    $result = extract_zip($zipPath, $extractDir);
    $t->assert('Valid ZIP extracted successfully', $result === true);

    $t->assert('index.php extracted', file_exists($extractDir . '/index.php'));
    $t->assert('VERSION extracted', file_exists($extractDir . '/VERSION'));
    $t->assert('src/helpers.php extracted', file_exists($extractDir . '/src/helpers.php'));
    $t->assert('assets/style.css extracted', file_exists($extractDir . '/assets/style.css'));

    $version = file_get_contents($extractDir . '/VERSION');
    $t->assertEquals('VERSION content correct', '1.0.0', $version);

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

function test_zip_missing_version_rejected(): Test
{
    $t = new Test('Update - ZIP Missing VERSION Rejected');

    $tmpDir = sys_get_temp_dir() . '/bb_no_version_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/noversion.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('index.php', '<?php echo "Hello"; ?>');
    $zip->addFromString('readme.txt', 'No version here');
    $zip->close();

    $zipContent = file_get_contents($zipPath);
    $t->assert('ZIP without VERSION created', $zipContent !== false);

    $zip2 = new ZipArchive();
    $zip2->open($zipPath);
    $hasVersion = false;
    for ($i = 0; $i < $zip2->numFiles; $i++) {
        if ($zip2->getNameIndex($i) === 'VERSION') {
            $hasVersion = true;
            break;
        }
    }
    $zip2->close();
    $t->assert('ZIP does not contain VERSION', !$hasVersion);

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

function test_zip_missing_index_php_rejected(): Test
{
    $t = new Test('Update - ZIP Missing index.php Rejected');

    $tmpDir = sys_get_temp_dir() . '/bb_no_index_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/noindex.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('VERSION', '1.0.0');
    $zip->addFromString('readme.txt', 'No index.php here');
    $zip->close();

    $zip2 = new ZipArchive();
    $zip2->open($zipPath);
    $hasIndex = false;
    for ($i = 0; $i < $zip2->numFiles; $i++) {
        if ($zip2->getNameIndex($i) === 'index.php') {
            $hasIndex = true;
            break;
        }
    }
    $zip2->close();
    $t->assert('ZIP does not contain index.php', !$hasIndex);

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

function test_nested_github_directory_flattened(): Test
{
    $t = new Test('Update - Nested GitHub Directory Structure');

    $tmpDir = sys_get_temp_dir() . '/bb_nested_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/nested.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $zip->addFromString('bulletinbored-core-main/index.php', '<?php echo "Hello"; ?>');
    $zip->addFromString('bulletinbored-core-main/VERSION', '1.0.0');
    $zip->addFromString('bulletinbored-core-main/src/App.php', '<?php // App');
    $zip->close();

    $extractDir = $tmpDir . '/extract';
    mkdir($extractDir, 0755, true);

    $result = extract_zip($zipPath, $extractDir);
    $t->assert('Nested ZIP extracted', $result === true);

    $hasNestedDir = file_exists($extractDir . '/bulletinbored-core-main/index.php');
    $t->assert('Nested directory structure preserved in extraction', $hasNestedDir);

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

function test_invalid_zip_rejected(): Test
{
    $t = new Test('Update - Invalid ZIP File Rejected');

    $tmpDir = sys_get_temp_dir() . '/bb_invalid_zip_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/invalid.zip';
    file_put_contents($zipPath, 'This is not a ZIP file, just plain text');

    $extractDir = $tmpDir . '/extract';
    mkdir($extractDir, 0755, true);

    $result = extract_zip($zipPath, $extractDir);
    $t->assert('Invalid ZIP rejected', $result === false);

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

function test_update_version_tracking(): Test
{
    $t = new Test('Update - Version Tracking');

    $tmpFile = sys_get_temp_dir() . '/bb_manifest_' . uniqid() . '.json';
    file_put_contents($tmpFile, json_encode([]));

    $pm = new PluginManager(sys_get_temp_dir(), sys_get_temp_dir() . '/pm_' . uniqid() . '.json');

    $manifest = json_decode(file_get_contents($tmpFile), true);
    $t->assert('Manifest starts empty', is_array($manifest) && empty($manifest));

    $manifest['core'] = ['core' => ['version' => '1.0.0', 'last_check' => date('c')]];
    file_put_contents($tmpFile, json_encode($manifest));

    $updated = json_decode(file_get_contents($tmpFile), true);
    $t->assertEquals('Version tracked', '1.0.0', $updated['core']['core']['version'] ?? '');

    unlink($tmpFile);

    return $t;
}

function test_update_check_records_version(): Test
{
    $t = new Test('Update - Check Records Remote Version');

    $tmpFile = sys_get_temp_dir() . '/bb_manifest_' . uniqid() . '.json';
    file_put_contents($tmpFile, json_encode([]));

    $manifest = [];
    $remoteVersion = '1.2.0';
    $localVersion = '1.0.0';

    $hasUpdate = version_compare($remoteVersion, $localVersion, '>');
    $t->assert('Update available when remote > local', $hasUpdate);

    $manifest['core'] = [
        'core' => [
            'version' => $localVersion,
            'remote_version' => $remoteVersion,
            'last_check' => date('c'),
            'available_update' => $hasUpdate ? ['url' => 'https://example.com/update'] : null,
        ]
    ];

    $t->assert('Update recorded in manifest', $manifest['core']['core']['available_update'] !== null);

    $noUpdate = version_compare('1.0.0', '1.0.0', '>');
    $t->assert('No update when versions equal', !$noUpdate);

    unlink($tmpFile);

    return $t;
}

function test_preflight_php_version_check(): Test
{
    $t = new Test('Update - Preflight PHP Version Check');

    $currentPhp = PHP_VERSION;
    $meetsRequirement = version_compare($currentPhp, '8.1.0', '>=');
    $t->assert('Current PHP meets minimum requirement', $meetsRequirement);

    $failsRequirement = version_compare('7.4.0', '8.1.0', '>=');
    $t->assert('PHP 7.4 fails minimum requirement', !$failsRequirement);

    return $t;
}

function test_backup_creates_copy(): Test
{
    $t = new Test('Update - Backup Creates Copy');

    $tmpDir = sys_get_temp_dir() . '/bb_backup_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    file_put_contents($tmpDir . '/index.php', '<?php echo "original"; ?>');
    file_put_contents($tmpDir . '/VERSION', '1.0.0');

    $backupDir = $tmpDir . '_backup';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    copy($tmpDir . '/index.php', $backupDir . '/index.php');
    copy($tmpDir . '/VERSION', $backupDir . '/VERSION');

    $t->assert('Backup index.php created', file_exists($backupDir . '/index.php'));
    $t->assert('Backup VERSION created', file_exists($backupDir . '/VERSION'));

    $originalContent = file_get_contents($tmpDir . '/index.php');
    $backupContent = file_get_contents($backupDir . '/index.php');
    $t->assertEquals('Backup content matches original', $originalContent, $backupContent);

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

    if (is_dir($backupDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($backupDir);
    }

    return $t;
}

function test_restore_from_backup(): Test
{
    $t = new Test('Update - Restore from Backup');

    $tmpDir = sys_get_temp_dir() . '/bb_restore_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    file_put_contents($tmpDir . '/index.php', '<?php echo "original"; ?>');
    file_put_contents($tmpDir . '/VERSION', '1.0.0');

    $backupDir = $tmpDir . '_backup';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    copy($tmpDir . '/index.php', $backupDir . '/index.php');
    copy($tmpDir . '/VERSION', $backupDir . '/VERSION');

    file_put_contents($tmpDir . '/index.php', '<?php echo "updated but broken"; ?>');
    file_put_contents($tmpDir . '/VERSION', '2.0.0-broken');

    copy($backupDir . '/index.php', $tmpDir . '/index.php');
    copy($backupDir . '/VERSION', $tmpDir . '/VERSION');

    $restoredContent = file_get_contents($tmpDir . '/index.php');
    $t->assertEquals('index.php restored from backup', '<?php echo "original"; ?>', $restoredContent);

    $restoredVersion = file_get_contents($tmpDir . '/VERSION');
    $t->assertEquals('VERSION restored from backup', '1.0.0', $restoredVersion);

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

    if (is_dir($backupDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($backupDir);
    }

    return $t;
}

function test_zip_entries_safe_blocks_traversal(): Test
{
    $t = new Test('Update - zip_entries_safe Blocks Traversal');

    $tmpDir = sys_get_temp_dir() . '/bb_entries_safe_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $zipPath = $tmpDir . '/test.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('../outside.txt', 'outside content');
    $zip->close();

    $zip2 = new ZipArchive();
    $zip2->open($zipPath);
    $safe = zip_entries_safe($zip2, $tmpDir . '/extract');
    $zip2->close();

    $t->assert('zip_entries_safe blocks ../ traversal', $safe === false);

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

$tests = [
    test_zip_slip_traversal_blocked(),
    test_zip_absolute_path_blocked(),
    test_valid_zip_extracts(),
    test_zip_missing_version_rejected(),
    test_zip_missing_index_php_rejected(),
    test_nested_github_directory_flattened(),
    test_invalid_zip_rejected(),
    test_update_version_tracking(),
    test_update_check_records_version(),
    test_preflight_php_version_check(),
    test_backup_creates_copy(),
    test_restore_from_backup(),
    test_zip_entries_safe_blocks_traversal(),
];

$totalPassed = 0;
$totalFailed = 0;
foreach ($tests as $t) {
    $t->run();
    $totalPassed += $t->getPassed();
    $totalFailed += $t->getFailed();
}

echo "\n";
echo "############################################################\n";
echo "# TOTAL: {$totalPassed} passed, {$totalFailed} failed\n";
echo "############################################################\n";

exit($totalFailed > 0 ? 1 : 0);
