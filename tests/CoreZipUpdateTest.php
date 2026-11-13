<?php

/**
 * CoreZipUpdateTest — a core update applied from an uploaded ZIP must leave the
 * installation in the same state as an update pulled from the repository.
 *
 * Before this test existed the manual ZIP route copied the files and stopped:
 * VERSION, config.json and the update manifest kept the old number, and the web
 * installer scripts stayed on disk.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/UpdateManager.php';

/**
 * Build a fake installation root with a config.json, VERSION and installers.
 */
function core_zip_root(string $version = '0.9.8'): string
{
    $root = sys_get_temp_dir() . '/bb_corezip_' . uniqid('', true);
    @mkdir($root . '/data', 0755, true);
    @mkdir($root . '/api', 0755, true);
    file_put_contents($root . '/VERSION', $version);
    file_put_contents($root . '/config.json', json_encode([
        'version' => $version,
        'db_driver' => 'sqlite',
        'site_name' => 'Old site',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($root . '/index.php', "<?php // old core\n");
    file_put_contents($root . '/install.php', "<?php // installer\n");
    file_put_contents($root . '/install2.php', "<?php // installer\n");
    file_put_contents($root . '/install3.php', "<?php // installer\n");
    file_put_contents($root . '/api/install.php', "<?php // admin endpoint\n");
    return $root;
}

function core_zip_cleanup(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($root);
}

/**
 * @param array<string, string> $files path => content
 */
function core_zip_build(array $files): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zipPath = sys_get_temp_dir() . '/bb_corezip_' . uniqid('', true) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return '';
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return $zipPath;
}

function test_core_zip_update_finalizes_like_remote_update(): Test
{
    $t = new Test('Core update - ZIP upload finalizes the installation');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive extension not available', true);
        return $t;
    }

    $root = core_zip_root('0.9.8');
    $zipPath = core_zip_build([
        'index.php' => "<?php // new core 1.0.0\n",
        'VERSION' => '1.0.0',
        'install.php' => "<?php // installer shipped in package\n",
    ]);

    $manifestPath = $root . '/data/update_manifest.json';
    $manager = new UpdateManager($manifestPath, null, null, null, $root);

    try {
        $t->assertNotEquals('ZIP built', '', $zipPath);
        $ok = $manager->applyUpdate('core', 'core', $zipPath);
        $t->assertTrue('applyUpdate() succeeded', $ok);

        $t->assertEquals('VERSION updated from the package', '1.0.0', trim((string)file_get_contents($root . '/VERSION')));

        $config = json_decode((string)file_get_contents($root . '/config.json'), true);
        $t->assertEquals('config.json version updated', '1.0.0', $config['version'] ?? null);
        $t->assertEquals('config.json keeps unrelated settings', 'Old site', $config['site_name'] ?? null);

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $t->assertEquals('manifest core version updated', '1.0.0', $manifest['core']['core']['version'] ?? null);

        $t->assertEquals('index.php replaced', "<?php // new core 1.0.0\n", file_get_contents($root . '/index.php'));

        foreach (['install.php', 'install2.php', 'install3.php'] as $installer) {
            $t->assertFalse("{$installer} removed after update", is_file($root . '/' . $installer));
        }
        $t->assertTrue('api/install.php kept (runtime admin endpoint)', is_file($root . '/api/install.php'));
    } finally {
        core_zip_cleanup($root);
        if ($zipPath !== '') {
            @unlink($zipPath);
        }
    }

    return $t;
}

function test_core_zip_update_flattens_nested_directory(): Test
{
    $t = new Test('Core update - nested package directory flattened');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive extension not available', true);
        return $t;
    }

    $root = core_zip_root('0.9.8');
    $zipPath = core_zip_build([
        'bulletinbored-core-1.1.0/index.php' => "<?php // new core 1.1.0\n",
        'bulletinbored-core-1.1.0/VERSION' => '1.1.0',
        'bulletinbored-core-1.1.0/lib/BbPdo.php' => "<?php // bbpdo 1.1.0\n",
    ]);

    $manager = new UpdateManager($root . '/data/update_manifest.json', null, null, null, $root);

    try {
        $t->assertTrue('applyUpdate() succeeded', $manager->applyUpdate('core', 'core', $zipPath));
        $t->assertEquals('Nested package version applied', '1.1.0', trim((string)file_get_contents($root . '/VERSION')));
        $t->assertTrue('Nested files landed in the root', is_file($root . '/lib/BbPdo.php'));
        $t->assertFalse('Wrapper directory removed', is_dir($root . '/bulletinbored-core-1.1.0'));
    } finally {
        core_zip_cleanup($root);
        if ($zipPath !== '') {
            @unlink($zipPath);
        }
    }

    return $t;
}

