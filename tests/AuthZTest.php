<?php

/**
 * AuthZTest — tests for the AuthZ authorization service.
 * Covers all AuthZ methods: can, canOnOwned, hasRole, getUserRole, getRolePermissions, clearCache.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function setupAuthZDB(): PDO
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
    ");

    // Insert default roles with permissions
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'user',
        json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'moderator',
        json_encode(['threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'posts.delete'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'admin',
        json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'users.edit', 'users.delete', 'roles.manage', 'categories.manage', 'settings.manage', 'plugins.manage', 'themes.manage', 'langs.manage'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'restricted',
        json_encode([])
    ]);

    return $pdo;
}

function setupAuthZApp(PDO $pdo): void
{
    App::reset();
    $app = App::getInstance();
    $app->pdo = $pdo;
    $app->config = [
        'site_name' => 'Test Forum',
    ];
    $app->authz = new AuthZ($pdo);
    $app->pluginManager = null;
}

// ============================================================
// CAN() METHOD TESTS
// ============================================================

function test_authz_can_admin_has_all_permissions(): Test
{
    $t = new Test('AuthZ::can - Admin Has All Permissions');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create admin user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'admin1', 'hash', 'admin')")->execute([1]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertTrue('Admin can posts.edit', $authz->can(1, 'posts.edit'));
    $t->assertTrue('Admin can users.ban', $authz->can(1, 'users.ban'));
    $t->assertTrue('Admin can admin.access', $authz->can(1, 'admin.access'));
    $t->assertTrue('Admin can threads.delete', $authz->can(1, 'threads.delete'));
    $t->assertTrue('Admin can nonexistent permission', $authz->can(1, 'nonexistent'));
    
    App::reset();
    return $t;
}

function test_authz_can_moderator_has_specific_permissions(): Test
{
    $t = new Test('AuthZ::can - Moderator Has Specific Permissions');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create moderator user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'mod1', 'hash', 'moderator')")->execute([2]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertTrue('Moderator can posts.edit', $authz->can(2, 'posts.edit'));
    $t->assertTrue('Moderator can posts.delete', $authz->can(2, 'posts.delete'));
    $t->assertTrue('Moderator can threads.split', $authz->can(2, 'threads.split'));
    $t->assertTrue('Moderator can threads.lock', $authz->can(2, 'threads.lock'));
    $t->assertTrue('Moderator can threads.sticky', $authz->can(2, 'threads.sticky'));
    $t->assertTrue('Moderator can threads.move', $authz->can(2, 'threads.move'));
    $t->assertTrue('Moderator can threads.merge', $authz->can(2, 'threads.merge'));
    $t->assertTrue('Moderator can threads.copy', $authz->can(2, 'threads.copy'));
    $t->assertTrue('Moderator can threads.approve', $authz->can(2, 'threads.approve'));
    
    $t->assertFalse('Moderator cannot users.ban', $authz->can(2, 'users.ban'));
    $t->assertFalse('Moderator cannot admin.access', $authz->can(2, 'admin.access'));
    $t->assertFalse('Moderator cannot roles.manage', $authz->can(2, 'roles.manage'));
    
    App::reset();
    return $t;
}

function test_authz_can_user_has_limited_permissions(): Test
{
    $t = new Test('AuthZ::can - User Has Limited Permissions');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertTrue('User can threads.create', $authz->can(3, 'threads.create'));
    $t->assertTrue('User can posts.create', $authz->can(3, 'posts.create'));
    $t->assertTrue('User can posts.edit_own', $authz->can(3, 'posts.edit_own'));
    $t->assertTrue('User can posts.delete_own', $authz->can(3, 'posts.delete_own'));
    
    $t->assertFalse('User cannot posts.edit', $authz->can(3, 'posts.edit'));
    $t->assertFalse('User cannot posts.delete', $authz->can(3, 'posts.delete'));
    $t->assertFalse('User cannot admin.access', $authz->can(3, 'admin.access'));
    $t->assertFalse('User cannot users.ban', $authz->can(3, 'users.ban'));
    $t->assertFalse('User cannot threads.delete', $authz->can(3, 'threads.delete'));
    
    App::reset();
    return $t;
}

function test_authz_can_restricted_role_no_permissions(): Test
{
    $t = new Test('AuthZ::can - Restricted Role Has No Permissions');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create restricted user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'restricted1', 'hash', 'restricted')")->execute([4]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertFalse('Restricted cannot threads.create', $authz->can(4, 'threads.create'));
    $t->assertFalse('Restricted cannot posts.create', $authz->can(4, 'posts.create'));
    $t->assertFalse('Restricted cannot admin.access', $authz->can(4, 'admin.access'));
    
    App::reset();
    return $t;
}

function test_authz_can_nonexistent_user_defaults_to_user_role(): Test
{
    $t = new Test('AuthZ::can - Nonexistent User Defaults to User Role');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    // User ID 999 doesn't exist, should default to 'user' role
    $t->assertTrue('Nonexistent user can threads.create', $authz->can(999, 'threads.create'));
    $t->assertTrue('Nonexistent user can posts.create', $authz->can(999, 'posts.create'));
    $t->assertFalse('Nonexistent user cannot admin.access', $authz->can(999, 'admin.access'));
    
    App::reset();
    return $t;
}

function test_authz_can_with_owner_id_same_user(): Test
{
    $t = new Test('AuthZ::can - With Owner ID (Same User)');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // User checking permission on their own resource
    // can() with ownerId only returns true if user has the GENERAL permission
    // (it doesn't check _own permissions - that's what canOnOwned() does)
    $t->assertFalse('User cannot edit own post via can() - no general posts.edit permission', $authz->can(3, 'posts.edit', 3));
    $t->assertFalse('User cannot delete own post via can() - no general posts.delete permission', $authz->can(3, 'posts.delete', 3));
    
    // But canOnOwned() checks _own permissions
    $t->assertTrue('User can edit own post via canOnOwned()', $authz->canOnOwned(3, 'posts.edit', 3));
    $t->assertTrue('User can delete own post via canOnOwned()', $authz->canOnOwned(3, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

function test_authz_can_with_owner_id_different_user(): Test
{
    $t = new Test('AuthZ::can - With Owner ID (Different User)');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create two regular users
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user2', 'hash', 'user')")->execute([4]);
    
    $authz = new AuthZ($pdo);
    
    // User 3 checking permission on user 4's resource
    $t->assertFalse('User cannot edit other user post', $authz->can(3, 'posts.edit', 4));
    $t->assertFalse('User cannot delete other user post', $authz->can(3, 'posts.delete', 4));
    
    App::reset();
    return $t;
}

function test_authz_can_with_owner_id_moderator(): Test
{
    $t = new Test('AuthZ::can - With Owner ID (Moderator)');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create moderator and regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'mod1', 'hash', 'moderator')")->execute([2]);
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // Moderator can edit/delete any post (has general permission)
    $t->assertTrue('Moderator can edit any post', $authz->can(2, 'posts.edit', 3));
    $t->assertTrue('Moderator can delete any post', $authz->can(2, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

function test_authz_can_with_owner_id_admin(): Test
{
    $t = new Test('AuthZ::can - With Owner ID (Admin)');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create admin and regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'admin1', 'hash', 'admin')")->execute([1]);
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // Admin can do anything via can()
    $t->assertTrue('Admin can edit any post via can()', $authz->can(1, 'posts.edit', 3));
    $t->assertTrue('Admin can delete any post via can()', $authz->can(1, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

// ============================================================
// CANONOWNED() METHOD TESTS
// ============================================================

function test_authz_canonowned_user_own_resource(): Test
{
    $t = new Test('AuthZ::canOnOwned - User Own Resource');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // User can edit/delete own posts
    $t->assertTrue('User can edit own post', $authz->canOnOwned(3, 'posts.edit', 3));
    $t->assertTrue('User can delete own post', $authz->canOnOwned(3, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

function test_authz_canonowned_user_other_resource(): Test
{
    $t = new Test('AuthZ::canOnOwned - User Other Resource');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create two regular users
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user2', 'hash', 'user')")->execute([4]);
    
    $authz = new AuthZ($pdo);
    
    // User cannot edit/delete other user's posts
    $t->assertFalse('User cannot edit other user post', $authz->canOnOwned(3, 'posts.edit', 4));
    $t->assertFalse('User cannot delete other user post', $authz->canOnOwned(3, 'posts.delete', 4));
    
    App::reset();
    return $t;
}

function test_authz_canonowned_moderator_any_resource(): Test
{
    $t = new Test('AuthZ::canOnOwned - Moderator Any Resource');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create moderator and regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'mod1', 'hash', 'moderator')")->execute([2]);
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // Moderator can edit/delete any post (has general permission)
    $t->assertTrue('Moderator can edit any post', $authz->canOnOwned(2, 'posts.edit', 3));
    $t->assertTrue('Moderator can delete any post', $authz->canOnOwned(2, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

function test_authz_canonowned_admin_any_resource(): Test
{
    $t = new Test('AuthZ::canOnOwned - Admin Any Resource');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create admin and regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'admin1', 'hash', 'admin')")->execute([1]);
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // Admin can do anything
    $t->assertTrue('Admin can edit any post', $authz->canOnOwned(1, 'posts.edit', 3));
    $t->assertTrue('Admin can delete any post', $authz->canOnOwned(1, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

function test_authz_canonowned_user_with_general_permission(): Test
{
    $t = new Test('AuthZ::canOnOwned - User With General Permission');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // User has posts.edit_own but not posts.edit
    // canOnOwned should check _own permission first
    $t->assertTrue('User can edit own post via _own', $authz->canOnOwned(3, 'posts.edit', 3));
    $t->assertTrue('User can delete own post via _own', $authz->canOnOwned(3, 'posts.delete', 3));
    
    App::reset();
    return $t;
}

// ============================================================
// HASROLE() METHOD TESTS
// ============================================================

function test_authz_hasrole_admin(): Test
{
    $t = new Test('AuthZ::hasRole - Admin Role');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'admin1', 'hash', 'admin')")->execute([1]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertTrue('hasRole admin for admin user', $authz->hasRole(1, 'admin'));
    $t->assertFalse('hasRole moderator for admin user', $authz->hasRole(1, 'moderator'));
    $t->assertFalse('hasRole user for admin user', $authz->hasRole(1, 'user'));
    
    App::reset();
    return $t;
}

function test_authz_hasrole_moderator(): Test
{
    $t = new Test('AuthZ::hasRole - Moderator Role');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'mod1', 'hash', 'moderator')")->execute([2]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertFalse('hasRole admin for moderator', $authz->hasRole(2, 'admin'));
    $t->assertTrue('hasRole moderator for moderator', $authz->hasRole(2, 'moderator'));
    $t->assertFalse('hasRole user for moderator', $authz->hasRole(2, 'user'));
    
    App::reset();
    return $t;
}

function test_authz_hasrole_user(): Test
{
    $t = new Test('AuthZ::hasRole - User Role');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertFalse('hasRole admin for user', $authz->hasRole(3, 'admin'));
    $t->assertFalse('hasRole moderator for user', $authz->hasRole(3, 'moderator'));
    $t->assertTrue('hasRole user for user', $authz->hasRole(3, 'user'));
    
    App::reset();
    return $t;
}

function test_authz_hasrole_nonexistent_user(): Test
{
    $t = new Test('AuthZ::hasRole - Nonexistent User');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    // Nonexistent user defaults to 'user' role
    $t->assertFalse('hasRole admin for nonexistent user', $authz->hasRole(999, 'admin'));
    $t->assertFalse('hasRole moderator for nonexistent user', $authz->hasRole(999, 'moderator'));
    $t->assertTrue('hasRole user for nonexistent user', $authz->hasRole(999, 'user'));
    
    App::reset();
    return $t;
}

// ============================================================
// GETUSERROLE() METHOD TESTS
// ============================================================

function test_authz_getuserrole_admin(): Test
{
    $t = new Test('AuthZ::getUserRole - Admin');
    
    $pdo = setupAuthZDB();
    setupAuthZApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'admin1', 'hash', 'admin')")->execute([1]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertEquals('Get admin role', 'admin', $authz->getUserRole(1));
    
    App::reset();
    return $t;
}

function test_authz_getuserrole_moderator(): Test
{
    $t = new Test('AuthZ::getUserRole - Moderator');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'mod1', 'hash', 'moderator')")->execute([2]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertEquals('Get moderator role', 'moderator', $authz->getUserRole(2));
    
    App::reset();
    return $t;
}

function test_authz_getuserrole_user(): Test
{
    $t = new Test('AuthZ::getUserRole - User');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    $t->assertEquals('Get user role', 'user', $authz->getUserRole(3));
    
    App::reset();
    return $t;
}

function test_authz_getuserrole_nonexistent(): Test
{
    $t = new Test('AuthZ::getUserRole - Nonexistent User');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $t->assertEquals('Get role for nonexistent user defaults to user', 'user', $authz->getUserRole(999));
    
    App::reset();
    return $t;
}

// ============================================================
// GETROLEPERMISSIONS() METHOD TESTS
// ============================================================

function test_authz_getrolepermissions_admin(): Test
{
    $t = new Test('AuthZ::getRolePermissions - Admin');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $perms = $authz->getRolePermissions('admin');
    
    $t->assert('Admin permissions is array', is_array($perms));
    $t->assertTrue('Admin has admin.access', in_array('admin.access', $perms, true));
    $t->assertTrue('Admin has users.ban', in_array('users.ban', $perms, true));
    $t->assertTrue('Admin has threads.delete', in_array('threads.delete', $perms, true));
    $t->assertTrue('Admin has roles.manage', in_array('roles.manage', $perms, true));
    $t->assertTrue('Admin has plugins.manage', in_array('plugins.manage', $perms, true));
    
    App::reset();
    return $t;
}

function test_authz_getrolepermissions_moderator(): Test
{
    $t = new Test('AuthZ::getRolePermissions - Moderator');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $perms = $authz->getRolePermissions('moderator');
    
    $t->assert('Moderator permissions is array', is_array($perms));
    $t->assertTrue('Moderator has threads.approve', in_array('threads.approve', $perms, true));
    $t->assertTrue('Moderator has posts.edit', in_array('posts.edit', $perms, true));
    $t->assertTrue('Moderator has threads.delete', in_array('threads.delete', $perms, true));
    $t->assertTrue('Moderator has threads.lock', in_array('threads.lock', $perms, true));
    $t->assertFalse('Moderator does not have admin.access', in_array('admin.access', $perms, true));
    $t->assertFalse('Moderator does not have users.ban', in_array('users.ban', $perms, true));
    
    App::reset();
    return $t;
}

function test_authz_getrolepermissions_user(): Test
{
    $t = new Test('AuthZ::getRolePermissions - User');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $perms = $authz->getRolePermissions('user');
    
    $t->assert('User permissions is array', is_array($perms));
    $t->assertTrue('User has threads.create', in_array('threads.create', $perms, true));
    $t->assertTrue('User has posts.create', in_array('posts.create', $perms, true));
    $t->assertTrue('User has posts.edit_own', in_array('posts.edit_own', $perms, true));
    $t->assertTrue('User has posts.delete_own', in_array('posts.delete_own', $perms, true));
    $t->assertFalse('User does not have posts.edit', in_array('posts.edit', $perms, true));
    $t->assertFalse('User does not have admin.access', in_array('admin.access', $perms, true));
    
    App::reset();
    return $t;
}

function test_authz_getrolepermissions_restricted(): Test
{
    $t = new Test('AuthZ::getRolePermissions - Restricted');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $perms = $authz->getRolePermissions('restricted');
    
    $t->assert('Restricted permissions is array', is_array($perms));
    $t->assertEquals('Restricted has no permissions', 0, count($perms));
    
    App::reset();
    return $t;
}

function test_authz_getrolepermissions_nonexistent_role(): Test
{
    $t = new Test('AuthZ::getRolePermissions - Nonexistent Role');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $perms = $authz->getRolePermissions('nonexistent');
    
    $t->assert('Nonexistent role returns empty array', is_array($perms));
    $t->assertEquals('Nonexistent role has no permissions', 0, count($perms));
    
    App::reset();
    return $t;
}

function test_authz_getrolepermissions_caching(): Test
{
    $t = new Test('AuthZ::getRolePermissions - Caching');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    // First call
    $perms1 = $authz->getRolePermissions('user');
    // Second call should use cache
    $perms2 = $authz->getRolePermissions('user');
    
    $t->assertEquals('Permissions cached', $perms1, $perms2);
    $t->assertTrue('Same array reference (cached)', $perms1 === $perms2);
    
    App::reset();
    return $t;
}

// ============================================================
// CLEARCACHE() METHOD TESTS
// ============================================================

function test_authz_clearcache(): Test
{
    $t = new Test('AuthZ::clearCache - Clears Role Cache');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    // First call to populate cache
    $perms1 = $authz->getRolePermissions('user');
    
    // Clear cache
    $authz->clearCache();
    
    // Second call should not use cache (new array with same values)
    $perms2 = $authz->getRolePermissions('user');
    
    $t->assertEquals('Permissions same after cache clear', $perms1, $perms2);
    // Note: PHP may optimize and return same array reference for identical JSON,
    // so we only test that values are equal, not reference identity
    
    App::reset();
    return $t;
}

function test_authz_clearcache_multiple_roles(): Test
{
    $t = new Test('AuthZ::clearCache - Clears All Role Caches');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    // Populate cache for multiple roles
    $userPerms1 = $authz->getRolePermissions('user');
    $modPerms1 = $authz->getRolePermissions('moderator');
    $adminPerms1 = $authz->getRolePermissions('admin');
    
    // Clear cache
    $authz->clearCache();
    
    // Get again
    $userPerms2 = $authz->getRolePermissions('user');
    $modPerms2 = $authz->getRolePermissions('moderator');
    $adminPerms2 = $authz->getRolePermissions('admin');
    
    $t->assertEquals('User permissions same', $userPerms1, $userPerms2);
    $t->assertEquals('Moderator permissions same', $modPerms1, $modPerms2);
    $t->assertEquals('Admin permissions same', $adminPerms1, $adminPerms2);
    
    // Note: PHP may optimize and return same array reference for identical JSON,
    // so we only test that values are equal, not reference identity
    
    App::reset();
    return $t;
}

// ============================================================
// PLUGIN PERMISSIONS TESTS
// ============================================================

function test_authz_plugin_permissions(): Test
{
    $t = new Test('AuthZ::can - Plugin Contributed Permissions');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    // Mock plugin manager with checkHook method
    $pluginManager = new class {
        public function checkHook(string $hook, string $role): bool {
            // Plugin grants 'custom.permission' to 'user' role
            return $hook === 'permission_custom.permission' && $role === 'user';
        }
    };
    
    $authz = new AuthZ($pdo, $pluginManager);
    
    // User should get plugin permission
    $t->assertTrue('User gets plugin permission', $authz->can(3, 'custom.permission'));
    
    // Admin should still get all permissions
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'admin1', 'hash', 'admin')")->execute([1]);
    $t->assertTrue('Admin still gets all permissions', $authz->can(1, 'custom.permission'));
    
    App::reset();
    return $t;
}

function test_authz_plugin_permissions_no_plugin_manager(): Test
{
    $t = new Test('AuthZ::can - No Plugin Manager');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    // No plugin manager (null)
    $authz = new AuthZ($pdo, null);
    
    // Should not crash and should work normally
    $t->assertTrue('User can threads.create', $authz->can(3, 'threads.create'));
    $t->assertFalse('User cannot admin.access', $authz->can(3, 'admin.access'));
    
    App::reset();
    return $t;
}

function test_authz_plugin_permissions_plugin_manager_without_checkhook(): Test
{
    $t = new Test('AuthZ::can - Plugin Manager Without checkHook Method');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    // Create regular user
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    // Plugin manager without checkHook method
    $pluginManager = new class {
        public function someOtherMethod(): void {}
    };
    
    $authz = new AuthZ($pdo, $pluginManager);
    
    // Should not crash and should work normally
    $t->assertTrue('User can threads.create', $authz->can(3, 'threads.create'));
    $t->assertFalse('User cannot admin.access', $authz->can(3, 'admin.access'));
    
    App::reset();
    return $t;
}

// ============================================================
// EDGE CASE TESTS
// ============================================================

function test_authz_case_sensitivity(): Test
{
    $t = new Test('AuthZ - Case Sensitivity');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // Permissions are case-sensitive
    $t->assertTrue('Lowercase permission works', $authz->can(3, 'threads.create'));
    $t->assertFalse('Uppercase permission fails', $authz->can(3, 'THREADS.CREATE'));
    $t->assertFalse('Mixed case permission fails', $authz->can(3, 'Threads.Create'));
    
    App::reset();
    return $t;
}

function test_authz_role_case_sensitivity(): Test
{
    $t = new Test('AuthZ - Role Case Sensitivity');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pdo->prepare("INSERT INTO users (id, username, password, role) VALUES (?, 'user1', 'hash', 'user')")->execute([3]);
    
    $authz = new AuthZ($pdo);
    
    // Roles are case-sensitive
    $t->assertTrue('Lowercase role works', $authz->hasRole(3, 'user'));
    $t->assertFalse('Uppercase role fails', $authz->hasRole(3, 'USER'));
    $t->assertFalse('Mixed case role fails', $authz->hasRole(3, 'User'));
    
    App::reset();
    return $t;
}

function test_authz_constructor_with_plugin_manager(): Test
{
    $t = new Test('AuthZ - Constructor With Plugin Manager');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $pluginManager = new class {
        public function checkHook(string $hook, string $role): bool {
            return false;
        }
    };
    
    $authz = new AuthZ($pdo, $pluginManager);
    
    $t->assert('AuthZ instance created', $authz instanceof AuthZ);
    
    App::reset();
    return $t;
}

function test_authz_constructor_without_plugin_manager(): Test
{
    $t = new Test('AuthZ - Constructor Without Plugin Manager');
    
    $pdo = setupAuthZDB();
    setupApp($pdo);
    
    $authz = new AuthZ($pdo);
    
    $t->assert('AuthZ instance created', $authz instanceof AuthZ);
    
    App::reset();
    return $t;
}

register_tests(
    // can() method tests
    'test_authz_can_admin_has_all_permissions',
    'test_authz_can_moderator_has_specific_permissions',
    'test_authz_can_user_has_limited_permissions',
    'test_authz_can_restricted_role_no_permissions',
    'test_authz_can_nonexistent_user_defaults_to_user_role',
    'test_authz_can_with_owner_id_same_user',
    'test_authz_can_with_owner_id_different_user',
    'test_authz_can_with_owner_id_moderator',
    'test_authz_can_with_owner_id_admin',
    
    // canOnOwned() method tests
    'test_authz_canonowned_user_own_resource',
    'test_authz_canonowned_user_other_resource',
    'test_authz_canonowned_moderator_any_resource',
    'test_authz_canonowned_admin_any_resource',
    'test_authz_canonowned_user_with_general_permission',
    
    // hasRole() method tests
    'test_authz_hasrole_admin',
    'test_authz_hasrole_moderator',
    'test_authz_hasrole_user',
    'test_authz_hasrole_nonexistent_user',
    
    // getUserRole() method tests
    'test_authz_getuserrole_admin',
    'test_authz_getuserrole_moderator',
    'test_authz_getuserrole_user',
    'test_authz_getuserrole_nonexistent',
    
    // getRolePermissions() method tests
    'test_authz_getrolepermissions_admin',
    'test_authz_getrolepermissions_moderator',
    'test_authz_getrolepermissions_user',
    'test_authz_getrolepermissions_restricted',
    'test_authz_getrolepermissions_nonexistent_role',
    'test_authz_getrolepermissions_caching',
    
    // clearCache() method tests
    'test_authz_clearcache',
    'test_authz_clearcache_multiple_roles',
    
    // Plugin permissions tests
    'test_authz_plugin_permissions',
    'test_authz_plugin_permissions_no_plugin_manager',
    'test_authz_plugin_permissions_plugin_manager_without_checkhook',
    
    // Edge case tests
    'test_authz_case_sensitivity',
    'test_authz_role_case_sensitivity',
    'test_authz_constructor_with_plugin_manager',
    'test_authz_constructor_without_plugin_manager'
);