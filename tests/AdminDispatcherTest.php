<?php

/**
 * AdminDispatcherTest — tests for the admin action dispatcher (src/actions/admin.php).
 * Covers dispatcher routing, method validation, 404/405 error handling, and CSRF validation delegation.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/actions/admin.php';
require_once __DIR__ . '/../src/actions/admin/settings.php';
require_once __DIR__ . '/../src/actions/admin/moderation.php';
require_once __DIR__ . '/../src/actions/admin/users.php';
require_once __DIR__ . '/../src/actions/admin/categories.php';
require_once __DIR__ . '/../src/actions/admin/langs.php';
require_once __DIR__ . '/../src/actions/admin/diagnostics.php';
require_once __DIR__ . '/../src/actions/admin/plugins.php';
require_once __DIR__ . '/../src/actions/admin/themes.php';
require_once __DIR__ . '/../src/actions/admin/catalog.php';
require_once __DIR__ . '/../src/actions/admin/updates.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/ThemeManager.php';
require_once __DIR__ . '/../lib/UpdateManager.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function admin_dispatcher_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_admin_dispatcher_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function admin_dispatcher_cleanup(string $dir): void
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

function admin_dispatcher_setup_test_env(string $tmpDir): array
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

    // Set up session for admin
    $_SESSION['user_id'] = $adminId;
    $_SESSION['session_version'] = 1;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Initialize AuthZ
    $pluginManager = new PluginManager($tmpDir . '/plugins', $dataDir . '/plugins.json');
    $app->authz = new AuthZ($pdo, $pluginManager);
    $app->pluginManager = $pluginManager;

    // Initialize ThemeManager
    @mkdir($tmpDir . '/themes', 0755, true);
    @mkdir($tmpDir . '/themes/freshbored', 0755, true);
    file_put_contents($tmpDir . '/themes/freshbored/style.css', 'body {}');
    file_put_contents($tmpDir . '/themes/freshbored/manifest.json', json_encode(['name' => 'freshbored', 'version' => '1.0.0']));
    $themeManager = new ThemeManager($tmpDir . '/themes', $dataDir . '/themes.json', 'freshbored');
    $app->themeManager = $themeManager;

    // Initialize UpdateManager
    require_once __DIR__ . '/../lib/UpdateManager.php';
    $updateManager = new UpdateManager($dataDir . '/updates.json', null, null, null, $tmpDir);
    $app->updateManager = $updateManager;

    // Set globals for admin handlers that use them
    global $pluginManager, $themeManager, $updateManager;
    $pluginManager = $app->pluginManager;
    $themeManager = $app->themeManager;
    $updateManager = $app->updateManager;

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

function test_admin_dispatcher_valid_action_get(): Test
{
    $t = new Test('Admin Dispatcher - Valid Action GET (admin dashboard)');

    $tmpDir = admin_dispatcher_test_tmp_dir('valid_get');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_action('admin', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_valid_action_post(): Test
{
    $t = new Test('Admin Dispatcher - Valid Action POST (admin settings)');

    $tmpDir = admin_dispatcher_test_tmp_dir('valid_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['site_name'] = 'Updated Forum';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_settings', 'POST');

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_method_not_allowed(): Test
{
    $t = new Test('Admin Dispatcher - Method Not Allowed (POST-only action with GET)');

    $tmpDir = admin_dispatcher_test_tmp_dir('method_not_allowed');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_admin_action('moderate', 'GET');
        $t->assert('Should throw MethodNotAllowedException', false);
    } catch (\Bulletin\MethodNotAllowedException $e) {
        $t->assertTrue('Throws MethodNotAllowedException', true);
        $t->assertEquals('Exception message', 'Method not allowed', $e->getMessage());
    }

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_action_not_found(): Test
{
    $t = new Test('Admin Dispatcher - Action Not Found');

    $tmpDir = admin_dispatcher_test_tmp_dir('not_found');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        handle_admin_action('nonexistent_action', 'GET');
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException', true);
        $t->assertEquals('Exception message', 'Admin action not found', $e->getMessage());
    }

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_csrf_validation_delegation(): Test
{
    $t = new Test('Admin Dispatcher - CSRF Validation Delegation');

    $tmpDir = admin_dispatcher_test_tmp_dir('csrf_delegation');
    $env = admin_dispatcher_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];

    // Hide the thread first
    $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);

    // Test with invalid CSRF token - should throw ForbiddenException from the handler
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'approve';
    $_POST['csrf_token'] = 'invalid_token';

    try {
        handle_admin_action('moderate', 'POST');
        $t->assert('Should throw ForbiddenException', false);
    } catch (\Bulletin\ForbiddenException $e) {
        $t->assertTrue('Throws ForbiddenException for invalid CSRF', true);
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_all_get_actions(): Test
{
    $t = new Test('Admin Dispatcher - All GET Actions');

    $tmpDir = admin_dispatcher_test_tmp_dir('all_get');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $getActions = [
        'admin',
        'admin_settings',
        'admin_smtp',
        'admin_moderation',
        'admin_roles',
        'admin_users',
        'admin_user_edit',
        'admin_categories',
        'admin_langs',
        'admin_diagnostics',
        'admin_plugins',
        'admin_themes',
        'admin_catalog',
        'admin_updates',
        'admin_get_images',
    ];

    foreach ($getActions as $action) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // For admin_user_edit, need an id parameter
        if ($action === 'admin_user_edit') {
            $result = handle_admin_action($action, 'GET', ['id' => (string)$env['userId']]);
        } else {
            $result = handle_admin_action($action, 'GET');
        }
        $t->assertTrue("Action '$action' returns true or Response", $result === true || $result instanceof \Bulletin\Response);
    }

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_all_post_actions(): Test
{
    $t = new Test('Admin Dispatcher - All POST Actions');

    $tmpDir = admin_dispatcher_test_tmp_dir('all_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];
    $catId = $env['catId'];

    // Create second category for move/copy tests
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['Off-Topic', 'Off topic discussion', 2]);
    $cat2Id = $pdo->lastInsertId();

    // Make user a moderator for frontend moderation tests
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['moderation.manage', 'threads.create', 'posts.create', 'threads.split', 'threads.merge'])]);
    }

    $postActions = [
        'admin_settings' => ['site_name' => 'Test', 'csrf_token' => $_SESSION['csrf_token']],
        'admin_smtp' => ['smtp_host' => 'localhost', 'csrf_token' => $_SESSION['csrf_token']],
        'admin_upload_site_image' => [],
        'moderate' => ['id' => (string)$threadId, 'do' => 'approve', 'csrf_token' => $_SESSION['csrf_token']],
        'frontend_moderate' => ['id' => (string)$threadId, 'do' => 'lock', 'csrf_token' => $_SESSION['csrf_token']],
        'split_thread' => ['thread_id' => (string)$threadId, 'post_ids' => [], 'new_title' => 'Split', 'csrf_token' => $_SESSION['csrf_token']],
        'merge_thread' => ['thread_id' => (string)$threadId, 'target_title' => 'Test Thread', 'csrf_token' => $_SESSION['csrf_token']],
        'admin_roles_action' => ['do' => 'create', 'role_name' => 'testrole', 'permissions' => ['threads.create'], 'csrf_token' => $_SESSION['csrf_token']],
        'admin_user_edit' => ['username' => 'testuser', 'email' => 'user@test.com', 'role' => 'user', 'status' => 'active', 'password' => '', 'csrf_token' => $_SESSION['csrf_token']],
        'admin_create_user' => ['username' => 'newuser', 'password' => 'Password123', 'email' => 'new@test.com', 'role' => 'user', 'status' => 'active', 'email_verified' => '1', 'csrf_token' => $_SESSION['csrf_token']],
        'admin_categories' => ['do' => 'create', 'name' => 'New Cat', 'description' => 'Desc', 'position' => '5', 'csrf_token' => $_SESSION['csrf_token']],
        'delete_category' => ['id' => (string)$catId, 'csrf_token' => $_SESSION['csrf_token']],
        'update_category_order' => ['order' => json_encode([$catId]), 'csrf_token' => $_SESSION['csrf_token']],
        'admin_langs' => ['do' => 'create', 'code' => 'xx', 'name' => 'Test', 'native_name' => 'Test', 'csrf_token' => $_SESSION['csrf_token']],
        'admin_plugins' => ['csrf_token' => $_SESSION['csrf_token']],
        'admin_themes' => ['csrf_token' => $_SESSION['csrf_token']],
        'admin_catalog' => ['csrf_token' => $_SESSION['csrf_token']],
        'admin_updates' => ['csrf_token' => $_SESSION['csrf_token']],
        'delete_user' => ['id' => (string)$userId, 'csrf_token' => $_SESSION['csrf_token']],
        'ban_user' => ['id' => (string)$userId, 'csrf_token' => $_SESSION['csrf_token']],
        'unban_user' => ['id' => (string)$userId, 'csrf_token' => $_SESSION['csrf_token']],
        'suspend_user' => ['id' => (string)$userId, 'days' => '7', 'csrf_token' => $_SESSION['csrf_token']],
    ];

    foreach ($postActions as $action => $postData) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $postData;

        // For admin_user_edit, need id parameter
        if ($action === 'admin_user_edit') {
            $result = handle_admin_action($action, 'POST', ['id' => (string)$userId]);
        } else {
            $result = handle_admin_action($action, 'POST');
        }

        $t->assertTrue("Action '$action' returns true or Response", $result === true || $result instanceof \Bulletin\Response);
    }

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_dashboard_post_settings(): Test
{
    $t = new Test('Admin Dispatcher - Admin Dashboard POST Settings Save');

    $tmpDir = admin_dispatcher_test_tmp_dir('dashboard_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['save_settings'] = '1';
    $_POST['site_name'] = 'New Site Name';
    $_POST['default_lang'] = 'en';
    $_POST['available_langs'] = 'en,fr';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin', 'POST');

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    // Verify config was updated
    $configPath = $env['dataDir'] . '/config.json';
    $config = json_decode(file_get_contents($configPath), true);
    $t->assertEquals('Site name updated in config', 'New Site Name', $config['site_name']);
    $t->assertEquals('Available langs updated', ['en', 'fr'], $config['available_langs']);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_dashboard_post_rate_limit(): Test
{
    $t = new Test('Admin Dispatcher - Admin Dashboard POST Rate Limit');

    $tmpDir = admin_dispatcher_test_tmp_dir('dashboard_ratelimit');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // Make many requests to trigger rate limit
    for ($i = 0; $i < 35; $i++) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['save_settings'] = '1';
        $_POST['site_name'] = 'Test ' . $i;
        $_POST['csrf_token'] = $_SESSION['csrf_token'];

        try {
            handle_admin_action('admin', 'POST');
        } catch (\Bulletin\TooManyRequestsException $e) {
            if ($i >= 30) {
                $t->assertTrue('Throws TooManyRequestsException after limit', true);
                admin_dispatcher_cleanup($tmpDir);
                return $t;
            }
        }
    }

    $t->assert('Should have thrown TooManyRequestsException', false);
    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_user_edit_get(): Test
{
    $t = new Test('Admin Dispatcher - Admin User Edit GET');

    $tmpDir = admin_dispatcher_test_tmp_dir('user_edit_get');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_action('admin_user_edit', 'GET', ['id' => (string)$env['userId']]);

    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_categories_get(): Test
{
    $t = new Test('Admin Dispatcher - Admin Categories GET');

    $tmpDir = admin_dispatcher_test_tmp_dir('categories_get');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_action('admin_categories', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_langs_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Langs GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('langs_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_langs', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST - create language
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'create';
    $_POST['code'] = 'xx';
    $_POST['name'] = 'Test Language';
    $_POST['native_name'] = 'Test Language';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_langs', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_plugins_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Plugins GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('plugins_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_plugins', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST (without file upload - should handle gracefully)
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_plugins', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_themes_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Themes GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('themes_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_themes', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST (activate theme)
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['activate_theme'] = '1';
    $_POST['theme'] = 'freshbored';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_themes', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_catalog_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Catalog GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('catalog_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_catalog', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_catalog', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_updates_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Updates GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('updates_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_updates', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_updates', 'POST');
    $t->assertTrue('POST returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_diagnostics_get(): Test
{
    $t = new Test('Admin Dispatcher - Admin Diagnostics GET');

    $tmpDir = admin_dispatcher_test_tmp_dir('diagnostics_get');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_diagnostics', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_smtp_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin SMTP GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('smtp_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_smtp', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['smtp_host'] = 'localhost';
    $_POST['smtp_port'] = '587';
    $_POST['smtp_user'] = 'test';
    $_POST['smtp_pass'] = 'pass';
    $_POST['smtp_secure'] = 'tls';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_smtp', 'POST');
    $t->assertTrue('POST returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_roles_get_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Roles GET and POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('roles_get_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_roles', 'GET');
    $t->assertTrue('GET returns true', $result === true);

    // POST - create role
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'create';
    $_POST['role_name'] = 'testrole';
    $_POST['permissions'] = ['threads.create', 'posts.create'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_roles_action', 'POST');
    $t->assertTrue('POST returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_users_get(): Test
{
    $t = new Test('Admin Dispatcher - Admin Users GET');

    $tmpDir = admin_dispatcher_test_tmp_dir('users_get');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_users', 'GET');

    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_create_user_post(): Test
{
    $t = new Test('Admin Dispatcher - Admin Create User POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('create_user_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = 'newuser';
    $_POST['password'] = 'Password123';
    $_POST['email'] = 'newuser@test.com';
    $_POST['role'] = 'user';
    $_POST['status'] = 'active';
    $_POST['email_verified'] = '1';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_create_user', 'POST');

    $t->assertTrue('Returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_delete_user_post(): Test
{
    $t = new Test('Admin Dispatcher - Delete User POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('delete_user_post');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$env['userId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('delete_user', 'POST');

    $t->assertTrue('Returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_ban_unban_suspend_user_post(): Test
{
    $t = new Test('Admin Dispatcher - Ban/Unban/Suspend User POST');

    $tmpDir = admin_dispatcher_test_tmp_dir('ban_unban_suspend');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // Ban user
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$env['userId'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('ban_user', 'POST');
    $t->assertTrue('Ban returns redirect Response', $result instanceof \Bulletin\Response);

    // Unban user
    $_POST['csrf_token'] = $_SESSION['csrf_token'];
    $result = handle_admin_action('unban_user', 'POST');
    $t->assertTrue('Unban returns redirect Response', $result instanceof \Bulletin\Response);

    // Suspend user
    $_POST['days'] = '7';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];
    $result = handle_admin_action('suspend_user', 'POST');
    $t->assertTrue('Suspend returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_frontend_moderate_actions(): Test
{
    $t = new Test('Admin Dispatcher - Frontend Moderate Actions');

    $tmpDir = admin_dispatcher_test_tmp_dir('frontend_moderate');
    $env = admin_dispatcher_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];
    $catId = $env['catId'];

    // Create second category
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['Off-Topic', 'Off topic discussion', 2]);
    $cat2Id = $pdo->lastInsertId();

    // Make user a moderator
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['moderation.manage', 'threads.create', 'posts.create'])]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Test lock
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'lock';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('frontend_moderate', 'POST');
    $t->assertTrue('Lock returns redirect Response', $result instanceof \Bulletin\Response);

    // Test move
    $_POST['do'] = 'move';
    $_POST['category_id'] = (string)$cat2Id;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('frontend_moderate', 'POST');
    $t->assertTrue('Move returns redirect Response', $result instanceof \Bulletin\Response);

    // Test copy
    $_POST['do'] = 'copy';
    $_POST['category_id'] = (string)$catId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('frontend_moderate', 'POST');
    $t->assertTrue('Copy returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_split_merge_thread(): Test
{
    $t = new Test('Admin Dispatcher - Split/Merge Thread');

    $tmpDir = admin_dispatcher_test_tmp_dir('split_merge');
    $env = admin_dispatcher_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];

    // Create additional posts for split
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'Reply 1', 'visible']);
    $postId2 = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'Reply 2', 'visible']);
    $postId3 = $pdo->lastInsertId();

    // Create target thread for merge
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$env['catId'], $userId, 'Target Thread', 'Target content', 'visible']);
    $targetThreadId = $pdo->lastInsertId();

    // Make user a moderator with split/merge permissions
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['threads.split', 'threads.merge', 'moderation.manage', 'threads.create', 'posts.create'])]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Test split thread
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$threadId;
    $_POST['post_ids'] = [$postId2, $postId3];
    $_POST['new_title'] = 'Split Thread';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('split_thread', 'POST');
    $t->assertTrue('Split returns redirect Response', $result instanceof \Bulletin\Response);

    // Test merge thread
    $_POST['thread_id'] = (string)$threadId;
    $_POST['target_title'] = 'Target Thread';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('merge_thread', 'POST');
    $t->assertTrue('Merge returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_admin_upload_site_image_get_images(): Test
{
    $t = new Test('Admin Dispatcher - Admin Upload Site Image / Get Images');

    $tmpDir = admin_dispatcher_test_tmp_dir('upload_get_images');
    $env = admin_dispatcher_setup_test_env($tmpDir);

    // Test admin_upload_site_image
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('admin_upload_site_image', 'POST');
    $t->assertTrue('Upload site image returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    // Test admin_get_images
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = handle_admin_action('admin_get_images', 'GET');
    $t->assertTrue('Get images returns true or Response', $result === true || $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_update_category_order(): Test
{
    $t = new Test('Admin Dispatcher - Update Category Order');

    $tmpDir = admin_dispatcher_test_tmp_dir('update_cat_order');
    $env = admin_dispatcher_setup_test_env($tmpDir);
    $catId = $env['catId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['order'] = json_encode([$catId]);
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('update_category_order', 'POST');
    $t->assertTrue('Returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

function test_admin_dispatcher_delete_category(): Test
{
    $t = new Test('Admin Dispatcher - Delete Category');

    $tmpDir = admin_dispatcher_test_tmp_dir('delete_category');
    $env = admin_dispatcher_setup_test_env($tmpDir);
    $catId = $env['catId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$catId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_action('delete_category', 'POST');
    $t->assertTrue('Returns redirect Response', $result instanceof \Bulletin\Response);

    admin_dispatcher_cleanup($tmpDir);
    return $t;
}

register_tests(
    'test_admin_dispatcher_valid_action_get',
    'test_admin_dispatcher_valid_action_post',
    'test_admin_dispatcher_method_not_allowed',
    'test_admin_dispatcher_action_not_found',
    'test_admin_dispatcher_csrf_validation_delegation',
    'test_admin_dispatcher_all_get_actions',
    'test_admin_dispatcher_all_post_actions',
    'test_admin_dispatcher_admin_dashboard_post_settings',
    'test_admin_dispatcher_admin_dashboard_post_rate_limit',
    'test_admin_dispatcher_admin_user_edit_get',
    'test_admin_dispatcher_admin_categories_get',
    'test_admin_dispatcher_admin_langs_get_post',
    'test_admin_dispatcher_admin_plugins_get_post',
    'test_admin_dispatcher_admin_themes_get_post',
    'test_admin_dispatcher_admin_catalog_get_post',
    'test_admin_dispatcher_admin_updates_get_post',
    'test_admin_dispatcher_admin_diagnostics_get',
    'test_admin_dispatcher_admin_smtp_get_post',
    'test_admin_dispatcher_admin_roles_get_post',
    'test_admin_dispatcher_admin_users_get',
    'test_admin_dispatcher_admin_create_user_post',
    'test_admin_dispatcher_delete_user_post',
    'test_admin_dispatcher_ban_unban_suspend_user_post',
    'test_admin_dispatcher_frontend_moderate_actions',
    'test_admin_dispatcher_split_merge_thread',
    'test_admin_dispatcher_admin_upload_site_image_get_images',
    'test_admin_dispatcher_update_category_order',
    'test_admin_dispatcher_delete_category'
);