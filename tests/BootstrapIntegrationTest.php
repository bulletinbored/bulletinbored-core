<?php

/**
 * Bootstrap integration tests.
 *
 * The CLI suite runs with BULLETIN_TEST_MODE defined, so src/bootstrap.php
 * returns early (no session, no security headers, no install check, no HTTPS
 * redirect). These tests exercise the *real* bootstrap path:
 *
 *  - a subprocess smoke test that boots src/bootstrap.php without the test-mode
 *    early return and verifies session startup, the UTC timezone and the
 *    autoloader/i18n wiring (always runs);
 *  - optional HTTP tests that boot the front controller through the PHP
 *    built-in server and inspect real response headers (run automatically on
 *    non-Windows, or when BB_HTTP_TESTS=1 is set).
 */

require_once __DIR__ . '/harness.php';

// Track built-in server PIDs so a fatal error / timeout in the test runner
// cannot leave orphaned `php -S` processes behind.
$GLOBALS['__bb_http_pids'] = [];
register_shutdown_function(function () {
    foreach ($GLOBALS['__bb_http_pids'] ?? [] as $pid) {
        $pid = (int)$pid;
        if ($pid <= 0) {
            continue;
        }
        if (stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>NUL');
        } else {
            @exec('kill -9 ' . $pid . ' 2>/dev/null');
        }
    }
});

function bootstrap_integration_run_subprocess(string $code, array $args = []): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exit' => -1];
    }
    // Use a temporary script file: reliable across SAPIs/platforms, unlike -r.
    $tmp = tempnam(sys_get_temp_dir(), 'bb_boot_');
    if ($tmp === false) {
        return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exit' => -1];
    }
    $tmpFile = $tmp . '.php';
    @rename($tmp, $tmpFile);
    file_put_contents($tmpFile, "<?php\n" . $code . "\n");

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpFile);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        @unlink($tmpFile);
        return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exit' => -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    @unlink($tmpFile);
    return ['ok' => true, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr, 'exit' => (int)$exit];
}

function test_bootstrap_non_test_mode_runs(): Test
{
    $t = new Test('Bootstrap - non-test mode boots (subprocess)');

    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = 'install.php'; // avoid the installer redirect without config.json
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
chdir($root);
require $root . '/src/bootstrap.php';
echo json_encode([
    'session'    => session_status(),
    'tz'         => date_default_timezone_get(),
    'has_t'      => function_exists('t'),
    'has_router' => class_exists('Bulletin\\Router'),
    'config'     => is_array(App::getInstance()->config),
]);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assert('Bootstrap produced JSON result', is_array($decoded));
    if (is_array($decoded)) {
        $t->assertEquals('Session started', PHP_SESSION_ACTIVE, $decoded['session'] ?? null);
        $t->assertEquals('Timezone pinned to UTC', 'UTC', $decoded['tz'] ?? null);
        $t->assertTrue('t() defined', !empty($decoded['has_t']));
        $t->assertTrue('PSR-4 autoloader registered (Router)', !empty($decoded['has_router']));
        $t->assertTrue('Config loaded into App', !empty($decoded['config']));
    } else {
        // Surface the failure reason for debugging.
        $t->assert('stderr: ' . trim($result['stderr']), false);
    }

    return $t;
}

function bootstrap_integration_http_enabled(): bool
{
    $flag = getenv('BB_HTTP_TESTS');
    if ($flag === '1') {
        return true;
    }
    if ($flag === '0') {
        return false;
    }
    // Default: enabled on non-Windows (reliable process termination), opt-in on Windows.
    return stripos(PHP_OS, 'WIN') !== 0;
}

function bootstrap_integration_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return 0;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if ($name === false || strpos($name, ':') === false) {
        return 0;
    }
    return (int)substr($name, strrpos($name, ':') + 1);
}

