<?php

/**
 * DatabaseIntegrityTest — foreign keys, constraints, counters, soft-delete.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';

function setupIntegrityDB(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0
        );
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    return $pdo;
}

function test_foreign_key_cascade_delete_user(): Test
{
    $t = new Test('Integrity - Cascade Delete User');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Thread by user1', 'Content')")->execute();
    $pdo->prepare("INSERT INTO posts (id, thread_id, user_id, content) VALUES (1, 1, 1, 'Post by user1')")->execute();

    $pdo->prepare("DELETE FROM users WHERE id = 1")->execute();

    $threads = $pdo->query("SELECT COUNT(*) FROM threads WHERE user_id = 1")->fetchColumn();
    $t->assertEquals('Threads deleted with user (CASCADE)', 0, (int)$threads);

    $posts = $pdo->query("SELECT COUNT(*) FROM posts WHERE user_id = 1")->fetchColumn();
    $t->assertEquals('Posts deleted with user (CASCADE)', 0, (int)$posts);

    App::reset();

    return $t;
}

function test_foreign_key_cascade_delete_thread(): Test
{
    $t = new Test('Integrity - Cascade Delete Thread');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Thread', 'Content')")->execute();
    $pdo->prepare("INSERT INTO posts (id, thread_id, user_id, content) VALUES (1, 1, 1, 'Post 1')")->execute();
    $pdo->prepare("INSERT INTO posts (id, thread_id, user_id, content) VALUES (2, 1, 1, 'Post 2')")->execute();

    $pdo->prepare("DELETE FROM threads WHERE id = 1")->execute();

    $posts = $pdo->query("SELECT COUNT(*) FROM posts WHERE thread_id = 1")->fetchColumn();
    $t->assertEquals('Posts deleted with thread (CASCADE)', 0, (int)$posts);

    App::reset();

    return $t;
}

function test_foreign_key_set_null_category(): Test
{
    $t = new Test('Integrity - SET NULL on Category Delete');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Thread', 'Content')")->execute();

    $pdo->prepare("DELETE FROM categories WHERE id = 1")->execute();

    $thread = $pdo->query("SELECT category_id FROM threads WHERE id = 1")->fetch();
    $t->assert('Thread category_id set to NULL', $thread === false || $thread['category_id'] === null);

    App::reset();

    return $t;
}

function test_unique_constraint_username(): Test
{
    $t = new Test('Integrity - Unique Constraint on Username');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();

    try {
        $pdo->prepare("INSERT INTO users (id, username, password) VALUES (2, 'user1', 'hash2')")->execute();
        $t->assert('Duplicate username rejected', false);
    } catch (PDOException $e) {
        $t->assert('Duplicate username rejected with exception', true);
    }

    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'user1'")->fetchColumn();
    $t->assertEquals('Only one user1 exists', 1, (int)$count);

    App::reset();

    return $t;
}

function test_unique_constraint_category_name(): Test
{
    $t = new Test('Integrity - Unique Constraint on Category Name');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();

    try {
        $pdo->prepare("INSERT INTO categories (id, name) VALUES (2, 'General')")->execute();
        $t->assert('Duplicate category rejected', false);
    } catch (PDOException $e) {
        $t->assert('Duplicate category rejected with exception', true);
    }

    App::reset();

    return $t;
}

function test_reply_count_accurate(): Test
{
    $t = new Test('Integrity - Reply Count Accurate');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Thread', 'Content')")->execute();

    for ($i = 1; $i <= 5; $i++) {
        $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (1, 1, ?, 'visible')")
            ->execute(["Reply {$i}"]);
    }

    $count = $pdo->query("SELECT COUNT(*) FROM posts WHERE thread_id = 1 AND status = 'visible'")->fetchColumn();
    $t->assertEquals('Reply count is 5', 5, (int)$count);

    $pdo->prepare("UPDATE posts SET status = 'hidden' WHERE id = 1")->execute();
    $visibleCount = $pdo->query("SELECT COUNT(*) FROM posts WHERE thread_id = 1 AND status = 'visible'")->fetchColumn();
    $t->assertEquals('Visible reply count is 4 after hiding', 4, (int)$visibleCount);

    App::reset();

    return $t;
}

function test_views_count_accurate(): Test
{
    $t = new Test('Integrity - Views Count Accurate');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content, views) VALUES (1, 1, 1, 'Thread', 'Content', 0)")->execute();

    for ($i = 0; $i < 10; $i++) {
        $pdo->prepare("UPDATE threads SET views = views + 1 WHERE id = 1")->execute();
    }

    $views = $pdo->query("SELECT views FROM threads WHERE id = 1")->fetchColumn();
    $t->assertEquals('Views count is 10', 10, (int)$views);

    App::reset();

    return $t;
}

function test_soft_delete_thread(): Test
{
    $t = new Test('Integrity - Soft Delete Thread (status hidden)');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content, status) VALUES (1, 1, 1, 'Thread', 'Content', 'visible')")->execute();

    $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = 1")->execute();

    $thread = $pdo->query("SELECT * FROM threads WHERE id = 1")->fetch();
    $t->assert('Thread still exists after soft delete', $thread !== false);
    $t->assertEquals('Thread status is hidden', 'hidden', $thread['status']);

    $totalThreads = $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn();
    $t->assertEquals('Thread still counted in total', 1, (int)$totalThreads);

    App::reset();

    return $t;
}

function test_hard_delete_thread(): Test
{
    $t = new Test('Integrity - Hard Delete Thread');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Thread', 'Content')")->execute();
    $pdo->prepare("INSERT INTO posts (id, thread_id, user_id, content) VALUES (1, 1, 1, 'Post')")->execute();

    $pdo->prepare("DELETE FROM threads WHERE id = 1")->execute();

    $thread = $pdo->query("SELECT * FROM threads WHERE id = 1")->fetch();
    $t->assert('Thread removed from DB', $thread === false);

    $posts = $pdo->query("SELECT COUNT(*) FROM posts WHERE thread_id = 1")->fetchColumn();
    $t->assertEquals('Posts also removed (CASCADE)', 0, (int)$posts);

    App::reset();

    return $t;
}

function test_not_null_constraints(): Test
{
    $t = new Test('Integrity - NOT NULL Constraints');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    try {
        $pdo->prepare("INSERT INTO users (username, password) VALUES (NULL, 'hash')")->execute();
        $t->assert('NULL username rejected', false);
    } catch (PDOException $e) {
        $t->assert('NULL username rejected with exception', true);
    }

    try {
        $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content) VALUES (NULL, 1, NULL, 'Content')")->execute();
        $t->assert('NULL title rejected', false);
    } catch (PDOException $e) {
        $t->assert('NULL title rejected with exception', true);
    }

    App::reset();

    return $t;
}

function test_thread_belongs_to_valid_category(): Test
{
    $t = new Test('Integrity - Thread Must Belong to Valid Category');

    $pdo = setupIntegrityDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();

    try {
        $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content) VALUES (999, 1, 'Thread', 'Content')")->execute();
        $t->assert('Invalid category_id rejected', false);
    } catch (PDOException $e) {
        $t->assert('Invalid category_id rejected with exception', true);
    }

    App::reset();

    return $t;
}

$tests = [
    test_foreign_key_cascade_delete_user(),
    test_foreign_key_cascade_delete_thread(),
    test_foreign_key_set_null_category(),
    test_unique_constraint_username(),
    test_unique_constraint_category_name(),
    test_reply_count_accurate(),
    test_views_count_accurate(),
    test_soft_delete_thread(),
    test_hard_delete_thread(),
    test_not_null_constraints(),
    test_thread_belongs_to_valid_category(),
];

$totalPassed = 0;
$totalFailed = 0;
foreach ($tests as $t) {
    $t->run();
    $totalPassed += $t->getPassed();
    $totalFailed += $t->getFailed();
}

echo "\n";
echo "############################################################\n";
echo "# TOTAL: {$totalPassed} passed, {$totalFailed} failed\n";
echo "############################################################\n";

exit($totalFailed > 0 ? 1 : 0);
