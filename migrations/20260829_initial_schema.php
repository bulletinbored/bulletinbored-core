<?php

/**
 * Migration: 20260829_initial_schema.php
 *
 * Creates the initial bulletinbored database schema.
 * This migration is idempotent — all CREATE statements use IF NOT EXISTS.
 *
 * Migration contract:
 *   - up(PDO $pdo): apply the schema change
 *   - down(PDO $pdo): reverse the schema change (if reversible === false, down is skipped)
 *   - irreversible(): return true to mark this as a one-way migration
 */
class InitialSchema
{
    /**
     * This migration is reversible — down() drops all tables.
     */
    public function irreversible(): bool
    {
        return false;
    }
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $this->upMysql($pdo);
        } else {
            $this->upSqlite($pdo);
        }

        $this->seedDefaults($pdo);
    }

    public function down(PDO $pdo): void
    {
        $tables = [
            'password_resets',
            'email_verifications',
            'roles',
            'private_messages',
            'notifications',
            'thread_watchers',
            'uploads',
            'posts',
            'threads',
            'categories',
            'users',
        ];

        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    private function upMysql(PDO $pdo): void
    {
        $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
                avatar VARCHAR(255),
                status VARCHAR(50) DEFAULT 'active',
                suspension_time INTEGER DEFAULT 0,
                email_verified INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                position INT DEFAULT 0,
                allowed_roles TEXT DEFAULT NULL
            ) $charset
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS threads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT,
                user_id INT,
                title TEXT,
                content TEXT,
                status VARCHAR(50) DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                views INTEGER DEFAULT 0
            ) $charset
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT,
                user_id INT,
                content TEXT,
                status VARCHAR(50) DEFAULT 'visible',
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
                token_hash VARCHAR(64) DEFAULT NULL,
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
                token_hash VARCHAR(64) DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                used INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset
        ");
    }

    private function upSqlite(PDO $pdo): void
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
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                position INTEGER DEFAULT 0,
                allowed_roles TEXT DEFAULT NULL
            )
        ");

        $pdo->exec("
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
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                user_id INTEGER,
                content TEXT NOT NULL,
                status TEXT DEFAULT 'visible',
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
                token_hash TEXT DEFAULT NULL,
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
                token_hash TEXT DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                used INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    private function seedDefaults(PDO $pdo): void
    {
        // Default roles — permissions use "resource.action" notation.
        // Ownership is expressed via AuthZ::canOnOwned() which checks for
        // "resource.action_own" when the user is the resource owner.
        $roles = [
            ['admin', json_encode([
                'admin.access',
                'threads.approve', 'threads.delete', 'threads.edit', 'threads.lock',
                'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy',
                'posts.delete', 'posts.edit',
                'users.ban', 'users.create', 'users.delete', 'users.edit',
                'roles.manage', 'categories.manage', 'settings.manage',
                'plugins.manage', 'themes.manage', 'langs.manage',
            ])],
            ['moderator', json_encode([
                'threads.approve', 'threads.delete', 'threads.edit', 'threads.lock',
                'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy',
                'posts.delete', 'posts.edit',
            ])],
            ['user', json_encode([
                'threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own',
            ])],
        ];

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertIgnore = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

        foreach ($roles as $role) {
            $stmt = $pdo->prepare("{$insertIgnore} INTO roles (name, permissions) VALUES (?, ?)");
            $stmt->execute($role);
        }

        // Default category
        $stmt = $pdo->prepare("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')");
        $stmt->execute();
    }
}
