<?php

/**
 * RouteAuthorizationTest — every route must be protected by the middleware it
 * needs, and the middleware chain must actually block the wrong users.
 *
 * Two layers:
 *   1. a static map of index.php: every registered route is collected with the
 *      middleware stack it sits in, so an admin route added outside the admin
 *      group (or a private route added outside "auth") fails the build;
 *   2. behavioural checks of the Router middleware chain (guest / auth / admin).
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Errors.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../lib/AuthZ.php';

/**
 * Parse index.php and return [['method' => 'GET', 'path' => '/admin/x', 'middleware' => ['admin']], ...].
 *
 * Groups are tracked with brace counting. Route patterns such as
 * "/thread/{id:\d+}" contain balanced braces, so they do not disturb the depth.
 */
function route_authorization_map(): array
{
    $file = dirname(__DIR__) . '/index.php';
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

    $routes = [];
    $depth = 0;
    // Each entry: ['depth' => depth before the group body, 'middleware' => [...]]
    $stack = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/\$router->middleware\(([^)]*)\)->group\(/', $trimmed, $m)) {
            preg_match_all("/'([^']+)'/", $m[1], $names);
            $stack[] = ['depth' => $depth, 'middleware' => $names[1] ?? []];
        } elseif (preg_match('/\$router->([a-z]+)\(\'([^\']+)\'/', $trimmed, $m)) {
            $method = strtoupper($m[1]);
            if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'ANY'], true)) {
                $middleware = [];
                foreach ($stack as $group) {
                    foreach ($group['middleware'] as $name) {
                        $middleware[] = $name;
                    }
                }
                $routes[] = ['method' => $method, 'path' => $m[2], 'middleware' => $middleware];
            }
        }

        // Update depth after processing the line (balanced-brace safe).
        $depth += substr_count($line, '{') - substr_count($line, '}');

        // Close groups whose body has ended.
        while ($stack && $depth <= $stack[count($stack) - 1]['depth']) {
            array_pop($stack);
        }
    }

    return $routes;
}

function test_route_map_is_discoverable(): Test
{
    $t = new Test('Routes - route map is discoverable');

    $routes = route_authorization_map();
    $t->assert('Routes were found in index.php', count($routes) > 30);

    $admin = array_filter($routes, fn($r) => str_starts_with($r['path'], '/admin'));
    $t->assert('Admin routes were found', count($admin) > 20);
    $t->assert('Public home route present', (bool)array_filter($routes, fn($r) => $r['path'] === '/'));

    return $t;
}

function test_every_admin_route_requires_admin_middleware(): Test
{
    $t = new Test('Routes - /admin/* requires the admin middleware');

    $routes = route_authorization_map();
    $unprotected = [];
    foreach ($routes as $route) {
        if (str_starts_with($route['path'], '/admin') && !in_array('admin', $route['middleware'], true)) {
            $unprotected[] = $route['method'] . ' ' . $route['path'];
        }
    }
    $t->assert('No admin route escapes the admin middleware', $unprotected === []);
    if ($unprotected) {
        $t->assert('unprotected: ' . implode(', ', $unprotected), false);
    }

    return $t;
}

function test_private_routes_require_authentication(): Test
{
    $t = new Test('Routes - private routes require auth');

    // Routes that are deliberately public. Anything else must be behind "auth"
    // (or "admin", which implies authentication).
    $publicPrefixes = [
        '/', '/login', '/register', '/forgot-password', '/reset-password',
        '/verify-email', '/thread/', '/category/', '/u/', '/search', '/download/',
    ];

    $routes = route_authorization_map();
    $exposed = [];
    foreach ($routes as $route) {
        $path = $route['path'];
        $isPublic = false;
        foreach ($publicPrefixes as $prefix) {
            if ($prefix === '/' ? $path === '/' : str_starts_with($path, $prefix)) {
                $isPublic = true;
                break;
            }
        }
        if ($isPublic) {
            continue;
        }
        $guarded = in_array('auth', $route['middleware'], true) || in_array('admin', $route['middleware'], true);
        if (!$guarded) {
            $exposed[] = $route['method'] . ' ' . $path;
        }
    }
    $t->assert('Every non-public route is authenticated', $exposed === []);
    if ($exposed) {
        $t->assert('exposed: ' . implode(', ', $exposed), false);
    }

    return $t;
}

function test_guest_only_routes_not_duplicated_in_private_groups(): Test
{
    $t = new Test('Routes - guest routes are not also private');

    $routes = route_authorization_map();
    $conflicts = [];
    $byPath = [];
    foreach ($routes as $route) {
        $byPath[$route['path']][] = $route['middleware'];
    }
    foreach ($byPath as $path => $stacks) {
        if (count($stacks) < 2) {
            continue;
        }
        $guest = false;
        $private = false;
        foreach ($stacks as $stack) {
            $guest = $guest || in_array('guest', $stack, true);
            $private = $private || in_array('auth', $stack, true) || in_array('admin', $stack, true);
        }
        if ($guest && $private) {
            $conflicts[] = $path;
        }
    }
    $t->assert('No path is both guest-only and authenticated', $conflicts === []);

    return $t;
}

// ---------------------------------------------------------------------------
// Behavioural checks of the middleware chain
//
// dispatch() emits headers and an HTTP status, so each scenario runs in its own
// PHP process: once the test runner has printed something, headers can no
// longer be sent and http_response_code() would return false.
// ---------------------------------------------------------------------------

