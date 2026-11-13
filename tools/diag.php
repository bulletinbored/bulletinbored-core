<?php

/**
 * diag.php — temporary remote diagnostic for "Internal Server Error" reports.
 *
 * The generic 500 handler intentionally hides the cause. On shared hosting the
 * PHP error log is often not reachable, so this file checks the pieces that
 * normally fail after an installer run and prints the real error.
 *
 * Usage: upload as diag.php in the forum root, open it in the browser, then
 * DELETE IT. It never prints passwords.
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "bulletinbored diagnosis\n";
echo "=======================\n";
echo "DELETE THIS FILE FROM THE SERVER AFTER USE.\n\n";

echo 'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
echo 'server: ' . ($_SERVER['SERVER_SOFTWARE'] ?? '-') . "\n\n";

$extensions = ['pdo', 'pdo_mysql', 'pdo_sqlite', 'mysqli', 'mbstring', 'iconv', 'zip', 'openssl', 'curl', 'fileinfo', 'json'];
echo "extensions:\n";
foreach ($extensions as $extension) {
    printf("  %-12s %s\n", $extension, extension_loaded($extension) ? 'ok' : 'MISSING');
}

// Works both inside tools/ (repository) and at the web root (uploaded copy).
$root = is_file(__DIR__ . '/config.json') ? __DIR__ : dirname(__DIR__);
$configPath = $root . '/config.json';
echo "\nconfig.json: " . (is_file($configPath) ? 'present' : 'MISSING') . "\n";
if (!is_file($configPath)) {
    echo "The forum is not installed: open the installer.\n";
    exit;
}
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    echo "config.json is not valid JSON: fix it first.\n";
    exit;
}

echo 'db_driver: ' . ($config['db_driver'] ?? '?') . "\n";
if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
    echo 'db_host: ' . ($config['db_host'] ?? '?') . "\n";
    echo 'db_name: ' . ($config['db_name'] ?? '?') . "\n";
    echo 'db_user: ' . ($config['db_user'] ?? '?') . "\n";
    echo 'db_port: ' . ($config['db_port'] ?? '(default)') . "\n";
    echo 'db_pass: ' . (($config['db_pass'] ?? '') === '' ? '(empty)' : '(set, hidden)') . "\n";
} else {
    $path = $config['db_path'] ?? '(unset)';
    echo 'db_path: ' . $path . ' (' . (is_file($path) ? 'exists' : 'MISSING') . ")\n";
    echo 'db_path writable: ' . (is_file($path) && is_writable($path) ? 'yes' : 'no') . "\n";
}

foreach (['data', 'plugins', 'uploads', 'data/sessions', 'data/logs'] as $dir) {
    $path = $root . '/' . $dir;
    printf("writable %-15s %s\n", $dir, (!is_dir($path) || !is_writable($path)) ? 'NO' : 'yes');
}

echo "\n=== database + migrations ===\n";
require_once $root . '/lib/BbPdo.php';
require_once $root . '/lib/Migrator.php';

try {
    if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
        $dsn = 'mysql:host=' . ($config['db_host'] ?? 'localhost')
            . (isset($config['db_port']) ? ';port=' . (int)$config['db_port'] : '')
            . ';dbname=' . ($config['db_name'] ?? '')
            . ';charset=utf8mb4';
        $pdo = new BbPdo($dsn, $config['db_user'] ?? null, $config['db_pass'] ?? null);
    } else {
        $pdo = new BbPdo('sqlite:' . ($config['db_path'] ?? ($root . '/data/database.sqlite')));
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "connection: ok\n";

    $tables = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "users: {$tables}\n";

    $migrator = new Migrator($pdo, $config);
    $migrator->ensureMigrationsTable();
    $pending = $migrator->getPending();
    echo 'pending migrations: ' . count($pending) . "\n";
    foreach ($pending as $migration) {
        echo '  - ' . $migration['name'] . "\n";
    }
    $migrator->migrate();
    echo "migrations: ok\n";
    $interrupted = $migrator->getInterruptedMigrations();
    if ($interrupted) {
        echo "interrupted migrations (needs recovery):\n";
        foreach ($interrupted as $row) {
            echo '  - ' . $row['migration'] . ': ' . ($row['error'] ?: 'unknown') . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "\nFAILED\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'at ' . $e->getFile() . ':' . $e->getLine() . "\n";
    if ($e instanceof \PDOException && isset($e->errorInfo)) {
        echo 'SQLSTATE: ' . ($e->errorInfo[0] ?? '') . ' / driver: ' . ($e->errorInfo[1] ?? '') . "\n";
    }
    echo "\ntrace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nDone. Delete diag.php now.\n";
