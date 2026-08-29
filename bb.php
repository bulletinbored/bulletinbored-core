#!/usr/bin/env php
<?php

/**
 * bb.php — bulletinbored CLI.
 *
 * Usage:
 *   php bb.php <command> [options]
 *
 * Commands:
 *   migrate              Run pending migrations
 *   migrate:rollback     Rollback last batch of migrations
 *   migrate:status       Show migration status
 *   plugin:list          List all plugins with status
 *   plugin:enable <name> Enable a plugin
 *   plugin:disable <name> Disable a plugin
 *   cache:flush          Flush all caches
 *   doctor               Run system diagnostics
 *   help                 Show this help
 *
 * Plugins can register custom CLI commands via the 'cli' hook:
 *   $pluginManager->addHook('cli', function($registry) {
 *       $registry->register('mycommand', 'Description', fn($args) => ...);
 *   });
 */

define('BB_CLI', true);
define('BB_ROOT', __DIR__);

$configPath = BB_ROOT . '/config.json';
$legacyConfigPath = BB_ROOT . '/config.php';

if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
    if (!is_array($config)) {
        $config = [];
    }
} elseif (file_exists($legacyConfigPath)) {
    $config = [];
    @include $legacyConfigPath;
    if (!is_array($config)) {
        $config = [];
    }
} else {
    fwrite(STDERR, "Error: No config.json found. Run the web installer first.\n");
    exit(1);
}

require_once BB_ROOT . '/lib/BbPdo.php';
require_once BB_ROOT . '/lib/DbQuery.php';
require_once BB_ROOT . '/lib/PluginManager.php';
require_once BB_ROOT . '/lib/Migrator.php';

$dbDriver = $config['db_driver'] ?? 'sqlite';
if ($dbDriver === 'mysql') {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new BbPdo($dsn, $config['db_user'], $config['db_pass']);
} else {
    $dbPath = $config['db_path'] ?? BB_ROOT . '/data/database.sqlite';
    $pdo = new BbPdo('sqlite:' . $dbPath);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function output(string $msg): void { echo $msg . "\n"; }
function error(string $msg): void { fwrite(STDERR, "Error: {$msg}\n"); }
function success(string $msg): void { output("  ✓ {$msg}"); }
function warning(string $msg): void { output("  ! {$msg}"); }
function fail(string $msg): void { output("  ✗ {$msg}"); }

function table(array $headers, array $rows): void
{
    $widths = array_map('strlen', $headers);
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen((string)$cell));
        }
    }

    $sep = '+';
    foreach ($widths as $w) {
        $sep .= str_repeat('-', $w + 2) . '+';
    }

    $formatRow = function ($row) use ($widths) {
        $cells = [];
        foreach ($row as $i => $cell) {
            $cells[] = ' ' . str_pad((string)$cell, $widths[$i]) . ' ';
        }
        return '|' . implode('|', $cells) . '|';
    };

    output($sep);
    output($formatRow($headers));
    output($sep);
    foreach ($rows as $row) {
        output($formatRow($row));
    }
    output($sep);
}

// --- Command Registry ---

class CommandRegistry
{
    private array $commands = [];

