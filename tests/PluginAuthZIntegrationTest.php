<?php

/**
 * PluginAuthZIntegrationTest — tests for AuthZ integration with PluginManager.
 *
 * Verifies that plugins can contribute permissions via hooks and that
 * AuthZ works correctly with and without PluginManager (DI pattern).
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../lib/PluginManager.php';

function test_authz_permission_hook_with_plugin(): Test
{
    $t = new Test('AuthZ - Plugin permission hook');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create core schema
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            role TEXT DEFAULT 'user'
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE,
            permissions TEXT DEFAULT '[]'
        );
    ");

    // Insert test user with 'user' role (no core permissions for custom_action)
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'testuser', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]')");

    App::getInstance()->pdo = $pdo;

    // Mock PluginManager that grants permission via hook
    $mockPluginManager = new class {
        public function checkHook(string $hook, string $role): bool
        {
            // Grant 'custom_action' permission to 'user' role via plugin
            return $hook === 'permission_custom_action' && $role === 'user';
        }
    };

    $authz = new AuthZ($pdo, $mockPluginManager);

    // User has no core permission but plugin grants it via hook
    $t->assertTrue('Plugin permission granted via hook for user role', $authz->can(1, 'custom_action'));

    // Plugin doesn't grant this permission
    $t->assertFalse('Plugin does not grant unrelated permission', $authz->can(1, 'other_custom_action'));

    // Admin still has all permissions (bypasses plugin check)
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (2, 'adminuser', 'admin')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\"]')");
    $t->assertTrue('Admin has all permissions regardless of plugin', $authz->can(2, 'any_permission'));

    App::reset();
    return $t;
}

function test_authz_without_pluginmanager(): Test
{
    $t = new Test('AuthZ - Works without PluginManager (backward compatibility)');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            role TEXT DEFAULT 'user'
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE,
            permissions TEXT DEFAULT '[]'
        );
    ");

    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'testuser', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"posts.create\",\"posts.edit_own\"]')");

    App::getInstance()->pdo = $pdo;

    // AuthZ instantiated without PluginManager (null)
    $authz = new AuthZ($pdo);

    $t->assertTrue('Core permission works without PluginManager', $authz->can(1, 'posts.create'));
    $t->assertTrue('Own permission works without PluginManager', $authz->can(1, 'posts.edit_own'));
    $t->assertFalse('No plugin permission without PluginManager', $authz->can(1, 'custom_action'));
    $t->assertFalse('No permission for non-existent action', $authz->can(1, 'nonexistent'));

    // canOnOwned still works
    $t->assertTrue('canOnOwned works for own resource', $authz->canOnOwned(1, 'posts.edit', 1));
    $t->assertFalse('canOnOwned denies other resource', $authz->canOnOwned(1, 'posts.edit', 2));

    App::reset();
    return $t;
}

function test_authz_pluginmanager_null_checkhook(): Test
{
    $t = new Test('AuthZ - PluginManager with null checkHook method');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            role TEXT DEFAULT 'user'
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE,
            permissions TEXT DEFAULT '[]'
        );
    ");

    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'testuser', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]')");

    App::getInstance()->pdo = $pdo;

    // PluginManager without checkHook method (edge case)
    $mockPluginManager = new class {
        public function someOtherMethod(): string { return 'test'; }
    };

    $authz = new AuthZ($pdo, $mockPluginManager);

    // Should not crash, should just not grant plugin permissions
    $t->assertFalse('No crash when checkHook missing, permission denied', $authz->can(1, 'custom_action'));

    App::reset();
    return $t;
}

function test_auth_hooks_execute(): Test
{
    $t = new Test('AuthZ - Auth hooks execute correctly');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT UNIQUE,
            password TEXT,
            role TEXT DEFAULT 'user',
            email_verified INTEGER DEFAULT 1
        );
    ");

    $passwordHash = password_hash('testpass', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (id, username, password, role, email_verified) VALUES (1, 'testuser', '$passwordHash', 'user', 1)");

    App::getInstance()->pdo = $pdo;

    $pluginManager = new PluginManager(
        __DIR__ . '/../plugins',
        __DIR__ . '/../data/plugins.json'
    );
    $pluginManager->discover();

    $hookResults = [
        'auth_before_verify' => null,
        'auth_login_block' => null,
        'auth_after_login' => null,
    ];

    // Register test hooks
    $pluginManager->addHook('auth_before_verify', function($user, $username, $password) use (&$hookResults) {
        $hookResults['auth_before_verify'] = ['user' => $user, 'username' => $username];
        return $user; // Pass through unchanged
    });

    $pluginManager->addHook('auth_login_block', function($user) use (&$hookResults) {
        $hookResults['auth_login_block'] = ['user_id' => $user['id']];
        return false; // Don't block
    });

    $pluginManager->addHook('auth_after_login', function($userId, $user) use (&$hookResults) {
        $hookResults['auth_after_login'] = ['user_id' => $userId, 'username' => $user['username']];
    });

    $pluginManager->loadEnabled();

    // Simulate the login flow from users.php
    $username = 'testuser';
    $password = 'testpass';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Test auth_before_verify hook
    if (isset($pluginManager)) {
        $user = $pluginManager->filter('auth_before_verify', $user ?: null, $username, $password);
    }
    $t->assertNotNull('auth_before_verify hook executed', $hookResults['auth_before_verify']);
    $t->assertEquals('auth_before_verify received username', 'testuser', $hookResults['auth_before_verify']['username']);

    // Test password verify
    $t->assertTrue('Password verifies correctly', password_verify($password, $user['password']));

    // Test auth_login_block hook
    $blocked = false;
    if (isset($pluginManager) && $pluginManager->checkHook('auth_login_block', $user)) {
        $blocked = true;
    }
    $t->assertFalse('auth_login_block hook executed and returned false', $blocked);
    $t->assertNotNull('auth_login_block hook executed', $hookResults['auth_login_block']);

    // Test auth_after_login hook (simulate successful login)
    if (isset($pluginManager)) {
        $pluginManager->runHook('auth_after_login', $user['id'], $user);
    }
    $t->assertNotNull('auth_after_login hook executed', $hookResults['auth_after_login']);
    $t->assertEquals('auth_after_login received user_id', 1, $hookResults['auth_after_login']['user_id']);

    App::reset();
    return $t;
}

function test_plugin_session_invalidation(): Test
{
    $t = new Test('Plugin - Session invalidation requires DB + session sync');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            password TEXT,
            role TEXT DEFAULT 'user',
            session_version INTEGER DEFAULT 1
        );
    ");

    $passwordHash = password_hash('testpass', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (id, username, password, role, session_version) VALUES (1, 'testuser', '$passwordHash', 'user', 1)");

    App::getInstance()->pdo = $pdo;

    // Simulate logged in user
    $_SESSION = [
        'user_id' => 1,
        'user_role' => 'user',
        'session_version' => 1
    ];

    // Initially logged in
    $t->assertTrue('User logged in initially', is_logged_in());

    // Scenario 1: Plugin updates BOTH DB and session (correct)
    $pdo->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([1]);
    $_SESSION['session_version'] = 2; // Plugin MUST also update session

    $t->assertTrue('Session valid after manual sync (DB + session updated)', is_logged_in());

    // Scenario 2: Plugin only updates DB but NOT session (incorrect - but current behavior trusts session)
    $pdo->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([1]);
    // $_SESSION['session_version'] NOT updated (stays at 2)

    $t->assertTrue('Session still valid (trusts session, no DB query)', is_logged_in());
    // Note: Plugin MUST update both DB and session for immediate invalidation. Current implementation trusts session-stored version.

    // Scenario 3: Session version missing (logout)
    unset($_SESSION['session_version']);
    $t->assertFalse('User logged out when session_version missing', is_logged_in());

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_plugin_fk_to_core_tables(): Test
{
    $t = new Test('Plugin - FK constraints to core tables enforced');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Core schema (simplified)
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT
        );
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            title TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    // Plugin creates table with FKs to core tables
    $pdo->exec("
        CREATE TABLE plugin_custom_data (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            thread_id INTEGER,
            data TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
        )
    ");

    // Insert core data
    $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'user1')");
    $pdo->exec("INSERT INTO threads (id, user_id, title) VALUES (1, 1, 'Thread 1')");

    // Insert plugin data referencing core
    $pdo->exec("INSERT INTO plugin_custom_data (user_id, thread_id, data) VALUES (1, 1, 'test data')");

    // Verify data exists
    $count = $pdo->query("SELECT COUNT(*) FROM plugin_custom_data")->fetchColumn();
    $t->assertEquals('Plugin data inserted', 1, $count);

    // Delete core user - should cascade to plugin table
    $pdo->exec("DELETE FROM users WHERE id = 1");

    $count = $pdo->query("SELECT COUNT(*) FROM plugin_custom_data")->fetchColumn();
    $t->assertEquals('Plugin data cascaded on user delete', 0, $count);

    // Re-insert for thread test
    $pdo->exec("INSERT INTO users (id, username) VALUES (2, 'user2')");
    $pdo->exec("INSERT INTO threads (id, user_id, title) VALUES (2, 2, 'Thread 2')");
    $pdo->exec("INSERT INTO plugin_custom_data (user_id, thread_id, data) VALUES (2, 2, 'test data 2')");

    // Delete core thread - should cascade to plugin table
    $pdo->exec("DELETE FROM threads WHERE id = 2");

    $count = $pdo->query("SELECT COUNT(*) FROM plugin_custom_data")->fetchColumn();
    $t->assertEquals('Plugin data cascaded on thread delete', 0, $count);

    // Test FK constraint violation (insert with non-existent user)
    try {
        $pdo->exec("INSERT INTO plugin_custom_data (user_id, thread_id, data) VALUES (999, 1, 'invalid')");
        $t->assertFalse('FK constraint should prevent insert with non-existent user', true);
    } catch (PDOException $e) {
        $t->assertTrue('FK constraint prevents invalid user_id', true);
    }

    App::reset();
    return $t;
}

function test_authz_canonical_permissions_still_work(): Test
{
    $t = new Test('AuthZ - Canonical permissions still work with PluginManager');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            role TEXT DEFAULT 'user'
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE,
            permissions TEXT DEFAULT '[]'
        );
    ");

    // Insert users with different roles
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'admin1', 'admin')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (2, 'mod1', 'moderator')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (3, 'user1', 'user')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (4, 'user2', 'user')");

    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"posts.edit\",\"posts.delete\",\"users.ban\",\"threads.delete\",\"threads.lock\",\"threads.split\",\"threads.merge\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"posts.edit\",\"posts.delete\",\"threads.delete\",\"threads.lock\",\"threads.split\",\"threads.merge\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"posts.create\",\"posts.edit_own\",\"posts.delete_own\"]')");

    App::getInstance()->pdo = $pdo;

    // PluginManager that doesn't interfere with canonical permissions
    $mockPluginManager = new class {
        public function checkHook(string $hook, string $role): bool
        {
            return false; // Never grant extra permissions
        }
    };

    $authz = new AuthZ($pdo, $mockPluginManager);

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

    // Ownership checks
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

    App::reset();
    return $t;
}

register_tests(
    'test_authz_permission_hook_with_plugin',
    'test_authz_without_pluginmanager',
    'test_authz_pluginmanager_null_checkhook',
    'test_auth_hooks_execute',
    'test_plugin_session_invalidation',
    'test_plugin_fk_to_core_tables',
    'test_authz_canonical_permissions_still_work'
);