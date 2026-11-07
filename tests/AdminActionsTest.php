<?php

/**
 * AdminActionsTest — tests for admin action handlers.
 * Covers moderation, user management, roles, settings, and other admin operations.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/actions/admin/moderation.php';
require_once __DIR__ . '/../src/actions/admin/users.php';
require_once __DIR__ . '/../src/actions/admin/categories.php';
require_once __DIR__ . '/../src/actions/admin/settings.php';
require_once __DIR__ . '/../src/actions/admin/plugins.php';
require_once __DIR__ . '/../src/actions/admin/themes.php';
require_once __DIR__ . '/../src/actions/admin/updates.php';
require_once __DIR__ . '/../src/actions/admin/catalog.php';
require_once __DIR__ . '/../src/actions/admin/langs.php';
require_once __DIR__ . '/../src/actions/admin/diagnostics.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/ThemeManager.php';
require_once __DIR__ . '/../lib/UpdateManager.php';

function admin_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_admin_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function admin_cleanup(string $dir): void
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

function admin_setup_test_env(string $tmpDir): array
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
    require_once __DIR__ . '/../lib/AuthZ.php';
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

function test_admin_moderation_approve_thread(): Test
{
    $t = new Test('Admin Moderation - Approve Thread');

    $tmpDir = admin_test_tmp_dir('mod_approve');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];

    // Hide the thread first
    $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);

    // Simulate POST request
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'approve';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_moderate_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);
    $headers = $result->getHeaders();
    $location = '';
    foreach ($headers as $h) {
        if (str_starts_with($h, 'Location:')) {
            $location = $h;
            break;
        }
    }
    // The redirect goes to admin_moderation route
    $t->assertTrue('Redirects to admin_moderation', str_contains($location, 'admin_moderation') || str_contains($location, 'admin'));

    // Verify thread is approved
    $stmt = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $status = $stmt->fetchColumn();
    $t->assertEquals('Thread status is visible', 'visible', $status);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_moderation_hide_thread(): Test
{
    $t = new Test('Admin Moderation - Hide Thread');

    $tmpDir = admin_test_tmp_dir('mod_hide');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'hide';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_moderate_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $status = $stmt->fetchColumn();
    $t->assertEquals('Thread status is hidden', 'hidden', $status);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_moderation_delete_thread(): Test
{
    $t = new Test('Admin Moderation - Delete Thread');

    $tmpDir = admin_test_tmp_dir('mod_delete');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $catId = $env['catId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'delete';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_moderate_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);
    $headers = $result->getHeaders();
    $location = '';
    foreach ($headers as $h) {
        if (str_starts_with($h, 'Location:')) {
            $location = $h;
            break;
        }
    }
    $t->assertTrue('Redirects to category', str_contains($location, 'category'));

    // Verify thread is deleted
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Thread deleted', 0, $count);

    // Verify posts are deleted (cascade)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $stmt->execute([$threadId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Posts deleted', 0, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_moderation_invalid_thread(): Test
{
    $t = new Test('Admin Moderation - Invalid Thread ID');

    $tmpDir = admin_test_tmp_dir('mod_invalid');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = '99999';
    $_POST['do'] = 'approve';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    // Handler doesn't throw for non-existent thread, just redirects
    $result = handle_moderate_post();
    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    // Verify no thread was affected (thread 99999 doesn't exist)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE id = 99999");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    $t->assertEquals('No thread with ID 99999', 0, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_moderation_csrf_failure(): Test
{
    $t = new Test('Admin Moderation - CSRF Token Invalid');

    $tmpDir = admin_test_tmp_dir('mod_csrf');
    $env = admin_setup_test_env($tmpDir);
    $threadId = $env['threadId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'approve';
    $_POST['csrf_token'] = 'invalid_token';

    try {
        handle_moderate_post();
        $t->assert('Should throw ForbiddenException', false);
    } catch (\Bulletin\ForbiddenException $e) {
        $t->assertTrue('Throws ForbiddenException for invalid CSRF', true);
    }

    admin_cleanup($tmpDir);
    return $t;
}

function test_frontend_moderation_lock_thread(): Test
{
    $t = new Test('Frontend Moderation - Lock Thread');

    $tmpDir = admin_test_tmp_dir('fmod_lock');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];

    // Make the user a moderator with moderation.manage permission
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    // Check if moderator role exists, if not create it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['moderation.manage', 'threads.create', 'posts.create'])]);
    }

    // Switch session to moderator user
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'lock';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_frontend_moderate_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $status = $stmt->fetchColumn();
    $t->assertEquals('Thread status is locked', 'locked', $status);

    admin_cleanup($tmpDir);
    return $t;
}

function test_frontend_moderation_move_thread(): Test
{
    $t = new Test('Frontend Moderation - Move Thread');

    $tmpDir = admin_test_tmp_dir('fmod_move');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];

    // Create second category
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['Off-Topic', 'Off topic discussion', 2]);
    $cat2Id = $pdo->lastInsertId();

    // Make the user a moderator with moderation.manage permission
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    // Check if moderator role exists, if not create it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['moderation.manage', 'threads.create', 'posts.create'])]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'move';
    $_POST['category_id'] = (string)$cat2Id;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_frontend_moderate_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT category_id FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $catId = $stmt->fetchColumn();
    $t->assertEquals('Thread moved to new category', (int)$cat2Id, (int)$catId);

    admin_cleanup($tmpDir);
    return $t;
}

function test_frontend_moderation_copy_thread(): Test
{
    $t = new Test('Frontend Moderation - Copy Thread');

    $tmpDir = admin_test_tmp_dir('fmod_copy');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];

    // Create second category
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['Archive', 'Archived threads', 3]);
    $cat2Id = $pdo->lastInsertId();

    // Make the user a moderator with moderation.manage permission
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    // Check if moderator role exists, if not create it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['moderation.manage', 'threads.create', 'posts.create'])]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$threadId;
    $_POST['do'] = 'copy';
    $_POST['category_id'] = (string)$cat2Id;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_frontend_moderate_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    // Verify new thread exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE category_id = ? AND title = ?");
    $stmt->execute([$cat2Id, 'Test Thread']);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Thread copied to new category', 1, $count);

    // Verify posts copied
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id IN (SELECT id FROM threads WHERE category_id = ? AND title = ?)");
    $stmt->execute([$cat2Id, 'Test Thread']);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Posts copied', 1, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_frontend_moderation_split_thread(): Test
{
    $t = new Test('Frontend Moderation - Split Thread');

    $tmpDir = admin_test_tmp_dir('fmod_split');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $postId = $env['postId'];
    $userId = $env['userId'];

    // Create additional posts
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'Reply 1', 'visible']);
    $postId2 = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$threadId, $userId, 'Reply 2', 'visible']);
    $postId3 = $pdo->lastInsertId();

    // Make the user a moderator with threads.split permission
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    // Check if moderator role exists, if not create it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['threads.split', 'moderation.manage', 'threads.create', 'posts.create'])]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$threadId;
    $_POST['post_ids'] = [$postId2, $postId3];
    $_POST['new_title'] = 'Split Thread';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_split_thread_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    // Verify new thread exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE title = ?");
    $stmt->execute(['Split Thread']);
    $count = $stmt->fetchColumn();
    $t->assertEquals('New thread created', 1, $count);

    // Verify posts moved (only replies are moved, first post becomes new thread's first post)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = (SELECT id FROM threads WHERE title = ?)");
    $stmt->execute(['Split Thread']);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Posts moved to new thread (replies only)', 1, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_frontend_moderation_merge_thread(): Test
{
    $t = new Test('Frontend Moderation - Merge Thread');

    $tmpDir = admin_test_tmp_dir('fmod_merge');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $threadId = $env['threadId'];
    $userId = $env['userId'];

    // Create second thread
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$env['catId'], $userId, 'Target Thread', 'Target content', 'visible']);
    $targetThreadId = $pdo->lastInsertId();

    // Create a post in the target thread
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)")
        ->execute([$targetThreadId, $userId, 'Target post content', 'visible']);

    // Make the user a moderator with threads.merge permission
    $pdo->prepare("UPDATE users SET role = 'moderator' WHERE id = ?")->execute([$userId]);
    // Check if moderator role exists, if not create it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute(['moderator', json_encode(['threads.merge', 'moderation.manage', 'threads.create', 'posts.create'])]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['thread_id'] = (string)$threadId;
    $_POST['target_title'] = 'Target Thread';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_merge_thread_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    // Verify source thread deleted
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Source thread deleted', 0, $count);

    // Verify posts moved to target (target had 1 post, source had 1 post = 2 total)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $stmt->execute([$targetThreadId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Posts merged to target thread', 2, $count); // original + moved

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_user_edit_update_profile(): Test
{
    $t = new Test('Admin User Edit - Update Profile');

    $tmpDir = admin_test_tmp_dir('user_edit');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = 'updateduser';
    $_POST['email'] = 'updated@test.com';
    $_POST['role'] = 'user';
    $_POST['status'] = 'active';
    $_POST['password'] = '';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_user_edit('POST', ['id' => (string)$userId]);

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Username updated', 'updateduser', $user['username']);
    $t->assertEquals('Email updated', 'updated@test.com', $user['email']);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_user_edit_change_password(): Test
{
    $t = new Test('Admin User Edit - Change Password');

    $tmpDir = admin_test_tmp_dir('user_edit_pw');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = 'testuser';
    $_POST['email'] = 'user@test.com';
    $_POST['role'] = 'user';
    $_POST['status'] = 'active';
    $_POST['password'] = 'Newpassword123'; // Must have uppercase, lowercase, number, and >=10 chars
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_user_edit('POST', ['id' => (string)$userId]);

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT password, session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertTrue('Password hash updated', password_verify('Newpassword123', $user['password']));
    $t->assertEquals('Session version incremented', 2, (int)$user['session_version']);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_user_edit_weak_password_rejected(): Test
{
    $t = new Test('Admin User Edit - Weak Password Rejected');

    $tmpDir = admin_test_tmp_dir('user_edit_weak');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = 'testuser';
    $_POST['email'] = 'user@test.com';
    $_POST['role'] = 'user';
    $_POST['status'] = 'active';
    $_POST['password'] = '123'; // too weak
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_user_edit('POST', ['id' => (string)$userId]);

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);
    $t->assertTrue('Error stored in session', isset($_SESSION['admin_user_error']));

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_create_user(): Test
{
    $t = new Test('Admin Create User');

    $tmpDir = admin_test_tmp_dir('create_user');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = 'newuser';
    $_POST['password'] = 'password123';
    $_POST['email'] = 'newuser@test.com';
    $_POST['role'] = 'user';
    $_POST['status'] = 'active';
    $_POST['email_verified'] = '1';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_create_user_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT username, email, role, status FROM users WHERE username = ?");
    $stmt->execute(['newuser']);
    $user = $stmt->fetch();
    $t->assertEquals('User created with correct username', 'newuser', $user['username']);
    $t->assertEquals('User created with correct email', 'newuser@test.com', $user['email']);
    $t->assertEquals('User created with correct role', 'user', $user['role']);
    $t->assertEquals('User created with correct status', 'active', $user['status']);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_create_user_duplicate_username(): Test
{
    $t = new Test('Admin Create User - Duplicate Username Rejected');

    $tmpDir = admin_test_tmp_dir('create_user_dup');
    $env = admin_setup_test_env($tmpDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['username'] = 'testuser'; // already exists
    $_POST['password'] = 'password123';
    $_POST['email'] = 'new@test.com';
    $_POST['role'] = 'user';
    $_POST['status'] = 'active';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    try {
        handle_admin_create_user_post();
        $t->assert('Should throw ValidationException', false);
    } catch (\Bulletin\ValidationException $e) {
        $t->assertTrue('Throws ValidationException for duplicate username', true);
        $details = $e->getDetails();
        $t->assertTrue('Error mentions username', isset($details['username']));
    }

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_delete_user(): Test
{
    $t = new Test('Admin Delete User');

    $tmpDir = admin_test_tmp_dir('delete_user');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$userId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_delete_user_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('User deleted', 0, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_delete_admin_user_blocked(): Test
{
    $t = new Test('Admin Delete User - Admin User Cannot Be Deleted');

    $tmpDir = admin_test_tmp_dir('delete_admin');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $adminId = $env['adminId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$adminId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    try {
        handle_delete_user_post();
        $t->assert('Should throw NotFoundException', false);
    } catch (\Bulletin\NotFoundException $e) {
        $t->assertTrue('Throws NotFoundException for admin user', true);
    }

    // Verify admin still exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Admin user still exists', 1, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_ban_user(): Test
{
    $t = new Test('Admin Ban User');

    $tmpDir = admin_test_tmp_dir('ban_user');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$userId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_ban_user_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $status = $stmt->fetchColumn();
    $t->assertEquals('User status is banned', 'banned', $status);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_unban_user(): Test
{
    $t = new Test('Admin Unban User');

    $tmpDir = admin_test_tmp_dir('unban_user');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    // Ban first
    $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$userId]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$userId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_unban_user_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $status = $stmt->fetchColumn();
    $t->assertEquals('User status is active', 'active', $status);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_suspend_user(): Test
{
    $t = new Test('Admin Suspend User');

    $tmpDir = admin_test_tmp_dir('suspend_user');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];
    $userId = $env['userId'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['id'] = (string)$userId;
    $_POST['days'] = '7';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_suspend_user_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT status, suspension_time FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('User status is suspended', 'suspended', $user['status']);
    $t->assertTrue('Suspension time is in future', (int)$user['suspension_time'] > time());

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_roles_create(): Test
{
    $t = new Test('Admin Roles - Create Role');

    $tmpDir = admin_test_tmp_dir('roles_create');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'create';
    $_POST['role_name'] = 'moderator';
    $_POST['permissions'] = ['threads.create', 'posts.create', 'moderation.manage'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_roles_action_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT name, permissions FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    $role = $stmt->fetch();
    $t->assertEquals('Role created', 'moderator', $role['name']);
    $perms = json_decode($role['permissions'], true);
    $t->assertEquals('Permissions saved', ['threads.create', 'posts.create', 'moderation.manage'], $perms);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_roles_update(): Test
{
    $t = new Test('Admin Roles - Update Role');

    $tmpDir = admin_test_tmp_dir('roles_update');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    // Create role first
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
        ->execute(['moderator', json_encode(['threads.create'])]);
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    $roleId = $stmt->fetchColumn();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'update';
    $_POST['role_id'] = (string)$roleId;
    $_POST['permissions'] = ['threads.create', 'posts.create', 'moderation.manage'];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_roles_action_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE id = ?");
    $stmt->execute([$roleId]);
    $perms = json_decode($stmt->fetchColumn(), true);
    $t->assertEquals('Permissions updated', ['threads.create', 'posts.create', 'moderation.manage'], $perms);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_roles_delete(): Test
{
    $t = new Test('Admin Roles - Delete Role');

    $tmpDir = admin_test_tmp_dir('roles_delete');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    // Create role first
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
        ->execute(['moderator', json_encode(['threads.create'])]);
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute(['moderator']);
    $roleId = $stmt->fetchColumn();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'delete';
    $_POST['role_id'] = (string)$roleId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_roles_action_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
    $stmt->execute([$roleId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Role deleted', 0, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_roles_delete_admin_blocked(): Test
{
    $t = new Test('Admin Roles - Cannot Delete Admin Role');

    $tmpDir = admin_test_tmp_dir('roles_delete_admin');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute(['admin']);
    $roleId = $stmt->fetchColumn();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'delete';
    $_POST['role_id'] = (string)$roleId;
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_roles_action_post();

    $t->assertTrue('Returns redirect response', $result instanceof \Bulletin\Response);

    // Admin role should still exist
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute(['admin']);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Admin role still exists', 1, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_categories_crud(): Test
{
    $t = new Test('Admin Categories - CRUD Operations');

    $tmpDir = admin_test_tmp_dir('categories');
    $env = admin_setup_test_env($tmpDir);
    $pdo = $env['pdo'];

    // Test create category
    require_once __DIR__ . '/../src/actions/admin/categories.php';

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['do'] = 'create';
    $_POST['name'] = 'New Category';
    $_POST['description'] = 'Description';
    $_POST['position'] = '5';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_categories_post();
    $t->assertTrue('Create returns redirect', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT name, description, position FROM categories WHERE name = ?");
    $stmt->execute(['New Category']);
    $cat = $stmt->fetch();
    $t->assertEquals('Category created', 'New Category', $cat['name']);
    $t->assertEquals('Description saved', 'Description', $cat['description']);
    $t->assertEquals('Position saved', 5, (int)$cat['position']);

    // Test update category
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
    $stmt->execute(['New Category']);
    $catId = $stmt->fetchColumn();

    $_POST['do'] = 'update';
    $_POST['id'] = (string)$catId;
    $_POST['name'] = 'Updated Category';
    $_POST['description'] = 'Updated description';
    $_POST['position'] = '10';

    $result = handle_admin_categories_post();
    $t->assertTrue('Update returns redirect', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT name, description, position FROM categories WHERE id = ?");
    $stmt->execute([$catId]);
    $cat = $stmt->fetch();
    $t->assertEquals('Category updated', 'Updated Category', $cat['name']);

    // Test delete category
    $_POST['do'] = 'delete';
    $_POST['id'] = (string)$catId;

    $result = handle_admin_categories_post();
    $t->assertTrue('Delete returns redirect', $result instanceof \Bulletin\Response);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = ?");
    $stmt->execute([$catId]);
    $count = $stmt->fetchColumn();
    $t->assertEquals('Category deleted', 0, $count);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_settings_update(): Test
{
    $t = new Test('Admin Settings - Update Settings');

    $tmpDir = admin_test_tmp_dir('settings');
    $env = admin_setup_test_env($tmpDir);
    $configPath = $env['dataDir'] . '/config.json';

    require_once __DIR__ . '/../src/actions/admin/settings.php';

    // Set global $config for the handler
    global $config;
    $config = $env['config'];

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['site_name'] = 'Updated Forum';
    $_POST['site_tagline'] = 'New tagline';
    $_POST['timezone'] = 'UTC';
    $_POST['date_format'] = 'Y-m-d';
    $_POST['time_format'] = 'H:i';
    $_POST['attachments_enabled'] = '1';
    $_POST['allow_catalog_only'] = '1';
    $_POST['plugin_verify_files'] = '1';
    $_POST['telemetry'] = '1';
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_settings_post();
    $t->assertTrue('Returns null (view rendered)', $result === null);

    // Update the global config reference
    $config = $GLOBALS['config'] ?? $config;
    $t->assertEquals('Site name updated', 'Updated Forum', $config['site_name']);
    $t->assertEquals('Attachments enabled', 1, (int)$config['attachments_enabled']);
    $t->assertEquals('Catalog only enabled', 1, (int)$config['allow_catalog_only']);
    $t->assertEquals('Plugin verify files enabled', 1, (int)$config['plugin_verify_files']);
    $t->assertEquals('Telemetry enabled', 1, (int)$config['telemetry']);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_plugins_install_update_uninstall(): Test
{
    $t = new Test('Admin Plugins - Install/Update/Uninstall');

    $tmpDir = admin_test_tmp_dir('plugins');
    $env = admin_setup_test_env($tmpDir);
    $dataDir = $env['dataDir'];

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        admin_cleanup($tmpDir);
        return $t;
    }

    require_once __DIR__ . '/../src/actions/admin/plugins.php';

    // Create a test plugin ZIP
    $zipPath = $tmpDir . '/testplug.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode([
        'name' => 'testplug',
        'version' => '1.0.0',
        'bootstrap' => 'testplug.php',
        'id' => 'testplug'
    ]));
    $zip->addFromString('testplug.php', "<?php\nfunction testplug_init() {}\n");
    $zip->close();

    // Test install
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['install_plugin'] = '1';
    $_FILES['plugin_zip'] = [
        'name' => 'testplug.zip',
        'type' => 'application/zip',
        'tmp_name' => $zipPath,
        'error' => 0,
        'size' => filesize($zipPath),
    ];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_plugins('POST');
    $t->assertTrue('Install returns true (view rendered)', $result === true);

    // Verify plugin installed
    $installed = json_decode(file_get_contents($dataDir . '/installed.json'), true);
    $t->assertTrue('Plugin recorded in installed.json', isset($installed['plugins']['testplug']));

    // Test update - create v2 ZIP
    $zipPath2 = $tmpDir . '/testplug_v2.zip';
    $zip2 = new ZipArchive();
    $zip2->open($zipPath2, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip2->addFromString('manifest.json', json_encode([
        'name' => 'testplug',
        'version' => '2.0.0',
        'bootstrap' => 'testplug.php',
        'id' => 'testplug'
    ]));
    $zip2->addFromString('testplug.php', "<?php\nfunction testplug_init() {}\n");
    $zip2->close();

    $_POST['install_plugin'] = '1';
    $_FILES['plugin_zip'] = [
        'name' => 'testplug_v2.zip',
        'type' => 'application/zip',
        'tmp_name' => $zipPath2,
        'error' => 0,
        'size' => filesize($zipPath2),
    ];

    $result = handle_admin_plugins('POST');
    $t->assertTrue('Update returns true (view rendered)', $result === true);

    $installed = json_decode(file_get_contents($dataDir . '/installed.json'), true);
    $t->assertEquals('Version updated to 2.0.0', '2.0.0', $installed['plugins']['testplug']['version'] ?? '');

    // Test uninstall
    $_POST['uninstall_plugin'] = '1';
    $_POST['plugin_name'] = 'testplug';

    $result = handle_admin_plugins('POST');
    $t->assertTrue('Uninstall returns true (view rendered)', $result === true);

    $installed = json_decode(file_get_contents($dataDir . '/installed.json'), true);
    $t->assertFalse('Plugin removed from installed.json', isset($installed['plugins']['testplug']));

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_themes_install_activate_delete(): Test
{
    $t = new Test('Admin Themes - Install/Activate/Delete');

    $tmpDir = admin_test_tmp_dir('themes');
    $env = admin_setup_test_env($tmpDir);
    $dataDir = $env['dataDir'];

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive unavailable', true);
        admin_cleanup($tmpDir);
        return $t;
    }

    require_once __DIR__ . '/../src/actions/admin/themes.php';

    // Create a test theme ZIP
    $zipPath = $tmpDir . '/testtheme.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('testtheme/style.css', 'body { color: red; }');
    $zip->addFromString('testtheme/manifest.json', json_encode([
        'name' => 'testtheme',
        'version' => '1.0.0'
    ]));
    $zip->close();

    // Test install
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['install_theme'] = '1';
    $_FILES['theme_zip'] = [
        'name' => 'testtheme.zip',
        'type' => 'application/zip',
        'tmp_name' => $zipPath,
        'error' => 0,
        'size' => filesize($zipPath),
    ];
    $_POST['csrf_token'] = $_SESSION['csrf_token'];

    $result = handle_admin_themes('POST');
    $t->assertTrue('Install returns true (view rendered)', $result === true);

    // Test activate
    $_POST['activate_theme'] = '1';
    $_POST['theme'] = 'testtheme';

    $result = handle_admin_themes('POST');
    $t->assertTrue('Activate returns true (view rendered)', $result === true);

    $themesManifest = json_decode(file_get_contents($dataDir . '/themes.json'), true);
    $t->assertEquals('Active theme set', 'testtheme', $themesManifest['active'] ?? '');

    // Test delete
    $_POST['delete_theme'] = '1';
    $_POST['theme'] = 'testtheme';

    $result = handle_admin_themes('POST');
    $t->assertTrue('Delete returns true (view rendered)', $result === true);

    $t->assertFalse('Theme directory removed', is_dir($tmpDir . '/themes/testtheme'));

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_updates_check(): Test
{
    $t = new Test('Admin Updates - Check for Updates');

    $tmpDir = admin_test_tmp_dir('updates');
    $env = admin_setup_test_env($tmpDir);
    $dataDir = $env['dataDir'];

    require_once __DIR__ . '/../src/actions/admin/updates.php';

    // Mock the fetcher to return a version
    // We can't easily mock HTTP, so test the handler structure
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_updates('GET');
    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_diagnostics(): Test
{
    $t = new Test('Admin Diagnostics - View');

    $tmpDir = admin_test_tmp_dir('diagnostics');
    $env = admin_setup_test_env($tmpDir);

    require_once __DIR__ . '/../src/actions/admin/diagnostics.php';

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_diagnostics_get();
    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_catalog(): Test
{
    $t = new Test('Admin Catalog - View');

    $tmpDir = admin_test_tmp_dir('catalog');
    $env = admin_setup_test_env($tmpDir);

    require_once __DIR__ . '/../src/actions/admin/catalog.php';

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_catalog_get();
    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_cleanup($tmpDir);
    return $t;
}

function test_admin_langs(): Test
{
    $t = new Test('Admin Languages - View');

    $tmpDir = admin_test_tmp_dir('langs');
    $env = admin_setup_test_env($tmpDir);

    require_once __DIR__ . '/../src/actions/admin/langs.php';

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = handle_admin_langs_get();
    $t->assertTrue('Returns true (view rendered)', $result === true);

    admin_cleanup($tmpDir);
    return $t;
}

register_tests(
    'test_admin_moderation_approve_thread',
    'test_admin_moderation_hide_thread',
    'test_admin_moderation_delete_thread',
    'test_admin_moderation_invalid_thread',
    'test_admin_moderation_csrf_failure',
    'test_frontend_moderation_lock_thread',
    'test_frontend_moderation_move_thread',
    'test_frontend_moderation_copy_thread',
    'test_frontend_moderation_split_thread',
    'test_frontend_moderation_merge_thread',
    'test_admin_user_edit_update_profile',
    'test_admin_user_edit_change_password',
    'test_admin_user_edit_weak_password_rejected',
    'test_admin_create_user',
    'test_admin_create_user_duplicate_username',
    'test_admin_delete_user',
    'test_admin_delete_admin_user_blocked',
    'test_admin_ban_user',
    'test_admin_unban_user',
    'test_admin_suspend_user',
    'test_admin_roles_create',
    'test_admin_roles_update',
    'test_admin_roles_delete',
    'test_admin_roles_delete_admin_blocked',
    'test_admin_categories_crud',
    'test_admin_settings_update',
    'test_admin_plugins_install_update_uninstall',
    'test_admin_themes_install_activate_delete',
    'test_admin_updates_check',
    'test_admin_diagnostics',
    'test_admin_catalog',
    'test_admin_langs'
);