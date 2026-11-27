<?php

/**
 * Installer schema bootstrap.
 *
 * Kept in its own file so the installer and the test suite exercise the same
 * code. The SQLite branch used to reference bb_default_role_permissions()
 * without loading DefaultRoles.php; requiring it here makes both drivers safe.
 */
function bb_install_schema(
    PDO $pdo,
    string $driver,
    string $adminUser,
    string $adminPass,
    string $adminEmail
): void {
    require_once __DIR__ . '/DefaultRoles.php';

    $driver = $driver === 'mysql' ? 'mysql' : 'sqlite';
    $insertIgnoreSql = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    if ($driver === 'mysql') {
        $tables = [
            "users" => "id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(191) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255), role VARCHAR(50) DEFAULT 'user', avatar VARCHAR(255), status VARCHAR(50) DEFAULT 'active', suspension_time INTEGER DEFAULT 0, email_verified INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "categories" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) NOT NULL UNIQUE, description TEXT, position INT DEFAULT 0, allowed_roles TEXT",
            "threads" => "id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT, title TEXT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, views INT DEFAULT 0",
            "posts" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "uploads" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, post_id INT, user_id INT, filename VARCHAR(255), original_name VARCHAR(255), size INT, mime_type VARCHAR(100), created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "thread_watchers" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_watch (thread_id, user_id)",
            "notifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) DEFAULT 'info', title TEXT NOT NULL, message TEXT, link TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "private_messages" => "id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, subject TEXT, content TEXT NOT NULL, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "roles" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, permissions TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "email_verifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token TEXT NOT NULL, token_hash VARCHAR(64) DEFAULT NULL, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            "password_resets" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token TEXT NOT NULL, token_hash VARCHAR(64) DEFAULT NULL, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        ];

        // ENGINE=InnoDB explicitly: hosts often default to MyISAM, which cannot
        // hold foreign keys and caps indexes at 1000 bytes.
        $charset = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        foreach ($tables as $name => $schema) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($schema)$charset");
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
        if ($stmt->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO users (username, password, role, email, email_verified) VALUES (?, ?, 'admin', ?, 1)")
                ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail]);
        }

        // Canonical "resource.action" permissions, shared with the migrations:
        // the old "can_*" names made every permission check fail for non-admin
        // users on installations created here.
        foreach (bb_default_role_permissions() as $roleName => $permissions) {
            $pdo->prepare("$insertIgnoreSql INTO roles (name, permissions) VALUES (?, ?)")
                ->execute([$roleName, json_encode($permissions)]);
        }

        $pdo->prepare("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')")->execute();

        return;
    }

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
        );
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
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
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
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
        );
        CREATE TABLE IF NOT EXISTS thread_watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(thread_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type VARCHAR(50) DEFAULT 'info',
            title TEXT NOT NULL,
            message TEXT,
            link TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS private_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            subject TEXT DEFAULT '',
            content TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO users (username, password, role, email, email_verified) VALUES (?, ?, 'admin', ?, 1)")
            ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail]);
    } else {
        $pdo->prepare("UPDATE users SET email_verified = 1 WHERE role = 'admin'")->execute();
    }

    // Same canonical permissions as the MySQL branch above.
    foreach (bb_default_role_permissions() as $roleName => $permissions) {
        $pdo->prepare("$insertIgnoreSql INTO roles (name, permissions) VALUES (?, ?)")
            ->execute([$roleName, json_encode($permissions)]);
    }

    $pdo->prepare("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')")->execute();
}
