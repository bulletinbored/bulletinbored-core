<?php
/**
 * setup.php — ensures required directories exist and initialises the database.
 *
 * Returns the active BbPdo/PDO connection in $pdo (global). Mirrors the
 * bootstrap behaviour that used to live inline at the top of index.php.
 */

// Ensure directories exist
foreach (['data', 'plugins', 'uploads', 'uploads/avatars'] as $d) {
    $dir = __DIR__ . '/../' . $d;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$dbPath = $config['db_path'];
$dbDriver = $config['db_driver'] ?? 'sqlite';

// INSERT IGNORE is MySQL-only; SQLite uses INSERT OR IGNORE.
$insertIgnoreSql = $dbDriver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

// Database initialization
require_once __DIR__ . '/../lib/BbPdo.php';
if ($dbDriver === 'mysql') {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new BbPdo($dsn, $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // MySQL tables
    $tables = [
        "users" => "id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255), role VARCHAR(50) DEFAULT 'user', avatar VARCHAR(255), status VARCHAR(50) DEFAULT 'active', email_verified INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "categories" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, position INT DEFAULT 0, allowed_roles TEXT DEFAULT NULL",
        "threads" => "id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT, title TEXT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "posts" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "uploads" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, post_id INT, user_id INT, filename VARCHAR(255), original_name VARCHAR(255), size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "thread_watchers" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_watch (thread_id, user_id)",
        "notifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) DEFAULT 'info', title TEXT NOT NULL, message TEXT, link TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                "private_messages" => "id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, subject TEXT, content TEXT NOT NULL, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                "roles" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, permissions TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "email_verifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token TEXT NOT NULL, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($tables as $name => $schema) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($schema)");
    }

    // Create admin user if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')")
            ->execute([$config['admin_user'], password_hash($config['admin_pass'], PASSWORD_DEFAULT)]);
    }

    // Create default roles if not exists
    $defaultRoles = [
        ['admin', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads', 'can_ban_users', 'can_manage_roles'])],
        ['moderator', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads'])],
        ['user', json_encode(['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts'])],
    ];
    foreach ($defaultRoles as $role) {
        $pdo->prepare("$insertIgnoreSql INTO roles (name, permissions) VALUES (?, ?)")->execute($role);
    }

    // Create default category (idempotent: only if it does not already exist)
    $pdo->prepare("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')")->execute();
} else {
    // SQLite handling
    if (!file_exists($dbPath)) {
        // New database - create all tables
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
 CREATE TABLE users (
                 id INTEGER PRIMARY KEY AUTOINCREMENT,
                 username TEXT UNIQUE NOT NULL,
                 password TEXT NOT NULL,
                 email TEXT,
                 role TEXT DEFAULT 'user',
                 avatar TEXT,
                 status TEXT DEFAULT 'active',
                 email_verified INTEGER DEFAULT 0,
                 created_at DATETIME DEFAULT CURRENT_TIMESTAMP
             );
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                position INTEGER DEFAULT 0,
                allowed_roles TEXT DEFAULT NULL
            );
            CREATE TABLE threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                user_id INTEGER,
                title TEXT NOT NULL,
                content TEXT,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                user_id INTEGER,
                content TEXT NOT NULL,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE uploads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                post_id INTEGER,
                user_id INTEGER,
                filename TEXT,
                original_name TEXT,
                size INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE thread_watchers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(thread_id, user_id)
            );
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                title TEXT NOT NULL,
                message TEXT,
                link TEXT,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE private_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                subject TEXT DEFAULT '',
                content TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                permissions TEXT DEFAULT '[]',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                permissions TEXT DEFAULT '[]',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Insert default data
        $pdo->exec("INSERT INTO users (username, password, role) VALUES ('admin', '" . password_hash($config['admin_pass'], PASSWORD_DEFAULT) . "', 'admin')");
        $pdo->exec("INSERT INTO categories (name, description, position) SELECT 'General', 'General discussion', 1 FROM dual WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'General')");
    } else {
        // Existing database - just connect
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Safe column addition for threads table
        try {
            $cols = $pdo->query("PRAGMA table_info(threads)")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category_id', $cols)) {
                $pdo->exec("ALTER TABLE threads ADD COLUMN category_id INTEGER");
            }
            if (!in_array('updated_at', $cols)) {
                $pdo->exec("ALTER TABLE threads ADD COLUMN updated_at DATETIME");
            }
        } catch (PDOException $e) {
            // Ignore errors if columns already exist
        }

        // Safe column addition for users table (email, created_at, avatar)
        try {
            $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('email', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
            }
            if (!in_array('created_at', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            if (!in_array('avatar', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN avatar TEXT");
            }
            if (!in_array('email_verified', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0");
            }
            $pdo->exec("UPDATE users SET email_verified = 1");
        } catch (PDOException $e) {}

        // Safe column addition for posts table
        try {
            $cols = $pdo->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN);
            // Add any missing columns for posts if needed
        } catch (PDOException $e) {}

        // Create password_resets table if not exists
        try {
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
        } catch (PDOException $e) {}

        // Create email_verifications table if not exists
        try {
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
        } catch (PDOException $e) {}

        // Create thread_watchers table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS thread_watchers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(thread_id, user_id)
                )
            ");
        } catch (PDOException $e) {}

        // Create notifications table if not exists
        try {
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
        } catch (PDOException $e) {}

        // Create private_messages table if not exists
        try {
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
        } catch (PDOException $e) {}

        // Create roles table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    permissions TEXT DEFAULT '[]',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {}

        // Insert default roles if not exists
        try {
            $defaultRoles = [
                ['admin', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads', 'can_ban_users', 'can_manage_roles'])],
                ['moderator', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads'])],
                ['user', json_encode(['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts'])],
            ];
            foreach ($defaultRoles as $role) {
        $pdo->prepare("$insertIgnoreSql INTO roles (name, permissions) VALUES (?, ?)")->execute($role);
            }
        } catch (PDOException $e) {}
    }
}

// Safe column addition for categories table
try {
    $cols = [];
    if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
        foreach ($pdo->query("SHOW COLUMNS FROM categories") as $c) {
            $cols[] = $c['Field'];
        }
    } else {
        $cols = $pdo->query("PRAGMA table_info(categories)")->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!in_array('allowed_roles', $cols)) {
        $pdo->exec("ALTER TABLE categories ADD COLUMN allowed_roles TEXT DEFAULT NULL");
    }
} catch (PDOException $e) {}

// Handle legacy database - add email + created_at if missing (SQLite migration for existing DB)
try {
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
    }
    if (!in_array('created_at', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('avatar', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar TEXT");
    }
    if (!in_array('status', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'active'");
    }
} catch (PDOException $e) {}

// Thread view counter (works on both drivers, ignored when already present)
try {
    $threadCols = [];
    if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
        foreach ($pdo->query("SHOW COLUMNS FROM threads") as $c) {
            $threadCols[] = $c['Field'];
        }
    } else {
        $threadCols = $pdo->query("PRAGMA table_info(threads)")->fetchAll(PDO::FETCH_COLUMN, 1);
    }
    if (!in_array('views', $threadCols, true)) {
        $pdo->exec("ALTER TABLE threads ADD COLUMN views INTEGER DEFAULT 0");
    }
} catch (PDOException $e) {}
