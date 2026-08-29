<?php

/**
 * SecurityTest.php — tests for CSRF rotation, Request sanitization, and admin audit log.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Request.php';

use Bulletin\Request;

function test_csrf_rotation(): Test
{
    $t = new Test('CSRF Token Rotation');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $token1 = generate_csrf_token();
    $t->assert('Token is generated', strlen($token1) === 64);
    $t->assertTrue('Token validates before rotation', validate_csrf_token($token1));

    $result = csrf_validate_request();
    $t->assertFalse('csrf_validate_request fails without POST token', $result);

    $_POST['csrf_token'] = $token1;
    $result = csrf_validate_request();
    $t->assertTrue('csrf_validate_request succeeds with valid token', $result);

    $token2 = $_SESSION['csrf_token'] ?? '';
    $t->assert('Token rotated after validation', $token1 !== $token2);

    $t->assertFalse('Old token no longer validates', validate_csrf_token($token1));
    $t->assertTrue('New token validates', validate_csrf_token($token2));

    unset($_POST['csrf_token']);

    return $t;
}

function test_csrf_field(): Test
{
    $t = new Test('CSRF Field Helper');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $field = csrf_field();
    $t->assert('Field contains hidden input', str_contains($field, 'type="hidden"'));
    $t->assert('Field contains csrf_token name', str_contains($field, 'name="csrf_token"'));
    $t->assert('Field contains value', str_contains($field, 'value="'));

    return $t;
}

function test_request_sanitization(): Test
{
    $t = new Test('Request Sanitization');

    $_GET['test_str'] = '  hello world  ';
    $_GET['test_int'] = '42';
    $_GET['test_array'] = ['  a  ', '  b  '];

    $result = Request::get('test_str');
    $t->assertEquals('GET trims whitespace', 'hello world', $result);

    $result = Request::get('test_int', 0);
    $t->assertEquals('GET returns string (not cast)', '42', $result);

    $result = Request::get('missing', 'default');
    $t->assertEquals('GET returns default for missing', 'default', $result);

    $result = Request::get('test_array');
    $t->assertEquals('GET sanitizes array elements', ['a', 'b'], $result);

    unset($_GET['test_str'], $_GET['test_int'], $_GET['test_array']);

    $_POST['post_str'] = "  O'Reilly  ";
    $result = Request::post('post_str');
    $t->assertEquals('POST trims and stripslashes', "O'Reilly", $result);

    $result = Request::post('missing', 'fallback');
    $t->assertEquals('POST returns default for missing', 'fallback', $result);

    $t->assertTrue('has() finds POST key', Request::has('post_str'));
    $t->assertFalse('has() returns false for missing', Request::has('nonexistent'));

    $result = Request::raw('post_str');
    $t->assertEquals('raw() returns un-sanitized', "  O'Reilly  ", $result);

    unset($_POST['post_str']);

    return $t;
}

function test_request_input_priority(): Test
{
    $t = new Test('Request Input Priority');

    $_POST['key'] = 'post_value';
    $_GET['key'] = 'get_value';

    $result = Request::input('key');
    $t->assertEquals('input() prefers POST over GET', 'post_value', $result);

    unset($_POST['key']);
    $result = Request::input('key');
    $t->assertEquals('input() falls back to GET', 'get_value', $result);

    unset($_GET['key']);

    return $t;
}

function test_admin_audit_log(): Test
{
    $t = new Test('Admin Audit Log');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'testadmin';

    $logDir = __DIR__ . '/../data/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/security.log';
    $before = file_exists($logFile) ? filesize($logFile) : 0;

    log_admin_action('test_action', ['target' => 123]);

    $after = file_exists($logFile) ? filesize($logFile) : 0;
    $t->assert('Audit log entry written', $after > $before);

    $content = file_get_contents($logFile);
    $lines = array_filter(explode("\n", $content));
    $lastLine = end($lines);

    $t->assert('Log contains admin prefix', str_contains($lastLine, 'admin_test_action'));
    $t->assert('Log contains admin user', str_contains($lastLine, 'testadmin'));
    $t->assert('Log contains context', str_contains($lastLine, '"target":123'));

    unset($_SESSION['user_id'], $_SESSION['username']);

    return $t;
}

$suite = new TestSuite();
$suite->addTest(test_csrf_rotation());
$suite->addTest(test_csrf_field());
$suite->addTest(test_request_sanitization());
$suite->addTest(test_request_input_priority());
$suite->addTest(test_admin_audit_log());
$suite->run();