function test_core_zip_update_rejects_invalid_package(): Test
{
    $t = new Test('Core update - invalid ZIP leaves installation untouched');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive extension not available', true);
        return $t;
    }

    $root = core_zip_root('0.9.8');
    $zipPath = core_zip_build([
        'index.php' => "<?php // incomplete package\n",
    ]);

    $manager = new UpdateManager($root . '/data/update_manifest.json', null, null, null, $root);

    try {
        $t->assertFalse('Package without VERSION rejected', $manager->applyUpdate('core', 'core', $zipPath));
        $t->assertEquals('VERSION unchanged', '0.9.8', trim((string)file_get_contents($root . '/VERSION')));
        $t->assertEquals('index.php unchanged', "<?php // old core\n", file_get_contents($root . '/index.php'));
        $t->assertTrue('installer still present', is_file($root . '/install.php'));
    } finally {
        core_zip_cleanup($root);
        if ($zipPath !== '') {
            @unlink($zipPath);
        }
    }

    return $t;
}

function test_core_zip_update_reports_metadata_failure(): Test
{
    $t = new Test('Core update - unfinished metadata is reported as failure');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = core_zip_root('0.9.8');
    // A corrupt config.json cannot receive the new version.
    file_put_contents($root . '/config.json', '{ this is not json');
    $zipPath = core_zip_build([
        'index.php' => "<?php // new core\n",
        'VERSION' => '1.0.0',
    ]);

    $manager = new UpdateManager($root . '/data/update_manifest.json', null, null, null, $root);

    try {
        $ok = $manager->applyUpdate('core', 'core', $zipPath);
        $t->assertFalse('Update reported as failed', $ok);
        $t->assertEquals('Files were still updated', "<?php // new core\n", file_get_contents($root . '/index.php'));
        $t->assertEquals('VERSION written before the failure', '1.0.0', trim((string)file_get_contents($root . '/VERSION')));
        $manifest = json_decode((string)file_get_contents($root . '/data/update_manifest.json'), true);
        $t->assertEquals('Manifest written before the failure', '1.0.0', $manifest['core']['core']['version'] ?? null);
        $t->assertEquals('Corrupt config left untouched', '{ this is not json', file_get_contents($root . '/config.json'));
    } finally {
        core_zip_cleanup($root);
        if ($zipPath !== '') {
            @unlink($zipPath);
        }
    }

    return $t;
}

function test_set_version_reports_write_failure(): Test
{
    $t = new Test('Update manifest - write failures are reported');

    // "data" is a file, so the manifest directory cannot be created.
    $root = sys_get_temp_dir() . '/bb_umw_' . uniqid('', true);
    @mkdir($root, 0755, true);
    file_put_contents($root . '/data', 'not a directory');

    try {
        $manager = new UpdateManager($root . '/data/update_manifest.json', null, null, null, $root);
        $t->assertFalse('setVersion() returns false when the manifest cannot be written', $manager->setVersion('core', 'core', '1.0.0'));
    } finally {
        @unlink($root . '/data');
        @rmdir($root);
    }

    return $t;
}

register_tests(
    'test_core_zip_update_finalizes_like_remote_update',
    'test_core_zip_update_flattens_nested_directory',
    'test_core_zip_update_rejects_invalid_package',
    'test_core_zip_update_reports_metadata_failure',
    'test_set_version_reports_write_failure'
);
