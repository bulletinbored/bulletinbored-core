<?php

/**
 * ContentConcurrencyTest — two writes at the same time must both succeed.
 *
 * The reply flow does INSERT INTO posts followed by UPDATE threads, i.e. two
 * writers touch the same row. On SQLite that is where "database is locked"
 * (SQLITE_BUSY) appears, and without a busy timeout the losing request fails
 * with a 500 instead of waiting. src/setup.php configures the pragma through
 * BbPdo::configureSqlite(); this test runs real processes against a file
 * database to prove it holds.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/BbPdo.php';

function concurrency_db(string $path): BbPdo
{
    $pdo = new BbPdo('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    BbPdo::configureSqlite($pdo);
    return $pdo;
}

function test_sqlite_pragmas_are_applied(): Test
{
    $t = new Test('Concurrency - SQLite pragmas');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $driverDefault = (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn();

    BbPdo::configureSqlite($pdo);
    $t->assertEquals('foreign keys enabled', 1, (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn());
    $t->assertEquals(
        'The driver busy_timeout is not lowered by default',
        $driverDefault,
        (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn()
    );

    BbPdo::configureSqlite($pdo, ['sqlite_busy_timeout' => 1234]);
    $t->assertEquals('busy_timeout is configurable', 1234, (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn());

    BbPdo::configureSqlite($pdo, ['sqlite_busy_timeout' => 0]);
    $t->assertEquals('0 keeps the driver default', 1234, (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn());

    return $t;
}

/**
 * Fire $processes writers, each inserting $perProcess replies into thread 1.
 * @return array{ok: int, failed: int, errors: string[], started: int}
 */
function concurrency_run_writers(string $dbPath, int $processes, int $perProcess): array
{
    $script = <<<'SCRIPT'
<?php
$db = $argv[1];
$tag = $argv[2];
$count = (int)$argv[3];
require $argv[4] . '/lib/BbPdo.php';
$pdo = new BbPdo('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
BbPdo::configureSqlite($pdo);
try {
    for ($i = 0; $i < $count; $i++) {
        $pdo->prepare('INSERT INTO posts (thread_id, user_id, content, created_at) VALUES (?, ?, ?, ?)')
            ->execute([1, 2, $tag . '-' . $i, date('Y-m-d H:i:s')]);
        // The write that serialises on the thread row.
        $pdo->prepare('UPDATE threads SET updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), 1]);
    }
    echo 'ok';
} catch (Throwable $e) {
    echo 'ERR:' . $e->getMessage();
}
SCRIPT;

    $tmp = tempnam(sys_get_temp_dir(), 'bb_conc_');
    $tmpFile = $tmp . '.php';
    @rename($tmp, $tmpFile);
    file_put_contents($tmpFile, $script);

    $procs = [];
    for ($i = 0; $i < $processes; $i++) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpFile)
            . ' ' . escapeshellarg($dbPath)
            . ' ' . escapeshellarg('w' . $i)
            . ' ' . $perProcess
            . ' ' . escapeshellarg(dirname(__DIR__));
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($proc)) {
            $procs[] = [$proc, $pipes];
        }
    }

    $ok = 0;
    $failed = 0;
    $errors = [];
    foreach ($procs as [$proc, $pipes]) {
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if (str_contains($out, 'ok')) {
            $ok++;
        } else {
            $failed++;
            $errors[] = trim($out . ' ' . $err);
        }
    }
    @unlink($tmpFile);

    return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'started' => count($procs)];
}

function test_concurrent_replies_are_not_lost(): Test
{
    $t = new Test('Concurrency - simultaneous replies are all stored');

    if (!function_exists('proc_open')) {
        $t->assert('Skipped: proc_open not available', true);
        return $t;
    }

    $dbPath = sys_get_temp_dir() . '/bb_concurrency_' . uniqid('', true) . '.sqlite';
    $pdo = concurrency_db($dbPath);
    $pdo->exec('CREATE TABLE threads (id INTEGER PRIMARY KEY, title TEXT, updated_at DATETIME)');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER, user_id INTEGER, content TEXT, created_at DATETIME)');
    $pdo->exec("INSERT INTO threads (id, title, updated_at) VALUES (1, 'Busy thread', '2000-01-01 00:00:00')");

    $processes = 6;
    $perProcess = 3;

    try {
        $result = concurrency_run_writers($dbPath, $processes, $perProcess);

        if ($result['started'] < $processes) {
            $t->assert('Skipped: could not start every writer', true);
            return $t;
        }

        $t->assertEquals('Every writer finished without a database error', 0, $result['failed']);
        if ($result['errors']) {
            $t->assert('errors: ' . implode(' | ', array_slice($result['errors'], 0, 2)), false);
        }

        $total = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
        $t->assertEquals('Every reply was stored', $processes * $perProcess, $total);

        $distinct = (int)$pdo->query('SELECT COUNT(DISTINCT content) FROM posts')->fetchColumn();
        $t->assertEquals('No duplicated reply', $processes * $perProcess, $distinct);

        $updated = (string)$pdo->query('SELECT updated_at FROM threads WHERE id = 1')->fetchColumn();
        $t->assertNotEquals('Thread row was updated', '2000-01-01 00:00:00', $updated);
    } finally {
        $pdo = null;
        @unlink($dbPath);
    }

    return $t;
}

register_tests(
    'test_sqlite_pragmas_are_applied',
    'test_concurrent_replies_are_not_lost'
);
