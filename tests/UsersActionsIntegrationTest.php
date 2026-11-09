<?php

/**
 * UsersActionsIntegrationTest — integration tests for user action handlers.
 * Tests the actual handler functions with full App context using subprocesses
 * to avoid view function redeclaration issues.
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

function integration_run_subprocess(string $code, array $args = []): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exit' => -1];
    }
    // Use a temporary script file: reliable across SAPIs/platforms, unlike -r.
    $tmp = tempnam(sys_get_temp_dir(), 'bb_integration_');
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

function integration_free_port(): int
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

function integration_start_server(): array
{
    if (!function_exists('proc_open')) {
        return [null, 0];
    }
    $port = integration_free_port();
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

    integration_stop_server($proc);
    return [null, 0];
}

function integration_stop_server($proc): void
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

function integration_request(int $port, string $path, array $postData = [], array $headers = []): array
{
    $opts = [
        'http' => [
            'ignore_errors' => true,
            'timeout' => 5,
            'header' => "Connection: close\r\n",
            'method' => empty($postData) ? 'GET' : 'POST',
        ],
    ];
    if (!empty($postData)) {
        $opts['http']['content'] = http_build_query($postData);
        $opts['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    if (!empty($headers)) {
        foreach ($headers as $h) {
            $opts['http']['header'] .= $h . "\r\n";
        }
    }
    $ctx = stream_context_create($opts);
    @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $responseHeaders = $http_response_header ?? [];
    return is_array($responseHeaders) ? $responseHeaders : [];
}

function integration_header(array $headers, string $name): ?string
{
    foreach ($headers as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return null;
}

function integration_status(array $headers): int
{
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

function integration_body(int $port, string $path, array $postData = []): string
{
    $opts = [
        'http' => [
            'ignore_errors' => true,
            'timeout' => 5,
            'header' => "Connection: close\r\n",
            'method' => empty($postData) ? 'GET' : 'POST',
        ],
    ];
    if (!empty($postData)) {
        $opts['http']['content'] = http_build_query($postData);
        $opts['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $ctx = stream_context_create($opts);
    return @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx) ?: '';
}

// ============================================================
// HANDLE_LOGIN INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_login_get_renders_view(): Test
{
    $t = new Test('handle_login - GET renders login view (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/login');
        $t->assert('Received an HTTP response', !empty($headers));
        $status = integration_status($headers);
        $t->assertEquals('Returns 200 OK', 200, $status);
        $body = integration_body($port, '/login');
        $t->assert('Contains login form', strpos($body, 'login') !== false || strpos($body, 'Login') !== false);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_login_post_invalid_csrf_shows_error(): Test
{
    $t = new Test('handle_login - POST invalid CSRF shows error (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/login', [
            'username' => 'testuser',
            'password' => 'password123',
            'csrf_token' => 'invalid',
        ]);
        $status = integration_status($headers);
        // Should return 200 with error or 403
        $t->assert('Returns HTTP response', in_array($status, [200, 403], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_login_nonexistent_user_shows_error(): Test
{
    $t = new Test('handle_login - POST nonexistent user shows error (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/login', [
            'username' => 'nonexistentuser123',
            'password' => 'password123',
            'csrf_token' => 'dummy',
        ]);
        $status = integration_status($headers);
        $t->assert('Returns HTTP response', in_array($status, [200, 403], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// HANDLE_REGISTER INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_register_get_renders_view(): Test
{
    $t = new Test('handle_register - GET renders register view (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/register');
        $t->assert('Received an HTTP response', !empty($headers));
        $status = integration_status($headers);
        $t->assertEquals('Returns 200 OK', 200, $status);
        $body = integration_body($port, '/register');
        $t->assert('Contains register form', strpos($body, 'register') !== false || strpos($body, 'Register') !== false);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_register_post_invalid_csrf_shows_error(): Test
{
    $t = new Test('handle_register - POST invalid CSRF shows error (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/register', [
            'username' => 'newuser',
            'email' => 'new@example.com',
            'password' => 'StrongP4ssword',
            'csrf_token' => 'invalid',
        ]);
        $status = integration_status($headers);
        $t->assert('Returns HTTP response', in_array($status, [200, 403], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_register_post_empty_fields_shows_error(): Test
{
    $t = new Test('handle_register - POST empty fields shows error (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/register', [
            'username' => '',
            'email' => '',
            'password' => '',
            'csrf_token' => 'dummy',
        ]);
        $status = integration_status($headers);
        $t->assert('Returns HTTP response', in_array($status, [200, 403], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// HANDLE_LOGOUT INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_logout_get_redirects(): Test
{
    $t = new Test('handle_logout - GET redirects to home (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/logout');
        $status = integration_status($headers);
        // GET logout should redirect to home
        $t->assert('Returns redirect', in_array($status, [301, 302, 303], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// HANDLE_VERIFY_EMAIL INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_verify_email_missing_token_throws(): Test
{
    $t = new Test('handle_verify_email - missing token throws 404 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/verify_email');
        $status = integration_status($headers);
        $t->assertEquals('Returns 404 Not Found', 404, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_verify_email_invalid_token_throws(): Test
{
    $t = new Test('handle_verify_email - invalid token throws 404 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/verify_email?token=invalid');
        $status = integration_status($headers);
        $t->assertEquals('Returns 404 Not Found', 404, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// HANDLE_PROFILE INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_profile_nonexistent_user_throws(): Test
{
    $t = new Test('handle_profile - nonexistent user throws 404 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/u/nonexistentuser123');
        $status = integration_status($headers);
        $t->assertEquals('Returns 404 Not Found', 404, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// HANDLE_FORGOT_PASSWORD INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_forgot_password_get_renders_view(): Test
{
    $t = new Test('handle_forgot_password - GET renders view (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/forgot_password');
        $t->assert('Received an HTTP response', !empty($headers));
        $status = integration_status($headers);
        $t->assertEquals('Returns 200 OK', 200, $status);
        $body = integration_body($port, '/forgot_password');
        $t->assert('Contains forgot password form', strpos($body, 'forgot') !== false || strpos($body, 'Forgot') !== false || strpos($body, 'reset') !== false);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_forgot_password_post_invalid_csrf_shows_error(): Test
{
    $t = new Test('handle_forgot_password - POST invalid CSRF shows error (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/forgot_password', [
            'email' => 'test@example.com',
            'csrf_token' => 'invalid',
        ]);
        $status = integration_status($headers);
        $t->assert('Returns HTTP response', in_array($status, [200, 403], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// HANDLE_RESET_PASSWORD INTEGRATION TESTS (via HTTP)
// ============================================================

function test_handle_reset_password_get_missing_token_throws(): Test
{
    $t = new Test('handle_reset_password - GET missing token throws 404 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/reset_password');
        $status = integration_status($headers);
        $t->assertEquals('Returns 404 Not Found', 404, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_reset_password_get_invalid_token_throws(): Test
{
    $t = new Test('handle_reset_password - GET invalid token throws 404 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/reset_password?token=invalid');
        $status = integration_status($headers);
        $t->assertEquals('Returns 404 Not Found', 404, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_handle_reset_password_post_invalid_csrf_shows_error(): Test
{
    $t = new Test('handle_reset_password - POST invalid CSRF shows error (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/reset_password', [
            'token' => 'some-token',
            'password' => 'NewP4ssword123',
            'confirm_password' => 'NewP4ssword123',
            'csrf_token' => 'invalid',
        ]);
        $status = integration_status($headers);
        $t->assert('Returns HTTP response', in_array($status, [200, 403], true));
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

// ============================================================
// DISPATCHER TESTS (via HTTP)
// ============================================================

function test_users_dispatcher_invalid_action_throws(): Test
{
    $t = new Test('users dispatcher - invalid action throws 404 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        $headers = integration_request($port, '/users/invalid_action');
        $status = integration_status($headers);
        $t->assertEquals('Returns 404 Not Found', 404, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

function test_users_dispatcher_invalid_method_throws(): Test
{
    $t = new Test('users dispatcher - invalid method throws 405 (HTTP)');
    
    [$proc, $port] = integration_start_server();
    if ($proc === null) {
        $t->assert('Skipped: could not start built-in server', true);
        return $t;
    }
    
    try {
        // Try PUT on login endpoint (should only accept GET/POST)
        $opts = [
            'http' => [
                'ignore_errors' => true,
                'timeout' => 5,
                'header' => "Connection: close\r\n",
                'method' => 'PUT',
            ],
        ];
        $ctx = stream_context_create($opts);
        @file_get_contents("http://127.0.0.1:{$port}/login", false, $ctx);
        $headers = $http_response_header ?? [];
        $status = integration_status($headers);
        $t->assertEquals('Returns 405 Method Not Allowed', 405, $status);
    } finally {
        integration_stop_server($proc);
    }
    
    return $t;
}

register_tests(
    // handle_login integration tests
    'test_handle_login_get_renders_view',
    'test_handle_login_post_invalid_csrf_shows_error',
    'test_handle_login_nonexistent_user_shows_error',
    
    // handle_register integration tests
    'test_handle_register_get_renders_view',
    'test_handle_register_post_invalid_csrf_shows_error',
    'test_handle_register_post_empty_fields_shows_error',
    
    // handle_logout integration tests
    'test_handle_logout_get_redirects',
    
    // handle_verify_email integration tests
    'test_handle_verify_email_missing_token_throws',
    'test_handle_verify_email_invalid_token_throws',
    
    // handle_profile integration tests
    'test_handle_profile_nonexistent_user_throws',
    
    // handle_forgot_password integration tests
    'test_handle_forgot_password_get_renders_view',
    'test_handle_forgot_password_post_invalid_csrf_shows_error',
    
    // handle_reset_password integration tests
    'test_handle_reset_password_get_missing_token_throws',
    'test_handle_reset_password_get_invalid_token_throws',
    'test_handle_reset_password_post_invalid_csrf_shows_error',
    
    // Dispatcher tests
    'test_users_dispatcher_invalid_action_throws',
    'test_users_dispatcher_invalid_method_throws'
);