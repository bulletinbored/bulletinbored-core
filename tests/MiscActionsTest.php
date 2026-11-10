<?php

/**
 * MiscActionsTest — tests for the misc action dispatcher (src/actions/misc.php).
 * Covers dispatcher routing, method validation, 404/405 error handling, markdown preview, and mention autocomplete.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/actions/misc.php';
require_once __DIR__ . '/../src/markdown.php';

function misc_actions_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_misc_actions_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function misc_actions_cleanup(string $dir): void
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

function misc_actions_setup_test_env(string $tmpDir): array
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

    // Create additional users for mention autocomplete
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['alice', $userPass, 'alice@test.com', 'user', 'active', 1]);
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['bob', $userPass, 'bob@test.com', 'user', 'active', 1]);
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['charlie', $userPass, 'charlie@test.com', 'user', 'active', 1]);

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

    return [
        'tmpDir' => $tmpDir,
        'dataDir' => $dataDir,
        'config' => $config,
        'pdo' => $pdo,
        'adminId' => $adminId,
        'userId' => $userId,
    ];
}

function test_misc_dispatcher_preview_get_method_not_allowed(): Test
{
    $t = new Test('Misc Dispatcher - Preview Method Not Allowed (GET)');

    $tmpDir = misc_actions_test_tmp_dir('preview_method');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_misc_action('preview', 'GET');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_preview_post(): Test
{
    $t = new Test('Misc Dispatcher - Markdown Preview POST');

    $tmpDir = misc_actions_test_tmp_dir('preview_post');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['content'] = '# Hello World\n\nThis is **bold** and *italic* text.';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_misc_action('preview', 'POST');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $t->assertTrue('Response contains rendered HTML', str_contains($result->getBody(), '<h1>Hello World</h1>'));
    $t->assertTrue('Response contains bold', str_contains($result->getBody(), '<strong>bold</strong>'));
    $t->assertTrue('Response contains italic', str_contains($result->getBody(), '<em>italic</em>'));

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_preview_post_csrf_invalid(): Test
{
    $t = new Test('Misc Dispatcher - Markdown Preview POST CSRF Invalid');

    $tmpDir = misc_actions_test_tmp_dir('preview_csrf');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['content'] = 'Test content';
    $_POST['csrf_token'] = 'invalid_token';

    try {
        handle_misc_action('preview', 'POST');
        $t->assert('Should throw ForbiddenException', false);
    } catch (\Bulletin\ForbiddenException $e) {
        $t->assertTrue('Throws ForbiddenException for invalid CSRF', true);
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_preview_post_empty_content(): Test
{
    $t = new Test('Misc Dispatcher - Markdown Preview POST Empty Content');

    $tmpDir = misc_actions_test_tmp_dir('preview_empty');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['content'] = '';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_misc_action('preview', 'POST');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $t->assertEquals('Empty content renders empty', '', trim($result->getBody()));

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_preview_post_xss_safe(): Test
{
    $t = new Test('Misc Dispatcher - Markdown Preview POST XSS Safe');

    $tmpDir = misc_actions_test_tmp_dir('preview_xss');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['content'] = '<script>alert("xss")</script>\n\n**Safe markdown**';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_misc_action('preview', 'POST');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $t->assertTrue('Script tags escaped', str_contains($result->getBody(), '<script>'));
    $t->assertTrue('Safe markdown rendered', str_contains($result->getBody(), '<strong>Safe markdown</strong>'));

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_get(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users GET');

    $tmpDir = misc_actions_test_tmp_dir('mention_get');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'a';

    $result = handle_misc_action('mention_users', 'GET');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $body = $result->getBody();
    $data = json_decode($body, true);
    $t->assertTrue('Response is valid JSON', is_array($data));
    $t->assertTrue('Has users key', isset($data['users']));
    $t->assertTrue('Returns matching users', count($data['users']) > 0);
    // Should match 'alice' and 'admin'
    $usernames = array_column($data['users'], 'username');
    $t->assertTrue('Includes alice', in_array('alice', $usernames));
    $t->assertTrue('Includes admin', in_array('admin', $usernames));

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_get_method_not_allowed(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users Method Not Allowed (POST)');

    $tmpDir = misc_actions_test_tmp_dir('mention_method');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';

    try {
        handle_misc_action('mention_users', 'POST');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_empty_query(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users Empty Query');

    $tmpDir = misc_actions_test_tmp_dir('mention_empty');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = '';

    $result = handle_misc_action('mention_users', 'GET');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $body = $result->getBody();
    $data = json_decode($body, true);
    $t->assertTrue('Response is valid JSON', is_array($data));
    $t->assertEquals('Returns empty users array', [], $data['users'] ?? null);

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_no_query_param(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users No Query Param');

    $tmpDir = misc_actions_test_tmp_dir('mention_no_param');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_GET['q']);

    $result = handle_misc_action('mention_users', 'GET');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $body = $result->getBody();
    $data = json_decode($body, true);
    $t->assertTrue('Response is valid JSON', is_array($data));
    $t->assertEquals('Returns empty users array', [], $data['users'] ?? null);

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_special_chars_filtered(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users Special Characters Filtered');

    $tmpDir = misc_actions_test_tmp_dir('mention_special');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'alice<script>';

    $result = handle_misc_action('mention_users', 'GET');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $body = $result->getBody();
    $data = json_decode($body, true);
    $t->assertTrue('Response is valid JSON', is_array($data));
    // Should only match 'alice' since special chars are stripped
    $usernames = array_column($data['users'], 'username');
    $t->assertTrue('Includes alice', in_array('alice', $usernames));

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_long_query_truncated(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users Long Query Truncated');

    $tmpDir = misc_actions_test_tmp_dir('mention_long');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = str_repeat('a', 25); // Longer than 20 chars

    $result = handle_misc_action('mention_users', 'GET');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $body = $result->getBody();
    $data = json_decode($body, true);
    $t->assertTrue('Response is valid JSON', is_array($data));
    $t->assertEquals('Returns empty users array for too long query', [], $data['users'] ?? null);

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_mention_users_limit_results(): Test
{
    $t = new Test('Misc Dispatcher - Mention Users Limits Results to 8');

    $tmpDir = misc_actions_test_tmp_dir('mention_limit');
    $env = misc_actions_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userPass = password_hash('user123', PASSWORD_DEFAULT);

    // Create 10 more users starting with 'test'
    for ($i = 1; $i <= 10; $i++) {
        $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute(["testuser{$i}", $userPass, "test{$i}@test.com", 'user', 'active', 1]);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'test';

    $result = handle_misc_action('mention_users', 'GET');

    $t->assertTrue('Returns Response object', $result instanceof \Bulletin\Response);
    $body = $result->getBody();
    $data = json_decode($body, true);
    $t->assertTrue('Response is valid JSON', is_array($data));
    $t->assertTrue('Limited to 8 results', count($data['users']) <= 8);

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_action_not_found(): Test
{
    $t = new Test('Misc Dispatcher - Action Not Found');

    $tmpDir = misc_actions_test_tmp_dir('not_found');
    $env = misc_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_misc_action('nonexistent_action', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException', true);
        $t->assertEquals('Exception message', 'Misc action not found', $e->getMessage());
    }

    misc_actions_cleanup($tmpDir);
    return $t;
}

function test_misc_dispatcher_all_actions_covered(): Test
{
    $t = new Test('Misc Dispatcher - All Actions Covered');

    $tmpDir = misc_actions_test_tmp_dir('all_actions');
    $env = misc_actions_setup_test_env($tmpDir);

    // Test preview
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['content'] = 'Test content';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];
    $result = handle_misc_action('preview', 'POST');
    $t->assertTrue('Preview returns Response', $result instanceof \Bulletin\Response);

    // Test mention_users
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'test';
    $result = handle_misc_action('mention_users', 'GET');
    $t->assertTrue('Mention users returns Response', $result instanceof \Bulletin\Response);

    misc_actions_cleanup($tmpDir);
    return $t;
}

register_tests(
    'test_misc_dispatcher_preview_get_method_not_allowed',
    'test_misc_dispatcher_preview_post',
    'test_misc_dispatcher_preview_post_csrf_invalid',
    'test_misc_dispatcher_preview_post_empty_content',
    'test_misc_dispatcher_preview_post_xss_safe',
    'test_misc_dispatcher_mention_users_get',
    'test_misc_dispatcher_mention_users_get_method_not_allowed',
    'test_misc_dispatcher_mention_users_empty_query',
    'test_misc_dispatcher_mention_users_no_query_param',
    'test_misc_dispatcher_mention_users_special_chars_filtered',
    'test_misc_dispatcher_mention_users_long_query_truncated',
    'test_misc_dispatcher_mention_users_limit_results',
    'test_misc_dispatcher_action_not_found',
    'test_misc_dispatcher_all_actions_covered'
);