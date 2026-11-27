<?php

/**
 * Installer E2E — exercise the same schema bootstrap that install3.php uses.
 *
 * The old installer tests reimplemented pieces of the schema, so they missed
 * the SQLite branch calling bb_default_role_permissions() without loading
 * DefaultRoles.php. This test calls bb_install_schema() directly (the function
 * install3.php calls) and then runs the real migrations.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/Installer.php';
require_once __DIR__ . '/../lib/Migrator.php';

function installer_e2e_schema_has_column(PDO $pdo, string $table, string $column, string $driver): bool
{
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare("PRAGMA table_info({$table})");
    $stmt->execute();
    return in_array($column, $stmt->fetchAll(PDO::FETCH_COLUMN, 1), true);
}

function installer_e2e_assert_core_schema(Test $t, PDO $pdo, string $driver): void
{
    $tables = ['users', 'categories', 'threads', 'posts', 'uploads', 'roles', 'notifications', 'private_messages'];
    foreach ($tables as $table) {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            $t->assert("Table {$table} exists", true);
        } catch (Throwable $e) {
            $t->assert("Table {$table} exists", false);
        }
    }

    $admin = $pdo->query("SELECT username, password, email FROM users WHERE role = 'admin'")->fetch(PDO::FETCH_ASSOC);
    $t->assert('Admin created', is_array($admin));
    if (is_array($admin)) {
        $t->assertEquals('Admin username', 'admin', (string)$admin['username']);
        $t->assertTrue('Admin password hashed', password_verify('AdminPass123!', (string)$admin['password']));
    }

    $roleCount = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    $t->assertEquals('Default roles seeded', 3, $roleCount);
    $roleNames = $pdo->query('SELECT name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $t->assertEquals('Role names', ['admin', 'moderator', 'user'], $roleNames);

    $categoryCount = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE name = 'General'")->fetchColumn();
    $t->assertEquals('General category seeded', 1, $categoryCount);
}

function test_installer_e2e_sqlite(): Test
{
    $t = new Test('Installer E2E - SQLite schema, roles and migrations');
    $dbPath = sys_get_temp_dir() . '/bb_install_e2e_' . bin2hex(random_bytes(4)) . '.sqlite';
    @unlink($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    bb_install_schema($pdo, 'sqlite', 'admin', 'AdminPass123!', 'admin@example.com');
    installer_e2e_assert_core_schema($t, $pdo, 'sqlite');

    // Idempotent: a second run must not duplicate the admin/roles/category.
    bb_install_schema($pdo, 'sqlite', 'admin', 'AdminPass123!', 'admin@example.com');
    $t->assertEquals('Admin not duplicated', 1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn());
    $t->assertEquals('Roles not duplicated', 3, (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
    $t->assertEquals('Categories not duplicated', 1, (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->migrate();

    $t->assertTrue(
        'Migration adds threads.visibility',
        installer_e2e_schema_has_column($pdo, 'threads', 'visibility', 'sqlite')
    );
    $t->assertTrue(
        'Migration adds users.profile_visibility',
        installer_e2e_schema_has_column($pdo, 'users', 'profile_visibility', 'sqlite')
    );

    $pdo = null;
    @unlink($dbPath);
    return $t;
}

function test_installer_e2e_mysql(): Test
{
    $t = new Test('Installer E2E - MySQL schema, roles and migrations');

    $host = getenv('BB_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('BB_TEST_MYSQL_PORT') ?: '3306';
    $user = getenv('BB_TEST_MYSQL_USER') ?: 'root';
    $pass = getenv('BB_TEST_MYSQL_PASS') ?: '';
    $dbName = 'bb_install_e2e_' . bin2hex(random_bytes(4));

    try {
        $root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass);
        $root->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        echo "MySQL not available ({$e->getMessage()}); skipping the MySQL installer test.\n";
        $t->assert('MySQL unavailable: test skipped', true);
        return $t;
    }

    $created = false;
    try {
        $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created = true;

        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        bb_install_schema($pdo, 'mysql', 'admin', 'AdminPass123!', 'admin@example.com');
        installer_e2e_assert_core_schema($t, $pdo, 'mysql');

        bb_install_schema($pdo, 'mysql', 'admin', 'AdminPass123!', 'admin@example.com');
        $t->assertEquals('Admin not duplicated', 1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn());
        $t->assertEquals('Roles not duplicated', 3, (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
        $t->assertEquals('Categories not duplicated', 1, (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());

        $migrator = new Migrator($pdo, ['db_driver' => 'mysql']);
        $migrator->migrate();

        $t->assertTrue(
            'Migration adds threads.visibility',
            installer_e2e_schema_has_column($pdo, 'threads', 'visibility', 'mysql')
        );
        $t->assertTrue(
            'Migration adds users.profile_visibility',
            installer_e2e_schema_has_column($pdo, 'users', 'profile_visibility', 'mysql')
        );

        $pdo = null;
    } catch (Throwable $e) {
        $t->assert('MySQL installer path completed without error: ' . $e->getMessage(), false);
    } finally {
        if ($created) {
            try {
                $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            } catch (Throwable $e) {
            }
        }
        $root = null;
    }

    return $t;
}

register_tests(
    'test_installer_e2e_sqlite',
    'test_installer_e2e_mysql'
);
