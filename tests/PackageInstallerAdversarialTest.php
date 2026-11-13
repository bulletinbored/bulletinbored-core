<?php

/**
 * PackageInstallerAdversarialTest — plugin/theme archives are hostile input.
 *
 * Covers Zip Slip in its several spellings, integrity-check mismatches,
 * pre-existing target directories, repo-only files and nested archives: the
 * installer must refuse anything unexpected and leave no partial state behind.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../lib/PackageInstaller.php';

function package_installer_tmp(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/bb_pkg_' . $prefix . '_' . uniqid('', true);
    @mkdir($dir, 0755, true);
    return $dir;
}

/**
 * @param array<string, string> $files
 */
function package_installer_zip(string $dir, array $files, string $name = 'pkg'): string
{
    $path = $dir . '/' . $name . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return '';
    }
    foreach ($files as $entry => $content) {
        $zip->addFromString($entry, $content);
    }
    $zip->close();
    return $path;
}

/**
 * Recursively delete a directory without following symlinks.
 */
function package_installer_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
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
    @rmdir($dir);
}

function package_installer_leftover_tmp(string $packagesDir): array
{
    return glob($packagesDir . '/.install-tmp-*') ?: [];
}

function test_package_rejects_zip_slip_variants(): Test
{
    $t = new Test('Package installer - Zip Slip variants rejected');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = package_installer_tmp('slip');
    $packages = $root . '/packages';
    @mkdir($packages, 0755, true);
    $sentinel = $root . '/escaped.php';

    $variants = [
        'relative traversal' => '../escaped.php',
        'nested traversal' => 'plugin/../../escaped.php',
        'absolute path' => $sentinel,
        'backslash traversal' => '..\\escaped.php',
    ];

    App::getInstance()->config = [];
    $installer = new PackageInstaller($packages, 'plugin_verify_files');

    try {
        foreach ($variants as $label => $entry) {
            $zipPath = package_installer_zip($root, [$entry => '<?php echo "pwned";'], 'slip_' . md5($label));
            $result = $installer->install($zipPath, $packages . '/plugin_' . md5($label));
            $t->assertFalse("{$label} rejected", $result['success']);
            $t->assert("{$label}: no file written outside the target", !is_file($sentinel));
            $t->assert("{$label}: no target directory created", !is_dir($packages . '/plugin_' . md5($label)));
            $t->assertCount("{$label}: temporary directory cleaned up", 0, package_installer_leftover_tmp($packages));
        }
    } finally {
        App::reset();
        package_installer_rmdir($root);
    }

    return $t;
}

function test_package_rejects_manifest_mismatch(): Test
{
    $t = new Test('Package installer - manifest integrity check');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = package_installer_tmp('manifest');
    $packages = $root . '/packages';
    @mkdir($packages, 0755, true);

    App::getInstance()->config = [];
    $installer = new PackageInstaller($packages, 'plugin_verify_files');
    $manifest = json_encode(['name' => 'demo', 'version' => '1.0.0', 'files' => ['demo.php', 'extra.php']]);

    try {
        // A declared file is missing from the archive.
        $missing = package_installer_zip($root, [
            'manifest.json' => $manifest,
            'demo.php' => '<?php // demo',
        ], 'missing');
        $result = $installer->install($missing, $packages . '/demo_missing');
        $t->assertFalse('Missing declared file rejected', $result['success']);
        $t->assert('Error mentions the manifest check', str_contains($result['message'], 'integrity'));
        $t->assertCount('Temporary directory cleaned up', 0, package_installer_leftover_tmp($packages));

        // An undeclared file is shipped with the package.
        $extra = package_installer_zip($root, [
            'manifest.json' => $manifest,
            'demo.php' => '<?php // demo',
            'extra.php' => '<?php // extra',
            'payload.php' => '<?php // not declared',
        ], 'extra');
        $result = $installer->install($extra, $packages . '/demo_extra');
        $t->assertFalse('Undeclared file rejected', $result['success']);
        $t->assert('Error lists the undeclared file', str_contains($result['message'], 'payload.php'));

        // With verification disabled the same archive installs.
        App::getInstance()->config = ['plugin_verify_files' => false];
        $installerRelaxed = new PackageInstaller($packages, 'plugin_verify_files');
        $okZip = package_installer_zip($root, [
            'manifest.json' => $manifest,
            'demo.php' => '<?php // demo',
            'extra.php' => '<?php // extra',
        ], 'ok');
        $result = $installerRelaxed->install($okZip, $packages . '/demo_ok');
        $t->assertTrue('Install works when verification is disabled for undeclared files', $result['success']);
        $t->assertTrue('Installed directory exists', is_file($packages . '/demo_ok/demo.php'));
    } finally {
        App::reset();
        package_installer_rmdir($root);
    }

    return $t;
}