    public function register(string $name, string $description, callable $handler): void
    {
        $this->commands[$name] = [
            'description' => $description,
            'handler' => $handler,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function execute(string $name, array $args): void
    {
        if (!isset($this->commands[$name])) {
            error("Unknown command: {$name}");
            exit(1);
        }
        ($this->commands[$name]['handler'])($args);
    }

    public function getAll(): array
    {
        return $this->commands;
    }
}

$registry = new CommandRegistry();

// Register core commands
$registry->register('migrate', 'Run pending migrations', function($args) use ($pdo, $config) {
    output('');
    output('Running migrations...');

    $migrator = new Migrator($pdo, $config);
    $migrator->addPluginPaths(BB_ROOT . '/plugins');
    $migrator->ensureMigrationsTable();

    $pending = $migrator->getPending();
    if (empty($pending)) {
        output('  No pending migrations.');
        return;
    }

    $batch = $migrator->getNextBatch();
    $ran = [];

    foreach ($pending as $migration) {
        output("  → {$migration['name']}");
        try {
            $migrator->runUp($migration, $batch);
            $ran[] = $migration['name'];
            success("{$migration['name']} migrated.");
        } catch (Throwable $e) {
            error("{$migration['name']} failed: " . $e->getMessage());
            error("  Rolled back this batch.");
            foreach (array_reverse($ran) as $name) {
                try {
                    $migrator->rollbackByName($name);
                } catch (Throwable $rollbackError) {
                    error("  Rollback of {$name} also failed: " . $rollbackError->getMessage());
                }
            }
            exit(1);
        }
    }

    output('');
    output('All migrations completed.');
});

$registry->register('migrate:rollback', 'Rollback last batch of migrations', function($args) use ($pdo, $config) {
    output('');
    output('Rolling back last batch...');

    $migrator = new Migrator($pdo, $config);
    $migrator->addPluginPaths(BB_ROOT . '/plugins');
    $migrator->ensureMigrationsTable();

    $lastBatch = $migrator->getLastBatch();
    if (empty($lastBatch)) {
        output('  No migrations to rollback.');
        return;
    }

    $migrations = $migrator->getMigrationsByBatch($lastBatch);
    if (empty($migrations)) {
        output('  No migrations found in last batch.');
        return;
    }

    foreach (array_reverse($migrations) as $migration) {
        output("  ← {$migration['name']}");
        try {
            $migrator->runDown($migration);
            success("{$migration['name']} rolled back.");
        } catch (Throwable $e) {
            error("{$migration['name']} rollback failed: " . $e->getMessage());
            exit(1);
        }
    }

    output('');
    output('Rollback completed.');
});

$registry->register('migrate:status', 'Show migration status', function($args) use ($pdo, $config) {
    output('');
    output('Migration status:');

    $migrator = new Migrator($pdo, $config);
    $migrator->addPluginPaths(BB_ROOT . '/plugins');
    $migrator->ensureMigrationsTable();

    $all = $migrator->getAllMigrations();
    $ran = $migrator->getRanMigrations();

    if (empty($all)) {
        output('  No migration files found.');
        return;
    }

    $rows = [];
    foreach ($all as $migration) {
        $status = in_array($migration['name'], $ran, true) ? 'Ran' : 'Pending';
        $batch = $migrator->getBatchFor($migration['name']);
        $source = $migration['source'] ?? 'core';
        $rows[] = [$migration['name'], $source, $status, $batch ?? '-'];
    }

    table(['Migration', 'Source', 'Status', 'Batch'], $rows);
});

$registry->register('plugin:list', 'List all plugins with status', function($args) use ($config) {
    output('');
    output('Plugins:');

    $pm = new PluginManager(
        BB_ROOT . '/plugins',
        $config['plugin_manifest'] ?? BB_ROOT . '/data/plugins.json'
    );
    $plugins = $pm->getAll();

    if (empty($plugins)) {
        output('  No plugins found.');
        return;
    }

    $rows = [];
    foreach ($plugins as $key => $plugin) {
        $status = !empty($plugin['enabled']) ? 'Enabled' : 'Disabled';
        $version = $plugin['version'] ?? '1.0.0';
        $rows[] = [$plugin['name'], $version, $status];
    }

    table(['Plugin', 'Version', 'Status'], $rows);
});

$registry->register('plugin:enable', 'Enable a plugin', function($args) use ($config) {
    $name = $args[0] ?? null;
    if (!$name) {
        error('Usage: php bb.php plugin:enable <name>');
        exit(1);
    }

    $pm = new PluginManager(
        BB_ROOT . '/plugins',
        $config['plugin_manifest'] ?? BB_ROOT . '/data/plugins.json'
    );

    if ($pm->enable($name)) {
        success("Plugin '{$name}' enabled.");
    } else {
        error("Plugin '{$name}' not found.");
        exit(1);
    }
});

$registry->register('plugin:disable', 'Disable a plugin', function($args) use ($config) {
    $name = $args[0] ?? null;
    if (!$name) {
        error('Usage: php bb.php plugin:disable <name>');
        exit(1);
    }

    $pm = new PluginManager(
        BB_ROOT . '/plugins',
        $config['plugin_manifest'] ?? BB_ROOT . '/data/plugins.json'
    );

    if ($pm->disable($name)) {
        success("Plugin '{$name}' disabled.");
    } else {
        error("Plugin '{$name}' not found.");
        exit(1);
    }
});

$registry->register('cache:flush', 'Flush all caches', function($args) {
    output('');
    output('Flushing caches...');

    $cacheDir = BB_ROOT . '/data/cache';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }
        success("Removed {$count} cache files.");
    } else {
        output('  No cache directory found.');
    }

    $sessionDir = BB_ROOT . '/data/sessions';
    if (is_dir($sessionDir)) {
        $files = glob($sessionDir . '/sess_*');
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }
        success("Cleared {$count} session files.");
    }

    output('');
    output('Cache flushed.');
});

