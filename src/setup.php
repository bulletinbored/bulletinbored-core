<?php
/**
 * setup.php — ensures required directories exist and initializes the database.
 *
 * Schema is managed exclusively by the Migrator. This file only:
 * 1. Ensures required directories exist
 * 2. Creates the PDO connection
 * 3. Runs pending migrations (which create schema + seed defaults)
 *
 * Returns the active PDO connection in $pdo (global).
 */

foreach (['data', 'plugins', 'uploads', 'uploads/avatars'] as $d) {
    $dir = __DIR__ . '/../' . $d;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$dbPath = $config['db_path'];
$dbDriver = $config['db_driver'] ?? 'sqlite';

require_once __DIR__ . '/../lib/BbPdo.php';

if ($dbDriver === 'mysql') {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new BbPdo($dsn, $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    try {
        $pdo->exec("ALTER DATABASE `{$config['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {}

    try {
        $pdo->exec("SET SESSION sql_mode = (SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
    } catch (PDOException $e) {}
} else {
    $isNewDb = !file_exists($dbPath);
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$pdo->exec("PRAGMA foreign_keys = ON");

$GLOBALS['pdo'] = $pdo;
App::getInstance()->pdo = $pdo;

require_once __DIR__ . '/../lib/Migrator.php';

$migrator = new Migrator($pdo, $config);
$migrator->migrate();

// Create admin user if not exists (after migrations ensure schema is ready)
require_once __DIR__ . '/../lib/DbQuery.php';
$db = new DbQuery($pdo);
if (!$db->table('users')->where('role', 'admin')->exists()) {
    $adminPassword = $config['admin_pass'] ?? null;
    if (empty($adminPassword)) {
        $adminPassword = bin2hex(random_bytes(12));
        error_log('bulletinbored: No admin user exists and no admin_pass in config. Generated temporary password: ' . $adminPassword . ' — CHANGE THIS IMMEDIATELY.');
    }
    $db->table('users')->insert([
        'username' => $config['admin_user'],
        'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'role' => 'admin',
    ]);
}
