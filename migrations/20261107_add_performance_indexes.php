<?php

/**
 * Migration: 20261107_add_performance_indexes.php
 *
 * Adds performance indexes on frequently queried columns that are not
 * covered by foreign key indexes. These indexes improve query performance
 * for common filter and sort operations.
 *
 * This migration is idempotent - all CREATE statements use IF NOT EXISTS.
 */
class AddPerformanceIndexes
{
    /**
     * Index definitions for MySQL.
     * [index_name, table, column(s)]
     */
    private function mysqlIndexSpecs(): array
    {
        return [
            ['idx_threads_status', 'threads', 'status'],
            ['idx_threads_created_at', 'threads', 'created_at'],
            ['idx_posts_status', 'posts', 'status'],
            ['idx_posts_created_at', 'posts', 'created_at'],
            ['idx_notifications_is_read', 'notifications', 'is_read'],
        ];
    }

    /**
     * Index definitions for SQLite.
     * [index_name, table, column(s)]
     */
    private function sqliteIndexSpecs(): array
    {
        return [
            ['idx_threads_status', 'threads', 'status'],
            ['idx_threads_created_at', 'threads', 'created_at'],
            ['idx_posts_status', 'posts', 'status'],
            ['idx_posts_created_at', 'posts', 'created_at'],
            ['idx_notifications_is_read', 'notifications', 'is_read'],
        ];
    }

    /**
     * Get existing index names for the current database.
     */
    private function existingIndexNames(PDO $pdo): array
    {
        $names = [];
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $pdo->query(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $names[(string)$name] = true;
            }
        } else {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='index'");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $names[(string)$name] = true;
            }
        }
        return $names;
    }

    public function irreversible(): bool
    {
        return false;
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $existing = $this->existingIndexNames($pdo);

        if ($driver === 'mysql') {
            foreach ($this->mysqlIndexSpecs() as $spec) {
                [$indexName, $table, $columns] = $spec;

                // Idempotent: skip if index already exists
                if (isset($existing[$indexName])) {
                    continue;
                }

                $pdo->exec("CREATE INDEX {$indexName} ON {$table} ({$columns})");
            }
        } else {
            // SQLite
            foreach ($this->sqliteIndexSpecs() as $spec) {
                [$indexName, $table, $columns] = $spec;

                // Idempotent: skip if index already exists
                if (isset($existing[$indexName])) {
                    continue;
                }

                $pdo->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} ({$columns})");
            }
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $existing = $this->existingIndexNames($pdo);

        if ($driver === 'mysql') {
            foreach ($this->mysqlIndexSpecs() as $spec) {
                [$indexName] = $spec;

                // Only drop if it exists
                if (!isset($existing[$indexName])) {
                    continue;
                }

                $pdo->exec("DROP INDEX {$indexName}");
            }
        } else {
            // SQLite
            foreach ($this->sqliteIndexSpecs() as $spec) {
                [$indexName] = $spec;

                // Only drop if it exists
                if (!isset($existing[$indexName])) {
                    continue;
                }

                $pdo->exec("DROP INDEX IF EXISTS {$indexName}");
            }
        }
    }
}