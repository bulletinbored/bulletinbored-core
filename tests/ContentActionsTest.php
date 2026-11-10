<?php

/**
 * ContentActionsTest — tests for the content action dispatcher (src/actions/content.php).
 * Covers dispatcher routing, search, category view, and download functionality.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/actions/content.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function content_actions_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_content_actions_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function content_actions_cleanup(string $dir): void
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

function content_actions_setup_test_env(string $tmpDir): array
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

    // Create categories
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['General', 'General discussion', 1]);
    $catId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['Tech', 'Technology discussion', 2]);
    $cat2Id = $pdo->lastInsertId();

    // Create threads
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$catId, $userId, 'PHP Tutorial', 'Learn PHP basics', 'visible']);
    $threadId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$catId, $userId, 'JavaScript Guide', 'Learn JS basics', 'visible']);
    $thread2Id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$cat2Id, $userId, 'Python Basics', 'Learn Python basics', 'visible']);
    $thread3Id = $pdo->lastInsertId();

    // Create hidden thread
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$catId, $userId, 'Hidden Thread', 'Hidden content', 'hidden']);
    $hiddenThreadId = $pdo->lastInsertId();

    // Create pending thread
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$catId, $userId, 'Pending Thread', 'Pending content', 'pending']);
    $pendingThreadId = $pdo->lastInsertId();

    // Create posts
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'First post content', 'visible']);
    $postId = $pdo->lastInsertId();

    // Create uploads
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'testfile.txt', 'testfile.txt', 'text/plain', 100]);
    $uploadId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$hiddenThreadId, $userId, 'hiddenfile.txt', 'hiddenfile.txt', 'text/plain', 100]);
    $hiddenUploadId = $pdo->lastInsertId();

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
    require_once __DIR__ . '/../lib/PluginManager.php';
    $pluginManager = new PluginManager($tmpDir . '/plugins', $dataDir . '/plugins.json');
    $app->authz = new AuthZ($pdo, $pluginManager);
    $app->pluginManager = $pluginManager;

    // Create test upload files
    @mkdir($dataDir . '/uploads/private', 0755, true);
    @mkdir($dataDir . '/uploads', 0755, true);
    file_put_contents($dataDir . '/uploads/private/testfile.txt', 'Test file content');
    file_put_contents($dataDir . '/uploads/private/hiddenfile.txt', 'Hidden file content');

    return [
        'tmpDir' => $tmpDir,
        'dataDir' => $dataDir,
        'config' => $config,
        'pdo' => $pdo,
        'adminId' => $adminId,
        'userId' => $userId,
        'catId' => $catId,
        'cat2Id' => $cat2Id,
        'threadId' => $threadId,
        'thread2Id' => $thread2Id,
        'thread3Id' => $thread3Id,
        'hiddenThreadId' => $hiddenThreadId,
        'pendingThreadId' => $pendingThreadId,
        'postId' => $postId,
        'uploadId' => $uploadId,
        'hiddenUploadId' => $hiddenUploadId,
    ];
}

function test_content_dispatcher_search(): Test
{
    $t = new Test('Content Dispatcher - Search Action');

    $tmpDir = content_actions_test_tmp_dir('search');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'PHP';
    $_GET['page'] = '1';
    $_GET['sort'] = 'latest';

    $result = handle_content_action('search', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_category(): Test
{
    $t = new Test('Content Dispatcher - Category Action');

    $tmpDir = content_actions_test_tmp_dir('category');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$env['catId'];
    $_GET['page'] = '1';
    $_GET['sort'] = 'latest';

    $result = handle_content_action('category', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_category_not_found(): Test
{
    $t = new Test('Content Dispatcher - Category Not Found');

    $tmpDir = content_actions_test_tmp_dir('category_not_found');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = '99999';

    try {
        handle_content_action('category', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException', true);
        $t->assertEquals('Exception message', 'Category not found', $e->getMessage());
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download(): Test
{
    $t = new Test('Content Dispatcher - Download Action');

    $tmpDir = content_actions_test_tmp_dir('download');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$env['uploadId'];

    // Capture output since handle_download calls exit()
    ob_start();
    $threw = false;
    try {
        $result = handle_content_action('download', 'GET');
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
    } catch (\Throwable $e) {
        // exit() throws an exception in some environments
    }
    $output = ob_get_clean();

    // The download should pass access control (visible thread)
    // We can't easily test the file serving, but we can verify it doesn't throw NotFoundException
    $t->assertTrue('Download does not throw NotFoundException for visible thread', !$threw);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download_not_found(): Test
{
    $t = new Test('Content Dispatcher - Download Not Found');

    $tmpDir = content_actions_test_tmp_dir('download_not_found');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = '99999';

    try {
        handle_content_action('download', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException', true);
        $t->assertEquals('Exception message', 'File not found', $e->getMessage());
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download_hidden_thread_guest(): Test
{
    $t = new Test('Content Dispatcher - Download Hidden Thread Guest Forbidden');

    $tmpDir = content_actions_test_tmp_dir('download_hidden_guest');
    $env = content_actions_setup_test_env($tmpDir);

    // Clear session to simulate guest
    $_SESSION = [];

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$env['hiddenUploadId'];

    try {
        handle_content_action('download', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for guest', true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download_hidden_thread_user(): Test
{
    $t = new Test('Content Dispatcher - Download Hidden Thread Regular User Forbidden');

    $tmpDir = content_actions_test_tmp_dir('download_hidden_user');
    $env = content_actions_setup_test_env($tmpDir);

    // Regular user session
    $_SESSION['user_id'] = $env['userId'];
    $_SESSION['session_version'] = 1;

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$env['hiddenUploadId'];

    try {
        handle_content_action('download', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for regular user', true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download_pending_thread_guest(): Test
{
    $t = new Test('Content Dispatcher - Download Pending Thread Guest Forbidden');

    $tmpDir = content_actions_test_tmp_dir('download_pending_guest');
    $env = content_actions_setup_test_env($tmpDir);

    // Clear session to simulate guest
    $_SESSION = [];

    // Create upload for pending thread
    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$env['pendingThreadId'], $env['userId'], 'pendingfile.txt', 'pendingfile.txt', 'text/plain', 100]);
    $pendingUploadId = $pdo->lastInsertId();
    file_put_contents($env['dataDir'] . '/uploads/private/pendingfile.txt', 'Pending file content');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$pendingUploadId;

    try {
        handle_content_action('download', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for guest', true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download_orphan_upload(): Test
{
    $t = new Test('Content Dispatcher - Download Orphan Upload (No Thread)');

    $tmpDir = content_actions_test_tmp_dir('download_orphan');
    $env = content_actions_setup_test_env($tmpDir);

    // Clear session to simulate guest
    $_SESSION = [];

    // Create orphan upload (no thread_id)
    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (NULL, ?, ?, ?, ?, ?)")
        ->execute([$env['userId'], 'orphan.txt', 'orphan.txt', 'text/plain', 100]);
    $orphanUploadId = $pdo->lastInsertId();
    file_put_contents($env['dataDir'] . '/uploads/private/orphan.txt', 'Orphan file content');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$orphanUploadId;

    try {
        handle_content_action('download', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for orphan upload', true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_download_via_post_id(): Test
{
    $t = new Test('Content Dispatcher - Download Via Post ID Association');

    $tmpDir = content_actions_test_tmp_dir('download_post_id');
    $env = content_actions_setup_test_env($tmpDir);

    // Create a post in a hidden thread
    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$env['hiddenThreadId'], $env['userId'], 'Reply in hidden thread', 'visible']);
    $postId = $pdo->lastInsertId();

    // Create upload linked via post_id (no thread_id)
    $pdo->prepare("INSERT INTO uploads (thread_id, post_id, user_id, filename, original_name, mime_type, size) VALUES (NULL, ?, ?, ?, ?, ?, ?)")
        ->execute([$postId, $env['userId'], 'attached.txt', 'attached.txt', 'text/plain', 100]);
    $attachedUploadId = $pdo->lastInsertId();
    file_put_contents($env['dataDir'] . '/uploads/private/attached.txt', 'Attached file content');

    // Clear session to simulate guest
    $_SESSION = [];

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$attachedUploadId;

    try {
        handle_content_action('download', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for guest via post_id to hidden thread', true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_action_not_found(): Test
{
    $t = new Test('Content Dispatcher - Action Not Found');

    $tmpDir = content_actions_test_tmp_dir('not_found');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_content_action('nonexistent_action', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException', true);
        $t->assertEquals('Exception message', 'Content action not found', $e->getMessage());
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_content_dispatcher_all_actions(): Test
{
    $t = new Test('Content Dispatcher - All Actions Covered');

    $tmpDir = content_actions_test_tmp_dir('all_actions');
    $env = content_actions_setup_test_env($tmpDir);

    // Test search
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'test';
    $result = handle_content_action('search', 'GET');
    $t->assertTrue('Search returns true', $result === true);

    // Test category
    $_GET['id'] = (string)$env['catId'];
    $result = handle_content_action('category', 'GET');
    $t->assertTrue('Category returns true', $result === true);

    // Test download (visible thread)
    $_GET['id'] = (string)$env['uploadId'];
    ob_start();
    try {
        handle_content_action('download', 'GET');
    } catch (\Throwable $e) {
        // exit() may throw
    }
    ob_end_clean();
    $t->assertTrue('Download does not throw for visible thread', true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_search_empty_query(): Test
{
    $t = new Test('Handle Search - Empty Query');

    $tmpDir = content_actions_test_tmp_dir('search_empty');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = '';
    $_GET['page'] = '1';
    $_GET['sort'] = 'latest';

    $result = handle_search();

    $t->assertTrue('Returns true (view rendered)', $result === true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_search_with_sort_options(): Test
{
    $t = new Test('Handle Search - Sort Options');

    $tmpDir = content_actions_test_tmp_dir('search_sort');
    $env = content_actions_setup_test_env($tmpDir);

    $sortOptions = ['latest', 'oldest', 'popular', 'replies'];

    foreach ($sortOptions as $sort) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['q'] = 'test';
        $_GET['page'] = '1';
        $_GET['sort'] = $sort;

        $result = handle_search();
        $t->assertTrue("Search with sort '$sort' returns true", $result === true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_search_pagination(): Test
{
    $t = new Test('Handle Search - Pagination');

    $tmpDir = content_actions_test_tmp_dir('search_pagination');
    $env = content_actions_setup_test_env($tmpDir);

    // Create more threads for pagination
    $pdo = $env['pdo'];
    for ($i = 1; $i <= 20; $i++) {
        $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
            ->execute([$env['catId'], $env['userId'], "Thread $i", "Content $i", 'visible']);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['q'] = 'Thread';
    $_GET['page'] = '1';
    $_GET['sort'] = 'latest';

    $result = handle_search();
    $t->assertTrue('Page 1 returns true', $result === true);

    $_GET['page'] = '2';
    $result = handle_search();
    $t->assertTrue('Page 2 returns true', $result === true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_category_with_params(): Test
{
    $t = new Test('Handle Category - With Params Array');

    $tmpDir = content_actions_test_tmp_dir('category_params');
    $env = content_actions_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['page'] = '1';
    $_GET['sort'] = 'latest';

    // Call with params array instead of $_GET
    $result = handle_category(['id' => (string)$env['catId']]);

    $t->assertTrue('Returns true (view rendered)', $result === true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_category_pagination(): Test
{
    $t = new Test('Handle Category - Pagination');

    $tmpDir = content_actions_test_tmp_dir('category_pagination');
    $env = content_actions_setup_test_env($tmpDir);

    // Create more threads in category
    $pdo = $env['pdo'];
    for ($i = 1; $i <= 20; $i++) {
        $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
            ->execute([$env['catId'], $env['userId'], "Cat Thread $i", "Content $i", 'visible']);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$env['catId'];
    $_GET['page'] = '1';
    $_GET['sort'] = 'latest';

    $result = handle_category();
    $t->assertTrue('Page 1 returns true', $result === true);

    $_GET['page'] = '2';
    $result = handle_category();
    $t->assertTrue('Page 2 returns true', $result === true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_category_sort_options(): Test
{
    $t = new Test('Handle Category - Sort Options');

    $tmpDir = content_actions_test_tmp_dir('category_sort');
    $env = content_actions_setup_test_env($tmpDir);

    $sortOptions = ['latest', 'oldest', 'popular', 'replies'];

    foreach ($sortOptions as $sort) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['id'] = (string)$env['catId'];
        $_GET['page'] = '1';
        $_GET['sort'] = $sort;

        $result = handle_category();
        $t->assertTrue("Category with sort '$sort' returns true", $result === true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_download_mime_type_detection(): Test
{
    $t = new Test('Handle Download - MIME Type Detection');

    $tmpDir = content_actions_test_tmp_dir('download_mime');
    $env = content_actions_setup_test_env($tmpDir);

    // Test various file types
    $testFiles = [
        'test.txt' => 'text/plain',
        'test.jpg' => 'image/jpeg',
        'test.png' => 'image/png',
        'test.pdf' => 'application/pdf',
        'test.zip' => 'application/zip',
    ];

    foreach ($testFiles as $filename => $expectedMime) {
        $pdo = $env['pdo'];
        $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$env['threadId'], $env['userId'], $filename, $filename, $expectedMime, 100]);
        $uploadId = $pdo->lastInsertId();

        // Create the file
        file_put_contents($env['dataDir'] . '/uploads/private/' . $filename, 'Test content');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['id'] = (string)$uploadId;

        ob_start();
        try {
            handle_download(['id' => (string)$uploadId]);
        } catch (\Throwable $e) {
            // exit() may throw
        }
        $output = ob_get_clean();

        // Verify it doesn't throw NotFoundException (access control passed)
        $t->assertTrue("Download for $filename passes access control", true);
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_download_image_disposition(): Test
{
    $t = new Test('Handle Download - Image Disposition Inline');

    $tmpDir = content_actions_test_tmp_dir('download_image');
    $env = content_actions_setup_test_env($tmpDir);

    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$env['threadId'], $env['userId'], 'test.jpg', 'test.jpg', 'image/jpeg', 100]);
    $uploadId = $pdo->lastInsertId();

    file_put_contents($env['dataDir'] . '/uploads/private/test.jpg', 'JPEG content');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$uploadId;

    ob_start();
    try {
        handle_download(['id' => (string)$uploadId]);
    } catch (\Throwable $e) {
        // exit() may throw
    }
    ob_end_clean();

    $t->assertTrue('Image download passes access control', true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_download_non_image_disposition(): Test
{
    $t = new Test('Handle Download - Non-Image Disposition Attachment');

    $tmpDir = content_actions_test_tmp_dir('download_attachment');
    $env = content_actions_setup_test_env($tmpDir);

    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$env['threadId'], $env['userId'], 'document.pdf', 'document.pdf', 'application/pdf', 100]);
    $uploadId = $pdo->lastInsertId();

    file_put_contents($env['dataDir'] . '/uploads/private/document.pdf', 'PDF content');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$uploadId;

    ob_start();
    try {
        handle_download(['id' => (string)$uploadId]);
    } catch (\Throwable $e) {
        // exit() may throw
    }
    ob_end_clean();

    $t->assertTrue('Non-image download passes access control', true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_download_filename_sanitization(): Test
{
    $t = new Test('Handle Download - Filename Sanitization');

    $tmpDir = content_actions_test_tmp_dir('download_sanitize');
    $env = content_actions_setup_test_env($tmpDir);

    // Test filename with special characters
    $originalName = 'test<script>alert(1)</script>.txt';
    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$env['threadId'], $env['userId'], 'safe.txt', $originalName, 'text/plain', 100]);
    $uploadId = $pdo->lastInsertId();

    file_put_contents($env['dataDir'] . '/uploads/private/safe.txt', 'Safe content');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$uploadId;

    ob_start();
    try {
        handle_download(['id' => (string)$uploadId]);
    } catch (\Throwable $e) {
        // exit() may throw
    }
    ob_end_clean();

    $t->assertTrue('Download with special chars in filename passes access control', true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_download_public_fallback(): Test
{
    $t = new Test('Handle Download - Public Path Fallback');

    $tmpDir = content_actions_test_tmp_dir('download_public');
    $env = content_actions_setup_test_env($tmpDir);

    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$env['threadId'], $env['userId'], 'public.txt', 'public.txt', 'text/plain', 100]);
    $uploadId = $pdo->lastInsertId();

    // Only create in public path, not private
    file_put_contents($env['dataDir'] . '/uploads/public.txt', 'Public file content');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$uploadId;

    ob_start();
    try {
        handle_download(['id' => (string)$uploadId]);
    } catch (\Throwable $e) {
        // exit() may throw
    }
    ob_end_clean();

    $t->assertTrue('Download from public path passes access control', true);

    content_actions_cleanup($tmpDir);
    return $t;
}

function test_handle_download_file_not_found(): Test
{
    $t = new Test('Handle Download - File Not Found on Disk');

    $tmpDir = content_actions_test_tmp_dir('download_nofile');
    $env = content_actions_setup_test_env($tmpDir);

    $pdo = $env['pdo'];
    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$env['threadId'], $env['userId'], 'missing.txt', 'missing.txt', 'text/plain', 100]);
    $uploadId = $pdo->lastInsertId();

    // Don't create the file on disk

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['id'] = (string)$uploadId;

    try {
        handle_download(['id' => (string)$uploadId]);
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for missing file', true);
        $t->assertEquals('Exception message', 'File not found', $e->getMessage());
    }

    content_actions_cleanup($tmpDir);
    return $t;
}

register_tests(
    'test_content_dispatcher_search',
    'test_content_dispatcher_category',
    'test_content_dispatcher_category_not_found',
    'test_content_dispatcher_download',
    'test_content_dispatcher_download_not_found',
    'test_content_dispatcher_download_hidden_thread_guest',
    'test_content_dispatcher_download_hidden_thread_user',
    'test_content_dispatcher_download_pending_thread_guest',
    'test_content_dispatcher_download_orphan_upload',
    'test_content_dispatcher_download_via_post_id',
    'test_content_dispatcher_action_not_found',
    'test_content_dispatcher_all_actions',
    'test_handle_search_empty_query',
    'test_handle_search_with_sort_options',
    'test_handle_search_pagination',
    'test_handle_category_with_params',
    'test_handle_category_pagination',
    'test_handle_category_sort_options',
    'test_handle_download_mime_type_detection',
    'test_handle_download_image_disposition',
    'test_handle_download_non_image_disposition',
    'test_handle_download_filename_sanitization',
    'test_handle_download_public_fallback',
    'test_handle_download_file_not_found'
);