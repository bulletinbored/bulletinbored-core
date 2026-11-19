<?php

/**
 * RolePermissionsTest — the default roles and the repairs of old data.
 *
 * Two bugs came from the same place: the migrations seeded canonical
 * "resource.action" permissions while the web installer seeded the legacy
 * "can_*" names, and Errors.php was never loaded by the front controller. On an
 * installation created by the installer every non-admin permission check failed
 * and the resulting Forbidden exception was a 500 instead of a 403.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/DefaultRoles.php';
require_once __DIR__ . '/../migrations/20261120_normalize_role_permissions.php';

function test_default_roles_use_canonical_permissions(): Test
{
    $t = new Test('Roles - defaults use canonical permissions');

    $defaults = bb_default_role_permissions();
    $t->assertCount('Three default roles', 3, $defaults);
    $t->assertContains('Admin can access the panel', 'admin.access', $defaults['admin']);
    $t->assertContains('User can create threads', 'threads.create', $defaults['user']);
    $t->assertContains('User can reply', 'posts.create', $defaults['user']);
    $t->assertContains('Moderator can approve', 'threads.approve', $defaults['moderator']);

    foreach ($defaults as $role => $permissions) {
        foreach ($permissions as $permission) {
            $t->assert(
                "Permission '{$permission}' of role {$role} is canonical",
                (bool)preg_match('/^[a-z]+\.[a-z_]+$/', $permission)
            );
        }
    }

    // The installer must not seed the legacy names any more.
    $installer = (string)file_get_contents(dirname(__DIR__) . '/install3.php');
    $t->assert('Installer uses the shared list', str_contains($installer, 'bb_default_role_permissions()'));
    $t->assert('Installer no longer seeds can_create_threads', !str_contains($installer, "'can_create_threads'"));

    $seedMigration = (string)file_get_contents(dirname(__DIR__) . '/migrations/20260829_initial_schema.php');
    $t->assert('Initial schema uses the shared list', str_contains($seedMigration, 'bb_default_role_permissions()'));

    return $t;
}

function test_legacy_permissions_are_normalized(): Test
{
    $t = new Test('Roles - legacy can_* permissions are repaired');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, permissions TEXT)");

    // What the old web installer created.
    $legacy = [
        ['admin', ['can_approve_threads', 'can_delete_threads', 'can_ban_users', 'can_manage_roles']],
        ['moderator', ['can_approve_threads', 'can_lock_threads']],
        ['user', ['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts']],
        // A custom role with a legacy name and a permission of its own.
        ['editor', ['can_edit_posts', 'custom.thing']],
    ];
    $insert = $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)");
    foreach ($legacy as [$name, $perms]) {
        $insert->execute([$name, json_encode($perms)]);
    }

    (new NormalizeRolePermissions())->up($pdo);

    $byName = [];
    foreach ($pdo->query("SELECT name, permissions FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byName[$row['name']] = json_decode($row['permissions'], true);
    }

    $t->assertCount('User has the canonical defaults', 4, $byName['user']);
    $t->assertContains('User can create threads', 'threads.create', $byName['user']);
    $t->assertContains('User can reply', 'posts.create', $byName['user']);
    $t->assert('No legacy names left', !in_array('can_create_threads', $byName['user'], true));

    $t->assertContains('Admin keeps the panel access', 'admin.access', $byName['admin']);
    $t->assertContains('Moderator can approve', 'threads.approve', $byName['moderator']);

    $t->assertContains('Custom role mapped', 'posts.edit', $byName['editor']);
    $t->assertContains('Custom permission preserved', 'custom.thing', $byName['editor']);

    // Running it again changes nothing (idempotent).
    (new NormalizeRolePermissions())->up($pdo);
    $again = [];
    foreach ($pdo->query("SELECT name, permissions FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $again[$row['name']] = json_decode($row['permissions'], true);
    }
    $t->assertEquals('Second run is a no-op', $byName, $again);

    return $t;
}

function test_tailored_role_is_not_widened(): Test
{
    $t = new Test('Roles - a trimmed role keeps its permissions');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, permissions TEXT)");
    // No legacy name: an administrator removed almost everything on purpose.
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"posts.create\"]')");

    (new NormalizeRolePermissions())->up($pdo);

    $perms = json_decode((string)$pdo->query("SELECT permissions FROM roles WHERE name = 'user'")->fetchColumn(), true);
    $t->assertCount('Untouched', 1, $perms);
    $t->assertContains('Only the configured permission', 'posts.create', $perms);
    $t->assert('Not widened to the defaults', !in_array('threads.create', $perms, true));

    return $t;
}

function test_http_exceptions_are_loaded_at_runtime(): Test
{
    $t = new Test('Errors - typed HTTP exceptions are available at runtime');

    // bootstrap.php is what the front controller loads: requiring it must make
    // the exception classes available (they live in a multi-class file the
    // PSR-4 autoloader cannot resolve).
    require_once dirname(__DIR__) . '/src/bootstrap.php';

    $t->assertTrue('ForbiddenException available', class_exists('Bulletin\\ForbiddenException'));
    $t->assertTrue('NotFoundException available', class_exists('Bulletin\\NotFoundException'));
    $t->assertTrue('TooManyRequestsException available', class_exists('Bulletin\\TooManyRequestsException'));

    $e = new \Bulletin\ForbiddenException('nope');
    $t->assertEquals('Forbidden maps to 403', 403, $e->getStatusCode());
    $t->assertTrue('It is catchable as HttpException', $e instanceof \Bulletin\HttpException);

    return $t;
}

register_tests(
    'test_default_roles_use_canonical_permissions',
    'test_legacy_permissions_are_normalized',
    'test_tailored_role_is_not_widened',
    'test_http_exceptions_are_loaded_at_runtime'
);
