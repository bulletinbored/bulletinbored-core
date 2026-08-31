<?php

/**
 * Migration: 20260831_upgrade_05x.php
 *
 * Upgrades a 0.5.x database to 0.6.0 schema.
 * Adds new columns and tables that didn't exist in 0.5.x.
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

        // Add email_verified column to users if missing
        if (!$this->columnExists($pdo, 'users', 'email_verified')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0");
        }

        // Add views column to threads if missing
        if (!$this->columnExists($pdo, 'threads', 'views')) {
            $pdo->exec("ALTER TABLE threads ADD COLUMN views INTEGER DEFAULT 0");
        }

        // Add allowed_roles column to categories if missing
        if (!$this->columnExists($pdo, 'categories', 'allowed_roles')) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN allowed_roles TEXT DEFAULT NULL");
        }

        // Create new tables (all use IF NOT EXISTS)
        if ($driver === 'mysql') {
            $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(50) NOT NULL UNIQUE,
                    permissions TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) $charset
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS email_verifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token TEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) $charset
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token TEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) $charset
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS thread_watchers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    thread_id INT NOT NULL,
                    user_id INT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_watch (thread_id, user_id)
                ) $charset
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type VARCHAR(50) DEFAULT 'info',
                    title TEXT NOT NULL,
                    message TEXT,
                    link TEXT,
                    is_read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) $charset
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS private_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_id INT NOT NULL,
                    recipient_id INT NOT NULL,
                    subject TEXT,
                    content TEXT NOT NULL,
                    is_read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) $charset
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS uploads (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    thread_id INT,
                    post_id INT,
                    user_id INT,
                    filename VARCHAR(255),
                    original_name VARCHAR(255),
                    size INT,
                    mime_type VARCHAR(100),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) $charset
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    permissions TEXT DEFAULT '[]',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS email_verifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token TEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token TEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS thread_watchers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(thread_id, user_id)
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    type VARCHAR(50) DEFAULT 'info',
                    title TEXT NOT NULL,
                    message TEXT,
                    link TEXT,
                    is_read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS private_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sender_id INTEGER NOT NULL,
                    recipient_id INTEGER NOT NULL,
                    subject TEXT DEFAULT '',
                    content TEXT NOT NULL,
                    is_read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS uploads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id INTEGER,
                    post_id INTEGER,
                    user_id INTEGER,
                    filename TEXT,
                    original_name TEXT,
                    size INTEGER,
                    mime_type TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }

        // Seed default roles if not present
        $count = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
        if ((int)$count === 0) {
            $roles = [
                ['admin', json_encode(['admin.access', 'threads.approve', 'threads.delete', 'threads.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'posts.delete', 'posts.edit', 'users.ban', 'users.create', 'users.delete', 'users.edit', 'roles.manage', 'categories.manage', 'settings.manage', 'plugins.manage', 'themes.manage', 'langs.manage'])],
                ['moderator', json_encode(['threads.approve', 'threads.delete', 'threads.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'posts.delete', 'posts.edit'])],
                ['user', json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own'])],
            ];
            $insertIgnore = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
            foreach ($roles as $role) {
                $stmt = $pdo->prepare("{$insertIgnore} INTO roles (name, permissions) VALUES (?, ?)");
                $stmt->execute($role);
            }
        }

        // Seed default category if none exist
        $count = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        if ((int)$count === 0) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')");
            $stmt->execute();
        }
    }

    public function down(PDO $pdo): void
    {
        // Reverse the upgrade — remove new columns and tables
        // Note: SQLite doesn't support DROP COLUMN easily, so this is best-effort
        $tables = ['uploads', 'private_messages', 'notifications', 'thread_watchers', 'password_resets', 'email_verifications', 'roles'];
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}

// Helper removed — columnExists is now a private method on the class.
