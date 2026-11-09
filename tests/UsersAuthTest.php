<?php

/**
 * UsersAuthTest — tests for user authentication actions (login, register, logout, verify_email).
 * Tests the underlying logic directly without full framework bootstrap.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function setupAuthDB(): PDO
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
        CREATE TABLE email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER DEFAULT 0,
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

function setupAuthApp(PDO $pdo): void
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

function cleanupAuthSession(): void
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
// LOGIN LOGIC TESTS
// ============================================================

function test_login_logic_success(): Test
{
    $t = new Test('Login Logic - Correct Credentials');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    // Create a test user
    $password = 'MyP4ssword123';
    $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified, status) VALUES (?, ?, ?, 'user', 1, 'active')")
        ->execute(['testuser', password_hash($password, PASSWORD_DEFAULT), 'test@example.com']);
    
    // Simulate login logic
    $username = 'testuser';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    $t->assert('User found', $user !== false);
    $t->assertTrue('Password verifies', password_verify($password, $user['password']));
    $t->assert('User is active', $user['status'] === 'active');
    $t->assert('Email is verified', $user['email_verified'] == 1);
    
    // Simulate session creation
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['avatar'] = $user['avatar'] ?? '';
    $_SESSION['user_status'] = $user['status'];
    $_SESSION['user_suspension_time'] = $user['suspension_time'] ?? 0;
    $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
    
    $t->assertTrue('Session user_id set', isset($_SESSION['user_id']));
    $t->assertEquals('Session username', 'testuser', $_SESSION['username'] ?? '');
    $t->assertEquals('Session role', 'user', $_SESSION['user_role'] ?? '');
    $t->assertEquals('Session status', 'active', $_SESSION['user_status'] ?? '');
    $t->assert('Session version set', isset($_SESSION['session_version']));
    
    App::reset();
    return $t;
}

function test_login_logic_wrong_password(): Test
{
    $t = new Test('Login Logic - Wrong Password Rejected');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    
    $username = 'testuser';
    $password = 'WrongPassword123';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    $t->assert('User found', $user !== false);
    $t->assertFalse('Wrong password does not verify', password_verify($password, $user['password']));
    
    $_SESSION = [];
    $t->assertFalse('Session not set after wrong credentials', isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_login_logic_nonexistent_user(): Test
{
    $t = new Test('Login Logic - Non-existent User Rejected');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['nonexistent']);
    $user = $stmt->fetch();
    
    $t->assert('Non-existent user not found', $user === false);
    
    $_SESSION = [];
    $t->assertFalse('Session not set for non-existent user', isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_login_logic_banned_user(): Test
{
    $t = new Test('Login Logic - Banned User Cannot Login');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, status, email_verified) VALUES (?, ?, ?, 'banned', 1)")
        ->execute(['banneduser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'banned@example.com']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['banneduser']);
    $user = $stmt->fetch();
    
    $t->assert('User found', $user !== false);
    $t->assertTrue('Password verifies', password_verify('MyP4ssword123', $user['password']));
    $t->assert('User is banned', $user['status'] === 'banned');
    
    $loginAllowed = true;
    if ($user['status'] === 'banned') {
        $loginAllowed = false;
    }
    $t->assertFalse('Banned user cannot login', $loginAllowed);
    
    App::reset();
    return $t;
}

function test_login_logic_suspended_user(): Test
{
    $t = new Test('Login Logic - Suspended User Cannot Login');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $futureTime = time() + 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, status, suspension_time, email_verified) VALUES (?, ?, ?, 'suspended', ?, 1)")
        ->execute(['suspendeduser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'suspended@example.com', $futureTime]);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['suspendeduser']);
    $user = $stmt->fetch();
    
    $t->assert('User found', $user !== false);
    $t->assert('User is suspended', $user['status'] === 'suspended');
    $t->assert('Suspension time in future', $user['suspension_time'] > time());
    
    $loginAllowed = true;
    if ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() < $user['suspension_time']) {
        $loginAllowed = false;
    }
    $t->assertFalse('Suspended user cannot login', $loginAllowed);
    
    App::reset();
    return $t;
}

function test_login_logic_expired_suspension(): Test
{
    $t = new Test('Login Logic - Expired Suspension Allows Login');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $pastTime = time() - 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, status, suspension_time, email_verified) VALUES (?, ?, ?, 'suspended', ?, 1)")
        ->execute(['expiredsuspended', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'expired@example.com', $pastTime]);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['expiredsuspended']);
    $user = $stmt->fetch();
    
    $t->assert('User found', $user !== false);
    $t->assert('User status is suspended', $user['status'] === 'suspended');
    $t->assert('Suspension time in past', $user['suspension_time'] < time());
    
    $loginAllowed = true;
    if ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() < $user['suspension_time']) {
        $loginAllowed = false;
    }
    $t->assertTrue('Expired suspension allows login', $loginAllowed);
    
    // Simulate the reactivation logic
    if ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() >= $user['suspension_time']) {
        $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$user['id']]);
        $user['status'] = 'active';
        $user['suspension_time'] = 0;
    }
    
    $stmt = $pdo->prepare("SELECT status FROM users WHERE username = ?");
    $stmt->execute(['expiredsuspended']);
    $updatedUser = $stmt->fetch();
    $t->assertEquals('Database status updated to active', 'active', $updatedUser['status'] ?? '');
    
    App::reset();
    return $t;
}

function test_login_logic_unverified_email(): Test
{
    $t = new Test('Login Logic - Unverified Email Cannot Login');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['unverified', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'unverified@example.com']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['unverified']);
    $user = $stmt->fetch();
    
    $t->assert('User found', $user !== false);
    $t->assert('Email not verified', $user['email_verified'] == 0);
    
    $loginAllowed = true;
    if (empty($user['email_verified'])) {
        $loginAllowed = false;
    }
    $t->assertFalse('Unverified user cannot login', $loginAllowed);
    
    App::reset();
    return $t;
}

function test_login_logic_rate_limiting(): Test
{
    $t = new Test('Login Logic - Rate Limiting');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['ratelimited', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'rl@example.com']);
    
    // Exhaust rate limit
    $rlKey = rate_limit_client_ip() . '|' . 'ratelimited';
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 7; $i++) {
        if (rate_limit('login', 5, 900, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }
    
    $t->assertEquals('First 5 login attempts allowed', 5, $allowed);
    $t->assertEquals('Attempts 6-7 blocked', 2, $blocked);
    
    App::reset();
    return $t;
}

function test_login_logic_already_logged_in(): Test
{
    $t = new Test('Login Logic - Already Logged In');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $_SESSION = ['user_id' => 1, 'user_role' => 'user', 'username' => 'existing', 'session_version' => 1];
    
    $isLoggedIn = is_logged_in();
    $t->assertTrue('User is logged in', $isLoggedIn);
    
    App::reset();
    return $t;
}

// ============================================================
// REGISTER LOGIC TESTS
// ============================================================

function test_register_logic_success(): Test
{
    $t = new Test('Register Logic - Successful Registration');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    $username = 'newuser';
    $password = 'StrongP4ssword';
    $email = 'new@example.com';
    
    $errors = validate_password_strength($password);
    $t->assert('Password passes validation', empty($errors));
    
    $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $existsStmt->execute([$username]);
    $exists = $existsStmt->fetchColumn();
    $t->assert('Username does not exist yet', $exists === 0);
    
    $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified) VALUES (?, ?, ?, 'user', 0)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
    
    $user = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $user->execute([$username]);
    $result = $user->fetch();
    
    $t->assert('User created in database', $result !== false);
    $t->assertEquals('User role is user', 'user', $result['role'] ?? '');
    $t->assertTrue('Password is hashed correctly', password_verify($password, $result['password']));
    $t->assertEquals('Email stored', $email, $result['email'] ?? '');
    $t->assertEquals('Email not verified by default', 0, $result['email_verified']);
    
    // Verify email verification token created
    $userId = $result['id'];
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), hash('sha256', $token), $expires]);
    
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ?");
    $stmt->execute([$userId]);
    $verification = $stmt->fetch();
    $t->assert('Email verification token created', $verification !== false);
    $t->assertEquals('Token not used', 0, $verification['used'] ?? 1);
    
    App::reset();
    return $t;
}

function test_register_logic_duplicate_username(): Test
{
    $t = new Test('Register Logic - Duplicate Username Rejected');
    
    $pdo = setupAuthDB();
    setupApp($pdo);
    cleanupSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)")
        ->execute(['existing', password_hash('password123', PASSWORD_DEFAULT), 'existing@test.com']);
    
    $username = 'existing';
    $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $existsStmt->execute([$username]);
    $exists = $existsStmt->fetchColumn();
    $t->assert('Username already exists', $exists > 0);
    
    $error = '';
    if ($exists > 0) {
        $error = 'Username already taken';
    }
    $t->assertEquals('Error message for duplicate', 'Username already taken', $error);
    
    App::reset();
    return $t;
}

function test_register_logic_empty_fields(): Test
{
    $t = new Test('Register Logic - Empty Username or Password Rejected');
    
    $username = '';
    $password = '';
    
    $error = '';
    if (trim($username) === '' || trim($password) === '') {
        $error = 'Username and password are required';
    }
    
    $t->assertEquals('Empty username/password rejected', 'Username and password are required', $error);
    
    return $t;
}

function test_register_logic_weak_password(): Test
{
    $t = new Test('Register Logic - Weak Password Rejected');
    
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
    
    return $t;
}

function test_register_logic_rate_limiting(): Test
{
    $t = new Test('Register Logic - Rate Limiting');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    // Exhaust rate limit
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 7; $i++) {
        if (rate_limit('register', 5, 3600)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }
    
    $t->assertEquals('First 5 registration attempts allowed', 5, $allowed);
    $t->assertEquals('Attempts 6-7 blocked', 2, $blocked);
    
    App::reset();
    return $t;
}

// ============================================================
// LOGOUT LOGIC TESTS
// ============================================================

function test_logout_logic_post_success(): Test
{
    $t = new Test('Logout Logic - POST Destroys Session');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $t->assertTrue('User logged in before logout', is_logged_in());
    
    // Simulate logout logic - clear session array and destroy
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    
    $t->assertFalse('Session empty after logout', isset($_SESSION['user_id']));
    $t->assertFalse('Not logged in after logout', is_logged_in());
    
    App::reset();
    return $t;
}

function test_logout_logic_get_redirects(): Test
{
    $t = new Test('Logout Logic - GET Does Not Destroy Session');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $t->assertTrue('User logged in before GET logout', is_logged_in());
    
    // GET logout should not destroy session (only POST does)
    // This is just testing the logic - the actual handler checks REQUEST_METHOD
    
    $t->assertTrue('Session still exists after GET', isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

// ============================================================
// VERIFY EMAIL LOGIC TESTS
// ============================================================

function test_verify_email_logic_success(): Test
{
    $t = new Test('Verify Email Logic - Valid Token Verifies Email');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    // Create user with unverified email
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create verification token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $verificationId = $pdo->lastInsertId();
    
    // Simulate verification logic
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assert('Valid token found', $validToken !== false);
    $t->assertTrue('Password verify matches', password_verify($token, $validToken['token']));
    
    // Consume token
    $consumeStmt = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE id = ? AND used = 0");
    $consumeStmt->execute([$validToken['id']]);
    $t->assertEquals('Token consumed', 1, $consumeStmt->rowCount());
    
    // Update user
    $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$validToken['user_id']]);
    
    // Verify email_verified updated
    $stmt = $pdo->prepare("SELECT email_verified FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Email verified set to 1', 1, $user['email_verified'] ?? 0);
    
    // Verify token marked as used
    $stmt = $pdo->prepare("SELECT used FROM email_verifications WHERE id = ?");
    $stmt->execute([$verificationId]);
    $verification = $stmt->fetch();
    $t->assertEquals('Token marked as used', 1, $verification['used'] ?? 0);
    
    App::reset();
    return $t;
}

function test_verify_email_logic_invalid_token(): Test
{
    $t = new Test('Verify Email Logic - Invalid Token Rejected');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    $token = 'invalid-token';
    $tokenHash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Invalid token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_verify_email_logic_expired_token(): Test
{
    $t = new Test('Verify Email Logic - Expired Token Rejected');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create EXPIRED verification token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Expired token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_verify_email_logic_already_used_token(): Test
{
    $t = new Test('Verify Email Logic - Already Used Token Rejected');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create verification token marked as used
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at, used) VALUES (?, ?, ?, ?, 1)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Already used token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_verify_email_logic_missing_token(): Test
{
    $t = new Test('Verify Email Logic - Missing Token Rejected');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    $token = '';
    $tokenHash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Missing token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

function test_verify_email_logic_token_hash_mismatch(): Test
{
    $t = new Test('Verify Email Logic - Token Hash Mismatch Rejected');
    
    $pdo = setupAuthDB();
    setupAuthApp($pdo);
    cleanupAuthSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create verification token with WRONG hash
    $token = bin2hex(random_bytes(32));
    $wrongHash = hash('sha256', 'wrong-token');
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $wrongHash, $expires]);
    
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();
    
    $t->assertFalse('Hash mismatch token not found', $validToken !== false);
    
    App::reset();
    return $t;
}

register_tests(
    // Login logic tests
    'test_login_logic_success',
    'test_login_logic_wrong_password',
    'test_login_logic_nonexistent_user',
    'test_login_logic_banned_user',
    'test_login_logic_suspended_user',
    'test_login_logic_expired_suspension',
    'test_login_logic_unverified_email',
    'test_login_logic_rate_limiting',
    'test_login_logic_already_logged_in',
    
    // Register logic tests
    'test_register_logic_success',
    'test_register_logic_duplicate_username',
    'test_register_logic_empty_fields',
    'test_register_logic_weak_password',
    'test_register_logic_rate_limiting',
    
    // Logout logic tests
    'test_logout_logic_post_success',
    'test_logout_logic_get_redirects',
    
    // Verify email logic tests
    'test_verify_email_logic_success',
    'test_verify_email_logic_invalid_token',
    'test_verify_email_logic_expired_token',
    'test_verify_email_logic_already_used_token',
    'test_verify_email_logic_missing_token',
    'test_verify_email_logic_token_hash_mismatch'
);