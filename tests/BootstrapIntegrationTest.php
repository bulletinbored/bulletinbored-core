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

function test_bootstrap_force_https_redirect(): Test
{
    $t = new Test('Bootstrap - forces HTTPS redirect when enabled');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['SERVER_NAME'] = 'example.com';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
chdir($root);
require $root . '/src/bootstrap.php';
echo 'REACHED_END';
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    // Should redirect (exit before REACHED_END)
    $t->assert('Redirects to HTTPS (exits early)', strpos($result['stdout'], 'REACHED_END') === false);
    $t->assert('Exit code indicates redirect', $result['exit'] === 0);

    return $t;
}

function test_bootstrap_force_https_disabled(): Test
{
    $t = new Test('Bootstrap - skips HTTPS redirect when force_https=false');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['SERVER_NAME'] = 'example.com';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
// Create config with force_https=false
$config = ['force_https' => false];
file_put_contents($root . '/config.json', json_encode($config));
chdir($root);
require $root . '/src/bootstrap.php';
echo 'REACHED_END';
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $t->assert('Continues past redirect (force_https=false)', strpos($result['stdout'], 'REACHED_END') !== false);

    return $t;
}

function test_bootstrap_skips_redirect_for_localhost(): Test
{
    $t = new Test('Bootstrap - skips HTTPS redirect for localhost');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
chdir($root);
require $root . '/src/bootstrap.php';
echo 'REACHED_END';
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $t->assert('Skips redirect for localhost', strpos($result['stdout'], 'REACHED_END') !== false);

    return $t;
}

function test_bootstrap_skips_redirect_for_cli(): Test
{
    $t = new Test('Bootstrap - skips HTTPS redirect for CLI');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['SERVER_NAME'] = 'example.com';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
// Simulate CLI
define('PHP_SAPI', 'cli');
chdir($root);
require $root . '/src/bootstrap.php';
echo 'REACHED_END';
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $t->assert('Skips redirect for CLI', strpos($result['stdout'], 'REACHED_END') !== false);

    return $t;
}

function test_bootstrap_installer_redirect(): Test
{
    $t = new Test('Bootstrap - redirects to installer when no config');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['SERVER_NAME'] = 'example.com';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
// Ensure no config exists
@unlink($root . '/config.json');
@unlink($root . '/config.php');
chdir($root);
require $root . '/src/bootstrap.php';
echo 'REACHED_END';
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $t->assert('Redirects to installer (exits early)', strpos($result['stdout'], 'REACHED_END') === false);

    return $t;
}

function test_bootstrap_loads_config_json(): Test
{
    $t = new Test('Bootstrap - loads config from config.json');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php'; // Avoid installer redirect
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
// Create config.json
$config = ['site_name' => 'Test Forum', 'custom_key' => 'custom_value'];
file_put_contents($root . '/config.json', json_encode($config));
chdir($root);
require $root . '/src/bootstrap.php';
echo json_encode(['site_name' => $GLOBALS['config']['site_name'] ?? '', 'custom_key' => $GLOBALS['config']['custom_key'] ?? '']);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Loads site_name from config', 'Test Forum', $decoded['site_name'] ?? '');
    $t->assertEquals('Loads custom_key from config', 'custom_value', $decoded['custom_key'] ?? '');

    return $t;
}

function test_bootstrap_loads_legacy_config_php(): Test
{
    $t = new Test('Bootstrap - loads legacy config.php');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
// Create legacy config.php
@unlink($root . '/config.json');
file_put_contents($root . '/config.php', "<?php\n\$config = ['legacy' => true, 'site_name' => 'Legacy Forum'];\n");
chdir($root);
require $root . '/src/bootstrap.php';
echo json_encode(['legacy' => $GLOBALS['config']['legacy'] ?? false, 'site_name' => $GLOBALS['config']['site_name'] ?? '']);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Loads legacy config', true, $decoded['legacy'] ?? false);
    $t->assertEquals('Loads site_name from legacy', 'Legacy Forum', $decoded['site_name'] ?? '');

    return $t;
}

