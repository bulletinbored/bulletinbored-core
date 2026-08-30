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

    // Create in-memory DB with roles
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"can_ban_users\",\"can_delete_threads\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"can_delete_threads\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"can_create_threads\"]')");

    // Make $pdo global for user_has_permission
    $GLOBALS['pdo'] = $pdo;

    // Test: admin has all permissions
    $_SESSION['user_role'] = 'admin';
    $t->assertTrue('Admin has can_ban_users', user_has_permission('can_ban_users'));
    $t->assertTrue('Admin has can_delete_threads', user_has_permission('can_delete_threads'));
    $t->assertTrue('Admin has any permission', user_has_permission('nonexistent_permission'));

    // Test: moderator has specific permissions
    $_SESSION['user_role'] = 'moderator';
    $t->assertTrue('Moderator has can_delete_threads', user_has_permission('can_delete_threads'));
    $t->assertFalse('Moderator does NOT have can_ban_users', user_has_permission('can_ban_users'));

    // Test: user has limited permissions
    $_SESSION['user_role'] = 'user';
    $t->assertTrue('User has can_create_threads', user_has_permission('can_create_threads'));
    $t->assertFalse('User does NOT have can_ban_users', user_has_permission('can_ban_users'));

    // Test: default role (no session)
    unset($_SESSION['user_role']);
    $t->assertFalse('Default role has no admin permissions', user_has_permission('can_ban_users'));

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

    // Test: clean_text trims and stripslashes (does NOT remove HTML — that's escape()'s job)
    $t->assertEquals('clean_text trims whitespace', 'Hello', clean_text('  Hello  '));
    $t->assertEquals('clean_text strips slashes', "It's", clean_text("It\\'s"));

    // Test: validate_input sanitizes
    $input = 'Hello <b>World</b>';
    $sanitized = validate_input($input);
    $t->assert('validate_input removes HTML tags', !str_contains($sanitized, '<b>'));

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
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"posts.edit\",\"posts.delete\",\"users.ban\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"posts.edit\",\"posts.delete\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"posts.create\",\"posts.edit_own\"]')");

    $authz = new AuthZ($pdo);

    // Admin has all permissions
    $t->assertTrue('Admin can posts.edit', $authz->can(1, 'posts.edit'));
    $t->assertTrue('Admin can users.ban', $authz->can(1, 'users.ban'));
    $t->assertTrue('Admin can anything', $authz->can(1, 'nonexistent'));

    // Moderator has specific permissions
    $t->assertTrue('Moderator can posts.edit', $authz->can(2, 'posts.edit'));
    $t->assertTrue('Moderator can posts.delete', $authz->can(2, 'posts.delete'));
    $t->assertFalse('Moderator cannot users.ban', $authz->can(2, 'users.ban'));

    // User has limited permissions
    $t->assertTrue('User can posts.create', $authz->can(3, 'posts.create'));
    $t->assertFalse('User cannot posts.delete', $authz->can(3, 'posts.delete'));

    // Ownership checks
    $t->assertTrue('User can edit own post', $authz->canOnOwned(3, 'posts.edit', 3));
    $t->assertFalse('User cannot edit others post', $authz->canOnOwned(3, 'posts.edit', 2));
    $t->assertTrue('Moderator can edit any post', $authz->canOnOwned(2, 'posts.edit', 3));

    // Role resolution
    $t->assertEquals('Get admin role', 'admin', $authz->getUserRole(1));
    $t->assertEquals('Get user role', 'user', $authz->getUserRole(3));

    return $t;
}
