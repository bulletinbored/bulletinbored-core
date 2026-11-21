<?php

/**
 * ThreadedCommentsTest — nested comment schema and tree loading.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/actions/posts-thread.php';
require_once __DIR__ . '/../migrations/20261121_add_parent_id_to_posts.php';

function setupThreadedDB(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("INSERT INTO users (id, username, password, role) VALUES (1, 'alice', 'x', 'user')");
    $pdo->exec("INSERT INTO users (id, username, password, role) VALUES (2, 'bob', 'x', 'user')");

    return $pdo;
}

function test_threaded_migration_adds_parent_id(): Test
{
    $t = new Test('Threaded comments - migration adds parent_id safely');

    $pdo = setupThreadedDB();

    $migration = new AddParentIdToPosts();
    $migration->up($pdo);
    $migration->up($pdo); // idempotent

    $columns = $pdo->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $t->assert('parent_id column exists', in_array('parent_id', $columns, true));

    $indexes = $pdo->query("PRAGMA index_list(posts)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $t->assert('parent_id index exists', in_array('idx_posts_parent', $indexes, true));

    return $t;
}

function test_threaded_fetch_returns_only_requested_level(): Test
{
    $t = new Test('Threaded comments - posts are loaded one level at a time');

    $pdo = setupThreadedDB();
    (new AddParentIdToPosts())->up($pdo);

    $pdo->exec("INSERT INTO posts (thread_id, user_id, content) VALUES (1, 1, 'root')");
    $rootId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, parent_id) VALUES (1, 2, 'child', ?)")
        ->execute([$rootId]);
    $childId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, parent_id) VALUES (1, 1, 'grandchild', ?)")
        ->execute([$childId]);

    $roots = bb_fetch_thread_posts($pdo, 1, null, true);
    $children = bb_fetch_thread_posts($pdo, 1, [$rootId]);
    $grandchildren = bb_fetch_thread_posts($pdo, 1, [$childId]);
    $empty = bb_fetch_thread_posts($pdo, 1, []);

    $t->assertEquals('One top-level comment', 1, count($roots));
    $t->assertEquals('Root content', 'root', $roots[0]['content']);
    $t->assertEquals('One direct child', 1, count($children));
    $t->assertEquals('Child content', 'child', $children[0]['content']);
    $t->assertEquals('One grandchild', 1, count($grandchildren));
    $t->assertEquals('Grandchild content', 'grandchild', $grandchildren[0]['content']);
    $t->assertEquals('Empty parent list loads nothing', 0, count($empty));

    return $t;
}

register_tests(
    'test_threaded_migration_adds_parent_id',
    'test_threaded_fetch_returns_only_requested_level'
);
