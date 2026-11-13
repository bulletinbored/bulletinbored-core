<?php

/**
 * SessionFreshnessTest — a session must stop being authenticated the moment the
 * account behind it disappears or loses its privileges.
 *
 * Covers the three ways a cached session can go stale:
 *   1. the user row is deleted;
 *   2. the role changes (admin demoted) and session_version is bumped;
 *   3. an endpoint outside bootstrap.php is asked to trust the session role.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/actions/admin/users.php';

function session_freshness_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL DEFAULT '',
        email TEXT DEFAULT '',
        role TEXT NOT NULL DEFAULT 'user',
        status TEXT NOT NULL DEFAULT 'active',
        suspension_time INTEGER DEFAULT 0,
        session_version INTEGER DEFAULT 1,
        email_verified INTEGER DEFAULT 1
    )");
    return $pdo;
}

function session_freshness_login(PDO $pdo, string $username, string $role, string $status = 'active'): array
{
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, '', ?, ?, 1)")
        ->execute([$username, password_hash('Sup3rSecretPw', PASSWORD_DEFAULT), $role, $status]);
    $id = (int)$pdo->lastInsertId();
    $_SESSION = [
        'user_id' => $id,
        'user_role' => $role,
        'user_status' => $status,
        'username' => $username,
        'session_version' => 1,
    ];
    return ['id' => $id, 'session_version' => 1];
}

function test_deleted_user_session_invalidated(): Test
{
    $t = new Test('Session freshness - deleted account is logged out');

    $pdo = session_freshness_pdo();
    App::getInstance()->pdo = $pdo;

    $user = session_freshness_login($pdo, 'gone', 'user');
    $t->assertTrue('Active account is logged in', is_logged_in());

    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);

    $t->assertFalse('Deleted account is no longer logged in', is_logged_in());
    $t->assertCount('Session data cleared', 0, $_SESSION);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_session_version_change_invalidates(): Test
{
    $t = new Test('Session freshness - bumped session_version invalidates');

    $pdo = session_freshness_pdo();
    App::getInstance()->pdo = $pdo;

    $user = session_freshness_login($pdo, 'bumped', 'user');
    $pdo->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([$user['id']]);

    $t->assertFalse('Session invalidated after version bump', is_logged_in());
    $t->assertCount('Session data cleared after bump', 0, $_SESSION);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_admin_demotion_invalidates_session(): Test
{
    $t = new Test('Session freshness - demoted admin loses the session');

    $pdo = session_freshness_pdo();
    App::getInstance()->pdo = $pdo;

    $_SESSION = ['csrf_token' => 'csrf-token-value'];

    $admin = session_freshness_login($pdo, 'admin1', 'admin');
    // A second admin performs the demotion.
    session_freshness_login($pdo, 'admin2', 'admin');
    $actor = $_SESSION;

    // Act as admin2 and demote admin1 through the real admin handler.
    $_SESSION = $actor;
    $_SESSION['csrf_token'] = 'csrf-token-value';
    $_POST = [
        'username' => 'admin1',
        'email' => '',
        'role' => 'user',
        'status' => 'active',
        'csrf_token' => 'csrf-token-value',
    ];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    $response = handle_admin_user_edit('POST', ['id' => $admin['id']]);
    $t->assertTrue('Handler returns a redirect response', $response instanceof \Bulletin\Response);

    $row = $pdo->prepare("SELECT role, session_version FROM users WHERE id = ?");
    $row->execute([$admin['id']]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    $t->assertEquals('Role demoted in database', 'user', $row['role']);
    $t->assertEquals('session_version bumped on demotion', 2, (int)$row['session_version']);

    // The demoted admin still holds the old session cookie.
    $_SESSION = [
        'user_id' => $admin['id'],
        'user_role' => 'admin',
        'session_version' => $admin['session_version'],
    ];
    $t->assertFalse('Demoted admin is logged out', is_logged_in());
    $t->assertFalse('Demoted admin is not is_admin()', is_admin());

    $_POST = [];
    $_SESSION = [];
    App::reset();
    return $t;
}

function test_non_privilege_edit_keeps_session(): Test
{
    $t = new Test('Session freshness - renaming a user keeps sessions alive');

    $pdo = session_freshness_pdo();
    App::getInstance()->pdo = $pdo;
    $_SESSION = ['csrf_token' => 'csrf-token-value'];

    $user = session_freshness_login($pdo, 'rename_me', 'user');
    session_freshness_login($pdo, 'staff', 'admin');
    $actor = $_SESSION;

    $_SESSION = $actor;
    $_SESSION['csrf_token'] = 'csrf-token-value';
    $_POST = ['username' => 'renamed', 'email' => '', 'role' => 'user', 'status' => 'active', 'csrf_token' => 'csrf-token-value'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    handle_admin_user_edit('POST', ['id' => $user['id']]);

    $row = $pdo->prepare("SELECT username, session_version FROM users WHERE id = ?");
    $row->execute([$user['id']]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    $t->assertEquals('Username updated', 'renamed', $row['username']);
    $t->assertEquals('session_version untouched', 1, (int)$row['session_version']);

    $_POST = [];
    $_SESSION = [];
    App::reset();
    return $t;
}

function test_ban_bumps_session_version(): Test
{
    $t = new Test('Session freshness - ban/suspend bump session_version');

    $pdo = session_freshness_pdo();
    App::getInstance()->pdo = $pdo;

    $user = session_freshness_login($pdo, 'troublemaker', 'user');
    session_freshness_login($pdo, 'staff2', 'admin');
    $actor = $_SESSION;

    $_SESSION = $actor;
    $_SESSION['csrf_token'] = 'csrf-token-value';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    // csrf_validate_request() rotates the token on success, so re-read it.
    foreach (['handle_ban_user_post', 'handle_suspend_user_post', 'handle_unban_user_post'] as $handler) {
        $_POST = ['id' => $user['id'], 'csrf_token' => $_SESSION['csrf_token'] ?? ''];
        $handler();
    }

    $stmt = $pdo->prepare("SELECT status, session_version FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $t->assertEquals('Status left as active after unban', 'active', $row['status']);
    $t->assertEquals('Three status changes bumped version three times', 4, (int)$row['session_version']);

    $_POST = [];
    $_SESSION = [];
    App::reset();
    return $t;
}

register_tests(
    'test_deleted_user_session_invalidated',
    'test_session_version_change_invalidates',
    'test_admin_demotion_invalidates_session',
    'test_non_privilege_edit_keeps_session',
    'test_ban_bumps_session_version'
);
