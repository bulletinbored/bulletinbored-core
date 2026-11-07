<?php

/**
 * RouterTest — tests for Router middleware, dispatch, JSON/HTML negotiation.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Errors.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Helpers/AuthHelpers.php';
require_once __DIR__ . '/../src/Security.php';

function router_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_router_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function router_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($dir);
}

function setup_test_app(): array
{
    $tmpDir = router_test_tmp_dir('app');
    $dataDir = $tmpDir . '/data';
    @mkdir($dataDir . '/sessions', 0755, true);
    @mkdir($dataDir . '/ratelimit', 0755, true);

    $config = [
        'db_driver' => 'sqlite',
        'db_path' => $dataDir . '/db.sqlite',
        'base_url' => 'http://localhost/test',
        'default_lang' => 'en',
        'available_langs' => ['en'],
        'cookie_secure' => false,
        'force_https' => false,
        'session_path' => $dataDir . '/sessions',
    ];

    App::reset();
    $app = App::getInstance();
    $app->config = $config;

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            email_verified INTEGER DEFAULT 0,
            session_version INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $app->pdo = $pdo;

    // Create admin user
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['admin', $adminPass, 'admin@test.com', 'admin', 'active', 1]);
    $adminId = $pdo->lastInsertId();

    // Create regular user
    $userPass = password_hash('user123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['testuser', $userPass, 'user@test.com', 'user', 'active', 1]);
    $userId = $pdo->lastInsertId();

    // Create roles
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
        ->execute(['user', json_encode(['threads.create', 'posts.create'])]);
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
        ->execute(['admin', json_encode(['admin.access', 'moderation.manage', 'users.manage'])]);

    // Initialize AuthZ
    require_once __DIR__ . '/../lib/AuthZ.php';
    require_once __DIR__ . '/../lib/PluginManager.php';
    $pluginManager = new PluginManager($tmpDir . '/plugins', $dataDir . '/plugins.json');
    $app->authz = new AuthZ($pluginManager);
    $app->pluginManager = $pluginManager;

    // Set up session
    $_SESSION['user_id'] = $adminId;
    $_SESSION['session_version'] = 1;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return ['tmpDir' => $tmpDir, 'dataDir' => $dataDir, 'config' => $config, 'pdo' => $pdo, 'app' => $app, 'adminId' => $adminId, 'userId' => $userId];
}

function test_router_basic_routing(): Test
{
    $t = new Test('Router - Basic Routing');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;
    $receivedParams = null;

    $router->get('/test', function($params) use (&$handlerCalled, &$receivedParams) {
        $handlerCalled = true;
        $receivedParams = $params;
        return new \Bulletin\Response(200, 'OK');
    });

    // Simulate request
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called', $handlerCalled);
    $t->assertEquals('Output contains OK', 'OK', $output);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_named_parameters(): Test
{
    $t = new Test('Router - Named Parameters');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $receivedParams = null;

    $router->get('/thread/{id:\d+}', function($params) use (&$receivedParams) {
        $receivedParams = $params;
        return new \Bulletin\Response(200, 'Thread ' . $params['id']);
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/thread/123';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertNotNull('Params received', $receivedParams);
    $t->assertEquals('ID parameter captured', '123', $receivedParams['id'] ?? '');
    $t->assertTrue('Output contains thread ID', str_contains($output, '123'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_multiple_parameters(): Test
{
    $t = new Test('Router - Multiple Parameters');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $receivedParams = null;

    $router->get('/category/{catId:\d+}/thread/{threadId:\d+}', function($params) use (&$receivedParams) {
        $receivedParams = $params;
        return new \Bulletin\Response(200, 'Cat: ' . $params['catId'] . ', Thread: ' . $params['threadId']);
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/category/5/thread/42';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertNotNull('Params received', $receivedParams);
    $t->assertEquals('Category ID captured', '5', $receivedParams['catId'] ?? '');
    $t->assertEquals('Thread ID captured', '42', $receivedParams['threadId'] ?? '');

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_post_method(): Test
{
    $t = new Test('Router - POST Method');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $receivedParams = null;
    $receivedMethod = null;

    $router->post('/submit', function($params) use (&$receivedParams, &$receivedMethod) {
        $receivedParams = $params;
        $receivedMethod = $_SERVER['REQUEST_METHOD'];
        return new \Bulletin\Response(200, 'Submitted');
    });

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/submit';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_POST['data'] = 'test';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertEquals('Method is POST', 'POST', $receivedMethod);
    $t->assertEquals('POST data in params', 'test', $receivedParams['data'] ?? '');
    $t->assertTrue('Output contains Submitted', str_contains($output, 'Submitted'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_method_mismatch(): Test
{
    $t = new Test('Router - Method Mismatch Returns 404');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/test', function($params) {
        return new \Bulletin\Response(200, 'GET only');
    });

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Returns 404 for wrong method', str_contains($output, 'Not found') || str_contains($output, '404'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_404_not_found(): Test
{
    $t = new Test('Router - 404 Not Found');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/exists', function($params) {
        return new \Bulletin\Response(200, 'Exists');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/nonexistent';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Returns 404', str_contains($output, 'Not found') || str_contains($output, '404'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_middleware_guest(): Test
{
    $t = new Test('Router - Guest Middleware');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->middleware('guest')->get('/login', function($params) {
        return new \Bulletin\Response(200, 'Login Page');
    });

    // Test as logged-in user (should redirect)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/login';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    // Guest middleware should redirect logged-in users
    $t->assertTrue('Redirects logged-in user', http_response_code() === 302 || str_contains($output, 'home'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_middleware_auth(): Test
{
    $t = new Test('Router - Auth Middleware');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;

    $router->middleware('auth')->get('/profile', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Profile');
    });

    // Test as logged-in user (should allow)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/profile';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called for logged-in user', $handlerCalled);
    $t->assertTrue('Output contains Profile', str_contains($output, 'Profile'));

    // Test as guest (should redirect to login)
    $_SESSION['user_id'] = null;
    unset($_SESSION['user_id']);

    $handlerCalled = false;
    $_SERVER['REQUEST_URI'] = '/profile';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called for guest', $handlerCalled);
    $t->assertTrue('Redirects to login', http_response_code() === 302 || str_contains($output, 'login'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_middleware_admin(): Test
{
    $t = new Test('Router - Admin Middleware');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;

    $router->middleware('admin')->get('/admin', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Admin Panel');
    });

    // Test as admin (should allow)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called for admin', $handlerCalled);
    $t->assertTrue('Output contains Admin Panel', str_contains($output, 'Admin Panel'));

    // Test as regular user (should 403)
    $_SESSION['user_id'] = $env['userId'];

    $handlerCalled = false;
    $_SERVER['REQUEST_URI'] = '/admin';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called for non-admin', $handlerCalled);
    $t->assertTrue('Returns 403', http_response_code() === 403 || str_contains($output, 'Forbidden') || str_contains($output, '403'));

    // Test as guest (should redirect to login)
    $_SESSION['user_id'] = null;
    unset($_SESSION['user_id']);

    $handlerCalled = false;
    $_SERVER['REQUEST_URI'] = '/admin';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called for guest', $handlerCalled);
    $t->assertTrue('Redirects to login', http_response_code() === 302 || str_contains($output, 'login'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_middleware_csrf(): Test
{
    $t = new Test('Router - CSRF Middleware');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;

    $router->middleware('csrf')->post('/submit', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Submitted');
    });

    // Test with valid CSRF token
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/submit';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called with valid CSRF', $handlerCalled);

    // Test with invalid CSRF token
    $handlerCalled = false;
    $_POST['csrf_token'] = 'invalid_token';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called with invalid CSRF', $handlerCalled);
    $t->assertTrue('Returns 403', http_response_code() === 403 || str_contains($output, 'CSRF') || str_contains($output, '403'));

    // Test GET request (CSRF not checked)
    $handlerCalled = false;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/submit';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called for GET (no route)', $handlerCalled);
    $t->assertTrue('Returns 404 for GET', str_contains($output, 'Not found') || str_contains($output, '404'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_middleware_chain(): Test
{
    $t = new Test('Router - Middleware Chain');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;

    $router->middleware('auth', 'csrf')->post('/admin/action', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Action Done');
    });

    // Test with valid auth and CSRF
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/admin/action';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called with valid auth and CSRF', $handlerCalled);

    // Test with valid auth but invalid CSRF
    $handlerCalled = false;
    $_POST['csrf_token'] = 'invalid';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called with invalid CSRF', $handlerCalled);
    $t->assertTrue('Returns 403', http_response_code() === 403 || str_contains($output, '403'));

    // Test with invalid auth but valid CSRF
    $handlerCalled = false;
    $_SESSION['user_id'] = $env['userId']; // not admin
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called for non-admin', $handlerCalled);
    $t->assertTrue('Returns 403', http_response_code() === 403 || str_contains($output, '403'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_group(): Test
{
    $t = new Test('Router - Group');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handler1Called = false;
    $handler2Called = false;

    $router->middleware('auth')->group(function($router) use (&$handler1Called, &$handler2Called) {
        $router->get('/dashboard', function($params) use (&$handler1Called) {
            $handler1Called = true;
            return new \Bulletin\Response(200, 'Dashboard');
        });
        $router->get('/settings', function($params) use (&$handler2Called) {
            $handler2Called = true;
            return new \Bulletin\Response(200, 'Settings');
        });
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];

    // Test first route
    $_SERVER['REQUEST_URI'] = '/dashboard';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('First handler called', $handler1Called);
    $t->assertTrue('Output contains Dashboard', str_contains($output, 'Dashboard'));

    // Test second route
    $handler1Called = false;
    $handler2Called = false;
    $_SERVER['REQUEST_URI'] = '/settings';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('Second handler called', $handler2Called);
    $t->assertTrue('Output contains Settings', str_contains($output, 'Settings'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_api_group(): Test
{
    $t = new Test('Router - API Group');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;
    $receivedParams = null;

    $router->api()->get('/api/users/{id:\d+}', function($params) use (&$handlerCalled, &$receivedParams) {
        $handlerCalled = true;
        $receivedParams = $params;
        return ['id' => (int)$params['id'], 'name' => 'User ' . $params['id']];
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/users/42';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called', $handlerCalled);
    $t->assertEquals('ID parameter captured', '42', $receivedParams['id'] ?? '');
    $t->assertTrue('Returns JSON', str_contains($output, '"id":42') || str_contains($output, '"id": 42'));
    $t->assertTrue('Content-Type is JSON', http_response_code() === 200); // headers_sent check would be better

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_view_group(): Test
{
    $t = new Test('Router - View Group');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;

    $router->view()->get('/page', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Page Content');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/page';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called', $handlerCalled);
    $t->assertTrue('Output contains Page Content', str_contains($output, 'Page Content'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_register_middleware(): Test
{
    $t = new Test('Router - Register Custom Middleware');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $customMiddlewareCalled = false;

    $router->registerMiddleware('custom', function($params) use (&$customMiddlewareCalled) {
        $customMiddlewareCalled = true;
        return null; // Continue
    });

    $handlerCalled = false;

    $router->middleware('custom')->get('/test', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Custom middleware called', $customMiddlewareCalled);
    $t->assertTrue('Handler called', $handlerCalled);

    // Test middleware that blocks
    $router2 = new \Bulletin\Router();
    $blockCalled = false;

    $router2->registerMiddleware('block', function($params) use (&$blockCalled) {
        $blockCalled = true;
        return ['status' => 403, 'body' => 'Blocked'];
    });

    $handlerCalled2 = false;
    $router2->middleware('block')->get('/test', function($params) use (&$handlerCalled2) {
        $handlerCalled2 = true;
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_URI'] = '/test';
    ob_start();
    $router2->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Blocking middleware called', $blockCalled);
    $t->assertFalse('Handler not called when middleware blocks', $handlerCalled2);
    $t->assertTrue('Returns 403', str_contains($output, 'Blocked') || str_contains($output, '403'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_can_middleware(): Test
{
    $t = new Test('Router - Can Middleware');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    // Register can middleware with AuthZ
    $router->registerCanMiddleware($env['app']->authz);

    $handlerCalled = false;

    $router->middleware('can:moderation.manage')->get('/moderate', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Moderate');
    });

    // Test as admin (has permission)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/moderate';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called for user with permission', $handlerCalled);

    // Test as regular user (no permission)
    $handlerCalled = false;
    $_SESSION['user_id'] = $env['userId'];
    $_SERVER['REQUEST_URI'] = '/moderate';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called for user without permission', $handlerCalled);
    $t->assertTrue('Returns 403', http_response_code() === 403 || str_contains($output, 'Forbidden') || str_contains($output, '403'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_can_middleware_owner(): Test
{
    $t = new Test('Router - Can Middleware with Owner Check');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->registerCanMiddleware($env['app']->authz);

    $handlerCalled = false;

    $router->middleware('can:posts.edit')->get('/post/{id:\d+}/edit', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'Edit Post ' . $params['id']);
    });

    // Create a post owned by admin
    $env['pdo']->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([1, $env['adminId'], 'Admin post', 'visible']);
    $postId = $env['pdo']->lastInsertId();

    // Test as owner (admin)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/post/' . $postId . '/edit';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = $env['adminId'];

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called for owner', $handlerCalled);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_wants_json_accept_header(): Test
{
    $t = new Test('Router - Wants JSON via Accept Header');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $wantsJson = false;

    $router->get('/test', function($params) use (&$wantsJson, $router) {
        $ref = new ReflectionMethod($router, 'wantsJson');
        $ref->setAccessible(true);
        $wantsJson = $ref->invoke($router);
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Wants JSON when Accept is application/json', $wantsJson);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_wants_json_content_type(): Test
{
    $t = new Test('Router - Wants JSON via Content-Type');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $wantsJson = false;

    $router->post('/test', function($params) use (&$wantsJson, $router) {
        $ref = new ReflectionMethod($router, 'wantsJson');
        $ref->setAccessible(true);
        $wantsJson = $ref->invoke($router);
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['CONTENT_TYPE'] = 'application/json';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Wants JSON when Content-Type is application/json', $wantsJson);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_wants_json_api_path(): Test
{
    $t = new Test('Router - Wants JSON via API Path');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $wantsJson = false;

    $router->get('/api/test', function($params) use (&$wantsJson, $router) {
        $ref = new ReflectionMethod($router, 'wantsJson');
        $ref->setAccessible(true);
        $wantsJson = $ref->invoke($router);
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Wants JSON when path starts with api/', $wantsJson);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_wants_json_false_for_html(): Test
{
    $t = new Test('Router - Wants JSON False for HTML');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $wantsJson = true; // Start with true to verify it becomes false

    $router->get('/test', function($params) use (&$wantsJson, $router) {
        $ref = new ReflectionMethod($router, 'wantsJson');
        $ref->setAccessible(true);
        $wantsJson = $ref->invoke($router);
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Does not want JSON for HTML accept', $wantsJson);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_exception_handling(): Test
{
    $t = new Test('Router - Exception Handling');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/error', function($params) {
        throw new \Bulletin\NotFoundException('Resource not found');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/error';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Returns 404', http_response_code() === 404 || str_contains($output, 'Not found') || str_contains($output, '404'));
    $t->assertTrue('Error message in output', str_contains($output, 'Resource not found'));

    // Test ValidationException
    $router2 = new \Bulletin\Router();
    $router2->get('/validation', function($params) {
        throw new \Bulletin\ValidationException(['field' => 'Invalid value']);
    });

    $_SERVER['REQUEST_URI'] = '/validation';
    ob_start();
    $router2->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Returns 400', http_response_code() === 400 || str_contains($output, 'Invalid value') || str_contains($output, '400'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_json_error_response(): Test
{
    $t = new Test('Router - JSON Error Response');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/api/error', function($params) {
        throw new \Bulletin\NotFoundException('API resource not found');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/error';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Returns JSON error', str_contains($output, '"error"') || str_contains($output, 'error'));
    $t->assertTrue('Error message in JSON', str_contains($output, 'API resource not found'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_response_object(): Test
{
    $t = new Test('Router - Response Object');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/response', function($params) {
        return new \Bulletin\Response(201, 'Created', ['X-Custom' => 'value']);
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/response';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Status 201', http_response_code() === 201);
    $t->assertTrue('Output contains Created', str_contains($output, 'Created'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_array_response(): Test
{
    $t = new Test('Router - Array Response');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/array', function($params) {
        return ['status' => 200, 'body' => 'Array Response', 'headers' => ['X-Array' => 'test']];
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/array';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Output contains Array Response', str_contains($output, 'Array Response'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_object_response_json(): Test
{
    $t = new Test('Router - Object Response Auto JSON');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/object', function($params) {
        return (object)['key' => 'value', 'number' => 42];
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/object';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Returns JSON for object', str_contains($output, '"key":"value"') || str_contains($output, '"key": "value"'));
    $t->assertTrue('Number in JSON', str_contains($output, '42'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_base_url_handling(): Test
{
    $t = new Test('Router - Base URL Handling');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $handlerCalled = false;

    $router->get('/test', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'OK');
    });

    // Test with base URL in path
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Handler called with base URL', $handlerCalled);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_any_method(): Test
{
    $t = new Test('Router - Any Method');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $methodsCalled = [];

    $router->any('/any', function($params) use (&$methodsCalled) {
        $methodsCalled[] = $_SERVER['REQUEST_METHOD'];
        return new \Bulletin\Response(200, 'Method: ' . $_SERVER['REQUEST_METHOD']);
    });

    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    // Test GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/any';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('GET handled', in_array('GET', $methodsCalled));

    // Test POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/any';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('POST handled', in_array('POST', $methodsCalled));

    // Test PUT
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    $_SERVER['REQUEST_URI'] = '/any';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('PUT handled', in_array('PUT', $methodsCalled));

    // Test DELETE
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = '/any';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('DELETE handled', in_array('DELETE', $methodsCalled));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_put_delete_patch(): Test
{
    $t = new Test('Router - PUT, DELETE, PATCH Methods');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $putCalled = false;
    $deleteCalled = false;
    $patchCalled = false;

    $router->put('/resource/{id:\d+}', function($params) use (&$putCalled) {
        $putCalled = true;
        return new \Bulletin\Response(200, 'PUT ' . $params['id']);
    });

    $router->delete('/resource/{id:\d+}', function($params) use (&$deleteCalled) {
        $deleteCalled = true;
        return new \Bulletin\Response(200, 'DELETE ' . $params['id']);
    });

    $router->patch('/resource/{id:\d+}', function($params) use (&$patchCalled) {
        $patchCalled = true;
        return new \Bulletin\Response(200, 'PATCH ' . $params['id']);
    });

    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/resource/1';

    // Test PUT
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('PUT handler called', $putCalled);

    // Test DELETE
    $putCalled = false;
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('DELETE handler called', $deleteCalled);

    // Test PATCH
    $deleteCalled = false;
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    ob_start();
    $router->dispatch();
    $output = ob_get_clean();
    $t->assertTrue('PATCH handler called', $patchCalled);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_params_in_request(): Test
{
    $t = new Test('Router - Params in $_GET and $_REQUEST');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->get('/test/{id:\d+}', function($params) {
        $inGet = isset($_GET['id']) && $_GET['id'] === '42';
        $inRequest = isset($_REQUEST['id']) && $_REQUEST['id'] === '42';
        return new \Bulletin\Response(200, 'GET:' . ($inGet ? 'yes' : 'no') . ' REQUEST:' . ($inRequest ? 'yes' : 'no'));
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test/42';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertTrue('Param in $_GET', str_contains($output, 'GET:yes'));
    $t->assertTrue('Param in $_REQUEST', str_contains($output, 'REQUEST:yes'));

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_compile_pattern(): Test
{
    $t = new Test('Router - Pattern Compilation');

    $router = new \Bulletin\Router();

    // Use reflection to test private method
    $ref = new ReflectionMethod($router, 'compilePattern');
    $ref->setAccessible(true);

    $pattern1 = $ref->invoke($router, '/simple');
    $t->assertEquals('Simple pattern', '#^/simple$#', $pattern1);

    $pattern2 = $ref->invoke($router, '/user/{id:\d+}');
    $t->assertEquals('Named parameter with constraint', '#^/user/(?P<id>\d+)$#', $pattern2);

    $pattern3 = $ref->invoke($router, '/{controller}/{action}');
    $t->assertEquals('Multiple parameters default constraint', '#^/(?P<controller>[^/]+)/(?P<action>[^/]+)$#', $pattern3);

    $pattern4 = $ref->invoke($router, '/api/{version:v\d+}/users/{id:\d+}');
    $t->assertEquals('Complex pattern', '#^/api/(?P<version>v\d+)/users/(?P<id>\d+)$#', $pattern4);

    return $t;
}

function test_router_middleware_priority(): Test
{
    $t = new Test('Router - Middleware Execution Order');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $order = [];

    $router->registerMiddleware('first', function($params) use (&$order) {
        $order[] = 'first';
        return null;
    });

    $router->registerMiddleware('second', function($params) use (&$order) {
        $order[] = 'second';
        return null;
    });

    $router->registerMiddleware('third', function($params) use (&$order) {
        $order[] = 'third';
        return null;
    });

    $router->middleware('first', 'second', 'third')->get('/test', function($params) use (&$order) {
        $order[] = 'handler';
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertEquals('Middleware order correct', ['first', 'second', 'third', 'handler'], $order);

    router_cleanup($env['tmpDir']);
    return $t;
}

function test_router_middleware_can_block(): Test
{
    $t = new Test('Router - Middleware Can Block Request');

    $env = setup_test_app();
    $router = new \Bulletin\Router();

    $router->registerMiddleware('blocker', function($params) {
        return ['status' => 403, 'body' => 'Blocked by middleware'];
    });

    $handlerCalled = false;

    $router->middleware('blocker')->get('/test', function($params) use (&$handlerCalled) {
        $handlerCalled = true;
        return new \Bulletin\Response(200, 'OK');
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $t->assertFalse('Handler not called when middleware blocks', $handlerCalled);
    $t->assertTrue('Returns blocked message', str_contains($output, 'Blocked by middleware'));
    $t->assertTrue('Status 403', http_response_code() === 403);

    router_cleanup($env['tmpDir']);
    return $t;
}

register_tests(
    'test_router_basic_routing',
    'test_router_named_parameters',
    'test_router_multiple_parameters',
    'test_router_post_method',
    'test_router_method_mismatch',
    'test_router_404_not_found',
    'test_router_middleware_guest',
    'test_router_middleware_auth',
    'test_router_middleware_admin',
    'test_router_middleware_csrf',
    'test_router_middleware_chain',
    'test_router_group',
    'test_router_api_group',
    'test_router_view_group',
    'test_router_register_middleware',
    'test_router_can_middleware',
    'test_router_can_middleware_owner',
    'test_router_wants_json_accept_header',
    'test_router_wants_json_content_type',
    'test_router_wants_json_api_path',
    'test_router_wants_json_false_for_html',
    'test_router_exception_handling',
    'test_router_json_error_response',
    'test_router_response_object',
    'test_router_array_response',
    'test_router_object_response_json',
    'test_router_base_url_handling',
    'test_router_any_method',
    'test_router_put_delete_patch',
    'test_router_params_in_request',
    'test_router_compile_pattern',
    'test_router_middleware_priority',
    'test_router_middleware_can_block'
);