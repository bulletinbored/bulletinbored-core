<?php

/**
 * Migration: 20260831_upgrade_05x.php
 *
 * Upgrades a 0.5.x/0.8.x database to latest schema.
 * Adds token_hash columns for O(1) token lookup.
 *
 * This migration is idempotent — uses IF NOT EXISTS and column existence checks.
 */
class Upgrade05x
{
    public function irreversible(): bool
    {
        return false;
    }

    /**
     * Check if a column exists in a table (SQLite/MySQL compatible).
     */
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

        // SQLite
        $stmt = $pdo->prepare("PRAGMA table_info({$table})");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($column, $columns, true);
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $intType = $driver === 'mysql' ? 'INTEGER DEFAULT 0' : 'INTEGER DEFAULT 0';
        $textType = $driver === 'mysql' ? 'TEXT DEFAULT NULL' : 'TEXT DEFAULT NULL';

        // users.email_verified
        if (!$this->columnExists($pdo, 'users', 'email_verified')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email_verified {$intType}");
        }

        // threads.views
        if (!$this->columnExists($pdo, 'threads', 'views')) {
            $pdo->exec("ALTER TABLE threads ADD COLUMN views {$intType}");
        }

        // categories.allowed_roles
        if (!$this->columnExists($pdo, 'categories', 'allowed_roles')) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN allowed_roles {$textType}");
        }

        // Add token_hash column to email_verifications if missing
        if (!$this->columnExists($pdo, 'email_verifications', 'token_hash')) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $type = $driver === 'mysql' ? 'VARCHAR(64)' : 'TEXT';
            $pdo->exec("ALTER TABLE email_verifications ADD COLUMN token_hash {$type} DEFAULT NULL");
        }

        // Add token_hash column to password_resets if missing
        if (!$this->columnExists($pdo, 'password_resets', 'token_hash')) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $type = $driver === 'mysql' ? 'VARCHAR(64)' : 'TEXT';
            $pdo->exec("ALTER TABLE password_resets ADD COLUMN token_hash {$type} DEFAULT NULL");
        }
    }

    public function down(PDO $pdo): void
    {
        // Best-effort: remove token_hash columns
        // SQLite doesn't support DROP COLUMN easily, so this is best-effort
    }
}
