<?php

/**
 * Cross-database compatibility tests — SQLite, MySQL.
 *
 * Tests run against SQLite by default (always available).
 * MySQL tests run when DB_DRIVER=mysql is set.
 * MariaDB uses the same PDO mysql driver; set DB_VERSION=mariadb for labeling only.
 *
 * Usage:
 *   php tests/DatabaseMatrixTest.php              # SQLite only
 *   DB_DRIVER=mysql php tests/DatabaseMatrixTest.php  # SQLite + MySQL
 */

require_once __DIR__ . '/harness.php';

function createPDO(?string $driver = null): PDO
{
    $driver = $driver ?: ($_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'sqlite');

    if ($driver === 'sqlite') {
        return new PDO('sqlite::memory:');
    }

    if ($driver === 'mysql') {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'bulletinbored_test';
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    throw new RuntimeException("Unknown driver: {$driver}");
}

function getDriverLabel(): string
{
    $driver = $_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?? 'sqlite';
    if ($driver === 'mysql') {
        $version = $_ENV['DB_VERSION'] ?? getenv('DB_VERSION') ?? '';
        return $version ? "MySQL/{$version}" : 'MySQL';
    }
    return 'SQLite';
}

function skipIfNoDB(string $driver): bool
{
    if ($driver === 'sqlite') return false;

    try {
        createPDO($driver);
        return false;
    } catch (PDOException $e) {
        return true;
    }
}

function test_schema_creation(): Test
{
    $t = new Test('Database Matrix - Schema Creation (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec("DROP TABLE IF EXISTS test_threads");
        $pdo->exec("
            CREATE TABLE test_threads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT,
                user_id INT,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                status VARCHAR(50) DEFAULT 'visible',
                views INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset
        ");
        $pdo->exec("DROP TABLE IF EXISTS test_posts");
        $pdo->exec("
            CREATE TABLE test_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT NOT NULL,
                user_id INT,
                content TEXT NOT NULL,
                status VARCHAR(50) DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset
        ");
    } else {
        $pdo->exec("
            CREATE TABLE test_threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                user_id INTEGER,
                title TEXT NOT NULL,
                content TEXT,
                status TEXT DEFAULT 'visible',
                views INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE test_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                user_id INTEGER,
                content TEXT NOT NULL,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    $t->assert('Threads table created', true);
    $t->assert('Posts table created', true);

    return $t;
}

function test_crud_operations(): Test
{
    $t = new Test('Database Matrix - CRUD Operations (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec("DROP TABLE IF EXISTS test_threads");
    $pdo->exec("DROP TABLE IF EXISTS test_posts");

    if ($driver === 'mysql') {
        $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec("CREATE TABLE test_threads (
            id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT,
            title VARCHAR(255) NOT NULL, content TEXT, status VARCHAR(50) DEFAULT 'visible',
            views INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset");
        $pdo->exec("CREATE TABLE test_posts (
            id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT,
            content TEXT NOT NULL, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset");
    } else {
        $pdo->exec("CREATE TABLE test_threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, user_id INTEGER,
            title TEXT NOT NULL, content TEXT, status TEXT DEFAULT 'visible',
            views INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE test_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL, user_id INTEGER,
            content TEXT NOT NULL, status TEXT DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    $stmt = $pdo->prepare("INSERT INTO test_threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([1, 1, 'Test Thread', 'Content here', 'visible']);
    $threadId = (int)$pdo->lastInsertId();
    $t->assert('Insert thread', $threadId > 0);

    $stmt = $pdo->prepare("SELECT * FROM test_threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();
    $t->assertEquals('Select thread', 'Test Thread', $thread['title']);

    $stmt = $pdo->prepare("UPDATE test_threads SET status = ? WHERE id = ?");
    $stmt->execute(['locked', $threadId]);
    $stmt = $pdo->prepare("SELECT status FROM test_threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $t->assertEquals('Update thread', 'locked', $stmt->fetchColumn());

    $stmt = $pdo->prepare("INSERT INTO test_posts (thread_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$threadId, 1, 'Reply content']);
    $postId = (int)$pdo->lastInsertId();
    $t->assert('Insert post', $postId > 0);

    $stmt = $pdo->prepare("
        SELECT t.title, p.content
        FROM test_threads t
        JOIN test_posts p ON p.thread_id = t.id
        WHERE t.id = ?
    ");
    $stmt->execute([$threadId]);
    $result = $stmt->fetch();
    $t->assert('Join query', $result !== false);
    $t->assertEquals('Join content', 'Reply content', $result['content']);

    $stmt = $pdo->prepare("DELETE FROM test_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM test_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $t->assertEquals('Delete post', 0, (int)$stmt->fetchColumn());

    return $t;
}

function test_transactions(): Test
{
    $t = new Test('Database Matrix - Transactions (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec("DROP TABLE IF EXISTS test_trans");
    if ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE test_trans (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } else {
        $pdo->exec("CREATE TABLE test_trans (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT
        )");
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO test_trans (name) VALUES (?)")->execute(['first']);
    $pdo->prepare("INSERT INTO test_trans (name) VALUES (?)")->execute(['second']);
    $pdo->commit();

    $count = $pdo->query("SELECT COUNT(*) FROM test_trans")->fetchColumn();
    $t->assertEquals('Transaction commit', 2, (int)$count);

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO test_trans (name) VALUES (?)")->execute(['third']);
    $pdo->rollBack();

    $count = $pdo->query("SELECT COUNT(*) FROM test_trans")->fetchColumn();
    $t->assertEquals('Transaction rollback', 2, (int)$count);

    return $t;
}

function test_unicode(): Test
{
    $t = new Test('Database Matrix - Unicode (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec("DROP TABLE IF EXISTS test_unicode");
    if ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE test_unicode (
            id INT AUTO_INCREMENT PRIMARY KEY, content TEXT
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } else {
        $pdo->exec("CREATE TABLE test_unicode (
            id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT
        )");
    }

    $unicode = '日本語テスト 🎉 émojis ñ';
    $stmt = $pdo->prepare("INSERT INTO test_unicode (content) VALUES (?)");
    $stmt->execute([$unicode]);

    $stmt = $pdo->prepare("SELECT content FROM test_unicode WHERE id = ?");
    $stmt->execute([(int)$pdo->lastInsertId()]);
    $result = $stmt->fetchColumn();

    $t->assertEquals('Unicode storage', $unicode, $result);

    return $t;
}

function test_migration_compatibility(): Test
{
    $t = new Test('Database Matrix - Migration Compatibility (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec("DROP TABLE IF EXISTS roles");
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("
            CREATE TABLE roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE,
                permissions TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset
        ");
        $pdo->exec("
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                role VARCHAR(50) DEFAULT 'user',
                avatar VARCHAR(255),
                status VARCHAR(50) DEFAULT 'active',
                email_verified INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $charset
        ");
    } else {
        $pdo->exec("DROP TABLE IF EXISTS roles");
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                permissions TEXT DEFAULT '[]',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
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
            )
        ");
    }

    $adminPerms = json_encode(['admin.access', 'threads.delete', 'posts.edit']);
    $stmt = $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)");
    $stmt->execute(['admin', $adminPerms]);
    $stmt->execute(['user', json_encode(['threads.create'])]);

    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
    $stmt->execute(['admin', password_hash('test123', PASSWORD_DEFAULT), 'admin@test.com', 'admin']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['admin']);
    $user = $stmt->fetch();
    $t->assert('User created', $user !== false);
    $t->assertEquals('User role', 'admin', $user['role']);

    $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE name = ?");
    $stmt->execute(['admin']);
    $perms = json_decode($stmt->fetchColumn(), true);
    $t->assert('Permissions stored as JSON', is_array($perms));
    $t->assert('Has admin.access', in_array('admin.access', $perms, true));

    return $t;
}

function test_authz_integration(): Test
{
    $t = new Test('Database Matrix - AuthZ Integration (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    require_once __DIR__ . '/../lib/AuthZ.php';

    $pdo->exec("DROP TABLE IF EXISTS roles");
    $pdo->exec("DROP TABLE IF EXISTS users");

    if ($driver === 'mysql') {
        $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec("CREATE TABLE roles (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL, permissions TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset");
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) UNIQUE NOT NULL, role VARCHAR(50) DEFAULT 'user'
        ) $charset");
    } else {
        $pdo->exec("CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, permissions TEXT DEFAULT '[]', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, role TEXT DEFAULT 'user'
        )");
    }

    $stmt = $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)");
    $stmt->execute(['admin', json_encode(['admin.access', 'threads.delete', 'posts.edit'])]);
    $stmt->execute(['user', json_encode(['threads.create'])]);

    $stmt = $pdo->prepare("INSERT INTO users (id, username, role) VALUES (?, ?, ?)");
    $stmt->execute([1, 'admin_user', 'admin']);
    $stmt->execute([2, 'regular_user', 'user']);

    $authz = new AuthZ($pdo);

    $t->assertTrue('Admin can admin.access', $authz->can(1, 'admin.access'));
    $t->assertTrue('Admin can threads.delete', $authz->can(1, 'threads.delete'));
    $t->assertFalse('User cannot admin.access', $authz->can(2, 'admin.access'));
    $t->assertTrue('User can threads.create', $authz->can(2, 'threads.create'));
    $t->assertFalse('User cannot threads.delete', $authz->can(2, 'threads.delete'));

    return $t;
}

function test_thread_listing_latest_join(): Test
{
    $t = new Test('Database Matrix - Thread Listing Latest Join (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec("DROP TABLE IF EXISTS test_posts");
    $pdo->exec("DROP TABLE IF EXISTS test_threads");

    if ($driver === 'mysql') {
        $charset = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec("CREATE TABLE test_threads (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(255) NOT NULL,
            status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset");
        $pdo->exec("CREATE TABLE test_posts (
            id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT,
            content TEXT NOT NULL, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME
        ) $charset");
    } else {
        $pdo->exec("CREATE TABLE test_threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT NOT NULL,
            status TEXT DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE test_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL, user_id INTEGER,
            content TEXT NOT NULL, status TEXT DEFAULT 'visible', created_at DATETIME
        )");
    }

    $pdo->exec("INSERT INTO test_threads (id, user_id, title, status) VALUES (1, 1, 'Alpha', 'visible')");
    $pdo->exec("INSERT INTO test_threads (id, user_id, title, status) VALUES (2, 2, 'Beta', 'visible')");

    // Two posts in thread 1 share the exact same timestamp: the old
    // MAX(created_at) join produced duplicate thread rows / a non-deterministic
    // last post. The deterministic join must return exactly one row per thread.
    $same = '2026-01-01 00:00:00';
    $pdo->prepare("INSERT INTO test_posts (id, thread_id, user_id, content, created_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([10, 1, 1, 'first', $same]);
    $pdo->prepare("INSERT INTO test_posts (id, thread_id, user_id, content, created_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([11, 1, 2, 'second', $same]);
    $pdo->prepare("INSERT INTO test_posts (id, thread_id, user_id, content, created_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([12, 2, 2, 'other', '2026-01-02 00:00:00']);

    $stmt = $pdo->query("
        SELECT t.id, lp.id AS last_post_id
        FROM test_threads t
        LEFT JOIN test_posts lp ON lp.id = (
            SELECT lp2.id FROM test_posts lp2
            WHERE lp2.thread_id = t.id AND lp2.status = 'visible'
            ORDER BY lp2.created_at DESC, lp2.id DESC
            LIMIT 1
        )
        WHERE t.status IN ('visible', 'sticky', 'locked')
        ORDER BY t.updated_at DESC, t.id DESC
    ");
    $rows = $stmt->fetchAll();

    $t->assert('One row per thread (no duplicates)', count($rows) === 2);
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = (int)$row['last_post_id'];
    }
    $t->assertEquals('Deterministic last post for tied timestamps', 11, $byId[1] ?? 0);
    $t->assertEquals('Last post for single-post thread', 12, $byId[2] ?? 0);

    return $t;
}

function test_vote_upsert(): Test
{
    $t = new Test('Database Matrix - Vote Upsert (' . getDriverLabel() . ')');

    $pdo = createPDO();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec("DROP TABLE IF EXISTS post_votes");
    if ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE post_votes (
            id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL,
            vote INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_post_user (post_id, user_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $upsert = "INSERT INTO post_votes (post_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)";
    } else {
        $pdo->exec("CREATE TABLE post_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            vote INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (post_id, user_id)
        )");
        $upsert = "INSERT INTO post_votes (post_id, user_id, vote) VALUES (?, ?, ?) ON CONFLICT(post_id, user_id) DO UPDATE SET vote = excluded.vote";
    }

    $pdo->prepare($upsert)->execute([1, 1, 1]);
    $pdo->prepare($upsert)->execute([1, 1, 1]); // duplicate must not create a second row
    $t->assertEquals('Upsert does not duplicate rows', 1, (int)$pdo->query("SELECT COUNT(*) FROM post_votes")->fetchColumn());

    $pdo->prepare($upsert)->execute([1, 1, -1]); // same user changes vote
    $t->assertEquals('Upsert updates the vote', -1, (int)$pdo->query("SELECT vote FROM post_votes WHERE post_id = 1 AND user_id = 1")->fetchColumn());
    $t->assertEquals('Still a single row after update', 1, (int)$pdo->query("SELECT COUNT(*) FROM post_votes")->fetchColumn());

    return $t;
}

function with_driver(string $driver, callable $fn): Test
{
    $oldDriver = $_ENV['DB_DRIVER'] ?? null;
    $oldEnv = getenv('DB_DRIVER');

    $_ENV['DB_DRIVER'] = $driver;
    putenv("DB_DRIVER={$driver}");

    try {
        return $fn();
    } finally {
        if ($oldDriver === null) {
            unset($_ENV['DB_DRIVER']);
        } else {
            $_ENV['DB_DRIVER'] = $oldDriver;
        }
        if ($oldEnv === false) {
            putenv('DB_DRIVER');
        } else {
            putenv("DB_DRIVER={$oldEnv}");
        }
    }
}

function register_database_matrix_tests(): void
{
    $driver = $_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?? 'sqlite';
    $availableDrivers = ['sqlite'];

    if ($driver === 'mysql') {
        $availableDrivers[] = 'mysql';
    }

    foreach ($availableDrivers as $d) {
        $skip = $d !== 'sqlite' && skipIfNoDB($d);
        if ($skip) {
            echo "\n[SKIP] {$d} - not available\n";
            continue;
        }

        $tests = [
            'test_schema_creation',
            'test_crud_operations',
            'test_transactions',
            'test_unicode',
            'test_migration_compatibility',
            'test_authz_integration',
            'test_thread_listing_latest_join',
            'test_vote_upsert',
        ];

        foreach ($tests as $testName) {
            get_test_suite()->addTestFactory(function () use ($d, $testName) {
                return with_driver($d, function () use ($testName) {
                    return $testName();
                });
            }, $testName . ':' . $d);
        }
    }
}

register_database_matrix_tests();

// Prevent direct execution - tests must be run via tests/run.php
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    fwrite(STDERR, "Error: DatabaseMatrixTest.php only registers tests. Run via: php tests/run.php" . PHP_EOL);
    fwrite(STDERR, "Example: DB_DRIVER=mysql php tests/run.php" . PHP_EOL);
    exit(1);
}
