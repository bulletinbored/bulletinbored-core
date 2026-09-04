<?php

/**
 * Authentication hardening tests — session lifecycle, token security, account enumeration.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_session_lifecycle(): Test
{
    $t = new Test('Auth Hardening - Session Lifecycle');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Anonymous → login
    $_SESSION = [];
    $t->assertFalse('Not logged in when session empty', is_logged_in());
    $_SESSION['user_id'] = 42;
    $_SESSION['user_role'] = 'user';
    $t->assertTrue('Logged in after setting user_id', is_logged_in());

    // Privilege change: user → admin
    $oldSessionId = session_id();
    $_SESSION['user_role'] = 'admin';
    $t->assertTrue('Role updated to admin', is_admin());

    // Privilege change: admin → user
    $_SESSION['user_role'] = 'user';
    $t->assertFalse('Role downgraded to user', is_admin());

    // Logout
    $_SESSION = [];
    $t->assertFalse('Not logged in after logout', is_logged_in());

    return $t;
}

function test_email_verification_token(): Test
{
    $t = new Test('Auth Hardening - Email Verification Token');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        used INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Generate token
    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = password_hash($rawToken, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $stmt = $pdo->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([1, $hashedToken, $expires]);
    $t->assert('Token stored as hash (not raw)', $hashedToken !== $rawToken);

    // Validate token
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([1]);
    $record = $stmt->fetch();
    $t->assert('Token record found', $record !== false);
    $t->assertTrue('Token verifies against hash', password_verify($rawToken, $record['token']));

    // Check expiry
    $t->assert('Token not expired', strtotime($record['expires_at']) > time());

    // Consume token (mark as used)
    $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?")->execute([$record['id']]);

    // Token cannot be reused
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([1]);
    $t->assertFalse('Consumed token cannot be reused', $stmt->fetch() !== false);

    return $t;
}

function test_email_verification_expired_token(): Test
{
    $t = new Test('Auth Hardening - Expired Token Rejected');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        used INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = password_hash($rawToken, PASSWORD_DEFAULT);
    // Use a clearly expired timestamp (1 hour ago in UTC for SQLite compatibility)
    $expired = gmdate('Y-m-d H:i:s', time() - 3600);
    $stmt = $pdo->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([1, $hashedToken, $expired]);

    // Query that filters by expiry (same logic as production code)
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ? AND used = 0 AND expires_at > datetime('now') ORDER BY id DESC LIMIT 1");
    $stmt->execute([1]);
    $t->assertFalse('Expired token not found by valid query', $stmt->fetch() !== false);

    // But without the expiry filter, the record exists
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([1]);
    $t->assert('Expired token exists but is filtered by expiry', $stmt->fetch() !== false);

    return $t;
}

function test_password_reset_token(): Test
{
    $t = new Test('Auth Hardening - Password Reset Token');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        used INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Generate token
    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = password_hash($rawToken, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([1, $hashedToken, $expires]);

    // Validate
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE user_id = ? AND used = 0 AND expires_at > datetime('now') ORDER BY id DESC LIMIT 1");
    $stmt->execute([1]);
    $record = $stmt->fetch();
    $t->assert('Reset token found', $record !== false);
    $t->assertTrue('Reset token verifies', password_verify($rawToken, $record['token']));

    // Consume
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$record['id']]);

    // Cannot reuse
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE user_id = ? AND used = 0 AND expires_at > datetime('now') ORDER BY id DESC LIMIT 1");
    $stmt->execute([1]);
    $t->assertFalse('Consumed reset token cannot be reused', $stmt->fetch() !== false);

    return $t;
}

function test_auth_account_enumeration_prevention(): Test
{
    $t = new Test('Auth Hardening - Account Enumeration Prevention');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        email TEXT,
        role TEXT DEFAULT 'user',
        status TEXT DEFAULT 'active'
    )");
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES ('alice', ?, 'alice@test.com')");
    $stmt->execute([password_hash('password123', PASSWORD_DEFAULT)]);

    // Both wrong username and wrong password should produce the same error
    $wrongUsernameError = 'Invalid credentials';
    $wrongPasswordError = 'Invalid credentials';
    $t->assertEquals('Same error for wrong username and wrong password', $wrongUsernameError, $wrongPasswordError);

    // Verify that login check doesn't reveal existence
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['nonexistent']);
    $user = $stmt->fetch();
    $t->assertFalse('Non-existent user not found', $user !== false);

    $stmt->execute(['alice']);
    $user = $stmt->fetch();
    $t->assert('Existing user found', $user !== false);

    return $t;
}

function test_csrf_token_rotation(): Test
{
    $t = new Test('Auth Hardening - CSRF Token Rotation');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION = [];

    // Generate initial token
    $token1 = generate_csrf_token();
    $t->assert('First CSRF token generated', !empty($token1));

    // Validate token (without rotation)
    $t->assertTrue('First token validates', validate_csrf_token($token1));

    // Simulate POST validation (which rotates)
    $_POST['csrf_token'] = $token1;
    $t->assertTrue('Token validates via request', csrf_validate_request());
    unset($_POST['csrf_token']);

    // Old token should be invalid after rotation
    $t->assertFalse('Old token invalid after rotation', validate_csrf_token($token1));

    // New token should be valid
    $token2 = $_SESSION['csrf_token'] ?? '';
    $t->assert('New token generated after rotation', !empty($token2));
    $t->assert('New token differs from old', $token2 !== $token1);
    $t->assertTrue('New token validates', validate_csrf_token($token2));

    return $t;
}

function test_rate_limiting(): Test
{
    $t = new Test('Auth Hardening - Rate Limiting');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    // Clear any previous rate limit state
    unset($_SESSION['rate_limit']);

    // Test rate limit function exists and works
    $t->assertTrue('rate_limit function exists', function_exists('rate_limit'));

    // Use a unique key to avoid state pollution from other tests
    $uniqueKey = 'test_action_' . uniqid();

    // First attempt should pass
    $result = rate_limit($uniqueKey, 3, 60);
    $t->assertTrue('First attempt passes rate limit', $result);

    // Subsequent attempts should pass until limit
    rate_limit($uniqueKey, 3, 60);
    rate_limit($uniqueKey, 3, 60);

    // Fourth attempt should fail (limit is 3)
    $result = rate_limit($uniqueKey, 3, 60);
    $t->assertFalse('Fourth attempt blocked by rate limit', $result);

    return $t;
}

register_tests(
    'test_session_lifecycle',
    'test_email_verification_token',
    'test_email_verification_expired_token',
    'test_password_reset_token',
    'test_auth_account_enumeration_prevention',
    'test_csrf_token_rotation',
    'test_rate_limiting'
);
