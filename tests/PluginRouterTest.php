<?php

/**
 * PluginRouterTest.php — tests for plugin route/middleware registration.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../src/Router.php';

function test_plugin_registers_route(): Test
{
    $t = new Test('Plugin Route Registration');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_route_manifest.json');
    $router = new Bulletin\Router();
    $pm->setRouter($router);

    $pm->registerRoute('GET', '/plugin-test', function() {
        return ['status' => 200, 'body' => 'plugin route works'];
    });

    $pm->applyRoutes();

    $t->assertNotNull('Router is set', $pm->getRouter());

    return $t;
}

function test_plugin_registers_middleware(): Test
{
    $t = new Test('Plugin Middleware Registration');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_mw_manifest.json');
    $router = new Bulletin\Router();
    $pm->setRouter($router);

    $middlewareCalled = false;
    $pm->registerMiddleware('test_plugin_mw', function($params) use (&$middlewareCalled) {
        $middlewareCalled = true;
        return null;
    });

    $pm->applyRoutes();

    $t->assertNotNull('Router is set after middleware registration', $pm->getRouter());

    return $t;
}

function test_plugin_route_without_router(): Test
{
    $t = new Test('Plugin Route Without Router (no crash)');

    $pm = new PluginManager(__DIR__ . '/tmp_plugins', __DIR__ . '/tmp_norouter_manifest.json');

    $pm->registerRoute('GET', '/will-not-crash', function() {});
    $pm->applyRoutes();

    $t->assertNull('Router is null when not set', $pm->getRouter());

    return $t;
}

function test_router_populates_get(): Test
{
    $t = new Test('Router Populates $_GET');

    $router = new Bulletin\Router();
    $capturedId = null;

    $router->get('/item/{id:\d+}', function($params) use (&$capturedId) {
        $capturedId = $_GET['id'] ?? null;
        return ['status' => 200, 'body' => ''];
    });

    $origUri = $_SERVER['REQUEST_URI'] ?? null;
    $origMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $origScript = $_SERVER['SCRIPT_NAME'] ?? null;

    $_SERVER['REQUEST_URI'] = '/item/42';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    ob_start();
    $router->dispatch();
    ob_end_clean();

    $t->assertEquals('$_GET[id] populated from route', '42', $capturedId);

    if ($origUri !== null) $_SERVER['REQUEST_URI'] = $origUri;
    if ($origMethod !== null) $_SERVER['REQUEST_METHOD'] = $origMethod;
    if ($origScript !== null) $_SERVER['SCRIPT_NAME'] = $origScript;

    return $t;
}

register_tests(
    'test_plugin_registers_route',
    'test_plugin_registers_middleware',
    'test_plugin_route_without_router',
    'test_router_populates_get'
);
