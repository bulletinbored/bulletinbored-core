<?php
// SQLite Database Layer

class SQLiteDatabase {
    private $pdo;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->connect();
    }

    private function connect() {
        $dbPath = $this->config['db_path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function autoMigrate() {
        $prefix = $this->config['db_table_prefix'];

        $schema = [
            "CREATE TABLE IF NOT EXISTS {$prefix}users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                role TEXT DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "CREATE TABLE IF NOT EXISTS {$prefix}categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                position INTEGER DEFAULT 0
            )",

            "CREATE TABLE IF NOT EXISTS {$prefix}threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                user_id INTEGER,
                title TEXT NOT NULL,
                content TEXT,
                status TEXT DEFAULT 'open',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES {$prefix}categories(id),
                FOREIGN KEY (user_id) REFERENCES {$prefix}users(id)
            )",

            "CREATE TABLE IF NOT EXISTS {$prefix}posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                user_id INTEGER,
                content TEXT NOT NULL,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (thread_id) REFERENCES {$prefix}threads(id),
                FOREIGN KEY (user_id) REFERENCES {$prefix}users(id)
            )",

            "CREATE TABLE IF NOT EXISTS {$prefix}moderation_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                moderator_id INTEGER,
                action TEXT NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NOT NULL,
                reason TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($schema as $sql) {
            $this->pdo->exec($sql);
        }

        // Create admin user if not exists
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$prefix}users WHERE role = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $this->pdo->prepare("INSERT INTO {$prefix}users (username, password, email, role) VALUES (?, ?, ?, 'admin')")
                ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin@forum.local']);
        }
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function fetch($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}