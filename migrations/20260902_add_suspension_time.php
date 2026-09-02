<?php

/**
 * Migration: 20260902_add_suspension_time.php
 *
 * Adds suspension_time column to users table if missing.
 * Fixes schema drift where core update added suspension_time but existing
 * databases were not migrated.
 */
class AddSuspensionTime
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

    public function up(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'users', 'suspension_time')) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $type = $driver === 'mysql' ? 'INTEGER DEFAULT 0' : 'INTEGER DEFAULT 0';
            $pdo->exec("ALTER TABLE users ADD COLUMN suspension_time {$type}");
        }
    }

    public function down(PDO $pdo): void
    {
        // Best-effort: MySQL supports DROP COLUMN, SQLite does not easily.
    }
}
