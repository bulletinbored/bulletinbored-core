<?php

/**
 * UpdateBackupTest — the backup is the only way back from a broken update, so
 * it must be complete or absent, must cover hidden files, and must never delete
 * an installation file it cannot prove it has a copy of.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/UpdateBackup.php';

function update_backup_root(): string
{
    $root = sys_get_temp_dir() . '/bb_bk_' . uniqid('', true);
    @mkdir($root . '/data', 0755, true);
    @mkdir($root . '/lib', 0755, true);
    @mkdir($root . '/plugins/custom', 0755, true);
    file_put_contents($root . '/index.php', "<?php // core\n");
    file_put_contents($root . '/VERSION', "0.9.8\n");
    file_put_contents($root . '/.htaccess', "# old htaccess\n");
    file_put_contents($root . '/lib/BbPdo.php', "<?php // lib\n");
    file_put_contents($root . '/lib/.hidden', "hidden lib file\n");
    file_put_contents($root . '/plugins/custom/plugin.php', "<?php // plugin\n");
    file_put_contents($root . '/data/database.sqlite', 'db');
    file_put_contents($root . '/config.json', '{"version":"0.9.8"}');
    return $root;
}

function update_backup_cleanup(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function test_backup_includes_hidden_files_and_manifest(): Test
{
    $t = new Test('Update backup - hidden files included, manifest written');

    $root = update_backup_root();
    $backup = new UpdateBackup($root . '/data', $root);

    try {
        $path = $backup->backupCore();
        $t->assertNotNull('Backup created', $path);
        if ($path === null) {
            return $t;
        }

        $t->assertTrue('.htaccess backed up', is_file($path . '/.htaccess'));
        $t->assertEquals('.htaccess content preserved', "# old htaccess\n", file_get_contents($path . '/.htaccess'));
        $t->assertTrue('Nested hidden file backed up', is_file($path . '/lib/.hidden'));
        $t->assertTrue('index.php backed up', is_file($path . '/index.php'));
        $t->assertFalse('user data excluded', is_dir($path . '/data'));
        $t->assertFalse('plugins excluded', is_dir($path . '/plugins'));

        $manifest = json_decode((string)@file_get_contents($path . '.json'), true);
        $t->assert('Manifest written', is_array($manifest));
        $t->assertTrue('Manifest marks the backup complete', !empty($manifest['complete']));
        $t->assertContains('Manifest lists .htaccess', '.htaccess', $manifest['entries'] ?? []);
        $t->assertContains('Manifest lists index.php', 'index.php', $manifest['entries'] ?? []);

        $listed = $backup->listBackups();
        $t->assertCount('One backup listed', 1, $listed);
        $t->assertTrue('Listed backup reports complete', !empty($listed[0]['complete']));
    } finally {
        update_backup_cleanup($root);
    }

    return $t;
}

function test_backup_failure_leaves_no_partial_backup(): Test
{
    $t = new Test('Update backup - a failed copy discards the backup');

    $root = update_backup_root();

    $failing = new class($root . '/data', $root) extends UpdateBackup {
        public function copyRecursive(string $src, string $dst): void
        {
            throw new RuntimeException('No space left on device');
        }
    };

    try {
        $t->assertNull('backupCore returns null', $failing->backupCore());
        $leftovers = glob($root . '/data/backups/core_*') ?: [];
        $t->assertCount('No partial backup directory left', 0, $leftovers);
        $manifests = glob($root . '/data/backups/core_*.json') ?: [];
        $t->assertCount('No manifest left', 0, $manifests);
    } finally {
        update_backup_cleanup($root);
    }

    return $t;
}

function test_copy_recursive_reports_failure(): Test
{
    $t = new Test('Update backup - copy failures throw');

    $root = update_backup_root();
    $backup = new UpdateBackup($root . '/data', $root);

    try {
        // Destination under a regular file: mkdir cannot succeed.
        file_put_contents($root . '/blocker', 'not a directory');
        $t->assertThrows(
            'copyRecursive throws when the destination cannot be created',
            fn() => $backup->copyRecursive($root . '/lib', $root . '/blocker/sub'),
            RuntimeException::class
        );

        $t->assertThrows(
            'copyRecursive refuses to follow symlinks',
            function () use ($root, $backup) {
                @symlink($root . '/lib/BbPdo.php', $root . '/link.php');
                try {
                    $backup->copyRecursive($root . '/link.php', $root . '/lib/copy.php');
                } finally {
                    @unlink($root . '/link.php');
                }
            },
            RuntimeException::class
        );
    } finally {
        update_backup_cleanup($root);
    }

    return $t;
}

function test_restore_prunes_only_with_a_manifest(): Test
{
    $t = new Test('Update backup - restore prunes only a verified backup');

    $root = update_backup_root();
    $backup = new UpdateBackup($root . '/data', $root);

    try {
        $path = $backup->backupCore();
        $t->assertNotNull('Backup created', $path);
        if ($path === null) {
            return $t;
        }

        // Simulate an interrupted update that changed a file and added a new one.
        file_put_contents($root . '/index.php', "<?php // broken update\n");
        file_put_contents($root . '/brand-new.php', "<?php // new\n");
        file_put_contents($root . '/.htaccess', "# new htaccess\n");

        $t->assertTrue('Restore succeeded', $backup->restoreCoreBackup($path));
        $t->assertEquals('index.php restored', "<?php // core\n", file_get_contents($root . '/index.php'));
        $t->assertEquals('.htaccess restored', "# old htaccess\n", file_get_contents($root . '/.htaccess'));
        $t->assertFalse('File created by the failed update removed', is_file($root . '/brand-new.php'));
        $t->assertTrue('User data untouched', is_file($root . '/data/database.sqlite'));
        $t->assertTrue('Plugins untouched', is_file($root . '/plugins/custom/plugin.php'));
        $t->assertEquals('config.json untouched', '{"version":"0.9.8"}', file_get_contents($root . '/config.json'));

        // Same backup without its manifest: files are restored, nothing pruned.
        $unverified = $root . '/data/backups/core_unverified';
        $backup->copyRecursive($path, $unverified);
        file_put_contents($root . '/stray.php', "<?php // stray\n");
        $t->assertTrue('Restore without manifest succeeds', $backup->restoreCoreBackup($unverified));
        $t->assertTrue('Nothing is pruned without a manifest', is_file($root . '/stray.php'));
    } finally {
        update_backup_cleanup($root);
    }

    return $t;
}

register_tests(
    'test_backup_includes_hidden_files_and_manifest',
    'test_backup_failure_leaves_no_partial_backup',
    'test_copy_recursive_reports_failure',
    'test_restore_prunes_only_with_a_manifest'
);