function route_authorization_scenario(string $scenario, string $path): array
{
    if (!function_exists('proc_open')) {
        return ['skipped' => true];
    }

    $script = <<<'PHP'
<?php
$root = $argv[1];
$scenario = $argv[2];
$path = $argv[3];

require $root . '/src/App.php';
require $root . '/src/Errors.php';
require $root . '/src/Response.php';
require $root . '/src/helpers.php';
require $root . '/src/Router.php';
require $root . '/lib/AuthZ.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, status TEXT DEFAULT 'active', session_version INTEGER DEFAULT 1)");
$pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, permissions TEXT)");
$pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]'), ('admin', '[]')");
$pdo->exec("INSERT INTO users (id, username, role) VALUES (5, 'member', 'user'), (1, 'admin', 'admin')");

App::getInstance()->pdo = $pdo;
App::getInstance()->authz = new AuthZ($pdo);
App::getInstance()->config = ['base_url' => 'http://localhost'];

$_SESSION = [];
if ($scenario === 'member_private' || $scenario === 'member_admin') {
    $_SESSION = ['user_id' => 5, 'user_role' => 'user', 'session_version' => 1];
} elseif ($scenario === 'admin_admin') {
    $_SESSION = ['user_id' => 1, 'user_role' => 'admin', 'session_version' => 1];
}

$router = new Bulletin\Router();
$router->middleware('auth')->group(function ($router) {
    $router->get('/private', function () {
        echo 'private-handler';
        return ['status' => 200];
    });
});
$router->middleware('admin')->group(function ($router) {
    $router->get('/admin-probe', function () {
        echo 'admin-handler';
        return ['status' => 200];
    });
});

$_SERVER['REQUEST_URI'] = $path;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
http_response_code(200);

ob_start();
$router->dispatch();
$body = (string)ob_get_clean();

echo json_encode(['status' => http_response_code(), 'body' => $body]);
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'bb_route_');
    $tmpFile = $tmp . '.php';
    @rename($tmp, $tmpFile);
    file_put_contents($tmpFile, $script);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpFile)
        . ' ' . escapeshellarg(dirname(__DIR__))
        . ' ' . escapeshellarg($scenario)
        . ' ' . escapeshellarg($path);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        @unlink($tmpFile);
        return ['skipped' => true];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmpFile);

    $decoded = json_decode(trim((string)$stdout), true);
    if (!is_array($decoded)) {
        return ['skipped' => false, 'status' => null, 'body' => '', 'error' => trim((string)$stderr) . ' ' . trim((string)$stdout)];
    }
    return ['skipped' => false, 'status' => (int)$decoded['status'], 'body' => (string)$decoded['body']];
}

function test_router_blocks_guest_from_private_routes(): Test
{
    $t = new Test('Router - guest is redirected from private routes');

    $private = route_authorization_scenario('guest_private', '/private');
    $admin = route_authorization_scenario('guest_admin', '/admin-probe');
    if (!empty($private['skipped'])) {
        $t->assert('Skipped: cannot spawn a PHP subprocess', true);
        return $t;
    }

    if ($private['status'] === null) {
        $t->assert('subprocess error: ' . ($private['error'] ?? 'no output'), false);
    }
    $t->assertEquals('Guest redirected (302) from /private', 302, $private['status']);
    $t->assert('Private handler not executed', !str_contains($private['body'], 'private-handler'));
    $t->assertEquals('Guest redirected (302) from /admin', 302, $admin['status']);
    $t->assert('Admin handler not executed', !str_contains($admin['body'], 'admin-handler'));

    return $t;
}

function test_router_blocks_non_admin_from_admin_routes(): Test
{
    $t = new Test('Router - non-admin gets 403 on admin routes');

    $private = route_authorization_scenario('member_private', '/private');
    $admin = route_authorization_scenario('member_admin', '/admin-probe');
    if (!empty($private['skipped'])) {
        $t->assert('Skipped: cannot spawn a PHP subprocess', true);
        return $t;
    }

    $t->assertEquals('Authenticated user reaches /private', 200, $private['status']);
    $t->assert('Private handler executed', str_contains($private['body'], 'private-handler'));
    $t->assertEquals('Authenticated non-admin gets 403 on /admin', 403, $admin['status']);
    $t->assert('Admin handler not executed', !str_contains($admin['body'], 'admin-handler'));

    return $t;
}

function test_router_allows_admin(): Test
{
    $t = new Test('Router - admin reaches admin routes');

    $admin = route_authorization_scenario('admin_admin', '/admin-probe');
    if (!empty($admin['skipped'])) {
        $t->assert('Skipped: cannot spawn a PHP subprocess', true);
        return $t;
    }

    $t->assertEquals('Admin gets 200', 200, $admin['status']);
    $t->assert('Admin handler executed', str_contains($admin['body'], 'admin-handler'));

    return $t;
}

register_tests(
    'test_route_map_is_discoverable',
    'test_every_admin_route_requires_admin_middleware',
    'test_private_routes_require_authentication',
    'test_guest_only_routes_not_duplicated_in_private_groups',
    'test_router_blocks_guest_from_private_routes',
    'test_router_blocks_non_admin_from_admin_routes',
    'test_router_allows_admin'
);
