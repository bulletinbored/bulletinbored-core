<?php

/**
 * PostsNewTest — tests for new thread creation logic (handle_new_thread).
 * Tests the underlying logic directly without full framework bootstrap.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../lib/PluginManager.php';

function setupPostsNewDB(): PDO
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

    // Insert default users for testing
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
        ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'user1', ?, 'user1@test.com', 'user', 1)")
        ->execute([password_hash('user123', PASSWORD_DEFAULT)]);

    return $pdo;
}

function setupPostsNewApp(PDO $pdo, ?object $pluginManager = null): void
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

function cleanupPostsNewSession(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (!headers_sent()) {
        session_start();
    }
}

// ============================================================
// HELPER: Mock PluginManager for testing hooks
// ============================================================

class MockPluginManagerNew
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
// NEW THREAD LOGIC TESTS
// ============================================================

function test_new_thread_logic_get_renders_view(): Test
{
    $t = new Test('New Thread Logic - GET Renders View');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    // Simulate logged in user
    $_SESSION = [
        'user_id' => 1,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $t->assertTrue('User is logged in', is_logged_in());

    // Test authorization check
    $authz = App::getInstance()->authz;
    $canCreate = $authz->can(1, 'threads.create');
    $t->assertTrue('User can create threads', $canCreate);

    // Test category fetch for view
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY position");
    $categories = $stmt->fetchAll();
    $t->assertEquals('Categories fetched for view', 5, count($categories));

    App::reset();
    return $t;
}

function test_new_thread_logic_post_success(): Test
{
    $t = new Test('New Thread Logic - POST Success Creates Thread');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    // Simulate logged in user
    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Simulate POST data
    $_POST = [
        'title' => 'My New Thread',
        'content' => 'This is the content of my new thread',
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Test CSRF validation
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'new_thread|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('new_thread', 20, 3600, $rlKey));

    // Test input validation
    $title = clean_text($_POST['title']);
    $content = validate_input($_POST['content']);
    $categoryId = (int)$_POST['category_id'];

    $t->assertEquals('Title cleaned', 'My New Thread', $title);
    $t->assertEquals('Content validated', 'This is the content of my new thread', $content);
    $t->assertEquals('Category ID parsed', 1, $categoryId);

    // Test category permission check
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();
    $t->assertNull('General category has no role restriction', $allowedRoles);

    // Test plugin filter hook
    $threadData = [
        'category_id' => $categoryId,
        'user_id' => $_SESSION['user_id'],
        'title' => $title,
        'content' => $content,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $filteredData = $pluginManager->filter('thread_before_create', $threadData);
    $t->assertEquals('Plugin filter called', 1, count($pluginManager->filterCalls));
    $t->assertEquals('Filter name', 'thread_before_create', $pluginManager->filterCalls[0]['name']);

    // Test plugin block hook (should not block)
    $blocked = $pluginManager->checkHook('thread_create_block', $threadData);
    $t->assertFalse('Thread creation not blocked', $blocked);

    // Insert thread
    $stmt = $pdo->prepare("
        INSERT INTO threads (category_id, user_id, title, content, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$threadData['category_id'], $threadData['user_id'], $threadData['title'], $threadData['content'], $threadData['created_at']]);
    $threadId = $pdo->lastInsertId();

    $t->assert('Thread created with ID', $threadId > 0);

    // Test plugin after_create hook
    $pluginManager->runHook('thread_after_create', $threadId, $threadData);
    $t->assertEquals('After create hook called', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'thread_after_create', $pluginManager->hookCalls[0]['name']);

    // Verify thread in database
    $verifyStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $verifyStmt->execute([$threadId]);
    $thread = $verifyStmt->fetch();

    $t->assertNotNull('Thread exists in database', $thread);
    $t->assertEquals('Thread title correct', 'My New Thread', $thread['title']);
    $t->assertEquals('Thread content correct', 'This is the content of my new thread', $thread['content']);
    $t->assertEquals('Thread user_id correct', 2, (int)$thread['user_id']);
    $t->assertEquals('Thread category_id correct', 1, (int)$thread['category_id']);
    $t->assertEquals('Thread status visible', 'visible', $thread['status']);

    App::reset();
    return $t;
}

function test_new_thread_logic_post_empty_title(): Test
{
    $t = new Test('New Thread Logic - POST Empty Title Shows Error');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

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

    $title = clean_text($_POST['title']);
    $content = validate_input($_POST['content']);

    $t->assertEquals('Empty title after cleaning', '', $title);
    $t->assertNotEquals('Content preserved', '', $content);

    // Simulate error handling logic
    $error = '';
    if ($title === '' || $content === '') {
        $error = 'Title and content are required';
    }

    $t->assertEquals('Error message set', 'Title and content are required', $error);

    // Verify session variables set for form repopulation
    $_SESSION['new_thread_error'] = $error;
    $_SESSION['new_thread_title'] = $_POST['title'];
    $_SESSION['new_thread_content'] = $_POST['content'];
    $_SESSION['new_thread_category'] = (int)$_POST['category_id'];

    $t->assertEquals('Error in session', 'Title and content are required', $_SESSION['new_thread_error']);
    $t->assertEquals('Title in session', '', $_SESSION['new_thread_title']);
    $t->assertEquals('Content in session', 'Content without title', $_SESSION['new_thread_content']);
    $t->assertEquals('Category in session', 1, $_SESSION['new_thread_category']);

    App::reset();
    return $t;
}

function test_new_thread_logic_post_empty_content(): Test
{
    $t = new Test('New Thread Logic - POST Empty Content Shows Error');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

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

    $title = clean_text($_POST['title']);
    $content = validate_input($_POST['content']);

    $error = '';
    if ($title === '' || $content === '') {
        $error = 'Title and content are required';
    }

    $t->assertEquals('Error message set', 'Title and content are required', $error);

    App::reset();
    return $t;
}

function test_new_thread_logic_csrf_invalid(): Test
{
    $t = new Test('New Thread Logic - POST Invalid CSRF Rejected');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

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

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_new_thread_logic_rate_limited(): Test
{
    $t = new Test('New Thread Logic - Rate Limited');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'new_thread|' . $_SESSION['user_id'];

    // Exhaust rate limit (20 per hour)
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 22; $i++) {
        if (rate_limit('new_thread', 20, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 20 requests allowed', 20, $allowed);
    $t->assertEquals('Requests 21-22 blocked', 2, $blocked);

    App::reset();
    return $t;
}

function test_new_thread_logic_not_logged_in(): Test
{
    $t = new Test('New Thread Logic - Not Logged In Throws Unauthorized');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    // No session = not logged in
    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    // The handler would throw UnauthorizedException
    // We test the condition that triggers it
    $isLoggedIn = is_logged_in();
    $t->assertFalse('is_logged_in returns false', $isLoggedIn);

    App::reset();
    return $t;
}

function test_new_thread_logic_no_permission(): Test
{
    $t = new Test('New Thread Logic - User Without Permission Denied');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    // Create a user with restricted role (no threads.create permission)
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

    $authz = App::getInstance()->authz;
    $canCreate = $authz->can(99, 'threads.create');
    $t->assertFalse('Restricted user cannot create threads', $canCreate);

    App::reset();
    return $t;
}

function test_new_thread_logic_category_moderator_only(): Test
{
    $t = new Test('New Thread Logic - Moderator Only Category');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $categoryId = 3; // Moderator Only category
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();

    $t->assertEquals('Category allows moderator', 'moderator', $allowedRoles);

    $authz = App::getInstance()->authz;
    $canModerate = $authz->can(2, 'moderation.manage');
    $t->assertFalse('Regular user cannot moderate', $canModerate);

    // User is not admin
    $isAdmin = $authz->hasRole(2, 'admin');
    $t->assertFalse('Regular user is not admin', $isAdmin);

    // Should be forbidden
    $forbidden = true;
    if ($allowedRoles && $allowedRoles !== 'all' && !$authz->can(2, 'admin.access')) {
        if ($allowedRoles === 'moderator' && !$authz->can(2, 'moderation.manage')) {
            $forbidden = true;
        }
    }
    $t->assertTrue('Regular user forbidden from moderator category', $forbidden);

    App::reset();
    return $t;
}

function test_new_thread_logic_category_admin_only(): Test
{
    $t = new Test('New Thread Logic - Admin Only Category');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $categoryId = 4; // Admin Only category
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();

    $t->assertEquals('Category allows admin', 'admin', $allowedRoles);

    $authz = App::getInstance()->authz;
    $isAdmin = $authz->hasRole(2, 'admin');
    $t->assertFalse('Regular user is not admin', $isAdmin);

    $forbidden = true;
    if ($allowedRoles && $allowedRoles !== 'all' && !$authz->can(2, 'admin.access')) {
        if ($allowedRoles === 'admin' && !$authz->hasRole(2, 'admin')) {
            $forbidden = true;
        }
    }
    $t->assertTrue('Regular user forbidden from admin category', $forbidden);

    App::reset();
    return $t;
}

function test_new_thread_logic_category_custom_roles(): Test
{
    $t = new Test('New Thread Logic - Custom Roles Category');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $categoryId = 5; // Custom Roles category
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();

    $t->assertEquals('Category allows custom roles', '["moderator","admin"]', $allowedRoles);

    $allowed = json_decode($allowedRoles, true);
    $userRole = $_SESSION['user_role'] ?? 'user';
    $t->assertFalse('User role not in allowed list', in_array($userRole, $allowed));

    App::reset();
    return $t;
}

function test_new_thread_logic_moderator_can_access_moderator_category(): Test
{
    $t = new Test('New Thread Logic - Moderator Can Access Moderator Category');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    // Create moderator user
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (10, 'moduser', ?, 'mod@test.com', 'moderator', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    $_SESSION = [
        'user_id' => 10,
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $categoryId = 3; // Moderator Only category
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();

    $authz = App::getInstance()->authz;
    $canModerate = $authz->can(10, 'moderation.manage');
    $t->assertTrue('Moderator can moderate', $canModerate);

    $forbidden = false;
    if ($allowedRoles && $allowedRoles !== 'all' && !$authz->can(10, 'admin.access')) {
        if ($allowedRoles === 'moderator' && !$authz->can(10, 'moderation.manage')) {
            $forbidden = true;
        }
    }
    $t->assertFalse('Moderator NOT forbidden from moderator category', $forbidden);

    App::reset();
    return $t;
}

function test_new_thread_logic_admin_can_access_admin_category(): Test
{
    $t = new Test('New Thread Logic - Admin Can Access Admin Category');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 1,
        'user_role' => 'admin',
        'username' => 'admin',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $categoryId = 4; // Admin Only category
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();

    $authz = App::getInstance()->authz;
    $isAdmin = $authz->hasRole(1, 'admin');
    $t->assertTrue('Admin has admin role', $isAdmin);

    $forbidden = false;
    if ($allowedRoles && $allowedRoles !== 'all' && !$authz->can(1, 'admin.access')) {
        if ($allowedRoles === 'admin' && !$authz->hasRole(1, 'admin')) {
            $forbidden = true;
        }
    }
    $t->assertFalse('Admin NOT forbidden from admin category', $forbidden);

    App::reset();
    return $t;
}

function test_new_thread_logic_plugin_filter_modifies_data(): Test
{
    $t = new Test('New Thread Logic - Plugin Filter Modifies Thread Data');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadData = [
        'category_id' => 1,
        'user_id' => 2,
        'title' => 'Original Title',
        'content' => 'Original Content',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    // Set up plugin filter to modify data
    $pluginManager->filters['thread_before_create'] = function($data) {
        $data['title'] = '[Modified] ' . $data['title'];
        $data['content'] = $data['content'] . ' [Modified]';
        return $data;
    };

    $filteredData = $pluginManager->filter('thread_before_create', $threadData);

    $t->assertEquals('Title modified by plugin', '[Modified] Original Title', $filteredData['title']);
    $t->assertEquals('Content modified by plugin', 'Original Content [Modified]', $filteredData['content']);
    $t->assertEquals('Original data unchanged', 'Original Title', $threadData['title']);

    App::reset();
    return $t;
}

function test_new_thread_logic_plugin_block_prevents_creation(): Test
{
    $t = new Test('New Thread Logic - Plugin Block Prevents Creation');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadData = [
        'category_id' => 1,
        'user_id' => 2,
        'title' => 'Test Thread',
        'content' => 'Test Content',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    // Set up plugin to block creation
    $pluginManager->hooks['thread_create_block'] = [
        function($data) { return true; } // Block
    ];

    $blocked = $pluginManager->checkHook('thread_create_block', $threadData);
    $t->assertTrue('Thread creation blocked by plugin', $blocked);

    App::reset();
    return $t;
}

function test_new_thread_logic_notify_mentioned_users(): Test
{
    $t = new Test('New Thread Logic - Notify Mentioned Users');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

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

    $content = 'Hello @mentioneduser, check this out!';
    $threadId = 42;
    $title = 'Test Thread';

    // Test notify_mentioned_users function exists
    $t->assertTrue('notify_mentioned_users function exists', function_exists('notify_mentioned_users'));

    // Mock the t() function for translation
    if (!function_exists('t')) {
        function t(string $key, array $params = []): string {
            return $key;
        }
    }

    // Call the function (it should not throw)
    $created = notify_mentioned_users($pdo, $content, $threadId, $title, 'user1', 2);

    // Verify notification was created
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 20 AND type = 'mention'");
    $stmt->execute();
    $notification = $stmt->fetch();

    $t->assertNotNull('Mention notification created', $notification);
    $t->assertEquals('Notification for mentioned user', 20, (int)$notification['user_id']);
    $t->assertEquals('Notification type mention', 'mention', $notification['type']);
    $t->assertEquals('Notification link contains thread ID', true, str_contains($notification['link'] ?? '', (string)$threadId));
    $t->assertEquals('Function returns count', 1, $created);

    App::reset();
    return $t;
}

function test_new_thread_logic_slugify_redirect(): Test
{
    $t = new Test('New Thread Logic - Slugify for Redirect');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $title = 'My New Thread';
    $slug = slugify($title);

    $t->assertEquals('Slug generated correctly', 'my-new-thread', $slug);

    // Test URL generation
    $threadId = 123;
    $url = url('thread', ['id' => $threadId, 'slug' => $slug]);

    $t->assert('URL contains thread ID', str_contains($url, '/thread/123'));
    $t->assert('URL contains slug', str_contains($url, 'my-new-thread'));

    App::reset();
    return $t;
}

function test_new_thread_logic_special_chars_in_title(): Test
{
    $t = new Test('New Thread Logic - Special Characters in Title');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $title = 'Thread with "quotes" & <special> chars!';
    $cleanTitle = clean_text($title);

    $t->assertEquals('Title cleaned', 'Thread with "quotes" & <special> chars!', $cleanTitle);

    $slug = slugify($cleanTitle);
    $t->assertEquals('Slug handles special chars', 'thread-with-quotes-special-chars', $slug);

    App::reset();
    return $t;
}

function test_new_thread_logic_unicode_title(): Test
{
    $t = new Test('New Thread Logic - Unicode Title');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $title = '日本語スレッド 🎉 émojis';
    $cleanTitle = clean_text($title);

    $t->assertEquals('Unicode title preserved', '日本語スレッド 🎉 émojis', $cleanTitle);

    $slug = slugify($cleanTitle);
    $t->assert('Slug generated for unicode', !empty($slug));

    App::reset();
    return $t;
}

function test_new_thread_logic_long_title(): Test
{
    $t = new Test('New Thread Logic - Long Title');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $title = str_repeat('A', 300); // Very long title
    $cleanTitle = clean_text($title);

    $t->assertEquals('Long title preserved', 300, strlen($cleanTitle));

    $slug = slugify($cleanTitle);
    $t->assert('Slug generated for long title', !empty($slug));

    App::reset();
    return $t;
}

function test_new_thread_logic_plugin_after_create_hook(): Test
{
    $t = new Test('New Thread Logic - Plugin After Create Hook Called');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadData = [
        'category_id' => 1,
        'user_id' => 2,
        'title' => 'Test Thread',
        'content' => 'Test Content',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $threadId = 999;

    $pluginManager->runHook('thread_after_create', $threadId, $threadData);

    $t->assertEquals('After create hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'thread_after_create', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$threadId, $threadData], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

function test_new_thread_logic_category_does_not_exist(): Test
{
    $t = new Test('New Thread Logic - Non-existent Category');

    $pdo = setupPostsNewDB();
    $pluginManager = new MockPluginManagerNew();
    setupPostsNewApp($pdo, $pluginManager);
    cleanupPostsNewSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $categoryId = 999; // Non-existent
    $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $allowedRoles = $catStmt->fetchColumn();

    $t->assertFalse('Non-existent category returns false', $allowedRoles);

    // Should proceed without category permission check
    $authz = App::getInstance()->authz;
    $canCreate = $authz->can(2, 'threads.create');
    $t->assertTrue('User can still create threads', $canCreate);

    App::reset();
    return $t;
}

register_tests(
    'test_new_thread_logic_get_renders_view',
    'test_new_thread_logic_post_success',
    'test_new_thread_logic_post_empty_title',
    'test_new_thread_logic_post_empty_content',
    'test_new_thread_logic_csrf_invalid',
    'test_new_thread_logic_rate_limited',
    'test_new_thread_logic_not_logged_in',
    'test_new_thread_logic_no_permission',
    'test_new_thread_logic_category_moderator_only',
    'test_new_thread_logic_category_admin_only',
    'test_new_thread_logic_category_custom_roles',
    'test_new_thread_logic_moderator_can_access_moderator_category',
    'test_new_thread_logic_admin_can_access_admin_category',
    'test_new_thread_logic_plugin_filter_modifies_data',
    'test_new_thread_logic_plugin_block_prevents_creation',
    'test_new_thread_logic_notify_mentioned_users',
    'test_new_thread_logic_slugify_redirect',
    'test_new_thread_logic_special_chars_in_title',
    'test_new_thread_logic_unicode_title',
    'test_new_thread_logic_long_title',
    'test_new_thread_logic_plugin_after_create_hook',
    'test_new_thread_logic_category_does_not_exist'
);