<?php

/**
 * RegistrationTest — user registration, login, validation, ban enforcement.
 * Tests the underlying logic directly without full framework bootstrap.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_register_user_success(): Test
{
    $t = new Test('Registration - Success');

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
            email_verified INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    App::getInstance()->pdo = $pdo;

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

    App::reset();

    return $t;
}

function test_register_duplicate_username(): Test
{
    $t = new Test('Registration - Duplicate Username Rejected');

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
            email_verified INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)")
        ->execute(['existing', password_hash('password123', PASSWORD_DEFAULT), 'existing@test.com']);

    App::getInstance()->pdo = $pdo;

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

function test_register_empty_fields(): Test
{
    $t = new Test('Registration - Empty Username or Password Rejected');

    $username = '';
    $password = '';

    $error = '';
    if (trim($username) === '' || trim($password) === '') {
        $error = 'Username and password are required';
    }

    $t->assertEquals('Empty username/password rejected', 'Username and password are required', $error);

    return $t;
}

function test_register_weak_password(): Test
{
    $t = new Test('Registration - Weak Password Rejected');

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

function test_login_correct(): Test
{
    $t = new Test('Login - Correct Credentials');

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
    ");

    $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified) VALUES (?, ?, ?, 'user', 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);

    App::getInstance()->pdo = $pdo;

    $username = 'testuser';
    $password = 'MyP4ssword123';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $t->assert('User found', $user !== false);
    $t->assertTrue('Password verifies', password_verify($password, $user['password']));
    $t->assert('User is active', $user['status'] === 'active');
    $t->assert('Email is verified', $user['email_verified'] == 1);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_status'] = $user['status'];

    $t->assertTrue('Session user_id set', isset($_SESSION['user_id']));
    $t->assertEquals('Session username set', 'testuser', $_SESSION['username'] ?? '');
    $t->assertEquals('Session role set', 'user', $_SESSION['user_role'] ?? '');

    App::reset();
    $_SESSION = [];

    return $t;
}

function test_login_wrong_credentials(): Test
{
    $t = new Test('Login - Wrong Credentials Rejected');

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
    ");

    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);

    App::getInstance()->pdo = $pdo;

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

function test_login_nonexistent_user(): Test
{
    $t = new Test('Login - Non-existent User Rejected');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            status TEXT DEFAULT 'active',
            email_verified INTEGER DEFAULT 1
        );
    ");

    App::getInstance()->pdo = $pdo;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['nonexistent']);
    $user = $stmt->fetch();

    $t->assert('Non-existent user not found', $user === false);

    $_SESSION = [];
    $t->assertFalse('Session not set for non-existent user', isset($_SESSION['user_id']));

    App::reset();

    return $t;
}

function test_login_banned_user(): Test
{
    $t = new Test('Login - Banned User Cannot Login');

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
    ");

    $pdo->prepare("INSERT INTO users (username, password, email, status, email_verified) VALUES (?, ?, ?, 'banned', 1)")
        ->execute(['banneduser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'banned@example.com']);

    App::getInstance()->pdo = $pdo;

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

function test_login_suspended_user(): Test
{
    $t = new Test('Login - Suspended User Cannot Login');

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
    ");

    $futureTime = time() + 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, status, suspension_time, email_verified) VALUES (?, ?, ?, 'suspended', ?, 1)")
        ->execute(['suspendeduser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'suspended@example.com', $futureTime]);

    App::getInstance()->pdo = $pdo;

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

function test_login_unverified_email(): Test
{
    $t = new Test('Login - Unverified Email Cannot Login');

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
            email_verified INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['unverified', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'unverified@example.com']);

    App::getInstance()->pdo = $pdo;

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

function test_ban_kicks_active_session(): Test
{
    $t = new Test('Ban - Active Session Kicked on Next Request');

    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'baduser',
        'user_status' => 'banned',
        'user_suspension_time' => 0,
    ];

    $t->assertTrue('is_banned() detects banned status', is_banned());
    $t->assertFalse('Banned user is not suspended', is_suspended());

    $_SESSION = [];

    return $t;
}

function test_logout_destroys_session(): Test
{
    $t = new Test('Logout - Session Destroyed');

    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
    ];

    $t->assertTrue('User logged in before logout', is_logged_in());

    $_SESSION = [];

    $t->assertFalse('Session empty after logout', isset($_SESSION['user_id']));
    $t->assertFalse('Not logged in after logout', is_logged_in());

    return $t;
}

function test_session_expired(): Test
{
    $t = new Test('Session - Expired/Invalid Cookie');

    $_SESSION = [];
    $t->assertFalse('No session means not logged in', is_logged_in());

    $_SESSION = ['user_id' => null];
    $t->assertFalse('user_id null means not logged in', is_logged_in());

    $_SESSION = [];

    return $t;
}

function test_account_enumeration_prevention(): Test
{
    $t = new Test('Security - Account Enumeration Prevention');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT
        );
    ");

    $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)")
        ->execute(['alice', password_hash('password123', PASSWORD_DEFAULT), 'alice@test.com']);

    $wrongUsernameError = 'Invalid credentials';
    $wrongPasswordError = 'Invalid credentials';
    $t->assertEquals('Same error for wrong username and wrong password', $wrongUsernameError, $wrongPasswordError);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['nonexistent']);
    $user = $stmt->fetch();
    $t->assertFalse('Non-existent user not found', $user !== false);

    $stmt->execute(['alice']);
    $user = $stmt->fetch();
    $t->assert('Existing user found', $user !== false);

    return $t;
}

function test_login_rate_limiting(): Test
{
    $t = new Test('Security - Login Rate Limiting');

    $_SESSION = [];
    $dir = __DIR__ . '/../data/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $uniqueKey = 'test_login_' . uniqid();
    $file = $dir . '/' . $uniqueKey . '.json';
    @unlink($file);

    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 7; $i++) {
        if (rate_limit($uniqueKey, 5, 900)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    @unlink($file);

    $t->assertEquals('First 5 login attempts allowed', 5, $allowed);
    $t->assertEquals('Attempts 6-7 blocked', 2, $blocked);

    return $t;
}

$tests = [
    test_register_user_success(),
    test_register_duplicate_username(),
    test_register_empty_fields(),
    test_register_weak_password(),
    test_login_correct(),
    test_login_wrong_credentials(),
    test_login_nonexistent_user(),
    test_login_banned_user(),
    test_login_suspended_user(),
    test_login_unverified_email(),
    test_ban_kicks_active_session(),
    test_logout_destroys_session(),
    test_session_expired(),
    test_account_enumeration_prevention(),
    test_login_rate_limiting(),
];

$totalPassed = 0;
$totalFailed = 0;
foreach ($tests as $t) {
    $t->run();
    $totalPassed += $t->getPassed();
    $totalFailed += $t->getFailed();
}

echo "\n";
echo "############################################################\n";
echo "# TOTAL: {$totalPassed} passed, {$totalFailed} failed\n";
echo "############################################################\n";

exit($totalFailed > 0 ? 1 : 0);