$registry->register('doctor', 'Run system diagnostics', function($args) use ($pdo, $config) {
    output('');
    output('=== bulletinbored diagnostics ===');
    output('');

    $issues = 0;
    $warnings = 0;

    // PHP version
    $phpVersion = PHP_VERSION;
    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    if ($phpOk) {
        success("PHP version: {$phpVersion}");
    } else {
        fail("PHP version: {$phpVersion} (requires 8.1+)");
        $issues++;
    }

    // Required extensions
    $required = ['pdo', 'pdo_sqlite', 'json', 'mbstring', 'fileinfo'];
    foreach ($required as $ext) {
        if (extension_loaded($ext)) {
            success("Extension: {$ext}");
        } else {
            fail("Extension: {$ext} (missing)");
            $issues++;
        }
    }

    // Optional extensions
    $optional = ['pdo_mysql', 'zip', 'curl', 'gd'];
    foreach ($optional as $ext) {
        if (extension_loaded($ext)) {
            success("Extension: {$ext} (optional)");
        } else {
            warning("Extension: {$ext} (optional, not installed)");
            $warnings++;
        }
    }

    // Directory permissions
    $directories = [
        'data' => BB_ROOT . '/data',
        'data/cache' => BB_ROOT . '/data/cache',
        'data/sessions' => BB_ROOT . '/data/sessions',
        'data/logs' => BB_ROOT . '/data/logs',
        'data/uploads' => BB_ROOT . '/data/uploads',
        'plugins' => BB_ROOT . '/plugins',
        'themes' => BB_ROOT . '/themes',
        'migrations' => BB_ROOT . '/migrations',
    ];

    output('');
    output('Directory permissions:');
    foreach ($directories as $name => $path) {
        if (!is_dir($path)) {
            warning("  {$name}: does not exist");
            $warnings++;
        } elseif (!is_writable($path)) {
            fail("  {$name}: not writable");
            $issues++;
        } else {
            success("  {$name}: writable");
        }
    }

    // Database connection
    output('');
    output('Database:');
    try {
        $driver = $config['db_driver'] ?? 'sqlite';
        if ($driver === 'mysql') {
            success("  Driver: mysql (connected)");
        } else {
            $dbPath = $config['db_path'] ?? BB_ROOT . '/data/database.sqlite';
            if (file_exists($dbPath)) {
                success("  Driver: sqlite (database exists)");
            } else {
                warning("  Driver: sqlite (database file not found, will be created)");
                $warnings++;
            }
        }

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        success("  Tables: " . count($tables));
    } catch (Throwable $e) {
        fail("  Database error: " . $e->getMessage());
        $issues++;
    }

    // Config
    output('');
    output('Configuration:');
    if (file_exists(BB_ROOT . '/config.json')) {
        success("  config.json exists");
    } elseif (file_exists(BB_ROOT . '/config.php')) {
        success("  config.json exists (legacy)");
    } else {
        fail("  No config file found");
        $issues++;
    }

    // Security checks
    output('');
    output('Security:');
    if (ini_get('display_errors')) {
        warning("  display_errors is ON (should be OFF in production)");
        $warnings++;
    } else {
        success("  display_errors is OFF");
    }

    if (ini_get('expose_php')) {
        warning("  expose_php is ON (reveals PHP version)");
        $warnings++;
    } else {
        success("  expose_php is OFF");
    }

    // Summary
    output('');
    if ($issues === 0 && $warnings === 0) {
        output('✓ All checks passed!');
    } elseif ($issues === 0) {
        output("✓ No critical issues ({$warnings} warning(s))");
    } else {
        output("✗ Found {$issues} issue(s) and {$warnings} warning(s)");
    }
    output('');

    if ($issues > 0) {
        exit(1);
    }
});

$registry->register('help', 'Show this help', function($args) use ($registry) {
    output('');
    output('bb.php — bulletinbored CLI');
    output('');
    output('Usage: php bb.php <command> [options]');
    output('');
    output('Commands:');

    foreach ($registry->getAll() as $name => $cmd) {
        $desc = $cmd['description'];
        output("  {$name}" . str_repeat(' ', 22 - strlen($name)) . $desc);
    }

    output('');
});

// Let plugins register their own commands
$pm = new PluginManager(
    BB_ROOT . '/plugins',
    $config['plugin_manifest'] ?? BB_ROOT . '/data/plugins.json'
);
$pm->loadEnabled();
$pm->runHook('cli', $registry);

// Dispatch
$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

if ($registry->has($command)) {
    $registry->execute($command, $args);
} else {
    error("Unknown command: {$command}");
    output('Run "php bb.php help" for available commands.');
    exit(1);
}
