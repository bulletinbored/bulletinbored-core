<?php

/**
 * PostsNewIntegrationTest — integration tests for handle_new_thread that actually execute the source file.
 * These tests include the actual handler and execute it to achieve line coverage.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../lib/PluginManager.php';

function setupPostsNewIntegrationDB(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            session_version INTEGER DEFAULT 1,
            email_verified INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0
        );
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]'
        );
        CREATE TABLE uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            thread_id INTEGER,
            post_id INTEGER,
            filename TEXT,
            original_name TEXT,
            size INTEGER,
            mime_type TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE thread_watchers (
            thread_id INTEGER,
            user_id INTEGER,
            PRIMARY KEY (thread_id, user_id)
        );
        CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT,
            message TEXT,
            link TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Insert default roles with permissions
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'user',
        json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'moderator',
        json_encode(['threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'moderation.manage'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'admin',
        json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy'])
    ]);

    // Insert default categories
    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (1, 'General', 'General discussion', 1)")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (2, 'Tech', 'Technology', 2)")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (3, 'Moderator Only', 'Moderators only', 3, 'moderator')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (4, 'Admin Only', 'Admins only', 4, 'admin')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (5, 'Custom Roles', 'Custom roles', 5, '[\"moderator\",\"admin\"]')")->execute();

    // Insert default users
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
        ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'user1', ?, 'user1@test.com', 'user', 1)")
        ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (10, 'moduser', ?, 'mod@test.com', 'moderator', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    return $pdo;
}

function setupPostsNewIntegrationApp(PDO $pdo, ?object $pluginManager = null): void
{
    App::reset();
    $app = App::getInstance();
    $app->pdo = $pdo;
    $app->config = [
        'site_name' => 'Test Forum',
        'base_url' => 'http://localhost',
        'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'avatar_max_size_mb' => 2,
        'image_max_size_mb' => 5,
        'posts_per_page' => 15,
    ];
    $app->authz = new AuthZ($pdo, $pluginManager);
    $app->pluginManager = $pluginManager;
}

function cleanupPostsNewIntegrationSession(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (!headers_sent()) {
        session_start();
    }
}

// Mock PluginManager for testing hooks
class MockPluginManagerIntegration
{
    public array $filters = [];
    public array $hooks = [];
    public array $filterCalls = [];
    public array $hookCalls = [];

    public function filter(string $name, $data, ...$args)
    {
        $this->filterCalls[] = ['name' => $name, 'data' => $data, 'args' => $args];
        if (isset($this->filters[$name])) {
            return $this->filters[$name]($data, ...$args);
        }
        return $data;
    }

    public function runHook(string $name, ...$args): void
    {
        $this->hookCalls[] = ['name' => $name, 'args' => $args];
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $hook) {
                $hook(...$args);
            }
        }
    }

    public function checkHook(string $name, ...$args): bool
    {
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $hook) {
                if ($hook(...$args)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function applyHook(string $name, ...$args)
    {
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $hook) {
                $result = $hook(...$args);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }
}

// ============================================================
// INTEGRATION TESTS - Actually execute handle_new_thread
// ============================================================

function test_handle_new_thread_get_renders_view(): Test
{
    $t = new Test('Integration - handle_new_thread GET Renders View');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];

    // Capture output
    ob_start();
    require_once __DIR__ . '/../src/actions/posts-new.php';
    $result = handle_new_thread('GET');
    $output = ob_get_clean();

    $t->assertEquals('Function returns true', true, $result);
    $t->assert('Output contains form', str_contains($output, '<form'));

    App::reset();
    return $t;
}

function test_handle_new_thread_post_success(): Test
{
    $t = new Test('Integration - handle_new_thread POST Success');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => 'Integration Test Thread',
        'content' => 'This is integration test content',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Capture output (should be redirect)
    ob_start();
    require_once __DIR__ . '/../src/actions/posts-new.php';
    $result = handle_new_thread('POST');
    $output = ob_get_clean();

    // Should return redirect response
    $t->assert('Result is Response object', $result instanceof \Bulletin\Response);
    if ($result instanceof \Bulletin\Response) {
        $t->assertEquals('Redirect status', 302, $result->getStatus());
    }

    // Verify thread created in database
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
    $stmt->execute(['Integration Test Thread']);
    $thread = $stmt->fetch();
    $t->assertNotNull('Thread created in database', $thread);
    $t->assertEquals('Thread content correct', 'This is integration test content', $thread['content']);
    $t->assertEquals('Thread user_id correct', 2, (int)$thread['user_id']);

    App::reset();
    return $t;
}

function test_handle_new_thread_post_empty_title(): Test
{
    $t = new Test('Integration - handle_new_thread POST Empty Title');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => '',
        'content' => 'Content without title',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    $result = require_once __DIR__ . '/../src/actions/posts-new.php';
    $output = ob_get_clean();

    $t->assertEquals('Function returns true (renders view with error)', true, $result);
    $t->assert('Output contains error message', str_contains($output, 'Title and content are required'));
    $t->assert('Output contains form', str_contains($output, '<form'));

    // Verify no thread created
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    $t->assertEquals('No thread created', 0, $count);

    App::reset();
    return $t;
}

function test_handle_new_thread_post_empty_content(): Test
{
    $t = new Test('Integration - handle_new_thread POST Empty Content');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => 'Title without content',
        'content' => '',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    $result = require_once __DIR__ . '/../src/actions/posts-new.php';
    $output = ob_get_clean();

    $t->assertEquals('Function returns true', true, $result);
    $t->assert('Output contains error message', str_contains($output, 'Title and content are required'));

    App::reset();
    return $t;
}

function test_handle_new_thread_post_csrf_invalid(): Test
{
    $t = new Test('Integration - handle_new_thread POST Invalid CSRF');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => 'Valid Title',
        'content' => 'Valid Content',
        'category_id' => '1',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $thrown = false;
    try {
        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    } catch (\Bulletin\ForbiddenException $e) {
        $thrown = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }

    $t->assertTrue('ForbiddenException thrown', $thrown);

    App::reset();
    return $t;
}

function test_handle_new_thread_not_logged_in(): Test
{
    $t = new Test('Integration - handle_new_thread Not Logged In');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $thrown = false;
    try {
        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    } catch (\Bulletin\UnauthorizedException $e) {
        $thrown = true;
    }

    $t->assertTrue('UnauthorizedException thrown', $thrown);

    App::reset();
    return $t;
}

function test_handle_new_thread_no_permission(): Test
{
    $t = new Test('Integration - handle_new_thread No Permission');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    // Create restricted user
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (99, 'restricted', ?, 'restricted@test.com', 'restricted', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('restricted', '[]')")->execute();

    $_SESSION = [
        'user_id' => 99,
        'user_role' => 'restricted',
        'username' => 'restricted',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $thrown = false;
    try {
        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    } catch (\Bulletin\ForbiddenException $e) {
        $thrown = true;
        $t->assertEquals('Exception message', 'Not authorized to create threads', $e->getMessage());
    }

    $t->assertTrue('ForbiddenException thrown', $thrown);

    App::reset();
    return $t;
}

function test_handle_new_thread_category_moderator_only(): Test
{
    $t = new Test('Integration - handle_new_thread Moderator Only Category');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => 'Test Thread',
        'content' => 'Test Content',
        'category_id' => '3', // Moderator Only
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $thrown = false;
    try {
        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    } catch (\Bulletin\ForbiddenException $e) {
        $thrown = true;
        $t->assertEquals('Exception message', 'Not authorized for this category', $e->getMessage());
    }

    $t->assertTrue('ForbiddenException thrown', $thrown);

    App::reset();
    return $t;
}

function test_handle_new_thread_moderator_can_access_moderator_category(): Test
{
    $t = new Test('Integration - handle_new_thread Moderator Can Access Moderator Category');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 10,
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => 'Moderator Thread',
        'content' => 'Moderator Content',
        'category_id' => '3', // Moderator Only
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    $result = require_once __DIR__ . '/../src/actions/posts-new.php';
    $output = ob_get_clean();

    $t->assert('Result is Response object', $result instanceof \Bulletin\Response);
    $t->assertEquals('Redirect status', 302, $result->getStatus());

    // Verify thread created
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
    $stmt->execute(['Moderator Thread']);
    $thread = $stmt->fetch();
    $t->assertNotNull('Thread created by moderator', $thread);

    App::reset();
    return $t;
}

function test_handle_new_thread_admin_can_access_admin_category(): Test
{
    $t = new Test('Integration - handle_new_thread Admin Can Access Admin Category');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 1,
        'user_role' => 'admin',
        'username' => 'admin',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'title' => 'Admin Thread',
        'content' => 'Admin Content',
        'category_id' => '4', // Admin Only
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    $result = require_once __DIR__ . '/../src/actions/posts-new.php';
    $output = ob_get_clean();

    $t->assert('Result is Response object', $result instanceof \Bulletin\Response);
    $t->assertEquals('Redirect status', 302, $result->getStatus());

    // Verify thread created
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
    $stmt->execute(['Admin Thread']);
    $thread = $stmt->fetch();
    $t->assertNotNull('Thread created by admin', $thread);

    App::reset();
    return $t;
}

function test_handle_new_thread_plugin_filter_modifies_data(): Test
{
    $t = new Test('Integration - handle_new_thread Plugin Filter Modifies Data');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Set up plugin filter to modify data
    $pluginManager->filters['thread_before_create'] = function($data) {
        $data['title'] = '[Modified] ' . $data['title'];
        $data['content'] = $data['content'] . ' [Modified]';
        return $data;
    };

    $_POST = [
        'title' => 'Original Title',
        'content' => 'Original Content',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    $result = require_once __DIR__ . '/../src/actions/posts-new.php';
    $output = ob_get_clean();

    $t->assert('Result is Response object', $result instanceof \Bulletin\Response);

    // Verify modified thread created
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
    $stmt->execute(['[Modified] Original Title']);
    $thread = $stmt->fetch();
    $t->assertNotNull('Modified thread created', $thread);
    $t->assertEquals('Content also modified', 'Original Content [Modified]', $thread['content']);

    App::reset();
    return $t;
}

function test_handle_new_thread_plugin_block_prevents_creation(): Test
{
    $t = new Test('Integration - handle_new_thread Plugin Block Prevents Creation');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Set up plugin to block creation
    $pluginManager->hooks['thread_create_block'] = [
        function($data) { return true; } // Block
    ];

    $_POST = [
        'title' => 'Blocked Thread',
        'content' => 'Blocked Content',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $thrown = false;
    try {
        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    } catch (\Bulletin\ForbiddenException $e) {
        $thrown = true;
        $t->assertEquals('Exception message', 'Thread creation blocked', $e->getMessage());
    }

    $t->assertTrue('ForbiddenException thrown', $thrown);

    // Verify no thread created
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    $t->assertEquals('No thread created', 0, $count);

    App::reset();
    return $t;
}

function test_handle_new_thread_notify_mentioned_users(): Test
{
    $t = new Test('Integration - handle_new_thread Notify Mentioned Users');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    // Create mentioned user
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (20, 'mentioneduser', ?, 'mentioned@test.com', 'user', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Mock t() function
    if (!function_exists('t')) {
        function t(string $key, array $params = []): string {
            return $key;
        }
    }

    $_POST = [
        'title' => 'Test Thread',
        'content' => 'Hello @mentioneduser, check this out!',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    $result = require_once __DIR__ . '/../src/actions/posts-new.php';
    $output = ob_get_clean();

    $t->assert('Result is Response object', $result instanceof \Bulletin\Response);

    // Verify notification created
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 20 AND type = 'mention'");
    $stmt->execute();
    $notification = $stmt->fetch();
    $t->assertNotNull('Mention notification created', $notification);
    $t->assertEquals('Notification for mentioned user', 20, (int)$notification['user_id']);

    App::reset();
    return $t;
}

function test_handle_new_thread_rate_limited(): Test
{
    $t = new Test('Integration - handle_new_thread Rate Limited');

    $pdo = setupPostsNewIntegrationDB();
    $pluginManager = new MockPluginManagerIntegration();
    setupPostsNewIntegrationApp($pdo, $pluginManager);
    cleanupPostsNewIntegrationSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Exhaust rate limit (20 per hour)
    for ($i = 0; $i < 20; $i++) {
        $_POST = [
            'title' => "Thread $i",
            'content' => "Content $i",
            'category_id' => '1',
            'csrf_token' => generate_csrf_token(),
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    }

    // 21st request should be rate limited
    $_POST = [
        'title' => 'Rate Limited Thread',
        'content' => 'Rate Limited Content',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $thrown = false;
    try {
        ob_start();
        require_once __DIR__ . '/../src/actions/posts-new.php';
        ob_get_clean();
    } catch (\Bulletin\TooManyRequestsException $e) {
        $thrown = true;
        $t->assertEquals('Exception message', 'You are posting too fast. Please try again later.', $e->getMessage());
    }

    $t->assertTrue('TooManyRequestsException thrown', $thrown);

    App::reset();
    return $t;
}

register_tests(
    'test_handle_new_thread_get_renders_view',
    'test_handle_new_thread_post_success',
    'test_handle_new_thread_post_empty_title',
    'test_handle_new_thread_post_empty_content',
    'test_handle_new_thread_post_csrf_invalid',
    'test_handle_new_thread_not_logged_in',
    'test_handle_new_thread_no_permission',
    'test_handle_new_thread_category_moderator_only',
    'test_handle_new_thread_moderator_can_access_moderator_category',
    'test_handle_new_thread_admin_can_access_admin_category',
    'test_handle_new_thread_plugin_filter_modifies_data',
    'test_handle_new_thread_plugin_block_prevents_creation',
    'test_handle_new_thread_notify_mentioned_users',
    'test_handle_new_thread_rate_limited'
);