function bootstrap_integration_start_server(): array
{
    if (!function_exists('proc_open')) {
        return [null, 0];
    }
    $port = bootstrap_integration_free_port();
    if ($port === 0) {
        return [null, 0];
    }

    $root = dirname(__DIR__);
    $null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg('router.php');
    $descriptors = [
        0 => ['file', $null, 'r'],
        1 => ['file', $null, 'w'],
        2 => ['file', $null, 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, $root);
    if (!is_resource($proc)) {
        return [null, 0];
    }
    $status = @proc_get_status($proc);
    if (is_array($status) && !empty($status['pid'])) {
        $GLOBALS['__bb_http_pids'][] = (int)$status['pid'];
    }

    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($conn)) {
            fclose($conn);
            return [$proc, $port];
        }
        usleep(100000);
    }

    bootstrap_integration_stop_server($proc);
    return [null, 0];
}

function bootstrap_integration_stop_server($proc): void
{
    if (!is_resource($proc)) {
        return;
    }
    $status = @proc_get_status($proc);
    $pid = (is_array($status) && !empty($status['pid'])) ? (int)$status['pid'] : 0;

    // Prefer taskkill/kill by PID: on Windows proc_terminate can leave the
    // built-in server running, which would leak the port and block proc_close.
    if ($pid > 0) {
        if (stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>NUL');
        } else {
            @exec('kill -9 ' . $pid . ' 2>/dev/null');
        }
        $GLOBALS['__bb_http_pids'] = array_values(array_diff($GLOBALS['__bb_http_pids'] ?? [], [$pid]));
    }
    @proc_terminate($proc, 9);
    for ($i = 0; $i < 15; $i++) {
        $s = @proc_get_status($proc);
        if (!is_array($s) || empty($s['running'])) {
            break;
        }
        usleep(100000);
    }
    @proc_close($proc);
}

function bootstrap_integration_request(int $port, string $path): array
{
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => "Connection: close\r\n"]]);
    @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $headers = $http_response_header ?? [];
    return is_array($headers) ? $headers : [];
}

function bootstrap_integration_header(array $headers, string $name): ?string
{
    foreach ($headers as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return null;
}

function bootstrap_integration_status(array $headers): int
{
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

function test_bootstrap_sends_security_headers(): Test
{
    $t = new Test('Bootstrap - security headers (integration)');

    if (!bootstrap_integration_http_enabled()) {
        $t->assert('Skipped (set BB_HTTP_TESTS=1 to enable HTTP tests)', true);
        return $t;
    }

    [$proc, $port] = bootstrap_integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }

    try {
        $headers = bootstrap_integration_request($port, '/');
        $t->assert('Received an HTTP response', !empty($headers));
        $t->assertEquals('X-Content-Type-Options', 'nosniff', bootstrap_integration_header($headers, 'X-Content-Type-Options'));
        $t->assertNotNull('Permissions-Policy header present', bootstrap_integration_header($headers, 'Permissions-Policy'));
        $csp = bootstrap_integration_header($headers, 'Content-Security-Policy');
        $t->assertNotNull('Content-Security-Policy header present', $csp);
        $t->assert('CSP carries a per-request nonce', $csp !== null && str_contains($csp, "'nonce-"));
        $t->assert('CSP forbids object-src', $csp !== null && str_contains($csp, "object-src 'none'"));
    } finally {
        bootstrap_integration_stop_server($proc);
    }

    return $t;
}

function test_bootstrap_blocks_sensitive_files_over_http(): Test
{
    $t = new Test('Bootstrap - sensitive files blocked (integration)');

    if (!bootstrap_integration_http_enabled()) {
        $t->assert('Skipped (set BB_HTTP_TESTS=1 to enable HTTP tests)', true);
        return $t;
    }

    [$proc, $port] = bootstrap_integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }

    try {
        foreach (['/config.json', '/config.php', '/bb.php', '/router.php', '/data/plugins.json'] as $path) {
            $headers = bootstrap_integration_request($port, $path);
            $t->assertEquals("Denied: {$path}", 403, bootstrap_integration_status($headers));
        }
    } finally {
        bootstrap_integration_stop_server($proc);
    }

    return $t;
}

register_tests(
    'test_bootstrap_non_test_mode_runs',
    'test_bootstrap_sends_security_headers',
    'test_bootstrap_blocks_sensitive_files_over_http'
);
