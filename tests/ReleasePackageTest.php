<?php

/**
 * ReleasePackageTest — the distribution ZIP is a product, not a copy of the repo.
 *
 * Builds the archive with tools/build-release.php into a temporary path and
 * inspects what actually landed in it: everything a clean install needs, and
 * none of the development or deployment files.
 */

require_once __DIR__ . '/harness.php';

function release_package_build(string $output): array
{
    if (!class_exists('ZipArchive') || !function_exists('proc_open')) {
        return ['ok' => false, 'skipped' => true, 'stdout' => '', 'stderr' => '', 'exit' => -1];
    }
    $root = dirname(__DIR__);
    // --allow-dirty: the suite runs on developer checkouts too, where the tree
    // is often dirty. The dirty-tree refusal has its own test below.
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-release.php')
        . ' --allow-dirty --output=' . escapeshellarg($output);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, $root);
    if (!is_resource($proc)) {
        return ['ok' => false, 'skipped' => true, 'stdout' => '', 'stderr' => '', 'exit' => -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['ok' => true, 'skipped' => false, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr, 'exit' => (int)$exit];
}

function release_package_zip_entries(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [];
    }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = (string)$zip->getNameIndex($i);
    }
    $zip->close();
    return $entries;
}

function test_release_package_contents(): Test
{
    $t = new Test('Release package - clean distribution archive');

    $output = sys_get_temp_dir() . '/bb_release_' . uniqid('', true) . '.zip';
    $result = release_package_build($output);

    if ($result['skipped']) {
        $t->assert('Skipped: ZipArchive/proc_open not available', true);
        return $t;
    }

    try {
        $t->assertEquals('Builder exited successfully', 0, $result['exit']);
        if ($result['exit'] !== 0) {
            $t->assert('stderr: ' . trim($result['stderr']), false);
        }
        $t->assertTrue('Archive created', is_file($output));

        $version = trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'));
        $t->assertTrue('SHA-256 checksum written', is_file($output . '.sha256'));
        if (is_file($output . '.sha256')) {
            $line = trim((string)file_get_contents($output . '.sha256'));
            $t->assertEquals('Checksum matches the archive', hash_file('sha256', $output), strtok($line, ' '));
        }

        $entries = release_package_zip_entries($output);
        $t->assert('Archive is not empty', count($entries) > 50);

        $required = [
            '.htaccess', 'VERSION', 'index.php', 'bb.php', 'router.php', 'nginx.conf', 'web.config',
            'config-sample.json', 'README.md', 'LICENSE',
            'install.php', 'install2.php', 'install3.php',
            'api/install.php',
            'lib/BbPdo.php', 'lib/DbQuery.php', 'lib/Migrator.php', 'lib/UpdateManager.php', 'lib/AdminAuth.php',
            'src/bootstrap.php', 'src/Helpers/Mail.php', 'src/Helpers/AuthHelpers.php',
            'lang/en.json',
            'themes/freshbored/manifest.json',
            'data/.htaccess', 'uploads/.htaccess',
        ];
        foreach ($required as $file) {
            $t->assertContains('Package contains ' . $file, $file, $entries);
        }
        $t->assert('Package contains migrations', (bool)preg_grep('#^migrations/.+\.php$#', $entries));
        $t->assert('Package contains views', (bool)preg_grep('#^views/.+#', $entries));

        $forbidden = [
            'tests' => '#^tests/#',
            'CI workflow' => '#^\.github/#',
            'release tooling' => '#^tools/#',
            'config.json' => '#^config\.json$#',
            'config.php' => '#^config\.php$#',
            'database' => '#^data/database\.sqlite$#',
            'sessions' => '#^data/sessions/#',
            'logs' => '#^data/logs/#',
            'rate limit buckets' => '#^data/ratelimit/#',
            'backups' => '#^data/backups/#',
            'migration lock' => '#^data/\.migration_lock$#',
            'editor screenshot' => '#^editor\.png$#',
            'user ini' => '#^\.user\.ini$#',
            'uploaded files' => '#^uploads/(?!\.htaccess)#',
        ];
        foreach ($forbidden as $label => $pattern) {
            $t->assert('Package excludes ' . $label, !preg_grep($pattern, $entries));
        }

        $zip = new ZipArchive();
        if ($zip->open($output) === true) {
            $t->assertEquals('Packaged VERSION matches repository', $version, trim((string)$zip->getFromName('VERSION')));
            $sample = json_decode((string)$zip->getFromName('config-sample.json'), true);
            $t->assertEquals('Packaged config-sample matches VERSION', $version, $sample['version'] ?? null);
            $zip->close();
        }
    } finally {
        @unlink($output);
        @unlink($output . '.sha256');
    }

    return $t;
}

