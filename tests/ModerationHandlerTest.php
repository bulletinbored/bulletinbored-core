<?php

/**
 * Moderation integration tests — verify actual handler functions work correctly.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/Errors.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../src/actions/admin/moderation.php';

function test_moderate_post_approve(): Test
{
    $t = new Test('Moderation Handler - Approve');

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

    App::getInstance()->pdo = $pdo;

    // Create pending thread
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'pending')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Setup CSRF token
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = [];
    $token = generate_csrf_token();

    // Call handler
    $_POST = ['id' => $threadId, 'do' => 'approve', 'csrf_token' => $token];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $result = handle_moderate_post();

    // Verify
    $status = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $status->execute([$threadId]);
    $t->assertEquals('Thread approved via handler', 'visible', $status->fetchColumn());

    return $t;
}

function test_moderate_post_delete(): Test
{
    $t = new Test('Moderation Handler - Delete');

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

    App::getInstance()->pdo = $pdo;

    // Create thread with posts
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Reply 1')")->execute([$threadId]);
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, 1, 'Reply 2')")->execute([$threadId]);

    // Setup CSRF
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = [];
    $token = generate_csrf_token();

    // Call handler
    $_POST = ['id' => $threadId, 'do' => 'delete', 'csrf_token' => $token];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $result = handle_moderate_post();

    // Verify thread deleted
    $count = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE id = ?");
    $count->execute([$threadId]);
    $t->assertEquals('Thread deleted via handler', 0, (int)$count->fetchColumn());

    // Posts are also deleted (cascade)
    $postCount = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $postCount->execute([$threadId]);
    $t->assertEquals('Posts cascade-deleted', 0, (int)$postCount->fetchColumn());

    return $t;
}

function test_moderate_post_invalid_csrf(): Test
{
    $t = new Test('Moderation Handler - Invalid CSRF');

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

    App::getInstance()->pdo = $pdo;

    // Setup invalid CSRF
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = [];

    // Call handler with invalid token — should throw
    $_POST = ['id' => 1, 'do' => 'delete', 'csrf_token' => 'invalid'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $threw = false;
    try {
        handle_moderate_post();
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
    }
    $t->assertTrue('Invalid CSRF throws ForbiddenException', $threw);

    return $t;
}

function test_moderate_post_invalid_thread(): Test
{
    $t = new Test('Moderation Handler - Invalid Thread ID');

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

    App::getInstance()->pdo = $pdo;

    // Setup CSRF
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = [];
    $token = generate_csrf_token();

    // Call handler with invalid thread ID — should throw
    $_POST = ['id' => 0, 'do' => 'approve', 'csrf_token' => $token];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $threw = false;
    try {
        handle_moderate_post();
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
    }
    $t->assertTrue('Invalid thread ID throws NotFoundException', $threw);

    return $t;
}

function test_frontend_moderate_lock(): Test
{
    $t = new Test('Frontend Moderation - Lock');

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
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)");
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'mod1', 'moderator')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"moderation.manage\"]')");

    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Setup CSRF and session
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = ['user_id' => 1];
    $token = generate_csrf_token();

    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    // Call handler
    $_POST = ['id' => $threadId, 'do' => 'lock', 'csrf_token' => $token];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $result = handle_frontend_moderate_post();

    // Verify
    $status = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $status->execute([$threadId]);
    $t->assertEquals('Thread locked via frontend handler', 'locked', $status->fetchColumn());

    return $t;
}

function test_frontend_moderate_unauthorized(): Test
{
    $t = new Test('Frontend Moderation - Unauthorized');

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
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)");
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'user1', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]')");

    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Test', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Setup CSRF and session for non-moderator
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = ['user_id' => 1];
    $token = generate_csrf_token();

    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;

    // Call handler — should throw ForbiddenException
    $_POST = ['id' => $threadId, 'do' => 'lock', 'csrf_token' => $token];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $threw = false;
    try {
        handle_frontend_moderate_post();
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
    }
    $t->assertTrue('Non-moderator cannot moderate', $threw);

    return $t;
}

register_tests(
    'test_moderate_post_approve',
    'test_moderate_post_delete',
    'test_moderate_post_invalid_csrf',
    'test_moderate_post_invalid_thread',
    'test_frontend_moderate_lock',
    'test_frontend_moderate_unauthorized'
);
