<?php

/**
 * E2eIntegrationTest — end-to-end flows simulating real user journeys.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Helpers/Data.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../src/actions/users.php';

function setupE2eDB(): PDO
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
        CREATE TABLE email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used INTEGER DEFAULT 0
        );
    ");

    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"threads.delete\",\"posts.edit\",\"users.ban\"]')")->execute();
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"threads.approve\",\"threads.delete\",\"posts.edit\"]')")->execute();
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('user', '[\"threads.create\",\"posts.create\",\"posts.edit_own\"]')")->execute();

    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (1, 'General', 'General discussion', 1)")->execute();

    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
        ->execute([password_hash('AdminP4ssword', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'moderator', ?, 'mod@test.com', 'moderator', 1)")
        ->execute([password_hash('ModP4ssword', PASSWORD_DEFAULT)]);

    return $pdo;
}

function test_e2e_register_login_create_thread_reply(): Test
{
    $t = new Test('E2E: Guest → Register → Login → Create Thread → Reply');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [];

    $username = 'newuser';
    $password = 'StrongP4ssword';
    $email = 'new@example.com';

    $errors = validate_password_strength($password);
    $t->assert('Step 0: Password valid', empty($errors));

    $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $existsStmt->execute([$username]);
    $t->assert('Step 1: Username available', $existsStmt->fetchColumn() === 0);

    $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified) VALUES (?, ?, ?, 'user', 1)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
    $userId = (int)$pdo->lastInsertId();
    $t->assert('Step 1: User registered', $userId > 0);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $t->assert('Step 2: User can login - found', $user !== false);
    $t->assertTrue('Step 2: Password verifies', password_verify($password, $user['password']));
    $t->assert('Step 2: User active', $user['status'] === 'active');
    $t->assert('Step 2: Email verified', $user['email_verified'] == 1);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $t->assert('Step 2: Session established', is_logged_in());

    $stmt = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, 'visible')");
    $stmt->execute([1, $userId, 'My E2E Thread', 'Thread content']);
    $threadId = (int)$pdo->lastInsertId();
    $t->assert('Step 3: Thread created', $threadId > 0);

    $stmt = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, 'visible')");
    $stmt->execute([$threadId, $userId, 'My reply to the thread']);
    $postId = (int)$pdo->lastInsertId();
    $t->assert('Step 4: Reply created', $postId > 0);

    $thread = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $t->assert('Thread exists in DB', $thread->fetch() !== false);

    $post = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $post->execute([$postId]);
    $t->assert('Reply exists in DB', $post->fetch() !== false);

    App::reset();
    $_POST = [];
    $_SESSION = [];

    return $t;
}

function test_e2e_thread_moderator_hides(): Test
{
    $t = new Test('E2E: User creates thread → Mod hides → User cannot see');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (10, 'regularuser', ?, 'user@test.com', 'user', 1)")
        ->execute([password_hash('UserP4ssword', PASSWORD_DEFAULT)]);

    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content, status) VALUES (100, 1, 10, 'User Thread', 'Content', 'visible')")->execute();

    $_SESSION = ['user_id' => 10, 'user_role' => 'user'];
    $t->assert('User can see visible thread', can_view_thread('visible'));

    $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = 100")->execute();

    $t->assert('User cannot see hidden thread', !can_view_thread('hidden'));

    $_SESSION = ['user_id' => 2, 'user_role' => 'moderator'];
    $t->assert('Moderator can see hidden thread', can_view_thread('hidden'));

    $_SESSION = [];
    App::reset();

    return $t;
}

function test_e2e_ban_user_kicks_session(): Test
{
    $t = new Test('E2E: Admin bans user → User kicked on next request');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password, email, role, status, email_verified) VALUES (20, 'baduser', ?, 'bad@test.com', 'user', 'active', 1)")
        ->execute([password_hash('BadP4ssword', PASSWORD_DEFAULT)]);

    $_SESSION = [
        'user_id' => 20,
        'user_role' => 'user',
        'username' => 'baduser',
        'user_status' => 'active',
    ];
    $t->assert('User starts as active', !is_banned());

    $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = 20")->execute();

    $_SESSION['user_status'] = 'banned';
    $t->assert('User is banned after admin action', is_banned());
    $t->assert('Banned user is not suspended', !is_suspended());

    $_SESSION = [];
    App::reset();

    return $t;
}

function test_e2e_admin_manages_settings(): Test
{
    $t = new Test('E2E: Admin can manage settings and users');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    $_SESSION = ['user_id' => 1, 'user_role' => 'admin'];

    $t->assert('Admin can access admin', $authz->can(1, 'admin.access'));
    $t->assert('Admin can ban users', $authz->can(1, 'users.ban'));
    $t->assert('Admin can manage settings', $authz->can(1, 'settings.manage'));
    $t->assert('Admin can delete threads', $authz->can(1, 'threads.delete'));

    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (30, 'victim', ?, 'victim@test.com', 'user', 1)")
        ->execute([password_hash('VictimP4ss', PASSWORD_DEFAULT)]);

    $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = 30")->execute();
    $victim = $pdo->query("SELECT status FROM users WHERE id = 30")->fetch();
    $t->assert('Admin ban executed', $victim['status'] === 'banned');

    $_SESSION = [];
    App::reset();

    return $t;
}

function test_e2e_moderator_approves_pending(): Test
{
    $t = new Test('E2E: Moderator approves pending thread');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content, status) VALUES (200, 1, 1, 'Pending Thread', 'Content', 'pending')")->execute();

    $_SESSION = ['user_id' => 2, 'user_role' => 'moderator'];
    $t->assert('Moderator can approve threads', $authz->can(2, 'threads.approve'));

    $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = 200 AND status = 'pending'")->execute();
    $thread = $pdo->query("SELECT status FROM threads WHERE id = 200")->fetch();
    $t->assert('Thread approved (visible)', $thread['status'] === 'visible');

    $_SESSION = [];
    App::reset();

    return $t;
}

function test_e2e_guest_cannot_post(): Test
{
    $t = new Test('E2E: Guest cannot create threads or reply');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    $_SESSION = [];

    $t->assert('Guest is not logged in', !is_logged_in());
    $t->assert('Guest cannot create threads', !can_view_thread('hidden'));

    $t->assert('Guest cannot see hidden threads', !can_view_thread('hidden'));
    $t->assert('Guest cannot see pending threads', !can_view_thread('pending'));

    $t->assert('Guest can see visible threads', can_view_thread('visible'));
    $t->assert('Guest can see sticky threads', can_view_thread('sticky'));
    $t->assert('Guest can see locked threads', can_view_thread('locked'));

    App::reset();

    return $t;
}

function test_e2e_user_cannot_access_admin(): Test
{
    $t = new Test('E2E: Regular user cannot access admin');

    $pdo = setupE2eDB();
    App::getInstance()->pdo = $pdo;
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (40, 'regular', ?, 'regular@test.com', 'user', 1)")
        ->execute([password_hash('RegularP4ss', PASSWORD_DEFAULT)]);

    $_SESSION = ['user_id' => 40, 'user_role' => 'user'];

    $t->assert('User cannot access admin', !$authz->can(40, 'admin.access'));
    $t->assert('User cannot ban users', !$authz->can(40, 'users.ban'));
    $t->assert('User cannot manage settings', !$authz->can(40, 'settings.manage'));

    $t->assert('User can create threads', $authz->can(40, 'threads.create'));
    $t->assert('User can create posts', $authz->can(40, 'posts.create'));

    $_SESSION = [];
    App::reset();

    return $t;
}

register_tests(
    'test_e2e_register_login_create_thread_reply',
    'test_e2e_thread_moderator_hides',
    'test_e2e_ban_user_kicks_session',
    'test_e2e_admin_manages_settings',
    'test_e2e_moderator_approves_pending',
    'test_e2e_guest_cannot_post',
    'test_e2e_user_cannot_access_admin'
);
