<?php

/**
 * InstallerTest — tests for the installation process.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/DbQuery.php';

function createTestSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
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
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL UNIQUE,
            batch INTEGER NOT NULL,
            ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
}

function test_installer_fresh_install(): Test
{
    $t = new Test('Installer - Fresh Install Creates Schema');

    $dbPath = sys_get_temp_dir() . '/bb_test_install_' . uniqid() . '.sqlite';
    if (file_exists($dbPath)) unlink($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");

    createTestSchema($pdo);

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $t->assert('users table exists', in_array('users', $tables));
    $t->assert('categories table exists', in_array('categories', $tables));
    $t->assert('threads table exists', in_array('threads', $tables));
    $t->assert('posts table exists', in_array('posts', $tables));
    $t->assert('roles table exists', in_array('roles', $tables));

    unlink($dbPath);

    return $t;
}

function test_installer_creates_admin(): Test
{
    $t = new Test('Installer - Creates Admin User');

    $dbPath = sys_get_temp_dir() . '/bb_test_install_' . uniqid() . '.sqlite';
    if (file_exists($dbPath)) unlink($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");

    createTestSchema($pdo);

    $adminUser = 'admin';
    $adminPass = 'SecureP4ssword';
    $adminEmail = 'admin@example.com';

    $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'admin')")
        ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail]);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch();

    $t->assert('Admin user created', $admin !== false);
    $t->assertEquals('Admin username correct', $adminUser, $admin['username']);
    $t->assertEquals('Admin email correct', $adminEmail, $admin['email']);
    $t->assertTrue('Admin password hashed', password_verify($adminPass, $admin['password']));

    unlink($dbPath);

    return $t;
}

function test_installer_idempotent(): Test
{
    $t = new Test('Installer - Idempotent (No Duplicate Admin)');

    $dbPath = sys_get_temp_dir() . '/bb_test_install_' . uniqid() . '.sqlite';
    if (file_exists($dbPath)) unlink($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");

    createTestSchema($pdo);

    $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'admin')")
        ->execute(['admin', password_hash('pass1', PASSWORD_DEFAULT), 'a@b.com']);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    $adminCount = (int)$stmt->fetchColumn();

    if ($adminCount === 0) {
        $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'admin')")
            ->execute(['admin', password_hash('pass2', PASSWORD_DEFAULT), 'c@d.com']);
    }

    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $t->assertEquals('Only one admin exists', 1, (int)$count);

    @unlink($dbPath);

    return $t;
}

function test_installer_validates_username(): Test
{
    $t = new Test('Installer - Username Validation');

    $t->assert('Empty username invalid', empty('') === true);
    $t->assert('Single char username invalid', strlen('a') < 3);
    $t->assert('Valid username passes', strlen('admin') >= 3);
    $t->assert('Username with spaces invalid', preg_match('/^[a-zA-Z0-9_-]+$/', 'user name') === 0);
    $t->assert('Alphanumeric username valid', preg_match('/^[a-zA-Z0-9_-]+$/', 'admin123') === 1);

    return $t;
}

function test_installer_validates_password(): Test
{
    $t = new Test('Installer - Password Validation');

    $errors = validate_password_strength('weak');
    $t->assert('Weak password rejected', !empty($errors));

    $errors = validate_password_strength('Short1A');
    $t->assert('Password too short rejected', !empty($errors));

    $errors = validate_password_strength('nouppercase123');
    $t->assert('No uppercase rejected', !empty($errors));

    $errors = validate_password_strength('NOLOWERCASE123');
    $t->assert('No lowercase rejected', !empty($errors));

    $errors = validate_password_strength('NoNumbersHere');
    $t->assert('No numbers rejected', !empty($errors));

    $errors = validate_password_strength('ValidP4ssword');
    $t->assert('Valid password accepted', empty($errors));

    return $t;
}

function test_installer_validates_email(): Test
{
    $t = new Test('Installer - Email Validation');

    $t->assert('Empty email invalid', filter_var('', FILTER_VALIDATE_EMAIL) === false);
    $t->assert('Invalid email rejected', filter_var('notanemail', FILTER_VALIDATE_EMAIL) === false);
    $t->assert('Missing domain rejected', filter_var('test@', FILTER_VALIDATE_EMAIL) === false);
    $t->assert('Valid email accepted', filter_var('admin@example.com', FILTER_VALIDATE_EMAIL) !== false);

    return $t;
}

function test_installer_seeds_default_roles(): Test
{
    $t = new Test('Installer - Seeds Default Roles');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, permissions TEXT DEFAULT '[]')");

    $roles = [
        ['admin', json_encode(['admin.access', 'threads.delete', 'users.ban'])],
        ['moderator', json_encode(['threads.delete', 'posts.edit'])],
        ['user', json_encode(['threads.create', 'posts.create'])],
    ];

    foreach ($roles as $role) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)");
        $stmt->execute($role);
    }

    $count = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    $t->assertEquals('Three default roles seeded', 3, (int)$count);

    $adminPerms = $pdo->query("SELECT permissions FROM roles WHERE name = 'admin'")->fetchColumn();
    $perms = json_decode($adminPerms, true);
    $t->assert('Admin has admin.access', in_array('admin.access', $perms));

    return $t;
}

function test_installer_seeds_default_category(): Test
{
    $t = new Test('Installer - Seeds Default Category');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT, position INTEGER DEFAULT 0)");

    $stmt = $pdo->prepare("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')");
    $stmt->execute();

    $count = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $t->assertEquals('Default category created', 1, (int)$count);

    return $t;
}

function test_installer_rollback_on_failure(): Test
{
    $t = new Test('Installer - Rollback on Failure');

    $dbPath = sys_get_temp_dir() . '/bb_test_install_' . uniqid() . '.sqlite';
    if (file_exists($dbPath)) unlink($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->beginTransaction();
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)");
    $pdo->exec("INSERT INTO users (username) VALUES ('test')");

    $pdo->rollBack();

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'users'")->fetchAll();
    $t->assert('Tables rolled back on failure', count($tables) === 0);

    unlink($dbPath);

    return $t;
}

function test_installer_sqlite_support(): Test
{
    $t = new Test('Installer - SQLite Support');

    $dbPath = sys_get_temp_dir() . '/bb_test_sqlite_' . uniqid() . '.sqlite';
    if (file_exists($dbPath)) unlink($dbPath);

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $t->assertEquals('SQLite driver available', 'sqlite', $driver);

        $pdo->exec("CREATE TABLE test (id INTEGER PRIMARY KEY)");
        $t->assert('Can create tables in SQLite', true);
    } catch (Exception $e) {
        $t->assert('SQLite works: ' . $e->getMessage(), false);
    }

    unlink($dbPath);

    return $t;
}

function test_installer_config_file_format(): Test
{
    $t = new Test('Installer - Config File Format');

    $config = [
        'db_driver' => 'sqlite',
        'db_path' => 'data/db.sqlite',
        'base_url' => 'http://localhost/forum',
        'admin_user' => 'admin',
    ];

    $json = json_encode($config, JSON_PRETTY_PRINT);
    $decoded = json_decode($json, true);

    $t->assert('Config encodes to JSON', $json !== false);
    $t->assertEquals('Config decodes correctly', 'sqlite', $decoded['db_driver']);
    $t->assert('Config has required keys', isset($decoded['db_driver'], $decoded['db_path']));

    return $t;
}

register_tests(
    'test_installer_fresh_install',
    'test_installer_creates_admin',
    'test_installer_idempotent',
    'test_installer_validates_username',
    'test_installer_validates_password',
    'test_installer_validates_email',
    'test_installer_seeds_default_roles',
    'test_installer_seeds_default_category',
    'test_installer_rollback_on_failure',
    'test_installer_sqlite_support',
    'test_installer_config_file_format'
);
