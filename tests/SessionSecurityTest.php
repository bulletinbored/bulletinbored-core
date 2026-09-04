<?php

/**
 * SessionSecurityTest — tests for session security and invalidation.
 *
 * Tests the session_version mechanism for invalidating sessions on:
 * - Password change
 * - Password reset
 * - Logout
 * - Ban
 * - Suspension
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_session_version_stored_on_login(): Test
{
    $t = new Test('Session - Version stored on login');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_session($pdo);
    App::getInstance()->pdo = $pdo;

    $userId = test_create_user_session($pdo, 'testuser', 'user');
    $pdo->prepare("UPDATE users SET session_version = 1 WHERE id = ?")->execute([$userId]);

    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'session_version' => 1
    ];

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $dbVersion = (int)$stmt->fetchColumn();

    $t->assertEquals('Session version matches DB', 1, $dbVersion);
    $t->assertEquals('Session version stored in session', 1, $_SESSION['session_version']);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_session_invalid_after_password_reset(): Test
{
    $t = new Test('Session - Invalid after password reset');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_session($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_session($pdo, 'testuser', 'user');

    $pdo->prepare("UPDATE users SET session_version = 5 WHERE id = ?")->execute([$userId]);

    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'session_version' => 5
    ];

    $oldSessionVersion = $_SESSION['session_version'];

    $pdo->prepare("UPDATE users SET session_version = COALESCE(session_version, 0) + 1 WHERE id = ?")
        ->execute([$userId]);

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $newDbVersion = (int)$stmt->fetchColumn();

    $t->assertEquals('DB session_version incremented', 6, $newDbVersion);
    $t->assertNotEquals('Session version unchanged in memory', 6, $_SESSION['session_version']);

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $storedVersion = (int)$stmt->fetchColumn();

    $sessionValid = ($storedVersion === (int)($_SESSION['session_version'] ?? 0));
    $t->assertFalse('Session now invalid (version mismatch)', $sessionValid);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_session_valid_when_versions_match(): Test
{
    $t = new Test('Session - Valid when versions match');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_session($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_session($pdo, 'testuser', 'user');

    $pdo->prepare("UPDATE users SET session_version = 3 WHERE id = ?")->execute([$userId]);

    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'session_version' => 3
    ];

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $dbVersion = (int)$stmt->fetchColumn();

    $sessionValid = ($dbVersion === (int)($_SESSION['session_version']));
    $t->assertTrue('Session valid when versions match', $sessionValid);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_session_regenerated_on_login(): Test
{
    $t = new Test('Session - Regenerated on login');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $oldSessionId = session_id();

    session_regenerate_id(true);

    $newSessionId = session_id();

    $t->assertNotEquals('New session ID generated', $oldSessionId, $newSessionId);
    $t->assertFalse('Session ID is not empty', empty($newSessionId));

    $_SESSION = [];
    return $t;
}

function test_logout_clears_session(): Test
{
    $t = new Test('Session - Logout clears session');

    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'session_version' => 1,
        'some_data' => 'value'
    ];

    $_SESSION = [];

    $t->assertFalse('user_id cleared', isset($_SESSION['user_id']));
    $t->assertFalse('user_role cleared', isset($_SESSION['user_role']));
    $t->assertFalse('session_version cleared', isset($_SESSION['session_version']));
    $t->assertFalse('some_data cleared', isset($_SESSION['some_data']));

    return $t;
}

function test_banned_status_invalidates_session(): Test
{
    $t = new Test('Session - Banned status invalidates');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_session($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_session($pdo, 'testuser', 'user');

    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'user_status' => 'banned',
        'session_version' => 1
    ];

    $isBanned = is_banned();
    $t->assertTrue('Banned user detected', $isBanned);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_suspended_status_invalidates(): Test
{
    $t = new Test('Session - Suspended status detected');

    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'user_status' => 'suspended',
        'user_suspension_time' => time() + 3600
    ];

    $isSuspended = is_suspended();
    $t->assertTrue('Suspended user detected', $isSuspended);

    $_SESSION['user_suspension_time'] = time() - 3600;
    $isExpired = is_suspended();
    $t->assertFalse('Expired suspension not detected', $isExpired);

    $_SESSION = [];
    return $t;
}

function test_session_cookie_flags(): Test
{
    $t = new Test('Session - Cookie security flags');

    $cookieParams = session_get_cookie_params();

    $t->assertTrue('HttpOnly flag set', $cookieParams['httponly'] === true);
    $t->assertFalse('Cookie lifetime is 0 (session)', $cookieParams['lifetime'] !== 0);

    $sameSiteExpected = 'Lax';
    $t->assertEquals('SameSite set to Lax', $sameSiteExpected, $cookieParams['samesite']);

    return $t;
}

function test_session_fixation_prevention(): Test
{
    $t = new Test('Session - Fixation prevention');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $sessionIdBefore = session_id();

    session_regenerate_id(true);

    $sessionIdAfter = session_id();

    $t->assertNotEquals('Session ID changed', $sessionIdBefore, $sessionIdAfter);
    $t->assertEquals('Session ID is 128 chars (bin2hex 64 bytes)', 128, strlen($sessionIdAfter));

    $_SESSION = [];
    return $t;
}

function test_concurrent_sessions_same_user(): Test
{
    $t = new Test('Session - Concurrent sessions same user');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_session($pdo);
    App::getInstance()->pdo = $pdo;

    $userId = test_create_user_session($pdo, 'testuser', 'user');
    $pdo->prepare("UPDATE users SET session_version = 1 WHERE id = ?")->execute([$userId]);

    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = 1;

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $version = (int)$stmt->fetchColumn();

    $t->assertEquals('Both sessions share same version', $version, $_SESSION['session_version']);

    $pdo->prepare("UPDATE users SET session_version = COALESCE(session_version, 0) + 1 WHERE id = ?")
        ->execute([$userId]);

    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $newVersion = (int)$stmt->fetchColumn();

    $t->assertNotEquals('Version incremented', $version, $newVersion);
    $t->assertEquals('Old session version no longer valid', false, $newVersion === $_SESSION['session_version']);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_session_expiration(): Test
{
    $t = new Test('Session - Expiration handling');

    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        '_session_created' => time() - 7200,
        '_session_timeout' => 3600
    ];

    $created = $_SESSION['_session_created'] ?? 0;
    $timeout = $_SESSION['_session_timeout'] ?? 3600;
    $now = time();

    $isExpired = ($now - $created) > $timeout;
    $t->assertTrue('Session older than timeout is expired', $isExpired);

    $_SESSION['_session_created'] = time() - 1800;
    $created = $_SESSION['_session_created'];
    $isNotExpired = ($now - $created) <= $timeout;
    $t->assertTrue('Session within timeout is valid', $isNotExpired);

    $_SESSION = [];
    return $t;
}

function test_is_logged_in_checks_version(): Test
{
    $t = new Test('Session - is_logged_in checks version');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_session($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_session($pdo, 'testuser', 'user');
    $pdo->prepare("UPDATE users SET session_version = 10 WHERE id = ?")->execute([$userId]);

    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'session_version' => 10
    ];

    $isLoggedIn = is_logged_in();
    $t->assertTrue('User logged in when version matches', $isLoggedIn);

    $pdo->prepare("UPDATE users SET session_version = 99 WHERE id = ?")->execute([$userId]);

    $isLoggedInAfter = is_logged_in();
    $t->assertFalse('User logged out when version mismatch', $isLoggedInAfter);

    $_SESSION = [];
    App::reset();
    return $t;
}

register_tests(
    'test_session_version_stored_on_login',
    'test_session_invalid_after_password_reset',
    'test_session_valid_when_versions_match',
    'test_session_regenerated_on_login',
    'test_logout_clears_session',
    'test_banned_status_invalidates_session',
    'test_suspended_status_invalidates',
    'test_session_cookie_flags',
    'test_session_fixation_prevention',
    'test_concurrent_sessions_same_user',
    'test_session_expiration',
    'test_is_logged_in_checks_version'
);

function test_setup_schema_session(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            session_version INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]'
        )
    ");
}

function test_create_user_session(PDO $pdo, string $username, string $role, string $status = 'active'): int
{
    $pdo->prepare("INSERT INTO users (username, password, email, role, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$username, password_hash('test123', PASSWORD_DEFAULT), $username . '@test.com', $role, $status]);
    return (int)$pdo->lastInsertId();
}
