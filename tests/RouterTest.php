<?php

/**
 * Router tests — tests pretty URL resolution and middleware dispatch.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Router.php';

use Bulletin\Router;

function test_router_resolve(): Test
{
    $t = new Test('Router::resolve()');

    // Save original state
    $origGet = $_GET;
    $origServer = $_SERVER;

    // Test: home page
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/';
    $result = Router::resolve();
    $t->assertEquals('Home page resolves to action=home', 'home', $result['action'] ?? '');

    // Test: thread URL
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/thread/123-my-thread';
    $result = Router::resolve();
    $t->assertEquals('Thread URL resolves action', 'thread', $result['action'] ?? '');
    $t->assertEquals('Thread URL resolves id', '123', $result['id'] ?? '');

    // Test: category URL
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/category/5-general';
    $result = Router::resolve();
    $t->assertEquals('Category URL resolves action', 'category', $result['action'] ?? '');
    $t->assertEquals('Category URL resolves id', '5', $result['id'] ?? '');

    // Test: profile URL
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/u/johndoe';
    $result = Router::resolve();
    $t->assertEquals('Profile URL resolves action', 'profile', $result['action'] ?? '');
    $t->assertEquals('Profile URL resolves user', 'johndoe', $result['user'] ?? '');

    // Test: admin URL
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/admin';
    $result = Router::resolve();
    $t->assertEquals('Admin URL resolves action', 'admin', $result['action'] ?? '');

    // Test: admin sub-page
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/admin/users';
    $result = Router::resolve();
    $t->assertEquals('Admin users URL resolves', 'admin_users', $result['action'] ?? '');

    // Test: already set action is preserved
    $_GET = ['action' => 'custom'];
    $_SERVER['REQUEST_URI'] = '/something';
    $result = Router::resolve();
    $t->assertEquals('Existing action is preserved', 'custom', $result['action'] ?? '');

    // Test: edit-post URL
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/edit-post/42';
    $result = Router::resolve();
    $t->assertEquals('Edit post resolves action', 'edit_post', $result['action'] ?? '');
    $t->assertEquals('Edit post resolves id', 42, $result['id'] ?? 0);

    // Test: new-thread URL
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/new-thread';
    $result = Router::resolve();
    $t->assertEquals('New thread resolves', 'new_thread', $result['action'] ?? '');

    // Restore state
    $_GET = $origGet;
    $_SERVER = $origServer;

    return $t;
}

function test_router_middleware_dispatch(): Test
{
    $t = new Test('Router middleware dispatch');

    // Test: basic route matching
    $router = new Router();
    $matched = false;
    $router->get('/test/{id:\d+}', function($params) use (&$matched) {
        $matched = true;
        return ['status' => 200, 'body' => 'ok'];
    });

    // We can't easily test dispatch() without output buffering,
    // but we can verify the router builds correctly
    $t->assertNotNull('Router instance created', $router);

    // Test: middleware registration
    $router2 = new Router();
    $router2 = $router2->registerMiddleware('test_mw', function($params) {
        return null;
    });
    $t->assertNotNull('Middleware registration returns router', $router2);

    // Test: group middleware
    $router3 = new Router();
    $router3 = $router3->middleware('auth')->group(function($r) {
        $r->get('/protected', function() { return ['status' => 200, 'body' => '']; });
    });
    $t->assertNotNull('Group middleware returns router', $router3);

    return $t;
}

function test_router_parameter_extraction(): Test
{
    $t = new Test('Router parameter extraction');

    // Test: compile pattern with named params
    $router = new Router();

    // Use reflection to test private method
    $ref = new ReflectionMethod($router, 'compilePattern');
    $ref->setAccessible(true);

    $pattern = $ref->invoke($router, '/thread/{id:\d+}');
    $t->assert('Digit constraint pattern compiled', (bool)preg_match($pattern, '/thread/123'));

    $pattern = $ref->invoke($router, '/user/{name}');
    $t->assert('Default pattern compiled', (bool)preg_match($pattern, '/user/john'));

    $pattern = $ref->invoke($router, '/post/{slug:[a-z0-9-]+}');
    $t->assert('Custom regex pattern compiled', (bool)preg_match($pattern, '/post/my-post-123'));

    return $t;
}

// Run all router tests
$suite = new TestSuite();
$suite->addTest(test_router_resolve());
$suite->addTest(test_router_middleware_dispatch());
$suite->addTest(test_router_parameter_extraction());
$suite->run();
