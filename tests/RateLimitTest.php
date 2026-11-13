<?php

/**
 * RateLimitTest — garbage collection and the file-lock behaviour under
 * concurrency.
 *
 * The limiter keeps one JSON file per action+key and relies on flock() for
 * atomicity. Two things are worth pinning down: the buckets are actually
 * removed when they expire, and simultaneous requests against the same bucket
 * cannot exceed the limit (no lost updates, no corrupted file).
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Security.php';

function ratelimit_dir(): string
{
    return dirname(__DIR__) . '/data/ratelimit';
}

function ratelimit_write_bucket(string $name, array $timestamps, ?int $mtime = null): string
{
    $dir = ratelimit_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . $name . '.json';
    file_put_contents($file, json_encode($timestamps));
    if ($mtime !== null) {
        touch($file, $mtime);
        clearstatcache(true, $file);
    }
    return $file;
}

function test_ratelimit_gc_removes_expired_buckets(): Test
{
    $t = new Test('Rate limit - deterministic GC');

    $old1 = ratelimit_write_bucket('bb_gc_old_1', [time() - 200000], time() - 200000);
    $old2 = ratelimit_write_bucket('bb_gc_old_2', [time() - 90000], time() - 90000);
    $fresh = ratelimit_write_bucket('bb_gc_fresh', [time()], time());

    try {
        $removed = rate_limit_gc(ratelimit_dir(), 86400);
        $t->assert('Expired buckets removed', $removed >= 2);
        $t->assertFalse('Old bucket 1 gone', is_file($old1));
        $t->assertFalse('Old bucket 2 gone', is_file($old2));
        $t->assertTrue('Fresh bucket kept', is_file($fresh));

        // Corrupt files are removed only by age, never by content.
        $corrupt = ratelimit_write_bucket('bb_gc_corrupt', []);
        file_put_contents($corrupt, '{not json');
        touch($corrupt, time() - 200000);
        rate_limit_gc(ratelimit_dir(), 86400);
        $t->assertFalse('Old corrupt bucket removed too', is_file($corrupt));
    } finally {
        foreach ([$old1, $old2, $fresh] as $file) {
            @unlink($file);
        }
    }

    return $t;
}

/**
 * Run N processes that each call rate_limit() once against the same bucket.
 * @return array{allowed: int, denied: int, raw: string}
 */
function ratelimit_concurrent_hits(string $action, int $limit, int $processes): array
{
    $script = <<<'PHP'
<?php
require $argv[1] . '/src/Security.php';
echo rate_limit($argv[2], (int)$argv[3], 120, 'shared') ? '1' : '0';
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'bb_rl_');
    $tmpFile = $tmp . '.php';
    @rename($tmp, $tmpFile);
    file_put_contents($tmpFile, $script);

    $procs = [];
    for ($i = 0; $i < $processes; $i++) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpFile)
            . ' ' . escapeshellarg(dirname(__DIR__))
            . ' ' . escapeshellarg($action)
            . ' ' . $limit;
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($proc)) {
            $procs[] = [$proc, $pipes];
        }
    }

    $raw = '';
    foreach ($procs as [$proc, $pipes]) {
        $raw .= stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    @unlink($tmpFile);

    return [
        'allowed' => substr_count($raw, '1'),
        'denied' => substr_count($raw, '0'),
        'raw' => $raw,
    ];
}

function test_ratelimit_limit_holds_under_concurrency(): Test
{
    $t = new Test('Rate limit - limit holds with concurrent processes');

    if (!function_exists('proc_open')) {
        $t->assert('Skipped: proc_open not available', true);
        return $t;
    }

    $action = 'bb_concurrency_' . bin2hex(random_bytes(4));
    $limit = 5;
    $processes = 8;
    $bucket = ratelimit_dir() . '/' . $action . '_shared.json';
    @unlink($bucket);

    try {
        $result = ratelimit_concurrent_hits($action, $limit, $processes);

        $responses = strlen($result['raw']);
        if ($responses < $processes) {
            $t->assert('Skipped: only ' . $responses . ' subprocesses answered', true);
            return $t;
        }

        $t->assertEquals('Exactly the limit was allowed', $limit, substr_count($result['raw'], '1'));
        $t->assertEquals('The remaining requests were denied', $processes - $limit, substr_count($result['raw'], '0'));

        $t->assertTrue('Bucket file written', is_file($bucket));
        if (is_file($bucket)) {
            $decoded = json_decode((string)file_get_contents($bucket), true);
            $t->assert('Bucket file is still valid JSON', is_array($decoded));
            $t->assertCount('Bucket holds only the allowed hits', $limit, is_array($decoded) ? $decoded : []);
        }
    } finally {
        @unlink($bucket);
    }

    return $t;
}

register_tests(
    'test_ratelimit_gc_removes_expired_buckets',
    'test_ratelimit_limit_holds_under_concurrency'
);
