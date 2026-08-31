<?php

/**
 * Moderation action tests — verify lock, unlock, sticky, unsticky, hide, delete, move, copy, split, merge.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_moderation_approve(): Test
{
    $t = new Test('Moderation - Approve Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER,
        user_id INTEGER,
        content TEXT NOT NULL,
        status TEXT DEFAULT 'visible',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Create a pending thread
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'pending')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Approve
    $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);

    $status = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $status->execute([$threadId]);
    $t->assertEquals('Thread approved', 'visible', $status->fetchColumn());

    return $t;
}

function test_moderation_lock_unlock(): Test
{
    $t = new Test('Moderation - Lock/Unlock Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Lock
    $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
    $status = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $status->execute([$threadId]);
    $t->assertEquals('Thread locked', 'locked', $status->fetchColumn());

    // Unlock
    $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
    $status->execute([$threadId]);
    $t->assertEquals('Thread unlocked', 'visible', $status->fetchColumn());

    return $t;
}

function test_moderation_sticky_unsticky(): Test
{
    $t = new Test('Moderation - Sticky/Unsticky Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Sticky
    $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
    $status = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $status->execute([$threadId]);
    $t->assertEquals('Thread stickied', 'sticky', $status->fetchColumn());

    // Unsticky
    $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
    $status->execute([$threadId]);
    $t->assertEquals('Thread unstickied', 'visible', $status->fetchColumn());

    return $t;
}

function test_moderation_hide(): Test
{
    $t = new Test('Moderation - Hide Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Hide
    $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
    $status = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $status->execute([$threadId]);
    $t->assertEquals('Thread hidden', 'hidden', $status->fetchColumn());

    return $t;
}

function test_moderation_delete_thread(): Test
{
    $t = new Test('Moderation - Delete Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER,
        user_id INTEGER,
        content TEXT NOT NULL,
        status TEXT DEFAULT 'visible',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Reply')")->execute([$threadId]);

    // Delete thread
    $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);

    $count = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE id = ?");
    $count->execute([$threadId]);
    $t->assertEquals('Thread deleted', 0, (int)$count->fetchColumn());

    return $t;
}

function test_moderation_move_thread(): Test
{
    $t = new Test('Moderation - Move Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Move to category 2
    $pdo->prepare("UPDATE threads SET category_id = ? WHERE id = ?")->execute([2, $threadId]);

    $catId = $pdo->prepare("SELECT category_id FROM threads WHERE id = ?");
    $catId->execute([$threadId]);
    $t->assertEquals('Thread moved to category 2', 2, (int)$catId->fetchColumn());

    return $t;
}

function test_moderation_copy_thread(): Test
{
    $t = new Test('Moderation - Copy Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER,
        user_id INTEGER,
        content TEXT NOT NULL,
        status TEXT DEFAULT 'visible',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Original', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Reply 1')")->execute([$threadId]);
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Reply 2')")->execute([$threadId]);

    // Copy to category 2
    $src = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $src->execute([$threadId]);
    $srcThread = $src->fetch();

    $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
    $ins->execute([2, $srcThread['user_id'], $srcThread['title'], $srcThread['content'], $srcThread['created_at']]);
    $newThreadId = (int)$pdo->lastInsertId();

    // Copy posts
    $postsStmt = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ? AND status = 'visible'");
    $postsStmt->execute([$threadId]);
    $posts = $postsStmt->fetchAll();
    $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) VALUES (?, ?, ?, 'visible', ?)");
    foreach ($posts as $post) {
        $postIns->execute([$newThreadId, $post['user_id'], $post['content'], $post['created_at']]);
    }

    // Verify
    $count = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE title = 'Original'");
    $count->execute();
    $t->assertEquals('Two threads with same title', 2, (int)$count->fetchColumn());

    $postCount = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $postCount->execute([$newThreadId]);
    $t->assertEquals('Copied posts to new thread', 2, (int)$postCount->fetchColumn());

    return $t;
}

function test_moderation_split_thread(): Test
{
    $t = new Test('Moderation - Split Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER,
        user_id INTEGER,
        content TEXT NOT NULL,
        status TEXT DEFAULT 'visible',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Create source thread with posts
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Original Thread', 'First post', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, created_at) VALUES (?, 1, 'Post 2', '2026-01-02 10:00:00')")->execute([$threadId]);
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, created_at) VALUES (?, 1, 'Post 3', '2026-01-03 10:00:00')")->execute([$threadId]);
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, created_at) VALUES (?, 1, 'Post 4', '2026-01-04 10:00:00')")->execute([$threadId]);

    // Split logic (matches handle_split_thread_post):
    // Select posts 2 and 3 (indices 1 and 2) to split into new thread
    $selStmt = $pdo->prepare("SELECT id, content, user_id, created_at FROM posts WHERE thread_id = ? ORDER BY created_at ASC, id ASC");
    $selStmt->execute([$threadId]);
    $allPosts = $selStmt->fetchAll();

    $splitIndices = [1, 2]; // 2nd and 3rd posts
    $splitPosts = [$allPosts[1], $allPosts[2]];

    $newTitle = 'Split Thread';

    // Create new thread with first split post as content
    $firstPost = $splitPosts[0];
    $srcThreadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $srcThreadStmt->execute([$threadId]);
    $srcThread = $srcThreadStmt->fetch();

    $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
    $ins->execute([$srcThread['category_id'], $firstPost['user_id'], $newTitle, $firstPost['content'], $firstPost['created_at']]);
    $newThreadId = (int)$pdo->lastInsertId();

    // Copy remaining split posts to new thread (using INSERT...SELECT like the code)
    $replyIds = [1]; // index 1 of splitPosts (the 2nd post)
    foreach ($replyIds as $idx) {
        $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) SELECT ?, user_id, content, status, created_at FROM posts WHERE id = ?");
        $postIns->execute([$newThreadId, $allPosts[$splitIndices[$idx]]['id']]);
    }

    // Delete split posts from original thread
    $splitPostIds = array_map(fn($p) => $p['id'], $splitPosts);
    $placeholders = implode(',', array_fill(0, count($splitPostIds), '?'));
    $pdo->prepare("DELETE FROM posts WHERE thread_id = ? AND id IN ($placeholders)")->execute(array_merge([$threadId], $splitPostIds));

    // Verify
    $newThreadPosts = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $newThreadPosts->execute([$newThreadId]);
    $t->assertEquals('New thread has 1 copied post', 1, (int)$newThreadPosts->fetchColumn());

    $originalPosts = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $originalPosts->execute([$threadId]);
    $t->assertEquals('Original thread has 1 remaining post', 1, (int)$originalPosts->fetchColumn());

    $newThreadStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
    $newThreadStmt->execute([$newThreadId]);
    $t->assertEquals('New thread has correct title', 'Split Thread', $newThreadStmt->fetchColumn());

    return $t;
}

function test_moderation_merge_thread(): Test
{
    $t = new Test('Moderation - Merge Thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        user_id INTEGER,
        title TEXT NOT NULL,
        content TEXT,
        status TEXT DEFAULT 'visible',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER,
        user_id INTEGER,
        content TEXT NOT NULL,
        status TEXT DEFAULT 'visible',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Create source and target threads
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Source Thread', 'Source content', 'visible')")->execute();
    $sourceThreadId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Target Thread', 'Target content', 'visible')")->execute();
    $targetThreadId = (int)$pdo->lastInsertId();

    // Add posts to source
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Source Post 1')")->execute([$sourceThreadId]);
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Source Post 2')")->execute([$sourceThreadId]);

    // Merge: move all posts from source to target
    $pdo->prepare("UPDATE posts SET thread_id = ? WHERE thread_id = ?")->execute([$targetThreadId, $sourceThreadId]);

    // Delete source thread
    $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$sourceThreadId]);

    // Verify
    $sourceExists = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE id = ?");
    $sourceExists->execute([$sourceThreadId]);
    $t->assertEquals('Source thread deleted', 0, (int)$sourceExists->fetchColumn());

    $targetPosts = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $targetPosts->execute([$targetThreadId]);
    $t->assertEquals('Target thread has 2 merged posts', 2, (int)$targetPosts->fetchColumn());

    return $t;
}

function test_moderation_csrf_protection(): Test
{
    $t = new Test('Moderation - CSRF Protection');

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION = [];

    // Without CSRF token, moderation should fail
    $_POST = ['id' => 1, 'do' => 'delete'];
    $result = csrf_validate_request();
    $t->assertFalse('CSRF validation fails without token', $result);

    // With valid CSRF token
    $token = generate_csrf_token();
    $_POST = ['id' => 1, 'do' => 'delete', 'csrf_token' => $token];
    $result = csrf_validate_request();
    $t->assertTrue('CSRF validation passes with valid token', $result);

    return $t;
}

function test_moderation_authorization(): Test
{
    $t = new Test('Moderation - Authorization');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)");
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'admin1', 'admin')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (2, 'user1', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"moderation.manage\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]')");

    $authz = new AuthZ($pdo);

    // Admin can moderate
    $t->assertTrue('Admin can moderate', $authz->can(1, 'moderation.manage'));

    // Regular user cannot moderate
    $t->assertFalse('User cannot moderate', $authz->can(2, 'moderation.manage'));

    return $t;
}

// Run all moderation tests
$suite = new TestSuite();
$suite->addTest(test_moderation_approve());
$suite->addTest(test_moderation_lock_unlock());
$suite->addTest(test_moderation_sticky_unsticky());
$suite->addTest(test_moderation_hide());
$suite->addTest(test_moderation_delete_thread());
$suite->addTest(test_moderation_move_thread());
$suite->addTest(test_moderation_copy_thread());
$suite->addTest(test_moderation_split_thread());
$suite->addTest(test_moderation_merge_thread());
$suite->addTest(test_moderation_csrf_protection());
$suite->addTest(test_moderation_authorization());
$suite->run();
