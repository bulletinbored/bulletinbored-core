<?php

/**
 * PostsDispatcherTest — tests for the posts action dispatcher (src/actions/posts.php).
 * Covers dispatcher routing, method validation, 404/405 error handling, and authentication checks.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/actions/posts.php';
require_once __DIR__ . '/../src/actions/posts-thread.php';
require_once __DIR__ . '/../src/actions/posts-new.php';
require_once __DIR__ . '/../src/actions/posts-edit.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function posts_dispatcher_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_posts_dispatcher_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function posts_dispatcher_cleanup(string $dir): void
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

function posts_dispatcher_setup_test_env(string $tmpDir): array
{
    $dataDir = $tmpDir . '/data';
    @mkdir($dataDir . '/sessions', 0755, true);
    @mkdir($dataDir . '/uploads/private', 0755, true);
    @mkdir($dataDir . '/uploads/avatars', 0755, true);
    @mkdir($dataDir . '/cache', 0755, true);
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
        'upload_path' => $dataDir . '/uploads',
        'cache_path' => $dataDir . '/cache',
        'mail_method' => 'php',
        'mail_from' => 'test@example.com',
        'mail_from_name' => 'Test',
        'site_name' => 'Test Forum',
    ];

    $configPath = $dataDir . '/config.json';
    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

    // Initialize App
    App::reset();
    $app = App::getInstance();
    $app->config = $config;

    // Create PDO and schema
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
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS threads (
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
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL UNIQUE,
            batch INTEGER NOT NULL,
            ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS thread_watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(thread_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            data TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS private_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            subject TEXT,
            content TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            expires_at DATETIME NOT NULL
        );
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            expires_at DATETIME NOT NULL
        );
        CREATE TABLE IF NOT EXISTS uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            post_id INTEGER,
            user_id INTEGER,
            filename TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size INTEGER NOT NULL,
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

    // Create category
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['General', 'General discussion', 1]);
    $catId = $pdo->lastInsertId();

    // Create thread by user
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$catId, $userId, 'Test Thread', 'Thread content', 'visible']);
    $threadId = $pdo->lastInsertId();

    // Create post by user
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'Test post content', 'visible']);
    $postId = $pdo->lastInsertId();

    // Create default role
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
        ->execute(['user', json_encode(['threads.create', 'posts.create'])]);

    // Create admin role
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
        ->execute(['admin', json_encode(['admin.access', 'moderation.manage', 'users.manage', 'categories.manage', 'settings.manage', 'plugins.manage', 'themes.manage', 'updates.manage'])]);

    // Set up session for user
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = 1;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Initialize AuthZ
    $pluginManager = new PluginManager($tmpDir . '/plugins', $dataDir . '/plugins.json');
    $app->authz = new AuthZ($pdo, $pluginManager);
    $app->pluginManager = $pluginManager;

    // Set globals for handlers that use them
    global $pluginManager;
    $pluginManager = $app->pluginManager;

    return [
        'tmpDir' => $tmpDir,
        'dataDir' => $dataDir,
        'config' => $config,
        'pdo' => $pdo,
        'adminId' => $adminId,
        'userId' => $userId,
        'catId' => $catId,
        'threadId' => $threadId,
        'postId' => $postId,
    ];
}

function test_posts_dispatcher_thread_view_get(): Test
{
    $t = new Test('Posts Dispatcher - Thread View GET');

    $tmpDir = posts_dispatcher_test_tmp_dir('thread_view');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_posts_action('thread', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_thread_view_method_not_allowed(): Test
{
    $t = new Test('Posts Dispatcher - Thread View Method Not Allowed (POST)');

    $tmpDir = posts_dispatcher_test_tmp_dir('thread_view_method');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';

    try {
        handle_posts_action('thread', 'POST');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_new_thread_get_post(): Test
{
    $t = new Test('Posts Dispatcher - New Thread GET and POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('new_thread');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    // GET - should show form
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_posts_action('new_thread', 'GET');
    $t->assertTrue('GET returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    // POST - create thread
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['category_id'] = (string)$env['catId'];
    $_POST['title'] = 'New Test Thread';
    $_POST['content'] = 'New thread content';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('new_thread', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_reply_post_method_not_allowed(): Test
{
    $t = new Test('Posts Dispatcher - Reply Post Method Not Allowed (GET)');

    $tmpDir = posts_dispatcher_test_tmp_dir('reply_method');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_posts_action('reply', 'GET');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_reply_post(): Test
{
    $t = new Test('Posts Dispatcher - Reply Post POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('reply_post');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$env['threadId'];
    $_POST['content'] = 'Reply content';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('reply', 'POST');
    $t->assertTrue('Returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_edit_post_get_post(): Test
{
    $t = new Test('Posts Dispatcher - Edit Post GET and POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('edit_post');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    // GET - show edit form
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_posts_action('edit_post', 'GET');
    $t->assertTrue('GET returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    // POST - update post
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['post_id'] = (string)$env['postId'];
    $_POST['content'] = 'Updated post content';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('edit_post', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_delete_post_method_not_allowed(): Test
{
    $t = new Test('Posts Dispatcher - Delete Post Method Not Allowed (GET)');

    $tmpDir = posts_dispatcher_test_tmp_dir('delete_post_method');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_posts_action('delete_post', 'GET');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_delete_post(): Test
{
    $t = new Test('Posts Dispatcher - Delete Post POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('delete_post');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['post_id'] = (string)$env['postId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('delete_post', 'POST');
    $t->assertTrue('Returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_edit_thread_get_post(): Test
{
    $t = new Test('Posts Dispatcher - Edit Thread GET and POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('edit_thread');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    // GET - show edit form
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_posts_action('edit_thread', 'GET');
    $t->assertTrue('GET returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    // POST - update thread
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$env['threadId'];
    $_POST['title'] = 'Updated Thread Title';
    $_POST['content'] = 'Updated thread content';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('edit_thread', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_delete_thread_method_not_allowed(): Test
{
    $t = new Test('Posts Dispatcher - Delete Thread Method Not Allowed (GET)');

    $tmpDir = posts_dispatcher_test_tmp_dir('delete_thread_method');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_posts_action('delete_thread', 'GET');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_delete_thread(): Test
{
    $t = new Test('Posts Dispatcher - Delete Thread POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('delete_thread');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$env['threadId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('delete_thread', 'POST');
    $t->assertTrue('Returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_watch_unauthorized(): Test
{
    $t = new Test('Posts Dispatcher - Watch Unauthorized (not logged in)');

    $tmpDir = posts_dispatcher_test_tmp_dir('watch_unauth');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    // Clear session to simulate not logged in
    $_SESSION['user_id'] = null;
    unset($_SESSION['user_id']);

    $_SERVER['REQUEST_METHOD'] = 'POST';

    try {
        handle_posts_action('watch', 'POST');
        $t->assert('Should throw UnauthorizedException', false);
    } catch (\Bulletin\UnauthorizedException $e) {
        $t->assertTrue('Throws UnauthorizedException', true);
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_watch(): Test
{
    $t = new Test('Posts Dispatcher - Watch Thread');

    $tmpDir = posts_dispatcher_test_tmp_dir('watch');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$env['threadId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('watch', 'POST');
    $t->assertTrue('Returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_unwatch_unauthorized(): Test
{
    $t = new Test('Posts Dispatcher - Unwatch Unauthorized (not logged in)');

    $tmpDir = posts_dispatcher_test_tmp_dir('unwatch_unauth');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    // Clear session to simulate not logged in
    $_SESSION['user_id'] = null;
    unset($_SESSION['user_id']);

    $_SERVER['REQUEST_METHOD'] = 'POST';

    try {
        handle_posts_action('unwatch', 'POST');
        $t->assert('Should throw UnauthorizedException', false);
    } catch (\Bulletin\UnauthorizedException $e) {
        $t->assertTrue('Throws UnauthorizedException', true);
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_unwatch(): Test
{
    $t = new Test('Posts Dispatcher - Unwatch Thread');

    $tmpDir = posts_dispatcher_test_tmp_dir('unwatch');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    // First watch the thread
    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")
        ->execute([$env['threadId'], $env['userId']]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$env['threadId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('unwatch', 'POST');
    $t->assertTrue('Returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_upload_image_method_not_allowed(): Test
{
    $t = new Test('Posts Dispatcher - Upload Image Method Not Allowed (GET)');

    $tmpDir = posts_dispatcher_test_tmp_dir('upload_image_method');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_posts_action('upload_image', 'GET');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_upload_image(): Test
{
    $t = new Test('Posts Dispatcher - Upload Image POST');

    $tmpDir = posts_dispatcher_test_tmp_dir('upload_image');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_posts_action('upload_image', 'POST');
    $t->assertTrue('Returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_action_not_found(): Test
{
    $t = new Test('Posts Dispatcher - Action Not Found');

    $tmpDir = posts_dispatcher_test_tmp_dir('not_found');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_posts_action('nonexistent_action', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException', true);
        $t->assertEquals('Exception message', 'Post action not found', $e->getMessage());
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_posts_dispatcher_all_actions_covered(): Test
{
    $t = new Test('Posts Dispatcher - All Actions Covered');

    $tmpDir = posts_dispatcher_test_tmp_dir('all_actions');
    $env = posts_dispatcher_setup_test_env($tmpDir);

    $actions = [
        'thread' => ['method' => 'GET', 'expect' => true],
        'new_thread' => ['method' => 'GET', 'expect' => true],
        'reply' => ['method' => 'POST', 'expect' => true],
        'edit_post' => ['method' => 'GET', 'expect' => true],
        'delete_post' => ['method' => 'POST', 'expect' => true],
        'edit_thread' => ['method' => 'GET', 'expect' => true],
        'delete_thread' => ['method' => 'POST', 'expect' => true],
        'watch' => ['method' => 'POST', 'expect' => true],
        'unwatch' => ['method' => 'POST', 'expect' => true],
        'upload_image' => ['method' => 'POST', 'expect' => true],
    ];

    foreach ($actions as $action => $config) {
        $_SERVER['REQUEST_METHOD'] = $config['method'];

        // Set required POST data for POST actions
        if ($config['method'] === 'POST') {
            $_POST['csrf_token'] = $_SESSION['csrf_token'];
            switch ($action) {
                case 'reply':
                    $_POST['thread_id'] = (string)$env['threadId'];
                    $_POST['content'] = 'Reply content';
                    break;
                case 'edit_post':
                    $_POST['post_id'] = (string)$env['postId'];
                    $_POST['content'] = 'Updated content';
                    break;
                case 'delete_post':
                    $_POST['post_id'] = (string)$env['postId'];
                    break;
                case 'edit_thread':
                    $_POST['thread_id'] = (string)$env['threadId'];
                    $_POST['title'] = 'Updated Title';
                    $_POST['content'] = 'Updated content';
                    break;
                case 'delete_thread':
                    $_POST['thread_id'] = (string)$env['threadId'];
                    break;
                case 'watch':
                case 'unwatch':
                    $_POST['thread_id'] = (string)$env['threadId'];
                    break;
                case 'upload_image':
                    // No additional data needed
                    break;
            }
        }

        $result = handle_posts_action($action, $config['method']);
        $t->assertTrue("Action '$action' returns true or Response", $result === true || $result instanceof \Bulletin\Response);
    }

    posts_dispatcher_cleanup($tmpDir);
    return $t;
}

register_tests(
    'test_posts_dispatcher_thread_view_get',
    'test_posts_dispatcher_thread_view_method_not_allowed',
    'test_posts_dispatcher_new_thread_get_post',
    'test_posts_dispatcher_reply_post_method_not_allowed',
    'test_posts_dispatcher_reply_post',
    'test_posts_dispatcher_edit_post_get_post',
    'test_posts_dispatcher_delete_post_method_not_allowed',
    'test_posts_dispatcher_delete_post',
    'test_posts_dispatcher_edit_thread_get_post',
    'test_posts_dispatcher_delete_thread_method_not_allowed',
    'test_posts_dispatcher_delete_thread',
    'test_posts_dispatcher_watch_unauthorized',
    'test_posts_dispatcher_watch',
    'test_posts_dispatcher_unwatch_unauthorized',
    'test_posts_dispatcher_unwatch',
    'test_posts_dispatcher_upload_image_method_not_allowed',
    'test_posts_dispatcher_upload_image',
    'test_posts_dispatcher_action_not_found',
    'test_posts_dispatcher_all_actions_covered'
);