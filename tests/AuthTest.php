<?php

/**
 * Auth tests — tests password hashing, CSRF tokens, session handling, permissions.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_password_hashing(): Test
{
    $t = new Test('Auth - Password Hashing');

    // Test: password_hash creates valid hash
    $hash = password_hash('mypassword', PASSWORD_DEFAULT);
    $t->assert('Password hash is non-empty', !empty($hash));
    $t->assert('Password hash starts with $', str_starts_with($hash, '$'));

    // Test: password_verify works
    $t->assertTrue('Password verify succeeds with correct password', password_verify('mypassword', $hash));
    $t->assertFalse('Password verify fails with wrong password', password_verify('wrongpassword', $hash));

    // Test: different hashes for same password (salt)
    $hash2 = password_hash('mypassword', PASSWORD_DEFAULT);
    $t->assertNotEquals('Same password produces different hashes', $hash, $hash2);
    $t->assertTrue('Both hashes verify correctly', password_verify('mypassword', $hash2));

    return $t;
}

function test_csrf_tokens(): Test
{
    $t = new Test('Auth - CSRF Tokens');

    // Start session for CSRF functions
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Test: generate_csrf_token creates token
    $token = generate_csrf_token();
    $t->assert('CSRF token is non-empty', !empty($token));
    $t->assertEquals('CSRF token is 64 hex chars', 64, strlen($token));

    // Test: validate_csrf_token accepts valid token
    $t->assertTrue('Valid CSRF token validates', validate_csrf_token($token));

    // Test: validate_csrf_token rejects invalid token
    $t->assertFalse('Invalid CSRF token rejected', validate_csrf_token('invalid-token'));

    // Test: validate_csrf_token rejects empty token
    $t->assertFalse('Empty CSRF token rejected', validate_csrf_token(''));

    return $t;
}

function test_user_has_permission(): Test
{
    $t = new Test('Auth - Permissions');

    // Setup session
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Create in-memory DB with roles using new permission notation
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"users.ban\",\"threads.delete\",\"posts.edit\",\"roles.manage\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"threads.delete\",\"posts.edit\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"threads.create\",\"posts.create\",\"posts.edit_own\"]')");

    // Make $pdo available for user_has_permission
    App::getInstance()->pdo = $pdo;

    // Test: admin has all permissions
    $_SESSION['user_role'] = 'admin';
    $t->assertTrue('Admin has users.ban', user_has_permission('users.ban'));
    $t->assertTrue('Admin has threads.delete', user_has_permission('threads.delete'));
    $t->assertTrue('Admin has any permission', user_has_permission('nonexistent_permission'));

    // Test: moderator has specific permissions
    $_SESSION['user_role'] = 'moderator';
    $t->assertTrue('Moderator has threads.delete', user_has_permission('threads.delete'));
    $t->assertFalse('Moderator does NOT have users.ban', user_has_permission('users.ban'));

    // Test: user has limited permissions
    $_SESSION['user_role'] = 'user';
    $t->assertTrue('User has threads.create', user_has_permission('threads.create'));
    $t->assertFalse('User does NOT have users.ban', user_has_permission('users.ban'));

    // Test: default role (no session)
    unset($_SESSION['user_role']);
    $t->assertFalse('Default role has no admin permissions', user_has_permission('users.ban'));

    return $t;
}

function test_is_logged_in(): Test
{
    $t = new Test('Auth - Session Login State');

    // Test: not logged in
    $_SESSION = [];
    $t->assertFalse('Not logged in when session empty', is_logged_in());

    // Test: logged in
    $_SESSION['user_id'] = 42;
    $t->assertTrue('Logged in when user_id set', is_logged_in());

    // Test: is_admin
    $_SESSION['user_role'] = 'admin';
    $t->assertTrue('is_admin returns true for admin role', is_admin());

    $_SESSION['user_role'] = 'user';
    $t->assertFalse('is_admin returns false for user role', is_admin());

    // Cleanup
    $_SESSION = [];

    return $t;
}

function test_is_banned_suspended(): Test
{
    $t = new Test('Auth - Ban & Suspension');

    // Test: banned user
    $_SESSION = ['user_status' => 'banned'];
    $t->assertTrue('Banned user detected', is_banned());
    $t->assertFalse('Banned user not suspended', is_suspended());

    // Test: suspended user
    $_SESSION = ['user_status' => 'suspended', 'user_suspension_time' => time() + 3600];
    $t->assertFalse('Suspended user not banned', is_banned());
    $t->assertTrue('Suspended user detected', is_suspended());

    // Test: expired suspension
    $_SESSION = ['user_status' => 'suspended', 'user_suspension_time' => time() - 3600];
    $t->assertFalse('Expired suspension not detected', is_suspended());

    // Test: active user
    $_SESSION = ['user_status' => 'active'];
    $t->assertFalse('Active user not banned', is_banned());
    $t->assertFalse('Active user not suspended', is_suspended());

    // Cleanup
    $_SESSION = [];

    return $t;
}

function test_input_validation(): Test
{
    $t = new Test('Auth - Input Validation');

    // Test: clean_text trims and escapes HTML
    $t->assertEquals('clean_text trims whitespace', 'Hello', clean_text('  Hello  '));
    $t->assertEquals('clean_text escapes HTML', 'It&#039;s', clean_text("It's"));

    // Test: validate_input trims and stripslashes
    $input = '  Hello <b>World</b>  ';
    $sanitized = validate_input($input);
    $t->assertEquals('validate_input trims', 'Hello <b>World</b>', $sanitized);

    // Test: escape function
    $t->assertEquals('escape encodes special chars', '&lt;script&gt;', escape('<script>'));
    $t->assertEquals('escape encodes quotes', 'He said &quot;hi&quot;', escape('He said "hi"'));

    return $t;
}

// Run all Auth tests
$suite = new TestSuite();
$suite->addTest(test_password_hashing());
$suite->addTest(test_csrf_tokens());
$suite->addTest(test_user_has_permission());
$suite->addTest(test_is_logged_in());
$suite->addTest(test_is_banned_suspended());
$suite->addTest(test_input_validation());
$suite->addTest(test_authz_service());
$suite->run();

function test_authz_service(): Test
{
    $t = new Test('AuthZ - Permission Service');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)");
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'admin1', 'admin')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (2, 'mod1', 'moderator')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (3, 'user1', 'user')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (4, 'user2', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"posts.edit\",\"posts.delete\",\"users.ban\",\"threads.delete\",\"threads.lock\",\"threads.split\",\"threads.merge\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"posts.edit\",\"posts.delete\",\"threads.delete\",\"threads.lock\",\"threads.split\",\"threads.merge\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"posts.create\",\"posts.edit_own\",\"posts.delete_own\"]')");

    $authz = new AuthZ($pdo);

    // Admin has all permissions
    $t->assertTrue('Admin can posts.edit', $authz->can(1, 'posts.edit'));
    $t->assertTrue('Admin can users.ban', $authz->can(1, 'users.ban'));
    $t->assertTrue('Admin can anything', $authz->can(1, 'nonexistent'));

    // Moderator has specific permissions
    $t->assertTrue('Moderator can posts.edit', $authz->can(2, 'posts.edit'));
    $t->assertTrue('Moderator can posts.delete', $authz->can(2, 'posts.delete'));
    $t->assertTrue('Moderator can threads.split', $authz->can(2, 'threads.split'));
    $t->assertFalse('Moderator cannot users.ban', $authz->can(2, 'users.ban'));
    $t->assertFalse('Moderator cannot admin.access', $authz->can(2, 'admin.access'));

    // User has limited permissions
    $t->assertTrue('User can posts.create', $authz->can(3, 'posts.create'));
    $t->assertFalse('User cannot posts.delete', $authz->can(3, 'posts.delete'));
    $t->assertFalse('User cannot admin.access', $authz->can(3, 'admin.access'));

    // Ownership checks — user can edit/delete own, not others
    $t->assertTrue('User can edit own post', $authz->canOnOwned(3, 'posts.edit', 3));
    $t->assertFalse('User cannot edit others post', $authz->canOnOwned(3, 'posts.edit', 4));
    $t->assertTrue('User can delete own post', $authz->canOnOwned(3, 'posts.delete', 3));
    $t->assertFalse('User cannot delete others post', $authz->canOnOwned(3, 'posts.delete', 4));

    // Moderator can edit/delete any post (has general permission)
    $t->assertTrue('Moderator can edit any post', $authz->canOnOwned(2, 'posts.edit', 3));
    $t->assertTrue('Moderator can delete any post', $authz->canOnOwned(2, 'posts.delete', 3));

    // Admin can do anything via canOnOwned
    $t->assertTrue('Admin can edit any post via canOnOwned', $authz->canOnOwned(1, 'posts.edit', 3));

    // Role resolution
    $t->assertEquals('Get admin role', 'admin', $authz->getUserRole(1));
    $t->assertEquals('Get user role', 'user', $authz->getUserRole(3));

    // hasRole checks
    $t->assertTrue('hasRole admin for admin user', $authz->hasRole(1, 'admin'));
    $t->assertFalse('hasRole admin for moderator', $authz->hasRole(2, 'admin'));
    $t->assertTrue('hasRole user for regular user', $authz->hasRole(3, 'user'));

    return $t;
}
