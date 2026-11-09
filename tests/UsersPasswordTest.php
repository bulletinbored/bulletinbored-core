<?php

/**
 * UsersPasswordTest — tests for password reset actions (forgot_password, reset_password).
 * Tests the underlying logic directly without full framework bootstrap.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function setupPasswordDB(): PDO
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

function setupPasswordApp(PDO $pdo): void
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

function cleanupPasswordSession(): void
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
// FORGOT PASSWORD LOGIC TESTS
// ============================================================

function test_forgot_password_logic_user_exists(): Test
{
    $t = new Test('Forgot Password Logic - User Exists');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Simulate forgot password logic
    $email = 'test@example.com';
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    
    $t->assert('User found by email', $user !== false);
    $t->assertEquals('User ID', (int)$userId, (int)($user['id'] ?? 0));
    
    // Create reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$user['id'], password_hash($token, PASSWORD_DEFAULT), hash('sha256', $token), $expires]);
    $resetId = $pdo->lastInsertId();
    
    $t->assert('Reset token created', $resetId > 0);
    
    // Verify token in database
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE id = ?");
    $stmt->execute([$resetId]);
    $reset = $stmt->fetch();
    $t->assert('Reset token stored', $reset !== false);
    $t->assertEquals('User ID matches', (int)$userId, (int)($reset['user_id'] ?? 0));
    $t->assertTrue('Token hashed', password_verify($token, $reset['token'] ?? ''));
    $t->assertEquals('Token hash matches', hash('sha256', $token), $reset['token_hash'] ?? '');
    $t->assertEquals('Not used', 0, $reset['used'] ?? 1);
    
    App::reset();
    return $t;
}

function test_forgot_password_logic_user_not_exists(): Test
{
    $t = new Test('Forgot Password Logic - User Does Not Exist (Silent Success)');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Simulate forgot password logic with non-existent email
    $email = 'nonexistent@example.com';
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    
    $t->assertFalse('User not found', $user !== false);
    
    // Should not create reset token (silent success for security)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    $t->assertEquals('No reset token created', 0, $count);
    
    App::reset();
    return $t;
}

function test_forgot_password_logic_rate_limiting(): Test
{
    $t = new Test('Forgot Password Logic - Rate Limiting');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Exhaust rate limit
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 7; $i++) {
        if (rate_limit('forgot_password', 5, 3600)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }
    
    $t->assertEquals('First 5 attempts allowed', 5, $allowed);
    $t->assertEquals('Attempts 6-7 blocked', 2, $blocked);
    
    App::reset();
    return $t;
}

function test_forgot_password_logic_csrf_validation(): Test
{
    $t = new Test('Forgot Password Logic - CSRF Validation');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Valid CSRF
    $validToken = generate_csrf_token();
    $t->assertTrue('Valid CSRF token validates', validate_csrf_token($validToken));
    
    // Invalid CSRF
    $t->assertFalse('Invalid CSRF token rejected', validate_csrf_token('invalid-token'));
    
    App::reset();
    return $t;
}

// ============================================================
// RESET PASSWORD LOGIC TESTS
// ============================================================

function test_reset_password_logic_success(): Test
{
    $t = new Test('Reset Password Logic - Success');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified, session_version) VALUES (?, ?, ?, 1, 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $resetId = $pdo->lastInsertId();
    
    // Simulate reset password logic
    $password = 'NewP4ssword123';
    $confirm = 'NewP4ssword123';
    
    $t->assertEquals('Passwords match', $password, $confirm);
    
    $errors = validate_password_strength($password);
    $t->assert('Password passes validation', empty($errors));
    
    // Verify token
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assert('Valid token found', $validToken !== false);
    $t->assertTrue('Password verify matches', password_verify($token, $validToken['token']));
    
    // Consume token
    $consumeStmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
    $consumeStmt->execute([$validToken['id']]);
    $t->assertEquals('Token consumed', 1, $consumeStmt->rowCount());
    
    // Update password and increment session_version
    $pdo->prepare("UPDATE users SET password = ?, session_version = COALESCE(session_version, 0) + 1 WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $validToken['user_id']]);
    
    // Verify password updated
    $stmt = $pdo->prepare("SELECT password, session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertTrue('Password hashed correctly', password_verify($password, $user['password'] ?? ''));
    $t->assertEquals('Session version incremented', 2, $user['session_version'] ?? 0);
    
    // Verify token marked as used
    $stmt = $pdo->prepare("SELECT used FROM password_resets WHERE id = ?");
    $stmt->execute([$resetId]);
    $reset = $stmt->fetch();
    $t->assertEquals('Token marked as used', 1, $reset['used'] ?? 0);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_password_mismatch(): Test
{
    $t = new Test('Reset Password Logic - Password Mismatch');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    $password = 'NewP4ssword123';
    $confirm = 'DifferentP4ssword123';
    
    $t->assertNotEquals('Passwords do not match', $password, $confirm);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_weak_password(): Test
{
    $t = new Test('Reset Password Logic - Weak Password Rejected');
    
    $pdo = setupPasswordDB();
    setupPasswordApp($pdo);
    cleanupPasswordSession();
    
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
    
    $strongPasswords = [
        'ValidP4ssword',
        'MyStr0ngPass',
        'C0mpl3x!tyR0cks',
    ];
    
    foreach ($strongPasswords as $pw) {
        $errors = validate_password_strength($pw);
        $t->assert("Strong password '{$pw}' accepted", empty($errors));
    }
    
    App::reset();
    return $t;
}

function test_reset_password_logic_invalid_token(): Test
{
    $t = new Test('Reset Password Logic - Invalid Token Rejected');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    $token = 'invalid-token';
    $tokenHash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Invalid token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_expired_token(): Test
{
    $t = new Test('Reset Password Logic - Expired Token Rejected');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create EXPIRED reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Expired token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_already_used_token(): Test
{
    $t = new Test('Reset Password Logic - Already Used Token Rejected');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token marked as used
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at, used) VALUES (?, ?, ?, ?, 1)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Already used token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_token_hash_mismatch(): Test
{
    $t = new Test('Reset Password Logic - Token Hash Mismatch Rejected');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token with WRONG hash
    $token = bin2hex(random_bytes(32));
    $wrongHash = hash('sha256', 'wrong-token');
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $wrongHash, $expires]);
    
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Hash mismatch token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_concurrent_consumption(): Test
{
    $t = new Test('Reset Password Logic - Concurrent Token Consumption (Atomic)');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $resetId = $pdo->lastInsertId();
    
    // Simulate concurrent consumption attempts
    $claim1 = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
    $claim1->execute([$resetId]);
    $claim2 = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
    $claim2->execute([$resetId]);
    
    $t->assertEquals('First claim succeeds', 1, $claim1->rowCount());
    $t->assertEquals('Second claim fails', 0, $claim2->rowCount());
    
    App::reset();
    return $t;
}

function test_reset_password_logic_rate_limiting(): Test
{
    $t = new Test('Reset Password Logic - Rate Limiting');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Exhaust rate limit
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 12; $i++) {
        if (rate_limit('reset_password', 10, 3600)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }
    
    $t->assertEquals('First 10 attempts allowed', 10, $allowed);
    $t->assertEquals('Attempts 11-12 blocked', 2, $blocked);
    
    App::reset();
    return $t;
}

function test_reset_password_logic_csrf_validation(): Test
{
    $t = new Test('Reset Password Logic - CSRF Validation');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Valid CSRF
    $validToken = generate_csrf_token();
    $t->assertTrue('Valid CSRF token validates', validate_csrf_token($validToken));
    
    // Invalid CSRF
    $t->assertFalse('Invalid CSRF token rejected', validate_csrf_token('invalid-token'));
    
    App::reset();
    return $t;
}

function test_reset_password_logic_session_update_for_current_user(): Test
{
    $t = new Test('Reset Password Logic - Session Version Updated for Current User');
    
    $pdo = setupPasswordDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified, session_version) VALUES (?, ?, ?, 1, 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Simulate current user logged in
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Create reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $resetId = $pdo->lastInsertId();
    
    // Simulate reset password logic
    $password = 'NewP4ssword123';
    $confirm = 'NewP4ssword123';
    
    $errors = validate_password_strength($password);
    $t->assert('Password passes validation', empty($errors));
    
    // Verify token
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assert('Valid token found', $validToken !== false);
    
    // Consume token
    $consumeStmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
    $consumeStmt->execute([$validToken['id']]);
    $t->assertEquals('Token consumed', 1, $consumeStmt->rowCount());
    
    // Update password and increment session_version
    $pdo->prepare("UPDATE users SET password = ?, session_version = COALESCE(session_version, 0) + 1 WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $validToken['user_id']]);
    
    // If the user resetting their password is the currently logged-in user, update their session
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$validToken['user_id']) {
        $_SESSION['session_version'] = (int)($_SESSION['session_version'] ?? 1) + 1;
    }
    
    $t->assertEquals('Session version updated for current user', 2, $_SESSION['session_version'] ?? 0);
    
    App::reset();
    return $t;
}

register_tests(
    // Forgot password logic tests
    'test_forgot_password_logic_user_exists',
    'test_forgot_password_logic_user_not_exists',
    'test_forgot_password_logic_rate_limiting',
    'test_forgot_password_logic_csrf_validation',
    
    // Reset password logic tests
    'test_reset_password_logic_success',
    'test_reset_password_logic_password_mismatch',
    'test_reset_password_logic_weak_password',
    'test_reset_password_logic_invalid_token',
    'test_reset_password_logic_expired_token',
    'test_reset_password_logic_already_used_token',
    'test_reset_password_logic_token_hash_mismatch',
    'test_reset_password_logic_concurrent_consumption',
    'test_reset_password_logic_rate_limiting',
    'test_reset_password_logic_csrf_validation',
    'test_reset_password_logic_session_update_for_current_user'
);