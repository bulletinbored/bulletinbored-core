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
 *   help                 Show this help
 */

// Bootstrap: load config and DB connection (minimal, no sessions/headers)
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

// Connect to database
$dbDriver = $config['db_driver'] ?? 'sqlite';
if ($dbDriver === 'mysql') {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new BbPdo($dsn, $config['db_user'], $config['db_pass']);
} else {
    $dbPath = $config['db_path'] ?? BB_ROOT . '/data/database.sqlite';
    $pdo = new BbPdo('sqlite:' . $dbPath);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Helpers
function output(string $msg): void
{
    echo $msg . "\n";
}

function error(string $msg): void
{
    fwrite(STDERR, "Error: {$msg}\n");
}

function success(string $msg): void
{
    output("  ✓ {$msg}");
}

function warning(string $msg): void
{
    output("  ! {$msg}");
}

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

// Command dispatcher
$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

switch ($command) {
    case 'migrate':
        cmd_migrate($pdo, $config);
        break;

    case 'migrate:rollback':
        cmd_migrate_rollback($pdo, $config);
        break;

    case 'migrate:status':
        cmd_migrate_status($pdo, $config);
        break;

    case 'plugin:list':
        cmd_plugin_list($config);
        break;

    case 'plugin:enable':
        cmd_plugin_enable($args, $config);
        break;

    case 'plugin:disable':
        cmd_plugin_disable($args, $config);
        break;

    case 'cache:flush':
        cmd_cache_flush($config);
        break;

    case 'help':
    default:
        cmd_help();
        break;
}

// --- Commands ---

function cmd_help(): void
{
    output('');
    output('bb.php — bulletinbored CLI');
    output('');
    output('Usage: php bb.php <command> [options]');
    output('');
    output('Commands:');
    output('  migrate              Run pending migrations');
    output('  migrate:rollback     Rollback last batch of migrations');
    output('  migrate:status       Show migration status');
    output('  plugin:list          List all plugins with status');
    output('  plugin:enable <name> Enable a plugin');
    output('  plugin:disable <name> Disable a plugin');
    output('  cache:flush          Flush all caches');
    output('  help                 Show this help');
    output('');
}

function cmd_migrate(PDO $pdo, array $config): void
{
    output('');
    output('Running migrations...');

    $migrator = new Migrator($pdo, $config);
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
            // Rollback already-run migrations in this batch
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
}

function cmd_migrate_rollback(PDO $pdo, array $config): void
{
    output('');
    output('Rolling back last batch...');

    $migrator = new Migrator($pdo, $config);
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
}

function cmd_migrate_status(PDO $pdo, array $config): void
{
    output('');
    output('Migration status:');

    $migrator = new Migrator($pdo, $config);
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
        $rows[] = [$migration['name'], $status, $batch ?? '-'];
    }

    table(['Migration', 'Status', 'Batch'], $rows);
}

function cmd_plugin_list(array $config): void
{
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
}

function cmd_plugin_enable(array $args, array $config): void
{
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
}

function cmd_plugin_disable(array $args, array $config): void
{
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
}

function cmd_cache_flush(array $config): void
{
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

    // Also clear sessions if requested
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
}