/**
 * Extract the package into a fresh directory and run the CLI installer path.
 * This is the "clean installation from the distributed ZIP" check: only files
 * that are actually shipped are used.
 */
function test_release_package_clean_install(): Test
{
    $t = new Test('Release package - clean install from the ZIP');

    $output = sys_get_temp_dir() . '/bb_release_install_' . uniqid('', true) . '.zip';
    $result = release_package_build($output);
    if ($result['skipped']) {
        $t->assert('Skipped: ZipArchive/proc_open not available', true);
        return $t;
    }

    $target = sys_get_temp_dir() . '/bb_release_root_' . uniqid('', true);
    @mkdir($target, 0755, true);

    try {
        $t->assertEquals('Builder exited successfully', 0, $result['exit']);

        $zip = new ZipArchive();
        if ($zip->open($output) !== true) {
            $t->assert('Package can be opened', false);
            return $t;
        }
        $extracted = $zip->extractTo($target);
        $zip->close();
        $t->assertTrue('Package extracted', $extracted === true);

        // Every shipped PHP file must at least compile.
        $broken = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        $phpFiles = 0;
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $phpFiles++;
            $lint = @proc_open(
                escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()),
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (is_resource($lint)) {
                stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($lint);
                if ($code !== 0) {
                    $broken[] = basename($file->getPathname()) . ': ' . trim($err);
                }
            }
        }
        $t->assert('Package contains PHP files', $phpFiles > 50);
        $t->assert('All shipped PHP files compile', $broken === []);
        if ($broken) {
            $t->assert('lint errors: ' . implode(' | ', array_slice($broken, 0, 3)), false);
        }

        $dbPath = $target . '/data/database.sqlite';
        file_put_contents($target . '/config.json', json_encode([
            'db_driver' => 'sqlite',
            'db_path' => $dbPath,
            'site_name' => 'Package test',
            'base_url' => 'http://localhost',
            'default_lang' => 'en',
            'available_langs' => ['en'],
        ], JSON_PRETTY_PRINT));

        $proc = @proc_open(
            escapeshellarg(PHP_BINARY) . ' bb.php migrate',
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $target
        );
        if (!is_resource($proc)) {
            $t->assert('Skipped: cannot run the packaged CLI', true);
            return $t;
        }
        $migrateOut = stream_get_contents($pipes[1]);
        $migrateErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $migrateExit = proc_close($proc);

        $t->assertEquals('Packaged CLI ran the migrations', 0, $migrateExit);
        if ($migrateExit !== 0) {
            $t->assert('migrate stderr: ' . trim($migrateErr) . ' stdout: ' . trim($migrateOut), false);
        }
        $t->assertTrue('Database created by the package', is_file($dbPath));

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['users', 'threads', 'posts', 'roles', 'migrations'] as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
            $t->assertEquals("Table {$table} created", 1, (int)$stmt->fetchColumn());
        }
        $pdo = null;
    } finally {
        @unlink($output);
        @unlink($output . '.sha256');
        // Delete only files/directories, never follow symlinks.
        if (is_dir($target)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
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
            @rmdir($target);
        }
    }

    return $t;
}

function test_release_builder_refuses_dirty_working_tree(): Test
{
    $t = new Test('Release package - dirty working tree is refused');

    if (!class_exists('ZipArchive') || !function_exists('proc_open') || !is_dir(dirname(__DIR__) . '/.git')) {
        $t->assert('Skipped: no git checkout or missing tooling', true);
        return $t;
    }

    $root = dirname(__DIR__);
    $status = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>/dev/null'));
    if ($status === '') {
        $t->assert('Skipped: working tree is clean', true);
        return $t;
    }

    $output = sys_get_temp_dir() . '/bb_release_dirty_' . uniqid('', true) . '.zip';
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-release.php')
            . ' --output=' . escapeshellarg($output);
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $root);
        if (!is_resource($proc)) {
            $t->assert('Skipped: cannot spawn the builder', true);
            return $t;
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $t->assertEquals('Builder fails on a dirty tree', 1, $exit);
        $t->assert('Error mentions uncommitted changes', str_contains($stderr, 'uncommitted changes'));
        $t->assert('Error suggests --allow-dirty', str_contains($stderr, '--allow-dirty'));
        $t->assertFalse('No archive written when refusing', is_file($output));
    } finally {
        @unlink($output);
        @unlink($output . '.sha256');
    }

    return $t;
}

register_tests(
    'test_release_package_contents',
    'test_release_package_clean_install',
    'test_release_builder_refuses_dirty_working_tree'
);

