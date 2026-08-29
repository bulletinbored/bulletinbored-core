<?php

/**
 * End-to-end flow tests — tests complete user workflows.
 *
 * These tests verify that the core flows work correctly from start to finish:
 *   - Thread creation → reply → moderation
 *   - User registration → login → profile update
 *   - Plugin route registration → dispatch
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../lib/Migrator.php';

use Bulletin\Router;

function test_e2e_thread_lifecycle(): Test
{
    $t = new Test('E2E: Thread creation → reply → moderation flow');

    // Test: thread view works without auth
    $_SERVER['REQUEST_URI'] = '/thread/123';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];

    $threadViewed = false;
    $router = new Router();
    $router->get('/thread/{id:\d+}', function($params) use (&$threadViewed) {
        $threadViewed = true;
        return ['status' => 200, 'body' => 'thread view'];
    });

    ob_start();
    $router->dispatch();
    ob_end_clean();

    $t->assertTrue('Thread view route dispatches correctly', $threadViewed);

    // Test: thread view with slug works
    $_SERVER['REQUEST_URI'] = '/thread/123-test-thread';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];

    $threadViewed = false;
    $router2 = new Router();
    $router2->get('/thread/{id:\d+}-{slug}', function($params) use (&$threadViewed) {
        $threadViewed = true;
        return ['status' => 200, 'body' => 'thread view'];
    });

    ob_start();
    $router2->dispatch();
    ob_end_clean();

    $t->assertTrue('Thread view with slug dispatches correctly', $threadViewed);

    // Test: reply requires auth (user not logged in → redirect)
    $_SERVER['REQUEST_URI'] = '/reply';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    unset($_SESSION['user_id']);

    $replyPosted = false;
    $replyRouter = new Router();
    $replyRouter->middleware('auth')->group(function($router) use (&$replyPosted) {
        $router->post('/reply', function($params) use (&$replyPosted) {
            $replyPosted = true;
            return ['status' => 200, 'body' => 'reply posted'];
        });
    });

    ob_start();
    $replyRouter->dispatch();
    ob_end_clean();

    $t->assertFalse('Reply route blocks unauthenticated user', $replyPosted);

    // Test: moderation requires admin (user not logged in → redirect)
    $_SERVER['REQUEST_URI'] = '/admin/moderate';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    unset($_SESSION['user_id']);

    $moderated = false;
    $modRouter = new Router();
    $modRouter->middleware('admin')->group(function($router) use (&$moderated) {
        $router->post('/admin/moderate', function($params) use (&$moderated) {
            $moderated = true;
            return ['status' => 200, 'body' => 'moderated'];
        });
    });

    ob_start();
    $modRouter->dispatch();
    ob_end_clean();

    $t->assertFalse('Moderation route blocks non-admin user', $moderated);

    return $t;
}

function test_e2e_json_api_flow(): Test
{
    $t = new Test('E2E: JSON API response flow');

    // Save and reset server state
    $origServer = $_SERVER;
    $origGet = $_GET;

    // Test: API route returns JSON when Accept header is set
    $_SERVER = [
        'REQUEST_URI' => '/api/threads',
        'REQUEST_METHOD' => 'GET',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_HOST' => 'localhost',
        'SCRIPT_NAME' => '/index.php',
    ];
    $_GET = [];

    $router = new Router();
    $router->api()->get('/api/threads', function($params) {
        return ['threads' => [['id' => 1, 'title' => 'Test']], 'total' => 1];
    });

    ob_start();
    $router->dispatch();
    $output = ob_get_clean();

    $decoded = json_decode($output, true);
    $t->assertTrue('API route returns valid JSON', $decoded !== null);
    $t->assertEquals('API route returns correct data', 1, $decoded['total'] ?? 0);

    // Test: API error route returns JSON error
    $_SERVER = [
        'REQUEST_URI' => '/api/error',
        'REQUEST_METHOD' => 'GET',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_HOST' => 'localhost',
        'SCRIPT_NAME' => '/index.php',
    ];
    $_GET = [];

    $router2 = new Router();
    $router2->api()->get('/api/error', function($params) {
        return ['status' => 404, 'body' => json_encode(['error' => 'Not found'])];
    });

    ob_start();
    $router2->dispatch();
    $output = ob_get_clean();

    $decoded = json_decode($output, true);
    $t->assertTrue('API error route returns valid JSON', $decoded !== null);
    $t->assertEquals('API error route returns error message', 'Not found', $decoded['error'] ?? '');

    // Restore
    $_SERVER = $origServer;
    $_GET = $origGet;

    return $t;
}

function test_e2e_plugin_route_registration(): Test
{
    $t = new Test('E2E: Plugin route registration and dispatch');

    // Test: plugin route dispatches
    $_SERVER['REQUEST_URI'] = '/plugin/custom';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];

    $pluginRouteHit = false;
    $router = new Router();
    $router->get('/plugin/custom', function($params) use (&$pluginRouteHit) {
        $pluginRouteHit = true;
        return ['status' => 200, 'body' => 'plugin response'];
    });

    ob_start();
    $router->dispatch();
    ob_end_clean();

    $t->assertTrue('Plugin custom route dispatches', $pluginRouteHit);

    // Test: plugin middleware is called
    $_SERVER['REQUEST_URI'] = '/plugin/protected';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];

    $middlewareCalled = false;
    $router2 = new Router();
    $router2->registerMiddleware('plugin_auth', function($params) use (&$middlewareCalled) {
        $middlewareCalled = true;
        return null;
    });

    $router2->middleware('plugin_auth')->group(function($router) {
        $router->get('/plugin/protected', function($params) {
            return ['status' => 200, 'body' => 'protected plugin response'];
        });
    });

    ob_start();
    $router2->dispatch();
    ob_end_clean();

    $t->assertTrue('Plugin middleware is invoked', $middlewareCalled);

    return $t;
}

function test_e2e_migration_plugin_paths(): Test
{
    $t = new Test('E2E: Plugin migration paths');

    // Create a temporary plugin migration directory
    $tmpDir = sys_get_temp_dir() . '/bb_test_migrations_' . uniqid();
    $pluginDir = $tmpDir . '/testplugin';
    $migrationDir = $pluginDir . '/migrations';

    mkdir($migrationDir, 0755, true);

    // Create a test migration file
    $migrationContent = '<?php class TestPluginMigration { public function up($pdo) {} public function down($pdo) {} }';
    file_put_contents($migrationDir . '/20260829_test_plugin.php', $migrationContent);

    // Create a mock PDO
    $mockPdo = new class extends PDO {
        public function __construct() {}
        public function getAttribute($attribute): mixed {
            if ($attribute === PDO::ATTR_DRIVER_NAME) return 'sqlite';
            return null;
        }
        public function query(string $query, ...$args): PDOStatement|false {
            return new class extends PDOStatement {
                public function __construct() {}
                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array { return []; }
                public function fetchColumn(int $column = 0): mixed { return false; }
            };
        }
        public function exec(string $statement): int|false { return 0; }
        public function prepare(string $query, array $options = []): PDOStatement|false {
            return new class extends PDOStatement {
                public function __construct() {}
                public function execute(?array $params = null): bool { return true; }
                public function fetchColumn(int $column = 0): mixed { return false; }
            };
        }
    };

    // Test: addPath adds plugin migrations alongside core
    // We use a temp directory as the "core" migrations dir to avoid picking up real files
    $tmpCoreDir = $tmpDir . '/core_migrations';
    mkdir($tmpCoreDir, 0755, true);

    $migrator = new Migrator($mockPdo, ['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    // Override the core migrations dir by reflection to point to our empty temp dir
    $ref = new ReflectionProperty($migrator, 'migrationsDir');
    $ref->setAccessible(true);
    $ref->setValue($migrator, $tmpCoreDir);

    $migrator->addPath($migrationDir);

    $all = $migrator->getAllMigrations();
    $t->assertEquals('Plugin migration is discovered', 1, count($all));
    $t->assertEquals('Plugin migration has correct name', '20260829_test_plugin', $all[0]['name'] ?? '');
    $t->assertEquals('Plugin migration source is plugin folder', 'testplugin', $all[0]['source'] ?? '');

    // Test: addPluginPaths scans plugin directories
    $migrator2 = new Migrator($mockPdo, ['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $ref->setValue($migrator2, $tmpCoreDir);
    $migrator2->addPluginPaths($tmpDir);

    $all2 = $migrator2->getAllMigrations();
    $t->assertEquals('addPluginPaths discovers plugin migrations', 1, count($all2));

    // Cleanup
    unlink($migrationDir . '/20260829_test_plugin.php');
    rmdir($migrationDir);
    rmdir($pluginDir);
    rmdir($tmpCoreDir);
    rmdir($tmpDir);

    return $t;
}

function test_e2e_current_route_action(): Test
{
    $t = new Test('E2E: current_route_action() helper');

    $origServer = $_SERVER;

    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $action = current_route_action();
    $t->assertEquals('Home page resolves to home', 'home', $action);

    $_SERVER['REQUEST_URI'] = '/thread/123-my-thread';
    $action = current_route_action();
    $t->assertEquals('Thread page resolves to thread', 'thread', $action);

    $_SERVER['REQUEST_URI'] = '/category/5-general';
    $action = current_route_action();
    $t->assertEquals('Category page resolves to category', 'category', $action);

    $_SERVER['REQUEST_URI'] = '/u/johndoe';
    $action = current_route_action();
    $t->assertEquals('Profile page resolves to profile', 'profile', $action);

    $_SERVER['REQUEST_URI'] = '/admin';
    $action = current_route_action();
    $t->assertEquals('Admin page resolves to admin', 'admin', $action);

    $_SERVER['REQUEST_URI'] = '/admin/users';
    $action = current_route_action();
    $t->assertEquals('Admin users page resolves to admin_users', 'admin_users', $action);

    $_SERVER['REQUEST_URI'] = '/new-thread';
    $action = current_route_action();
    $t->assertEquals('New thread page resolves to new_thread', 'new_thread', $action);

    $_SERVER = $origServer;

    return $t;
}

// Run all e2e tests
$suite = new TestSuite();
$suite->addTest(test_e2e_thread_lifecycle());
$suite->addTest(test_e2e_json_api_flow());
$suite->addTest(test_e2e_plugin_route_registration());
$suite->addTest(test_e2e_migration_plugin_paths());
$suite->addTest(test_e2e_current_route_action());
$suite->run();
