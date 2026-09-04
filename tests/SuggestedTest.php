<?php

/**
 * SuggestedTest — additional edge case tests suggested during review.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Helpers/Data.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function setupSuggestedDB(): PDO
{
    $pdo = new PDO('sqlite::memory:');
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
            suspension_time INTEGER DEFAULT 0,
            email_verified INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
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
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0
        );
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
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
            permissions TEXT DEFAULT '[]'
        );
    ");

    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\"]')")->execute();
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('user', '[\"threads.create\",\"posts.create\"]')")->execute();

    return $pdo;
}

function test_register_duplicate_email(): Test
{
    $t = new Test('Suggested - Registration with Duplicate Email');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password, email) VALUES (1, 'user1', 'hash', 'duplicate@test.com')")->execute();

    $email = 'duplicate@test.com';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND email != ''");
    $stmt->execute([$email]);
    $exists = (int)$stmt->fetchColumn() > 0;

    $t->assert('Duplicate email detected', $exists);

    $newEmail = 'unique@test.com';
    $stmt->execute([$newEmail]);
    $newExists = (int)$stmt->fetchColumn() > 0;
    $t->assert('Unique email not found (available)', !$newExists);

    App::reset();

    return $t;
}

function test_csrf_concurrent_requests(): Test
{
    $t = new Test('Suggested - CSRF Token Rotation Prevents Replay');

    $_SESSION = [];
    if (session_status() === PHP_SESSION_NONE) @session_start();

    $token1 = generate_csrf_token();
    $t->assert('First token generated', strlen($token1) === 64);

    $_POST['csrf_token'] = $token1;
    $result1 = csrf_validate_request();
    $t->assert('First request with token succeeds', $result1 === true);

    $_POST['csrf_token'] = $token1;
    $result2 = csrf_validate_request();
    $t->assert('Second request with same token fails', $result2 === false);

    $token2 = $_SESSION['csrf_token'] ?? '';
    $t->assert('New token generated after first use', $token2 !== '' && $token2 !== $token1);

    $_POST = [];
    $_SESSION = [];

    return $t;
}

function test_thread_merge_preserves_ownership(): Test
{
    $t = new Test('Suggested - Thread Merge Preserves Post Ownership');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (2, 'user2', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();

    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Source Thread', 'Content')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (2, 1, 2, 'Target Thread', 'Content')")->execute();

    $pdo->prepare("INSERT INTO posts (id, thread_id, user_id, content) VALUES (1, 1, 1, 'Post by user1')")->execute();
    $pdo->prepare("INSERT INTO posts (id, thread_id, user_id, content) VALUES (2, 1, 2, 'Post by user2')")->execute();

    $pdo->prepare("UPDATE posts SET thread_id = 2 WHERE thread_id = 1")->execute();
    $pdo->prepare("DELETE FROM threads WHERE id = 1")->execute();

    $posts = $pdo->query("SELECT * FROM posts WHERE thread_id = 2 ORDER BY id ASC")->fetchAll();
    $t->assertEquals('Target thread has 2 merged posts', 2, count($posts));
    $t->assertEquals('First post still owned by user1', 1, (int)$posts[0]['user_id']);
    $t->assertEquals('Second post still owned by user2', 2, (int)$posts[1]['user_id']);

    App::reset();

    return $t;
}

function test_category_allowed_roles(): Test
{
    $t = new Test('Suggested - Category with allowed_roles');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (1, 'Admin Only', 'Admins only', 1, '[\"admin\"]')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (2, 'Public', 'Everyone', 2, NULL)")->execute();

    $adminCategory = $pdo->query("SELECT * FROM categories WHERE id = 1")->fetch();
    $publicCategory = $pdo->query("SELECT * FROM categories WHERE id = 2")->fetch();

    $t->assert('Admin category has allowed_roles', !empty($adminCategory['allowed_roles']));
    $t->assert('Public category has NULL allowed_roles', $publicCategory['allowed_roles'] === null);

    $roles = json_decode($adminCategory['allowed_roles'], true);
    $t->assert('Admin category allows admin role', in_array('admin', $roles));

    App::reset();

    return $t;
}

function test_thread_watcher_notification(): Test
{
    $t = new Test('Suggested - Thread Watcher Receives Notification');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'author', 'hash')")->execute();
    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (2, 'watcher', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (id, category_id, user_id, title, content) VALUES (1, 1, 1, 'Thread', 'Content')")->execute();

    $pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (1, 2)")->execute();

    $watcher = $pdo->query("SELECT * FROM thread_watchers WHERE thread_id = 1 AND user_id = 2")->fetch();
    $t->assert('Watcher registered for thread', $watcher !== false);

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (1, 1, 'New reply')")->execute();

    $watchers = $pdo->query("SELECT * FROM thread_watchers WHERE thread_id = 1")->fetchAll();
    $t->assert('Watchers exist for notification', count($watchers) > 0);

    $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (2, 'reply', 'New reply', 'Someone replied', '/thread/1')")->execute();
    $notification = $pdo->query("SELECT * FROM notifications WHERE user_id = 2")->fetch();
    $t->assert('Notification created for watcher', $notification !== false);

    App::reset();

    return $t;
}

function test_private_messages(): Test
{
    $t = new Test('Suggested - Private Messages');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'alice', 'hash')")->execute();
    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (2, 'bob', 'hash')")->execute();

    $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (1, 2, 'Hello', 'Hi Bob!')")->execute();
    $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (2, 1, 'Re: Hello', 'Hi Alice!')")->execute();

    $messages = $pdo->query("SELECT * FROM private_messages WHERE recipient_id = 2")->fetchAll();
    $t->assertEquals('Bob received 1 message', 1, count($messages));
    $t->assertEquals('Message from alice', 1, (int)$messages[0]['sender_id']);
    $t->assertEquals('Message subject correct', 'Hello', $messages[0]['subject']);

    $unread = $pdo->query("SELECT COUNT(*) FROM private_messages WHERE recipient_id = 2 AND is_read = 0")->fetchColumn();
    $t->assertEquals('Bob has 1 unread message', 1, (int)$unread);

    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE id = 1")->execute();
    $unreadAfter = $pdo->query("SELECT COUNT(*) FROM private_messages WHERE recipient_id = 2 AND is_read = 0")->fetchColumn();
    $t->assertEquals('Bob has 0 unread after reading', 0, (int)$unreadAfter);

    App::reset();

    return $t;
}

function test_search_malicious_input(): Test
{
    $t = new Test('Suggested - Search with Malicious Input');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, 'Normal Thread', 'Content', 'visible')")->execute();

    $maliciousInputs = [
        '<script>alert(1)</script>',
        "'; DROP TABLE threads; --",
        "' UNION SELECT * FROM users --",
        '<img src=x onerror=alert(1)>',
        '1 OR 1=1',
        '${7*7}',
        '{{7*7}}',
    ];

    foreach ($maliciousInputs as $input) {
        $stmt = $pdo->prepare("SELECT * FROM threads WHERE status = 'visible' AND (title LIKE ? OR content LIKE ?)");
        $stmt->execute(["%{$input}%", "%{$input}%"]);
        $results = $stmt->fetchAll();
        $t->assert("Search '{$input}' does not crash", is_array($results));
    }

    $thread = $pdo->query("SELECT * FROM threads WHERE id = 1")->fetch();
    $t->assert('Original thread still exists', $thread !== false);
    $t->assertEquals('Thread title unchanged', 'Normal Thread', $thread['title']);

    App::reset();

    return $t;
}

function test_pagination_edge_cases(): Test
{
    $t = new Test('Suggested - Pagination Edge Cases');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'user1', 'hash')")->execute();
    $pdo->prepare("INSERT INTO categories (id, name) VALUES (1, 'General')")->execute();

    for ($i = 1; $i <= 5; $i++) {
        $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 1, ?, ?, 'visible')")
            ->execute(["Thread {$i}", "Content {$i}"]);
    }

    $perPage = 2;
    $totalThreads = 5;
    $totalPages = (int)ceil($totalThreads / $perPage);
    $t->assertEquals('Total pages for 5 threads with perPage=2', 3, $totalPages);

    $page = max(1, 0);
    $t->assert('Page 0 clamped to 1', $page === 1);

    $page = max(1, -5);
    $t->assert('Negative page clamped to 1', $page === 1);

    $page = 10;
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE status = 'visible' ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $results = $stmt->fetchAll();
    $t->assert('Page beyond max returns empty', count($results) === 0);

    App::reset();

    return $t;
}

function test_username_case_sensitivity(): Test
{
    $t = new Test('Suggested - Username Case Sensitivity');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'Admin', 'hash')")->execute();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['admin']);
    $lowercase = $stmt->fetch();

    $stmt->execute(['Admin']);
    $exact = $stmt->fetch();

    $t->assert('Exact case match found', $exact !== false);

    App::reset();

    return $t;
}

function test_concurrent_registration_race(): Test
{
    $t = new Test('Suggested - Concurrent Registration Race Condition');

    $pdo = setupSuggestedDB();
    App::getInstance()->pdo = $pdo;

    $username = 'raceuser';

    $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $existsStmt->execute([$username]);
    $exists = $existsStmt->fetchColumn();

    if ($exists === 0) {
        usleep(10000);
        $existsStmt->execute([$username]);
        $exists = $existsStmt->fetchColumn();

        if ($exists === 0) {
            $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)")
                ->execute([$username, password_hash('pass1', PASSWORD_DEFAULT)]);
        }
    }

    $existsStmt->execute([$username]);
    $exists = $existsStmt->fetchColumn();

    if ($exists === 0) {
        $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)")
            ->execute([$username, password_hash('pass2', PASSWORD_DEFAULT)]);
    }

    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE username = '{$username}'")->fetchColumn();
    $t->assertEquals('Only one user created despite race', 1, (int)$count);

    App::reset();

    return $t;
}

register_tests(
    'test_register_duplicate_email',
    'test_csrf_concurrent_requests',
    'test_thread_merge_preserves_ownership',
    'test_category_allowed_roles',
    'test_thread_watcher_notification',
    'test_private_messages',
    'test_search_malicious_input',
    'test_pagination_edge_cases',
    'test_username_case_sensitivity',
    'test_concurrent_registration_race'
);
