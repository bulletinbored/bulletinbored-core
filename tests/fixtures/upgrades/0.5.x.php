<?php

/**
 * Upgrade fixture: 0.5.x database state.
 *
 * Simulates a bulletinbored 0.5.x installation before the 0.6.0 schema changes.
 * Used by upgrade tests to verify that migrations correctly transform old data.
 *
 * Schema differences from 0.6.0:
 *   - No roles table (roles were hardcoded)
 *   - No email_verifications table
 *   - No password_resets table
 *   - No thread_watchers table
 *   - No notifications table
 *   - No private_messages table
 *   - No uploads table
 *   - users table has no email_verified column
 *   - threads table has no views column
 *   - categories table has no allowed_roles column
 */
function fixture_05x_schema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $pdo->exec("
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
                avatar VARCHAR(255),
                status VARCHAR(50) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                position INT DEFAULT 0
            )
        ");
        $pdo->exec("
            CREATE TABLE threads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT,
                user_id INT,
                title TEXT,
                content TEXT,
                status VARCHAR(50) DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT,
                user_id INT,
                content TEXT,
                status VARCHAR(50) DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                email TEXT,
                role TEXT DEFAULT 'user',
                avatar TEXT,
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                position INTEGER DEFAULT 0
            )
        ");
        $pdo->exec("
            CREATE TABLE threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                user_id INTEGER,
                title TEXT NOT NULL,
                content TEXT,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                user_id INTEGER,
                content TEXT NOT NULL,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
}

/**
 * Seed realistic 0.5.x data into the fixture schema.
 */
function fixture_05x_seed(PDO $pdo): void
{
    // Users
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['admin', password_hash('admin123456', PASSWORD_DEFAULT), 'admin@test.com', 'admin', 'active']);
    $stmt->execute(['moderator', password_hash('mod123456789', PASSWORD_DEFAULT), 'mod@test.com', 'moderator', 'active']);
    $stmt->execute(['alice', password_hash('alice12345678', PASSWORD_DEFAULT), 'alice@test.com', 'user', 'active']);
    $stmt->execute(['bob', password_hash('bob1234567890', PASSWORD_DEFAULT), 'bob@test.com', 'user', 'active']);
    $stmt->execute(['spammer', password_hash('spam123456789', PASSWORD_DEFAULT), 'spam@test.com', 'user', 'banned']);

    // Categories
    $stmt = $pdo->prepare("INSERT INTO categories (name, description, position) VALUES (?, ?, ?)");
    $stmt->execute(['General', 'General discussion', 1]);
    $stmt->execute(['Tech', 'Technology talk', 2]);
    $stmt->execute(['Off-Topic', 'Everything else', 3]);

    // Threads
    $stmt = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([1, 3, 'Welcome to the forum', 'First post content', 'visible']);
    $stmt->execute([1, 4, 'Hello world', 'Second thread', 'visible']);
    $stmt->execute([2, 3, 'PHP 8.1 released', 'Discussion about new features', 'visible']);
    $stmt->execute([3, 4, 'Random thoughts', 'Off-topic chat', 'sticky']);

    // Posts
    $stmt = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([1, 4, 'Great to be here!', 'visible']);
    $stmt->execute([1, 3, 'Welcome!', 'visible']);
    $stmt->execute([2, 3, 'Hi everyone', 'visible']);
    $stmt->execute([3, 4, 'Looks promising', 'visible']);
}

/**
 * Verify that the upgrade from 0.5.x produced the expected 0.6.0 schema.
 * Returns array of error messages (empty = success).
 */
function fixture_05x_verify_upgrade(PDO $pdo): array
{
    $errors = [];

    // New tables should exist
    $requiredTables = ['roles', 'email_verifications', 'password_resets', 'thread_watchers', 'notifications', 'private_messages', 'uploads'];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        if (!$stmt->fetch()) {
            $errors[] = "Missing table after upgrade: {$table}";
        }
    }

    // users.email_verified should exist
    $stmt = $pdo->query("PRAGMA table_info(users)");
    $columns = array_column($stmt->fetchAll(), 'name');
    if (!in_array('email_verified', $columns, true)) {
        $errors[] = "Missing column: users.email_verified";
    }

    // threads.views should exist
    $stmt = $pdo->query("PRAGMA table_info(threads)");
    $columns = array_column($stmt->fetchAll(), 'name');
    if (!in_array('views', $columns, true)) {
        $errors[] = "Missing column: threads.views";
    }

    // categories.allowed_roles should exist
    $stmt = $pdo->query("PRAGMA table_info(categories)");
    $columns = array_column($stmt->fetchAll(), 'name');
    if (!in_array('allowed_roles', $columns, true)) {
        $errors[] = "Missing column: categories.allowed_roles";
    }

    // Data should be preserved
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ((int)$count < 5) {
        $errors[] = "User data lost during upgrade (expected >= 5, got {$count})";
    }

    $count = $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn();
    if ((int)$count < 4) {
        $errors[] = "Thread data lost during upgrade (expected >= 4, got {$count})";
    }

    $count = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    if ((int)$count < 4) {
        $errors[] = "Post data lost during upgrade (expected >= 4, got {$count})";
    }

    // Default roles should be seeded
    $count = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    if ((int)$count < 3) {
        $errors[] = "Default roles not seeded (expected >= 3, got {$count})";
    }

    return $errors;
}