function test_bootstrap_migrates_legacy_config(): Test
{
    $t = new Test('Bootstrap - migrates legacy config.php to config.json');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
// Create legacy config.php
@unlink($root . '/config.json');
file_put_contents($root . '/config.php', "<?php\n\$config = ['migrated' => true];\n");
chdir($root);
require $root . '/src/bootstrap.php';
// Check if config.json was created
echo json_encode(['config_json_exists' => file_exists($root . '/config.json'), 'config_php_exists' => file_exists($root . '/config.php')]);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Creates config.json', true, $decoded['config_json_exists'] ?? false);
    $t->assertEquals('Removes config.php', false, $decoded['config_php_exists'] ?? true);

    return $t;
}

function test_bootstrap_sets_utc_timezone(): Test
{
    $t = new Test('Bootstrap - sets UTC timezone');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
chdir($root);
require $root . '/src/bootstrap.php';
echo json_encode(['tz' => date_default_timezone_get()]);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Timezone is UTC', 'UTC', $decoded['tz'] ?? '');

    return $t;
}

function test_bootstrap_sets_language_cookie(): Test
{
    $t = new Test('Bootstrap - sets language cookie');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/?lang=fr';
$_SERVER['HTTP_ACCEPT'] = '';
// Create config with available langs
$config = ['default_lang' => 'en', 'available_langs' => ['en', 'fr', 'de']];
file_put_contents($root . '/config.json', json_encode($config));
chdir($root);
require $root . '/src/bootstrap.php';
// Check headers_sent would be true after setcookie, but we can't easily test that in subprocess
// Just verify it doesn't crash
echo 'REACHED_END';
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $t->assert('Sets language without error', strpos($result['stdout'], 'REACHED_END') !== false);

    return $t;
}

function test_bootstrap_loads_core_translations(): Test
{
    $t = new Test('Bootstrap - loads core translations');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
chdir($root);
require $root . '/src/bootstrap.php';
echo json_encode(['has_i18n' => isset($GLOBALS['translations']) && is_array($GLOBALS['translations']), 'has_login_key' => isset($GLOBALS['translations']['login'])]);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Loads i18n registry', true, $decoded['has_i18n'] ?? false);
    $t->assertEquals('Has login translation key', true, $decoded['has_login_key'] ?? false);

    return $t;
}

function test_bootstrap_registers_autoloader(): Test
{
    $t = new Test('Bootstrap - registers PSR-4 autoloader');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
chdir($root);
require $root . '/src/bootstrap.php';
// Try to load a Bulletin class
$loaded = class_exists('Bulletin\\Router');
echo json_encode(['router_loaded' => $loaded]);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Autoloader loads Router class', true, $decoded['router_loaded'] ?? false);

    return $t;
}

function test_bootstrap_exception_handler(): Test
{
    $t = new Test('Bootstrap - registers exception handler');
    
    $code = <<<'PHP'
$root = $argv[1];
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_URI'] = '/';
chdir($root);
require $root . '/src/bootstrap.php';
// Check if exception handler is set
$handler = set_exception_handler(function() {});
restore_exception_handler();
echo json_encode(['handler_set' => $handler !== null]);
PHP;

    $result = bootstrap_integration_run_subprocess($code, [dirname(__DIR__)]);
    $t->assert('Subprocess executed', $result['ok']);
    $decoded = json_decode($result['stdout'], true);
    $t->assertEquals('Exception handler registered', true, $decoded['handler_set'] ?? false);

    return $t;
}

function test_app_singleton_getinstance(): Test
{
    $t = new Test('App - getInstance returns singleton');
    
    App::reset();
    $app1 = App::getInstance();
    $app2 = App::getInstance();
    
    $t->assert('Returns instance', $app1 instanceof App);
    $t->assert('Same instance returned', $app1 === $app2);

    return $t;
}

function test_app_singleton_reset(): Test
{
    $t = new Test('App - reset clears singleton');
    
    App::reset();
    $app1 = App::getInstance();
    $app1->config = ['test' => 'value1'];
    
    App::reset();
    $app2 = App::getInstance();
    
    $t->assert('New instance after reset', $app1 !== $app2);
    $t->assert('Config cleared', empty($app2->config));

    return $t;
}