function test_package_refuses_to_overwrite_existing_directory(): Test
{
    $t = new Test('Package installer - pre-existing target directory');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = package_installer_tmp('existing');
    $packages = $root . '/packages';
    $target = $packages . '/demo';
    @mkdir($target, 0755, true);
    file_put_contents($target . '/keep.php', '<?php // installed version');

    App::getInstance()->config = [];
    $installer = new PackageInstaller($packages, 'plugin_verify_files');

    try {
        $zipPath = package_installer_zip($root, [
            'manifest.json' => json_encode(['name' => 'demo', 'files' => ['new.php']]),
            'new.php' => '<?php // new version',
        ]);
        $result = $installer->install($zipPath, $target);

        $t->assertFalse('Install over an existing package is refused', $result['success']);
        $t->assertTrue('Existing files untouched', is_file($target . '/keep.php'));
        $t->assertEquals('Existing file content preserved', '<?php // installed version', file_get_contents($target . '/keep.php'));
        $t->assertFalse('New payload not merged into the old package', is_file($target . '/new.php'));
        $t->assertCount('Temporary directory cleaned up', 0, package_installer_leftover_tmp($packages));
    } finally {
        App::reset();
        package_installer_rmdir($root);
    }

    return $t;
}

function test_package_strips_repo_only_files_and_flattens(): Test
{
    $t = new Test('Package installer - repo files stripped, nesting flattened');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = package_installer_tmp('flatten');
    $packages = $root . '/packages';
    @mkdir($packages, 0755, true);

    App::getInstance()->config = [];
    $installer = new PackageInstaller($packages, 'plugin_verify_files');

    try {
        $zipPath = package_installer_zip($root, [
            'demo-1.0.0/manifest.json' => json_encode(['name' => 'demo', 'files' => ['demo.php']]),
            'demo-1.0.0/demo.php' => '<?php // demo',
            'demo-1.0.0/tests/DemoTest.php' => '<?php // test',
            'demo-1.0.0/.github/workflows/ci.yml' => 'name: ci',
            'demo-1.0.0/.gitignore' => '/vendor',
            'demo-1.0.0/composer.json' => '{}',
        ]);

        $result = $installer->install($zipPath, $packages . '/demo');
        $t->assertTrue('Nested package installed', $result['success']);
        $t->assertTrue('Manifest landed at the package root', is_file($packages . '/demo/manifest.json'));
        $t->assertTrue('Plugin file present', is_file($packages . '/demo/demo.php'));
        $t->assertFalse('tests/ stripped', is_dir($packages . '/demo/tests'));
        $t->assertFalse('.github/ stripped', is_dir($packages . '/demo/.github'));
        $t->assertFalse('.gitignore stripped', is_file($packages . '/demo/.gitignore'));
        $t->assertFalse('composer.json stripped', is_file($packages . '/demo/composer.json'));
        $t->assertFalse('Wrapper directory removed', is_dir($packages . '/demo/demo-1.0.0'));
    } finally {
        App::reset();
        package_installer_rmdir($root);
    }

    return $t;
}

function test_package_rejects_invalid_archives(): Test
{
    $t = new Test('Package installer - invalid archives');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = package_installer_tmp('invalid');
    $packages = $root . '/packages';
    @mkdir($packages, 0755, true);

    App::getInstance()->config = [];
    $installer = new PackageInstaller($packages, 'plugin_verify_files');

    try {
        $missing = $installer->install($root . '/does-not-exist.zip', $packages . '/nope');
        $t->assertFalse('Missing archive rejected', $missing['success']);

        $notZip = $root . '/not-a-zip.zip';
        file_put_contents($notZip, 'this is not a zip file');
        $broken = $installer->install($notZip, $packages . '/broken');
        $t->assertFalse('Corrupt archive rejected', $broken['success']);
        $t->assertFalse('No directory created for a corrupt archive', is_dir($packages . '/broken'));

        $callbackZip = package_installer_zip($root, [
            'manifest.json' => json_encode(['name' => 'demo', 'files' => ['demo.php']]),
            'demo.php' => '<?php // demo',
        ], 'callback');
        $rejected = $installer->install($callbackZip, $packages . '/callback', function () {
            return ['success' => false, 'message' => 'manifest incompatible with this core version'];
        });
        $t->assertFalse('Verification callback can veto an install', $rejected['success']);
        $t->assertFalse('Vetoed install leaves no directory', is_dir($packages . '/callback'));
        $t->assertCount('No temporary directories left', 0, package_installer_leftover_tmp($packages));
    } finally {
        App::reset();
        package_installer_rmdir($root);
    }

    return $t;
}

register_tests(
    'test_package_rejects_zip_slip_variants',
    'test_package_rejects_manifest_mismatch',
    'test_package_refuses_to_overwrite_existing_directory',
    'test_package_strips_repo_only_files_and_flattens',
    'test_package_rejects_invalid_archives'
);
