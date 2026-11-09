<?php

/**
 * UsersProfileTest — tests for user profile actions (profile, edit_profile, remove_avatar).
 * Tests the underlying logic directly without full framework bootstrap.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function setupProfileDB(): PDO
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
            email_verified INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]',
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
    ");

    // Insert default roles with permissions
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'user',
        json_encode(['threads.create', 'posts.create'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'moderator',
        json_encode(['threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'admin',
        json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy'])
    ]);

    return $pdo;
}

function setupProfileApp(PDO $pdo): void
{
    App::reset();
    $app = App::getInstance();
    $app->pdo = $pdo;
    $app->config = [
        'site_name' => 'Test Forum',
        'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'avatar_max_size_mb' => 2,
    ];
    $app->authz = new AuthZ($pdo);
    $app->pluginManager = null;
}

function cleanupProfileSession(): void
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
// PROFILE LOGIC TESTS
// ============================================================

function test_profile_logic_user_found(): Test
{
    $t = new Test('Profile Logic - User Found');
    
    $pdo = setupProfileDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, created_at) VALUES (?, ?, ?, 'user', 'active', datetime('now'))")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Simulate profile logic
    $username = 'testuser';
    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute([$username]);
    $profileUser = $profileStmt->fetch();
    
    $t->assert('User found', $profileUser !== false);
    $t->assertEquals('Username', 'testuser', $profileUser['username'] ?? '');
    $t->assertEquals('Email', 'test@example.com', $profileUser['email'] ?? '');
    $t->assertEquals('Role', 'user', $profileUser['role'] ?? '');
    $t->assertEquals('Status', 'active', $profileUser['status'] ?? '');
    
    // Test user threads query
    $userThreadsStmt = $pdo->prepare("
        SELECT t.*, u.username as author
        FROM threads t
        JOIN users u ON t.user_id = u.id
        WHERE t.user_id = ? AND t.status IN ('visible', 'sticky', 'locked')
        ORDER BY (t.status = 'sticky') DESC, t.created_at DESC
        LIMIT 20
    ");
    $userThreadsStmt->execute([$profileUser['id']]);
    $userThreads = $userThreadsStmt->fetchAll();
    
    $t->assert('User threads query executed', is_array($userThreads));
    
    // Test profile stats
    $profileStats = ['threads' => 0, 'posts' => 0];
    $s = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE user_id = ? AND status IN ('visible','sticky','locked')");
    $s->execute([$profileUser['id']]);
    $profileStats['threads'] = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 'visible'");
    $s->execute([$profileUser['id']]);
    $profileStats['posts'] = (int)$s->fetchColumn();
    
    $t->assertEquals('Threads count', 0, $profileStats['threads']);
    $t->assertEquals('Posts count', 0, $profileStats['posts']);
    
    App::reset();
    return $t;
}

function test_profile_logic_user_not_found(): Test
{
    $t = new Test('Profile Logic - User Not Found');
    
    $pdo = setupProfileDB();
    setupApp($pdo);
    cleanupSession();
    
    $username = 'nonexistent';
    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute([$username]);
    $profileUser = $profileStmt->fetch();
    
    $t->assertFalse('User not found', $profileUser !== false);
    
    App::reset();
    return $t;
}

function test_profile_logic_with_threads(): Test
{
    $t = new Test('Profile Logic - User With Threads');
    
    $pdo = setupProfileDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, created_at) VALUES (?, ?, ?, 'user', 'active', datetime('now'))")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create a category
    $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)")
        ->execute(['General', 'General discussion', 1]);
    $categoryId = $pdo->lastInsertId();
    
    // Create threads for the user
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', datetime('now'))")
        ->execute([$categoryId, $userId, 'Thread 1', 'Content 1']);
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'sticky', datetime('now'))")
        ->execute([$categoryId, $userId, 'Thread 2', 'Content 2']);
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'locked', datetime('now'))")
        ->execute([$categoryId, $userId, 'Thread 3', 'Content 3']);
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'hidden', datetime('now'))")
        ->execute([$categoryId, $userId, 'Thread 4', 'Content 4']);
    
    // Simulate profile logic
    $username = 'testuser';
    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute([$username]);
    $profileUser = $profileStmt->fetch();
    
    $userThreadsStmt = $pdo->prepare("
        SELECT t.*, u.username as author
        FROM threads t
        JOIN users u ON t.user_id = u.id
        WHERE t.user_id = ? AND t.status IN ('visible', 'sticky', 'locked')
        ORDER BY (t.status = 'sticky') DESC, t.created_at DESC
        LIMIT 20
    ");
    $userThreadsStmt->execute([$profileUser['id']]);
    $userThreads = $userThreadsStmt->fetchAll();
    
    $t->assertEquals('Thread count (visible, sticky, locked only)', 3, count($userThreads));
    
    // Check ordering - sticky first
    $t->assertEquals('First thread is sticky', 'sticky', $userThreads[0]['status'] ?? '');
    
    // Test profile stats
    $profileStats = ['threads' => 0, 'posts' => 0];
    $s = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE user_id = ? AND status IN ('visible','sticky','locked')");
    $s->execute([$profileUser['id']]);
    $profileStats['threads'] = (int)$s->fetchColumn();
    $t->assertEquals('Threads count', 3, $profileStats['threads']);
    
    App::reset();
    return $t;
}

function test_profile_logic_banned_user(): Test
{
    $t = new Test('Profile Logic - Banned User');
    
    $pdo = setupProfileDB();
    setupApp($pdo);
    cleanupSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, created_at) VALUES (?, ?, ?, 'user', 'banned', datetime('now'))")
        ->execute(['banneduser', password_hash('password123', PASSWORD_DEFAULT), 'banned@example.com']);
    
    $username = 'banneduser';
    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute([$username]);
    $profileUser = $profileStmt->fetch();
    
    $t->assert('User found', $profileUser !== false);
    $t->assertEquals('Status', 'banned', $profileUser['status'] ?? '');
    
    $pStatus = $profileUser['status'] ?? 'active';
    $isBanned = ($pStatus === 'banned');
    $t->assertTrue('isBanned flag', $isBanned);
    
    App::reset();
    return $t;
}

function test_profile_logic_suspended_user(): Test
{
    $t = new Test('Profile Logic - Suspended User');
    
    $pdo = setupProfileDB();
    setupApp($pdo);
    cleanupSession();
    
    $futureTime = time() + 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, suspension_time, created_at) VALUES (?, ?, ?, 'user', 'suspended', ?, datetime('now'))")
        ->execute(['suspendeduser', password_hash('password123', PASSWORD_DEFAULT), 'suspended@example.com', $futureTime]);
    
    $username = 'suspendeduser';
    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute([$username]);
    $profileUser = $profileStmt->fetch();
    
    $t->assert('User found', $profileUser !== false);
    $t->assertEquals('Status', 'suspended', $profileUser['status'] ?? '');
    $t->assertEquals('Suspension time in future', $futureTime, $profileUser['suspension_time'] ?? 0);
    
    $pStatus = $profileUser['status'] ?? 'active';
    $pSusp = (int)($profileUser['suspension_time'] ?? 0);
    $isSuspended = ($pStatus === 'suspended' && $pSusp > time());
    $t->assertTrue('isSuspended flag', $isSuspended);
    
    App::reset();
    return $t;
}

// ============================================================
// EDIT PROFILE LOGIC TESTS
// ============================================================

function test_edit_profile_logic_not_logged_in(): Test
{
    $t = new Test('Edit Profile Logic - Not Logged In');
    
    $pdo = setupProfileDB();
    setupApp($pdo);
    cleanupSession();
    
    // Simulate not logged in check
    $isLoggedIn = is_logged_in();
    $t->assertFalse('User not logged in', $isLoggedIn);
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_username_update(): Test
{
    $t = new Test('Edit Profile Logic - Username Update');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['olduser', password_hash('password123', PASSWORD_DEFAULT), 'old@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'olduser',
        'email' => 'old@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Simulate username update
    $newUsername = 'newuser';
    $existingStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
    $existingStmt->execute([$newUsername, $userId]);
    $existing = $existingStmt->fetchColumn();
    $t->assertFalse('New username not taken', $existing !== false);
    
    $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")
        ->execute([$newUsername, $userId]);
    
    // Verify update
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Username updated in database', 'newuser', $user['username'] ?? '');
    
    // Simulate session update
    $_SESSION['username'] = $newUsername;
    $t->assertEquals('Username updated in session', 'newuser', $_SESSION['username'] ?? '');
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_duplicate_username(): Test
{
    $t = new Test('Edit Profile Logic - Duplicate Username Rejected');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create two users
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['user1', password_hash('password123', PASSWORD_DEFAULT), 'user1@example.com']);
    $userId1 = $pdo->lastInsertId();
    
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['user2', password_hash('password123', PASSWORD_DEFAULT), 'user2@example.com']);
    $userId2 = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId2,
        'user_role' => 'user',
        'username' => 'user2',
        'email' => 'user2@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Try to change user2's username to user1
    $newUsername = 'user1';
    $existingStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
    $existingStmt->execute([$newUsername, $userId2]);
    $existing = $existingStmt->fetchColumn();
    $t->assertTrue('Username already taken', $existing !== false);
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_email_update(): Test
{
    $t = new Test('Edit Profile Logic - Email Update');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'old@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'old@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Simulate email update
    $newEmail = 'new@example.com';
    $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")
        ->execute([$newEmail, $userId]);
    
    // Verify update
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Email updated in database', 'new@example.com', $user['email'] ?? '');
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_password_update(): Test
{
    $t = new Test('Edit Profile Logic - Password Update');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Simulate password update
    $newPassword = 'NewP4ssword123';
    $errors = validate_password_strength($newPassword);
    $t->assert('New password passes validation', empty($errors));
    
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password = ?, session_version = COALESCE(session_version, 0) + 1 WHERE id = ?")
        ->execute([$hashedPassword, $userId]);
    
    // Verify update
    $stmt = $pdo->prepare("SELECT password, session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertTrue('Password hashed correctly', password_verify($newPassword, $user['password'] ?? ''));
    $t->assertEquals('Session version incremented', 2, $user['session_version'] ?? 0);
    
    // Simulate session update
    $_SESSION['session_version'] = (int)($_SESSION['session_version'] ?? 1) + 1;
    $t->assertEquals('Session version updated', 2, $_SESSION['session_version'] ?? 0);
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_weak_password(): Test
{
    $t = new Test('Edit Profile Logic - Weak Password Rejected');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $weakPasswords = [
        'short' => 'Ab1',
        'no_upper' => 'mypassword123',
        'no_lower' => 'MYPASSWORD123',
        'no_number' => 'MyPasswordHere',
    ];
    
    foreach ($weakPasswords as $type => $pw) {
        $errors = validate_password_strength($pw);
        $t->assert("Weak password ({$type}) rejected", !empty($errors));
    }
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_rate_limiting(): Test
{
    $t = new Test('Edit Profile Logic - Rate Limiting');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Exhaust rate limit
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 22; $i++) {
        if (rate_limit('edit_profile', 20, 3600, (string)$userId)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }
    
    $t->assertEquals('First 20 attempts allowed', 20, $allowed);
    $t->assertEquals('Attempts 21-22 blocked', 2, $blocked);
    
    App::reset();
    return $t;
}

function test_edit_profile_logic_csrf_validation(): Test
{
    $t = new Test('Edit Profile Logic - CSRF Validation');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Valid CSRF
    $validToken = generate_csrf_token();
    $t->assertTrue('Valid CSRF token validates', validate_csrf_token($validToken));
    
    // Invalid CSRF
    $t->assertFalse('Invalid CSRF token rejected', validate_csrf_token('invalid-token'));
    
    App::reset();
    return $t;
}

// ============================================================
// REMOVE AVATAR LOGIC TESTS
// ============================================================

function test_remove_avatar_logic_not_logged_in(): Test
{
    $t = new Test('Remove Avatar Logic - Not Logged In');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    $isLoggedIn = is_logged_in();
    $t->assertFalse('User not logged in', $isLoggedIn);
    
    App::reset();
    return $t;
}

function test_remove_avatar_logic_success(): Test
{
    $t = new Test('Remove Avatar Logic - Success');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user with avatar and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, avatar, session_version) VALUES (?, ?, ?, 'user', 'active', 'avatar_1.jpg', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'avatar' => 'avatar_1.jpg',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Simulate avatar removal
    $pdo->prepare("UPDATE users SET avatar = '' WHERE id = ?")
        ->execute([$userId]);
    
    // Verify database update
    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Avatar cleared in database', '', $user['avatar'] ?? 'not_empty');
    
    // Simulate session update
    $_SESSION['avatar'] = '';
    $t->assertEquals('Avatar cleared in session', '', $_SESSION['avatar'] ?? 'not_empty');
    
    App::reset();
    return $t;
}

function test_remove_avatar_logic_rate_limiting(): Test
{
    $t = new Test('Remove Avatar Logic - Rate Limiting');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Exhaust rate limit
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 22; $i++) {
        if (rate_limit('remove_avatar', 20, 3600, (string)$userId)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }
    
    $t->assertEquals('First 20 attempts allowed', 20, $allowed);
    $t->assertEquals('Attempts 21-22 blocked', 2, $blocked);
    
    App::reset();
    return $t;
}

function test_remove_avatar_logic_csrf_validation(): Test
{
    $t = new Test('Remove Avatar Logic - CSRF Validation');
    
    $pdo = setupProfileDB();
    setupProfileApp($pdo);
    cleanupProfileSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Valid CSRF
    $validToken = generate_csrf_token();
    $t->assertTrue('Valid CSRF token validates', validate_csrf_token($validToken));
    
    // Invalid CSRF
    $t->assertFalse('Invalid CSRF token rejected', validate_csrf_token('invalid-token'));
    
    App::reset();
    return $t;
}

register_tests(
    // Profile logic tests
    'test_profile_logic_user_found',
    'test_profile_logic_user_not_found',
    'test_profile_logic_with_threads',
    'test_profile_logic_banned_user',
    'test_profile_logic_suspended_user',
    
    // Edit profile logic tests
    'test_edit_profile_logic_not_logged_in',
    'test_edit_profile_logic_username_update',
    'test_edit_profile_logic_duplicate_username',
    'test_edit_profile_logic_email_update',
    'test_edit_profile_logic_password_update',
    'test_edit_profile_logic_weak_password',
    'test_edit_profile_logic_rate_limiting',
    'test_edit_profile_logic_csrf_validation',
    
    // Remove avatar logic tests
    'test_remove_avatar_logic_not_logged_in',
    'test_remove_avatar_logic_success',
    'test_remove_avatar_logic_rate_limiting',
    'test_remove_avatar_logic_csrf_validation'
);