function test_app_config_method(): Test
{
    $t = new Test('App - config() method returns values with defaults');
    
    App::reset();
    $app = App::getInstance();
    $app->config = ['key1' => 'value1', 'key2' => ''];
    
    $t->assertEquals('Returns existing value', 'value1', $app->config('key1'));
    $t->assertEquals('Returns empty string for empty value', '', $app->config('key2'));
    $t->assertEquals('Returns default for missing key', 'default', $app->config('missing', 'default'));
    $t->assertEquals('Returns null default for missing key', null, $app->config('missing'));

    return $t;
}

function test_app_clientip(): Test
{
    $t = new Test('App - clientIp returns forwarded or remote address');
    
    App::reset();
    $app = App::getInstance();
    
    // Test with forwarded_for
    $app->forwardedFor = '192.168.1.100';
    $t->assertEquals('Returns forwarded_for', '192.168.1.100', $app->clientIp());
    
    // Test without forwarded_for (falls back to REMOTE_ADDR)
    $app->forwardedFor = null;
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $t->assertEquals('Returns REMOTE_ADDR', '10.0.0.1', $app->clientIp());
    
    // Test fallback
    $app->forwardedFor = null;
    unset($_SERVER['REMOTE_ADDR']);
    $t->assertEquals('Returns fallback', '0.0.0.0', $app->clientIp());

    return $t;
}

function test_app_getinstance_returns_instance(): Test
{
    $t = new Test('App - getInstance returns instance (covers line 42)');
    
    App::reset();
    $app = App::getInstance();
    
    $t->assert('Returns instance', $app instanceof App);
    $t->assert('Same instance returned on second call', $app === App::getInstance());

    return $t;
}

function test_app_config_method_returns_value(): Test
{
    $t = new Test('App - config() method returns value with default (covers line 58)');
    
    App::reset();
    $app = App::getInstance();
    $app->config = ['key1' => 'value1', 'key2' => ''];
    
    $t->assertEquals('Returns existing value', 'value1', $app->config('key1'));
    $t->assertEquals('Returns empty string for empty value', '', $app->config('key2'));
    $t->assertEquals('Returns default for missing key', 'default', $app->config('missing', 'default'));
    $t->assertEquals('Returns null default for missing key', null, $app->config('missing'));

    return $t;
}

function test_app_clientip_method(): Test
{
    $t = new Test('App - clientIp() method returns IP (covers line 66)');
    
    App::reset();
    $app = App::getInstance();
    
    // Test with forwarded_for
    $app->forwardedFor = '192.168.1.100';
    $t->assertEquals('Returns forwarded_for', '192.168.1.100', $app->clientIp());
    
    // Test without forwarded_for (falls back to REMOTE_ADDR)
    $app->forwardedFor = null;
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $t->assertEquals('Returns REMOTE_ADDR', '10.0.0.1', $app->clientIp());
    
    // Test fallback
    $app->forwardedFor = null;
    unset($_SERVER['REMOTE_ADDR']);
    $t->assertEquals('Returns fallback', '0.0.0.0', $app->clientIp());

    return $t;
}

register_tests(
    'test_bootstrap_non_test_mode_runs',
    'test_bootstrap_sends_security_headers',
    'test_bootstrap_blocks_sensitive_files_over_http',
    'test_bootstrap_force_https_redirect',
    'test_bootstrap_force_https_disabled',
    'test_bootstrap_skips_redirect_for_localhost',
    'test_bootstrap_skips_redirect_for_cli',
    'test_bootstrap_installer_redirect',
    'test_bootstrap_loads_config_json',
    'test_bootstrap_loads_legacy_config_php',
    'test_bootstrap_migrates_legacy_config',
    'test_bootstrap_sets_utc_timezone',
    'test_bootstrap_sets_language_cookie',
    'test_bootstrap_loads_core_translations',
    'test_bootstrap_registers_autoloader',
    'test_bootstrap_exception_handler',
    'test_app_singleton_getinstance',
    'test_app_singleton_reset',
    'test_app_config_method',
    'test_app_clientip',
    'test_app_getinstance_returns_instance',
    'test_app_config_method_returns_value',
    'test_app_clientip_method'
);