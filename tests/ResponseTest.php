<?php

/**
 * ResponseTest.php — tests for Response object and typed Request getters.
 */

require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Request.php';
require_once __DIR__ . '/../src/Errors.php';

use Bulletin\Response;
use Bulletin\Request;

function test_response_html(): Test
{
    $t = new Test('Response::html');

    $r = Response::html('<p>Hello</p>', 200);
    $t->assertEquals('Status is 200', 200, $r->getStatus());
    $t->assertEquals('Body is HTML', '<p>Hello</p>', $r->getBody());
    $t->assertFalse('Not JSON', $r->isJson());

    return $t;
}

function test_response_json(): Test
{
    $t = new Test('Response::json');

    $r = Response::json(['ok' => true, 'count' => 42]);
    $t->assertEquals('Status is 200', 200, $r->getStatus());
    $t->assertTrue('Is JSON', $r->isJson());
    $t->assert('Body is valid JSON', str_contains($r->getBody(), '"ok":true'));
    $t->assert('Body contains count', str_contains($r->getBody(), '"count":42'));

    return $t;
}

function test_response_redirect(): Test
{
    $t = new Test('Response::redirect');

    $r = Response::redirect('/login', 302);
    $t->assertEquals('Status is 302', 302, $r->getStatus());
    $t->assert('Has Location header', str_contains($r->getHeaders()[0] ?? '', 'Location: /login'));

    return $t;
}

function test_response_error(): Test
{
    $t = new Test('Response::error');

    $r = Response::error(403, 'Forbidden');
    $t->assertEquals('Status is 403', 403, $r->getStatus());
    $t->assertEquals('Body is message', 'Forbidden', $r->getBody());

    return $t;
}

function test_typed_request_string(): Test
{
    $t = new Test('Request::string');

    $_POST['name'] = '  hello  ';
    $result = Request::string('name');
    $t->assertEquals('String is trimmed', 'hello', $result);

    $result = Request::string('missing', 'default');
    $t->assertEquals('Default for missing', 'default', $result);

    unset($_POST['name']);

    return $t;
}

function test_typed_request_int(): Test
{
    $t = new Test('Request::int');

    $_POST['page'] = '42';
    $result = Request::int('page');
    $t->assertEquals('Int is cast', 42, $result);

    $result = Request::int('missing', 1);
    $t->assertEquals('Default for missing', 1, $result);

    $_POST['zero'] = '0';
    $result = Request::int('zero');
    $t->assertEquals('Zero is preserved', 0, $result);

    unset($_POST['page'], $_POST['zero']);

    return $t;
}

function test_typed_request_bool(): Test
{
    $t = new Test('Request::bool');

    $_POST['agree'] = '1';
    $t->assertTrue('Bool true for "1"', Request::bool('agree'));

    $_POST['agree'] = '0';
    $t->assertFalse('Bool false for "0"', Request::bool('agree'));

    $t->assertFalse('Default false', Request::bool('missing'));
    $t->assertTrue('Default true', Request::bool('missing', true));

    unset($_POST['agree']);

    return $t;
}

function test_typed_request_email(): Test
{
    $t = new Test('Request::email');

    $_POST['email'] = 'user@example.com';
    $t->assertEquals('Valid email', 'user@example.com', Request::email('email'));

    $_POST['email'] = 'not-an-email';
    $t->assertEquals('Invalid email returns empty', '', Request::email('email'));

    $t->assertEquals('Missing returns empty', '', Request::email('missing'));

    unset($_POST['email']);

    return $t;
}

function test_typed_request_enum(): Test
{
    $t = new Test('Request::enum');

    $_POST['status'] = 'visible';
    $result = Request::enum('status', ['visible', 'hidden']);
    $t->assertEquals('Valid enum', 'visible', $result);

    $_POST['status'] = 'invalid';
    $result = Request::enum('status', ['visible', 'hidden']);
    $t->assertNull('Invalid enum returns null', $result);

    $result = Request::enum('missing', ['visible', 'hidden'], 'default');
    $t->assertEquals('Default for missing', 'default', $result);

    unset($_POST['status']);

    return $t;
}

register_tests(
    'test_response_html',
    'test_response_json',
    'test_response_redirect',
    'test_response_error',
    'test_typed_request_string',
    'test_typed_request_int',
    'test_typed_request_bool',
    'test_typed_request_email',
    'test_typed_request_enum'
);
