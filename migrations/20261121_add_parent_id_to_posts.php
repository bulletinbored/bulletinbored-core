<?php

/**
 * Migration: 20261121_add_parent_id_to_posts.php
 *
 * Adds posts.parent_id to support threaded (nested) comments. A NULL
 * parent_id means a top-level reply to the opening post; any other value
 * points at the comment being answered.
 *
 * This migration is idempotent: the column and index are only created when
 * they are missing, so a retry after a partially applied run is safe.
 */
class AddParentIdToPosts
{
    public function irreversible(): bool
    {
        return false;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

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
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($column, $columns, true);
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            ");
            $stmt->execute([$table, $index]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare("PRAGMA index_list({$table})");
        $stmt->execute();
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($index, $indexes, true);
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!$this->columnExists($pdo, 'posts', 'parent_id')) {
            $type = $driver === 'mysql' ? 'INT NULL DEFAULT NULL' : 'INTEGER DEFAULT NULL';
            $pdo->exec("ALTER TABLE posts ADD COLUMN parent_id {$type}");
        }

        if ($driver === 'mysql') {
            if (!$this->indexExists($pdo, 'posts', 'idx_posts_parent')) {
                $pdo->exec("ALTER TABLE posts ADD INDEX idx_posts_parent (parent_id)");
            }
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_parent ON posts(parent_id)");
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            if ($this->indexExists($pdo, 'posts', 'idx_posts_parent')) {
                $pdo->exec("ALTER TABLE posts DROP INDEX idx_posts_parent");
            }
            if ($this->columnExists($pdo, 'posts', 'parent_id')) {
                $pdo->exec("ALTER TABLE posts DROP COLUMN parent_id");
            }
            return;
        }

        $pdo->exec("DROP INDEX IF EXISTS idx_posts_parent");
        // SQLite before 3.35 cannot drop a column; leave it in place. The
        // application ignores parent_id when threaded comments are disabled.
    }
}
