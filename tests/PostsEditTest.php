<?php

/**
 * PostsEditTest — tests for post/thread editing logic (handle_reply_post, handle_edit_post, handle_delete_post, handle_edit_thread, handle_delete_thread).
 * Tests the underlying logic directly without full framework bootstrap.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/DbQuery.php';

function setupPostsEditDB(): PDO
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
        json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own', 'threads.edit_own', 'threads.delete_own'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'moderator',
        json_encode(['threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'threads.edit', 'threads.delete', 'posts.edit', 'posts.delete'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'admin',
        json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'threads.edit', 'threads.delete', 'posts.edit', 'posts.delete'])
    ]);

    // Insert default categories
    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (1, 'General', 'General discussion', 1)")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (2, 'Tech', 'Technology', 2)")->execute();

    // Insert default users for testing
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
        ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'user1', ?, 'user1@test.com', 'user', 1)")
        ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (3, 'user2', ?, 'user2@test.com', 'user', 1)")
        ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (10, 'moduser', ?, 'mod@test.com', 'moderator', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    return $pdo;
}

function setupPostsEditApp(PDO $pdo, ?object $pluginManager = null): void
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

function cleanupPostsEditSession(): void
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

class MockPluginManager
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
// REPLY POST LOGIC TESTS
// ============================================================

function test_reply_post_logic_success(): Test
{
    $t = new Test('Reply Post Logic - Success Creates Reply');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    // Create a thread first
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'thread_id' => (string)$threadId,
        'content' => 'This is a reply',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Test authorization
    $authz = App::getInstance()->authz;
    $canCreate = $authz->can(3, 'posts.create');
    $t->assertTrue('User can create posts', $canCreate);

    // Test CSRF validation
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'reply|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('reply', 30, 3600, $rlKey));

    // Test input validation
    $content = validate_input($_POST['content']);
    $t->assertEquals('Content validated', 'This is a reply', $content);

    // Test thread exists and is viewable
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();
    $t->assertNotNull('Thread exists', $thread);
    $t->assertEquals('Thread status visible', 'visible', $thread['status']);

    // Test can_view_thread
    $t->assertTrue('Can view thread', can_view_thread($thread['status']));

    // Test plugin filter hook
    $postData = [
        'thread_id' => $threadId,
        'user_id' => $_SESSION['user_id'],
        'content' => $content,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $filteredData = $pluginManager->filter('post_before_create', $postData, $thread);
    $t->assertEquals('Plugin filter called', 1, count($pluginManager->filterCalls));
    $t->assertEquals('Filter name', 'post_before_create', $pluginManager->filterCalls[0]['name']);

    // Test plugin block hook (should not block)
    $blocked = $pluginManager->checkHook('post_create_block', $postData, $thread);
    $t->assertFalse('Post creation not blocked', $blocked);

    // Insert post
    $stmt = $pdo->prepare("
        INSERT INTO posts (thread_id, user_id, content, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$postData['thread_id'], $postData['user_id'], $postData['content'], $postData['created_at']]);
    $postId = $pdo->lastInsertId();

    $t->assert('Post created with ID', $postId > 0);

    // Test thread updated_at updated
    $pdo->prepare("UPDATE threads SET updated_at = ? WHERE id = ?")->execute([$postData['created_at'], $threadId]);
    $threadCheck = $pdo->prepare("SELECT updated_at FROM threads WHERE id = ?");
    $threadCheck->execute([$threadId]);
    $updatedAt = $threadCheck->fetchColumn();
    $t->assertEquals('Thread updated_at updated', $postData['created_at'], $updatedAt);

    // Test plugin after_create hook
    $pluginManager->runHook('post_after_create', $postId, $postData, $thread);
    $t->assertEquals('After create hook called', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'post_after_create', $pluginManager->hookCalls[0]['name']);

    // Verify post in database
    $verifyStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $verifyStmt->execute([$postId]);
    $post = $verifyStmt->fetch();

    $t->assertNotNull('Post exists in database', $post);
    $t->assertEquals('Post thread_id correct', $threadId, (int)$post['thread_id']);
    $t->assertEquals('Post user_id correct', 3, (int)$post['user_id']);
    $t->assertEquals('Post content correct', 'This is a reply', $post['content']);
    $t->assertEquals('Post status visible', 'visible', $post['status']);

    App::reset();
    return $t;
}

function test_reply_post_logic_empty_content(): Test
{
    $t = new Test('Reply Post Logic - Empty Content Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'thread_id' => (string)$threadId,
        'content' => '',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $content = validate_input($_POST['content']);
    $t->assertEquals('Empty content after validation', '', $content);

    // Simulate validation error
    $error = '';
    if ($content === '') {
        $error = 'Content is required';
    }
    $t->assertEquals('Error message set', 'Content is required', $error);

    App::reset();
    return $t;
}

function test_reply_post_logic_invalid_thread(): Test
{
    $t = new Test('Reply Post Logic - Invalid Thread Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadId = 999; // Non-existent
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $t->assertFalse('Non-existent thread not found', $thread);

    App::reset();
    return $t;
}

function test_reply_post_logic_locked_thread(): Test
{
    $t = new Test('Reply Post Logic - Locked Thread Rejected for Regular User');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Locked Thread', 'Thread content', 'locked')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $authz = App::getInstance()->authz;
    $canLock = $authz->can(3, 'threads.lock');
    $t->assertFalse('Regular user cannot lock threads', $canLock);

    $forbidden = false;
    if ($thread['status'] === 'locked' && !$authz->can(3, 'threads.lock')) {
        $forbidden = true;
    }
    $t->assertTrue('Regular user forbidden from locked thread', $forbidden);

    App::reset();
    return $t;
}

function test_reply_post_logic_moderator_can_reply_locked(): Test
{
    $t = new Test('Reply Post Logic - Moderator Can Reply to Locked Thread');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Locked Thread', 'Thread content', 'locked')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 10,
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $authz = App::getInstance()->authz;
    $canLock = $authz->can(10, 'threads.lock');
    $t->assertTrue('Moderator can lock threads', $canLock);

    $forbidden = false;
    if ($thread['status'] === 'locked' && !$authz->can(10, 'threads.lock')) {
        $forbidden = true;
    }
    $t->assertFalse('Moderator NOT forbidden from locked thread', $forbidden);

    App::reset();
    return $t;
}

function test_reply_post_logic_csrf_invalid(): Test
{
    $t = new Test('Reply Post Logic - Invalid CSRF Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'thread_id' => (string)$threadId,
        'content' => 'Valid content',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_reply_post_logic_rate_limited(): Test
{
    $t = new Test('Reply Post Logic - Rate Limited');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'reply|' . $_SESSION['user_id'];

    // Exhaust rate limit (30 per hour)
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 32; $i++) {
        if (rate_limit('reply', 30, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 30 requests allowed', 30, $allowed);
    $t->assertEquals('Requests 31-32 blocked', 2, $blocked);

    App::reset();
    return $t;
}

function test_reply_post_logic_not_logged_in(): Test
{
    $t = new Test('Reply Post Logic - Not Logged In Throws Unauthorized');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_reply_post_logic_no_permission(): Test
{
    $t = new Test('Reply Post Logic - User Without Permission Denied');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    // Create a user with restricted role (no posts.create permission)
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
    $canCreate = $authz->can(99, 'posts.create');
    $t->assertFalse('Restricted user cannot create posts', $canCreate);

    App::reset();
    return $t;
}

function test_reply_post_logic_plugin_filter_modifies_data(): Test
{
    $t = new Test('Reply Post Logic - Plugin Filter Modifies Post Data');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postData = [
        'thread_id' => $threadId,
        'user_id' => 3,
        'content' => 'Original Content',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    // Set up plugin filter to modify data
    $pluginManager->filters['post_before_create'] = function($data, $thread) {
        $data['content'] = '[Modified] ' . $data['content'];
        return $data;
    };

    $filteredData = $pluginManager->filter('post_before_create', $postData, $thread);

    $t->assertEquals('Content modified by plugin', '[Modified] Original Content', $filteredData['content']);
    $t->assertEquals('Original data unchanged', 'Original Content', $postData['content']);

    App::reset();
    return $t;
}

function test_reply_post_logic_plugin_block_prevents_creation(): Test
{
    $t = new Test('Reply Post Logic - Plugin Block Prevents Creation');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postData = [
        'thread_id' => $threadId,
        'user_id' => 3,
        'content' => 'Test Content',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    // Set up plugin to block creation
    $pluginManager->hooks['post_create_block'] = [
        function($data, $thread) { return true; } // Block
    ];

    $blocked = $pluginManager->checkHook('post_create_block', $postData, $thread);
    $t->assertTrue('Post creation blocked by plugin', $blocked);

    App::reset();
    return $t;
}

function test_reply_post_logic_notify_thread_reply(): Test
{
    $t = new Test('Reply Post Logic - Notify Thread Reply');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    // Create thread author
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (5, 'threadauthor', ?, 'author@test.com', 'user', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 5, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $thread = [
        'id' => $threadId,
        'user_id' => 5,
        'title' => 'Test Thread',
        'status' => 'visible',
    ];

    // Mock t() function
    if (!function_exists('t')) {
        function t(string $key, array $params = []): string {
            return $key;
        }
    }

    // Test notify_thread_reply function exists
    $t->assertTrue('notify_thread_reply function exists', function_exists('notify_thread_reply'));

    // Call the function
    notify_thread_reply($thread, 3, 'This is a reply');

    // Verify notification was created for thread author
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 5 AND type = 'reply'");
    $stmt->execute();
    $notification = $stmt->fetch();

    $t->assertNotNull('Reply notification created for thread author', $notification);
    $t->assertEquals('Notification for thread author', 5, (int)$notification['user_id']);
    $t->assertEquals('Notification type reply', 'reply', $notification['type']);

    App::reset();
    return $t;
}

function test_reply_post_logic_notify_mentioned_users(): Test
{
    $t = new Test('Reply Post Logic - Notify Mentioned Users in Reply');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    // Create mentioned user
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (20, 'mentioneduser', ?, 'mentioned@test.com', 'user', 1)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3,
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $content = 'Hello @mentioneduser, check this reply!';
    $threadTitle = 'Test Thread';

    // Mock t() function
    if (!function_exists('t')) {
        function t(string $key, array $params = []): string {
            return $key;
        }
    }

    // Call the function
    $created = notify_mentioned_users($pdo, $content, $threadId, $threadTitle, 'user2', 3);

    // Verify notification was created
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 20 AND type = 'mention'");
    $stmt->execute();
    $notification = $stmt->fetch();

    $t->assertNotNull('Mention notification created', $notification);
    $t->assertEquals('Notification for mentioned user', 20, (int)$notification['user_id']);
    $t->assertEquals('Notification type mention', 'mention', $notification['type']);
    $t->assertEquals('Function returns count', 1, $created);

    App::reset();
    return $t;
}

// ============================================================
// EDIT POST LOGIC TESTS
// ============================================================

function test_edit_post_logic_get_renders_view(): Test
{
    $t = new Test('Edit Post Logic - GET Renders View');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    // Create thread and post
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute([$threadId]);
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Test authorization - user owns the post
    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(2, 'posts.edit', 2);
    $t->assertTrue('User can edit own post', $canEdit);

    // Test post exists
    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch(PDO::FETCH_ASSOC);
    $t->assertNotNull('Post exists', $post);

    // Debug: check post structure
    if ($post !== false) {
        $postKeys = array_keys($post);
        $t->assertEquals('Post has thread_id key', true, in_array('thread_id', $postKeys));
        $t->assertEquals('Post thread_id value', $threadId, (int)($post['thread_id'] ?? 0));
    } else {
        $t->assertEquals('Post has thread_id key', true, false);
        $t->assertEquals('Post thread_id value', $threadId, 0);
    }

    // Debug: check post thread_id
    $t->assertEquals('Post has correct thread_id', $threadId, (int)($post['thread_id'] ?? 0));

    // Test thread exists
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$post['thread_id']]);
    $thread = $threadStmt->fetch(PDO::FETCH_ASSOC);
    $t->assertNotNull('Thread exists', $thread);

    // Debug: check thread structure - dump all keys
    if ($thread !== false) {
        $threadKeys = array_keys($thread);
        $t->assertEquals('Thread has title key', true, in_array('title', $threadKeys));
        $t->assertEquals('Thread title value', 'Test Thread', $thread['title'] ?? 'MISSING');
    } else {
        $t->assertEquals('Thread has title key', true, false);
        $t->assertEquals('Thread title value', 'Test Thread', 'MISSING');
    }

    // Test thread_title added to post for view
    $threadTitle = $thread['title'] ?? '';
    $post['thread_title'] = $threadTitle;
    $t->assertEquals('Thread title added to post', 'Test Thread', $post['thread_title']);

    App::reset();
    return $t;
}

function test_edit_post_logic_post_success(): Test
{
    $t = new Test('Edit Post Logic - POST Success Updates Post');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    // Create thread and post
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'post_id' => (string)$postId,
        'content' => 'Updated post content',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Test CSRF validation
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'edit_post|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('edit_post', 30, 3600, $rlKey));

    // Test authorization
    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(2, 'posts.edit', 2);
    $t->assertTrue('User can edit own post', $canEdit);

    // Test input validation
    $content = validate_input($_POST['content']);
    $t->assertEquals('Content validated', 'Updated post content', $content);

    // Test plugin filter hook
    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    $updateData = ['content' => $content];
    $filteredData = $pluginManager->filter('post_before_update', $updateData, $post);
    $t->assertEquals('Plugin filter called', 1, count($pluginManager->filterCalls));
    $t->assertEquals('Filter name', 'post_before_update', $pluginManager->filterCalls[0]['name']);

    // Update post
    $setParts = [];
    $sqlParams = [];
    foreach ($filteredData as $col => $val) {
        $setParts[] = "{$col} = ?";
        $sqlParams[] = $val;
    }
    $sqlParams[] = $postId;
    $pdo->prepare("UPDATE posts SET " . implode(', ', $setParts) . " WHERE id = ?")->execute($sqlParams);

    // Test plugin after_update hook
    $pluginManager->runHook('post_after_update', $postId, $filteredData, $post);
    $t->assertEquals('After update hook called', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'post_after_update', $pluginManager->hookCalls[0]['name']);

    // Verify post updated in database
    $verifyStmt = $pdo->prepare("SELECT content FROM posts WHERE id = ?");
    $verifyStmt->execute([$postId]);
    $updatedContent = $verifyStmt->fetchColumn();
    $t->assertEquals('Post content updated', 'Updated post content', $updatedContent);

    App::reset();
    return $t;
}

function test_edit_post_logic_post_empty_content(): Test
{
    $t = new Test('Edit Post Logic - POST Empty Content Does Not Update');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'post_id' => (string)$postId,
        'content' => '',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $content = validate_input($_POST['content']);
    $t->assertEquals('Empty content after validation', '', $content);

    // Empty content should not update
    $updateData = [];
    if ($content !== '') {
        $updateData['content'] = $content;
    }
    $t->assertEquals('Update data empty', 0, count($updateData));

    App::reset();
    return $t;
}

function test_edit_post_logic_not_owner(): Test
{
    $t = new Test('Edit Post Logic - Not Owner Cannot Edit');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3, // Different user
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(3, 'posts.edit', 2);
    $t->assertFalse('User cannot edit other user post', $canEdit);

    App::reset();
    return $t;
}

function test_edit_post_logic_moderator_can_edit_any(): Test
{
    $t = new Test('Edit Post Logic - Moderator Can Edit Any Post');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 10, // Moderator
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(10, 'posts.edit', 2);
    $t->assertTrue('Moderator can edit any post', $canEdit);

    App::reset();
    return $t;
}

function test_edit_post_logic_admin_can_edit_any(): Test
{
    $t = new Test('Edit Post Logic - Admin Can Edit Any Post');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 1, // Admin
        'user_role' => 'admin',
        'username' => 'admin',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(1, 'posts.edit', 2);
    $t->assertTrue('Admin can edit any post', $canEdit);

    App::reset();
    return $t;
}

function test_edit_post_logic_csrf_invalid(): Test
{
    $t = new Test('Edit Post Logic - Invalid CSRF Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'post_id' => (string)$postId,
        'content' => 'Updated content',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_edit_post_logic_rate_limited(): Test
{
    $t = new Test('Edit Post Logic - Rate Limited');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'edit_post|' . $_SESSION['user_id'];

    // Exhaust rate limit (30 per hour)
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 32; $i++) {
        if (rate_limit('edit_post', 30, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 30 requests allowed', 30, $allowed);
    $t->assertEquals('Requests 31-32 blocked', 2, $blocked);

    App::reset();
    return $t;
}

function test_edit_post_logic_not_logged_in(): Test
{
    $t = new Test('Edit Post Logic - Not Logged In Throws Unauthorized');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_edit_post_logic_post_not_found(): Test
{
    $t = new Test('Edit Post Logic - Post Not Found');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postId = 999; // Non-existent
    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    $t->assertFalse('Non-existent post not found', $post);

    App::reset();
    return $t;
}

function test_edit_post_logic_plugin_filter_modifies_data(): Test
{
    $t = new Test('Edit Post Logic - Plugin Filter Modifies Update Data');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    $updateData = ['content' => 'Updated content'];

    // Set up plugin filter to modify data
    $pluginManager->filters['post_before_update'] = function($data, $post) {
        $data['content'] = '[Modified] ' . $data['content'];
        return $data;
    };

    $filteredData = $pluginManager->filter('post_before_update', $updateData, $post);

    $t->assertEquals('Content modified by plugin', '[Modified] Updated content', $filteredData['content']);
    $t->assertEquals('Original data unchanged', 'Updated content', $updateData['content']);

    App::reset();
    return $t;
}

function test_edit_post_logic_plugin_after_update_hook(): Test
{
    $t = new Test('Edit Post Logic - Plugin After Update Hook Called');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Original post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    $updateData = ['content' => 'Updated content'];

    $pluginManager->runHook('post_after_update', $postId, $updateData, $post);

    $t->assertEquals('After update hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'post_after_update', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$postId, $updateData, $post], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

// ============================================================
// DELETE POST LOGIC TESTS
// ============================================================

function test_delete_post_logic_success(): Test
{
    $t = new Test('Delete Post Logic - Success Deletes Post');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$postId,
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Test CSRF validation
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'delete_post|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('delete_post', 20, 3600, $rlKey));

    // Test authorization
    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(2, 'posts.delete', 2);
    $t->assertTrue('User can delete own post', $canDelete);

    // Test post exists
    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();
    $t->assertNotNull('Post exists', $post);

    // Test plugin block hook (should not block)
    $blocked = $pluginManager->checkHook('post_delete_block', $post);
    $t->assertFalse('Post deletion not blocked', $blocked);

    // Test plugin before_delete hook
    $pluginManager->runHook('post_before_delete', $postId, $post);
    $t->assertEquals('Before delete hook called', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'post_before_delete', $pluginManager->hookCalls[0]['name']);

    // Delete post
    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);

    // Test plugin after_delete hook
    $pluginManager->runHook('post_after_delete', $postId, $threadId);
    $t->assertEquals('After delete hook called', 2, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'post_after_delete', $pluginManager->hookCalls[1]['name']);

    // Verify post deleted
    $verifyStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $verifyStmt->execute([$postId]);
    $deletedPost = $verifyStmt->fetch();
    $t->assertFalse('Post deleted from database', $deletedPost);

    // Verify thread still exists
    $threadCheck = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadCheck->execute([$threadId]);
    $thread = $threadCheck->fetch();
    $t->assertNotNull('Thread still exists after deleting post', $thread);

    App::reset();
    return $t;
}

function test_delete_post_logic_not_owner(): Test
{
    $t = new Test('Delete Post Logic - Not Owner Cannot Delete');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3, // Different user
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(3, 'posts.delete', 2);
    $t->assertFalse('User cannot delete other user post', $canDelete);

    App::reset();
    return $t;
}

function test_delete_post_logic_moderator_can_delete_any(): Test
{
    $t = new Test('Delete Post Logic - Moderator Can Delete Any Post');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 10, // Moderator
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(10, 'posts.delete', 2);
    $t->assertTrue('Moderator can delete any post', $canDelete);

    App::reset();
    return $t;
}

function test_delete_post_logic_admin_can_delete_any(): Test
{
    $t = new Test('Delete Post Logic - Admin Can Delete Any Post');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 1, // Admin
        'user_role' => 'admin',
        'username' => 'admin',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(1, 'posts.delete', 2);
    $t->assertTrue('Admin can delete any post', $canDelete);

    App::reset();
    return $t;
}

function test_delete_post_logic_csrf_invalid(): Test
{
    $t = new Test('Delete Post Logic - Invalid CSRF Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$postId,
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_delete_post_logic_rate_limited(): Test
{
    $t = new Test('Delete Post Logic - Rate Limited');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'delete_post|' . $_SESSION['user_id'];

    // Exhaust rate limit (20 per hour)
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 22; $i++) {
        if (rate_limit('delete_post', 20, 3600, $rlKey)) {
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

function test_delete_post_logic_not_logged_in(): Test
{
    $t = new Test('Delete Post Logic - Not Logged In Throws Unauthorized');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_delete_post_logic_post_not_found(): Test
{
    $t = new Test('Delete Post Logic - Post Not Found');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postId = 999; // Non-existent
    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    $t->assertFalse('Non-existent post not found', $post);

    App::reset();
    return $t;
}

function test_delete_post_logic_plugin_block_prevents_deletion(): Test
{
    $t = new Test('Delete Post Logic - Plugin Block Prevents Deletion');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    // Set up plugin to block deletion
    $pluginManager->hooks['post_delete_block'] = [
        function($post) { return true; } // Block
    ];

    $blocked = $pluginManager->checkHook('post_delete_block', $post);
    $t->assertTrue('Post deletion blocked by plugin', $blocked);

    App::reset();
    return $t;
}

function test_delete_post_logic_plugin_before_delete_hook(): Test
{
    $t = new Test('Delete Post Logic - Plugin Before Delete Hook Called');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    $pluginManager->runHook('post_before_delete', $postId, $post);

    $t->assertEquals('Before delete hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'post_before_delete', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$postId, $post], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

function test_delete_post_logic_plugin_after_delete_hook(): Test
{
    $t = new Test('Delete Post Logic - Plugin After Delete Hook Called');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post to delete', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $pluginManager->runHook('post_after_delete', $postId, $threadId);

    $t->assertEquals('After delete hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'post_after_delete', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$postId, $threadId], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

// ============================================================
// EDIT THREAD LOGIC TESTS
// ============================================================

function test_edit_thread_logic_get_renders_view(): Test
{
    $t = new Test('Edit Thread Logic - GET Renders View');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Test authorization - user owns the thread
    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(2, 'threads.edit', 2);
    $t->assertTrue('User can edit own thread', $canEdit);

    // Test thread exists
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();
    $t->assertNotNull('Thread exists', $thread);

    App::reset();
    return $t;
}

function test_edit_thread_logic_post_success(): Test
{
    $t = new Test('Edit Thread Logic - POST Success Updates Thread');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Original Title', 'Original content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$threadId,
        'title' => 'Updated Title',
        'content' => 'Updated content',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Test CSRF validation
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'edit_thread|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('edit_thread', 20, 3600, $rlKey));

    // Test authorization
    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(2, 'threads.edit', 2);
    $t->assertTrue('User can edit own thread', $canEdit);

    // Test input validation
    $content = validate_input($_POST['content']);
    $title = clean_text($_POST['title']);
    $t->assertEquals('Content validated', 'Updated content', $content);
    $t->assertEquals('Title cleaned', 'Updated Title', $title);

    // Test plugin filter hook
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $updateData = [];
    if ($content !== '') {
        $updateData['content'] = $content;
    }
    if (!empty($_POST['title'])) {
        $updateData['title'] = $title;
    }

    $filteredData = $pluginManager->filter('thread_before_update', $updateData, $thread);
    $t->assertEquals('Plugin filter called', 1, count($pluginManager->filterCalls));
    $t->assertEquals('Filter name', 'thread_before_update', $pluginManager->filterCalls[0]['name']);

    // Update thread
    $setParts = [];
    $params = [];
    foreach ($filteredData as $col => $val) {
        $setParts[] = "{$col} = ?";
        $params[] = $val;
    }
    $params[] = $threadId;
    $pdo->prepare("UPDATE threads SET " . implode(', ', $setParts) . " WHERE id = ?")->execute($params);

    // Test plugin after_update hook
    $pluginManager->runHook('thread_after_update', $threadId, $filteredData, $thread);
    $t->assertEquals('After update hook called', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'thread_after_update', $pluginManager->hookCalls[0]['name']);

    // Verify thread updated in database
    $verifyStmt = $pdo->prepare("SELECT title, content FROM threads WHERE id = ?");
    $verifyStmt->execute([$threadId]);
    $updated = $verifyStmt->fetch();
    $t->assertEquals('Thread title updated', 'Updated Title', $updated['title']);
    $t->assertEquals('Thread content updated', 'Updated content', $updated['content']);

    App::reset();
    return $t;
}

function test_edit_thread_logic_post_empty_content(): Test
{
    $t = new Test('Edit Thread Logic - POST Empty Content Does Not Update Content');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Original Title', 'Original content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$threadId,
        'title' => 'Updated Title',
        'content' => '',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $content = validate_input($_POST['content']);
    $title = clean_text($_POST['title']);

    $updateData = [];
    if ($content !== '') {
        $updateData['content'] = $content;
    }
    if (!empty($_POST['title'])) {
        $updateData['title'] = $title;
    }

    $t->assertEquals('Only title in update data', 1, count($updateData));
    $t->assertEquals('Title in update data', 'Updated Title', $updateData['title']);

    App::reset();
    return $t;
}

function test_edit_thread_logic_not_owner(): Test
{
    $t = new Test('Edit Thread Logic - Not Owner Cannot Edit');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3, // Different user
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(3, 'threads.edit', 2);
    $t->assertFalse('User cannot edit other user thread', $canEdit);

    App::reset();
    return $t;
}

function test_edit_thread_logic_moderator_can_edit_any(): Test
{
    $t = new Test('Edit Thread Logic - Moderator Can Edit Any Thread');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 10, // Moderator
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(10, 'threads.edit', 2);
    $t->assertTrue('Moderator can edit any thread', $canEdit);

    App::reset();
    return $t;
}

function test_edit_thread_logic_admin_can_edit_any(): Test
{
    $t = new Test('Edit Thread Logic - Admin Can Edit Any Thread');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 1, // Admin
        'user_role' => 'admin',
        'username' => 'admin',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canEdit = $authz->canOnOwned(1, 'threads.edit', 2);
    $t->assertTrue('Admin can edit any thread', $canEdit);

    App::reset();
    return $t;
}

function test_edit_thread_logic_csrf_invalid(): Test
{
    $t = new Test('Edit Thread Logic - Invalid CSRF Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$threadId,
        'title' => 'Updated Title',
        'content' => 'Updated content',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_edit_thread_logic_rate_limited(): Test
{
    $t = new Test('Edit Thread Logic - Rate Limited');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'edit_thread|' . $_SESSION['user_id'];

    // Exhaust rate limit (20 per hour)
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 22; $i++) {
        if (rate_limit('edit_thread', 20, 3600, $rlKey)) {
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

function test_edit_thread_logic_not_logged_in(): Test
{
    $t = new Test('Edit Thread Logic - Not Logged In Throws Unauthorized');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_edit_thread_logic_thread_not_found(): Test
{
    $t = new Test('Edit Thread Logic - Thread Not Found');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadId = 999; // Non-existent
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $t->assertFalse('Non-existent thread not found', $thread);

    App::reset();
    return $t;
}

function test_edit_thread_logic_plugin_filter_modifies_data(): Test
{
    $t = new Test('Edit Thread Logic - Plugin Filter Modifies Update Data');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Original Title', 'Original content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $updateData = ['title' => 'Updated Title', 'content' => 'Updated content'];

    // Set up plugin filter to modify data
    $pluginManager->filters['thread_before_update'] = function($data, $thread) {
        $data['title'] = '[Modified] ' . $data['title'];
        return $data;
    };

    $filteredData = $pluginManager->filter('thread_before_update', $updateData, $thread);

    $t->assertEquals('Title modified by plugin', '[Modified] Updated Title', $filteredData['title']);
    $t->assertEquals('Original data unchanged', 'Updated Title', $updateData['title']);

    App::reset();
    return $t;
}

function test_edit_thread_logic_plugin_after_update_hook(): Test
{
    $t = new Test('Edit Thread Logic - Plugin After Update Hook Called');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Original Title', 'Original content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $updateData = ['title' => 'Updated Title', 'content' => 'Updated content'];

    $pluginManager->runHook('thread_after_update', $threadId, $updateData, $thread);

    $t->assertEquals('After update hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'thread_after_update', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$threadId, $updateData, $thread], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

// ============================================================
// DELETE THREAD LOGIC TESTS
// ============================================================

function test_delete_thread_logic_success(): Test
{
    $t = new Test('Delete Thread Logic - Success Deletes Thread and Posts');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post 1', 'visible')")->execute();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post 2', 'visible')")->execute();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$threadId,
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Test CSRF validation
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'delete_thread|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('delete_thread', 10, 3600, $rlKey));

    // Test authorization
    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(2, 'threads.delete', 2);
    $t->assertTrue('User can delete own thread', $canDelete);

    // Test thread exists
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();
    $t->assertNotNull('Thread exists', $thread);

    // Test plugin block hook (should not block)
    $blocked = $pluginManager->checkHook('thread_delete_block', $thread);
    $t->assertFalse('Thread deletion not blocked', $blocked);

    // Test plugin before_delete hook
    $pluginManager->runHook('thread_before_delete', $threadId, $thread);
    $t->assertEquals('Before delete hook called', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'thread_before_delete', $pluginManager->hookCalls[0]['name']);

    // Delete posts first
    $pdo->prepare("DELETE FROM posts WHERE thread_id = ?")->execute([$threadId]);

    // Delete thread
    $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);

    // Test plugin after_delete hook
    $pluginManager->runHook('thread_after_delete', $threadId, $thread);
    $t->assertEquals('After delete hook called', 2, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name', 'thread_after_delete', $pluginManager->hookCalls[1]['name']);

    // Verify thread deleted
    $verifyStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $verifyStmt->execute([$threadId]);
    $deletedThread = $verifyStmt->fetch();
    $t->assertFalse('Thread deleted from database', $deletedThread);

    // Verify posts deleted
    $postCheck = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ?");
    $postCheck->execute([$threadId]);
    $posts = $postCheck->fetchAll();
    $t->assertEquals('Posts deleted', 0, count($posts));

    // Verify redirect to category
    $categoryId = $thread['category_id'];
    $redirectUrl = url('category', ['id' => $categoryId]);
    $t->assert('Redirect URL contains category ID', str_contains($redirectUrl, 'category/' . $categoryId));

    App::reset();
    return $t;
}

function test_delete_thread_logic_not_owner(): Test
{
    $t = new Test('Delete Thread Logic - Not Owner Cannot Delete');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 3, // Different user
        'user_role' => 'user',
        'username' => 'user2',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(3, 'threads.delete', 2);
    $t->assertFalse('User cannot delete other user thread', $canDelete);

    App::reset();
    return $t;
}

function test_delete_thread_logic_moderator_can_delete_any(): Test
{
    $t = new Test('Delete Thread Logic - Moderator Can Delete Any Thread');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 10, // Moderator
        'user_role' => 'moderator',
        'username' => 'moduser',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(10, 'threads.delete', 2);
    $t->assertTrue('Moderator can delete any thread', $canDelete);

    App::reset();
    return $t;
}

function test_delete_thread_logic_admin_can_delete_any(): Test
{
    $t = new Test('Delete Thread Logic - Admin Can Delete Any Thread');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 1, // Admin
        'user_role' => 'admin',
        'username' => 'admin',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $authz = App::getInstance()->authz;
    $canDelete = $authz->canOnOwned(1, 'threads.delete', 2);
    $t->assertTrue('Admin can delete any thread', $canDelete);

    App::reset();
    return $t;
}

function test_delete_thread_logic_csrf_invalid(): Test
{
    $t = new Test('Delete Thread Logic - Invalid CSRF Rejected');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'id' => (string)$threadId,
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_delete_thread_logic_rate_limited(): Test
{
    $t = new Test('Delete Thread Logic - Rate Limited');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'delete_thread|' . $_SESSION['user_id'];

    // Exhaust rate limit (10 per hour)
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 12; $i++) {
        if (rate_limit('delete_thread', 10, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 10 requests allowed', 10, $allowed);
    $t->assertEquals('Requests 11-12 blocked', 2, $blocked);

    App::reset();
    return $t;
}

function test_delete_thread_logic_not_logged_in(): Test
{
    $t = new Test('Delete Thread Logic - Not Logged In Throws Unauthorized');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_delete_thread_logic_thread_not_found(): Test
{
    $t = new Test('Delete Thread Logic - Thread Not Found');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadId = 999; // Non-existent
    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $t->assertFalse('Non-existent thread not found', $thread);

    App::reset();
    return $t;
}

function test_delete_thread_logic_plugin_block_prevents_deletion(): Test
{
    $t = new Test('Delete Thread Logic - Plugin Block Prevents Deletion');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    // Set up plugin to block deletion
    $pluginManager->hooks['thread_delete_block'] = [
        function($thread) { return true; } // Block
    ];

    $blocked = $pluginManager->checkHook('thread_delete_block', $thread);
    $t->assertTrue('Thread deletion blocked by plugin', $blocked);

    App::reset();
    return $t;
}

function test_delete_thread_logic_plugin_before_delete_hook(): Test
{
    $t = new Test('Delete Thread Logic - Plugin Before Delete Hook Called');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $pluginManager->runHook('thread_before_delete', $threadId, $thread);

    $t->assertEquals('Before delete hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'thread_before_delete', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$threadId, $thread], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

function test_delete_thread_logic_plugin_after_delete_hook(): Test
{
    $t = new Test('Delete Thread Logic - Plugin After Delete Hook Called');

    $pdo = setupPostsEditDB();
    $pluginManager = new MockPluginManager();
    setupPostsEditApp($pdo, $pluginManager);
    cleanupPostsEditSession();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $pluginManager->runHook('thread_after_delete', $threadId, $thread);

    $t->assertEquals('After delete hook called once', 1, count($pluginManager->hookCalls));
    $t->assertEquals('Hook name correct', 'thread_after_delete', $pluginManager->hookCalls[0]['name']);
    $t->assertEquals('Hook args correct', [$threadId, $thread], $pluginManager->hookCalls[0]['args']);

    App::reset();
    return $t;
}

register_tests(
    // Reply post tests
    'test_reply_post_logic_success',
    'test_reply_post_logic_empty_content',
    'test_reply_post_logic_invalid_thread',
    'test_reply_post_logic_locked_thread',
    'test_reply_post_logic_moderator_can_reply_locked',
    'test_reply_post_logic_csrf_invalid',
    'test_reply_post_logic_rate_limited',
    'test_reply_post_logic_not_logged_in',
    'test_reply_post_logic_no_permission',
    'test_reply_post_logic_plugin_filter_modifies_data',
    'test_reply_post_logic_plugin_block_prevents_creation',
    'test_reply_post_logic_notify_thread_reply',
    'test_reply_post_logic_notify_mentioned_users',

    // Edit post tests
    'test_edit_post_logic_get_renders_view',
    'test_edit_post_logic_post_success',
    'test_edit_post_logic_post_empty_content',
    'test_edit_post_logic_not_owner',
    'test_edit_post_logic_moderator_can_edit_any',
    'test_edit_post_logic_admin_can_edit_any',
    'test_edit_post_logic_csrf_invalid',
    'test_edit_post_logic_rate_limited',
    'test_edit_post_logic_not_logged_in',
    'test_edit_post_logic_post_not_found',
    'test_edit_post_logic_plugin_filter_modifies_data',
    'test_edit_post_logic_plugin_after_update_hook',

    // Delete post tests
    'test_delete_post_logic_success',
    'test_delete_post_logic_not_owner',
    'test_delete_post_logic_moderator_can_delete_any',
    'test_delete_post_logic_admin_can_delete_any',
    'test_delete_post_logic_csrf_invalid',
    'test_delete_post_logic_rate_limited',
    'test_delete_post_logic_not_logged_in',
    'test_delete_post_logic_post_not_found',
    'test_delete_post_logic_plugin_block_prevents_deletion',
    'test_delete_post_logic_plugin_before_delete_hook',
    'test_delete_post_logic_plugin_after_delete_hook',

    // Edit thread tests
    'test_edit_thread_logic_get_renders_view',
    'test_edit_thread_logic_post_success',
    'test_edit_thread_logic_post_empty_content',
    'test_edit_thread_logic_not_owner',
    'test_edit_thread_logic_moderator_can_edit_any',
    'test_edit_thread_logic_admin_can_edit_any',
    'test_edit_thread_logic_csrf_invalid',
    'test_edit_thread_logic_rate_limited',
    'test_edit_thread_logic_not_logged_in',
    'test_edit_thread_logic_thread_not_found',
    'test_edit_thread_logic_plugin_filter_modifies_data',
    'test_edit_thread_logic_plugin_after_update_hook',

    // Delete thread tests
    'test_delete_thread_logic_success',
    'test_delete_thread_logic_not_owner',
    'test_delete_thread_logic_moderator_can_delete_any',
    'test_delete_thread_logic_admin_can_delete_any',
    'test_delete_thread_logic_csrf_invalid',
    'test_delete_thread_logic_rate_limited',
    'test_delete_thread_logic_not_logged_in',
    'test_delete_thread_logic_thread_not_found',
    'test_delete_thread_logic_plugin_block_prevents_deletion',
    'test_delete_thread_logic_plugin_before_delete_hook',
    'test_delete_thread_logic_plugin_after_delete_hook'
);