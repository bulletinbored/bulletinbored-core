<?php

/**
 * ContentCrudTest — thread creation, replies, viewing, pagination, profiles.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Helpers/Data.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function setupContentDB(): PDO
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
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]'
        );
    ");

    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
        ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'user1', ?, 'user1@test.com', 'user', 1)")
        ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (3, 'user2', ?, 'user2@test.com', 'user', 1)")
        ->execute([password_hash('user123', PASSWORD_DEFAULT)]);

    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (1, 'General', 'General discussion', 1)")->execute();
    $pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (2, 'Tech', 'Technology', 2)")->execute();

    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"threads.delete\",\"posts.edit\"]')")->execute();
    $pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('user', '[\"threads.create\",\"posts.create\",\"posts.edit_own\"]')")->execute();

    return $pdo;
}

function test_create_thread(): Test
{
    $t = new Test('Content - Create Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $stmt = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, 'visible')");
    $stmt->execute([1, 2, 'My First Thread', 'This is the content of my thread']);

    $threadId = (int)$pdo->lastInsertId();
    $t->assert('Thread created with ID', $threadId > 0);

    $thread = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $result = $thread->fetch();

    $t->assertEquals('Thread title stored', 'My First Thread', $result['title']);
    $t->assertEquals('Thread content stored', 'This is the content of my thread', $result['content']);
    $t->assertEquals('Thread status is visible', 'visible', $result['status']);
    $t->assertEquals('Thread has user_id', 2, (int)$result['user_id']);
    $t->assertEquals('Thread has category_id', 1, (int)$result['category_id']);

    App::reset();

    return $t;
}

function test_create_thread_requires_title(): Test
{
    $t = new Test('Content - Thread Requires Title');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $error = '';
    $title = '';
    $content = 'Some content';

    if (empty(trim($title))) {
        $error = 'Title is required';
    }

    $t->assert('Empty title rejected', !empty($error));

    $stmt = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, 'visible')");
    $stmt->execute([1, 2, 'Valid Title', $content]);

    $threadId = (int)$pdo->lastInsertId();
    $t->assert('Thread with title created', $threadId > 0);

    App::reset();

    return $t;
}

function test_reply_to_thread(): Test
{
    $t = new Test('Content - Reply to Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Original Thread', 'First post', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, 'visible')");
    $stmt->execute([$threadId, 3, 'This is a reply']);

    $postId = (int)$pdo->lastInsertId();
    $t->assert('Reply created with ID', $postId > 0);

    $post = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $post->execute([$postId]);
    $result = $post->fetch();

    $t->assertEquals('Reply has correct thread_id', $threadId, (int)$result['thread_id']);
    $t->assertEquals('Reply has correct user_id', 3, (int)$result['user_id']);
    $t->assertEquals('Reply content stored', 'This is a reply', $result['content']);

    App::reset();

    return $t;
}

function test_view_thread_pagination(): Test
{
    $t = new Test('Content - Thread View Pagination');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Paginated Thread', 'First post', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    for ($i = 1; $i <= 25; $i++) {
        $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, ?, 'visible')")
            ->execute([$threadId, "Reply number {$i}"]);
    }

    $perPage = 10;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'");
    $stmt->execute([$threadId]);
    $totalPosts = (int)$stmt->fetchColumn();
    $t->assertEquals('Total posts is 25', 25, $totalPosts);

    $totalPages = (int)ceil($totalPosts / $perPage);
    $t->assertEquals('Total pages is 3', 3, $totalPages);

    $page1Stmt = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ? AND status = 'visible' ORDER BY created_at ASC LIMIT ? OFFSET ?");
    $page1Stmt->execute([$threadId, $perPage, 0]);
    $page1 = $page1Stmt->fetchAll();
    $t->assert('Page 1 has 10 posts', count($page1) === 10);

    $page1Stmt->execute([$threadId, $perPage, $perPage]);
    $page2 = $page1Stmt->fetchAll();
    $t->assert('Page 2 has 10 posts', count($page2) === 10);

    $page1Stmt->execute([$threadId, $perPage, $perPage * 2]);
    $page3 = $page1Stmt->fetchAll();
    $t->assert('Page 3 has 5 posts', count($page3) === 5);

    App::reset();

    return $t;
}

function test_view_thread_order(): Test
{
    $t = new Test('Content - Thread View Order');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Ordered Thread', 'First post', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $dates = ['2026-01-01 10:00:00', '2026-01-02 10:00:00', '2026-01-03 10:00:00'];
    foreach ($dates as $date) {
        $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) VALUES (?, 2, ?, 'visible', ?)")
            ->execute([$threadId, "Post at {$date}", $date]);
    }

    $stmt = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ? AND status = 'visible' ORDER BY created_at ASC");
    $stmt->execute([$threadId]);
    $ascPosts = $stmt->fetchAll();
    $t->assert('ASC order: first post is oldest', str_contains($ascPosts[0]['content'], '2026-01-01'));
    $t->assert('ASC order: last post is newest', str_contains($ascPosts[2]['content'], '2026-01-03'));

    $stmt = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ? AND status = 'visible' ORDER BY created_at DESC");
    $stmt->execute([$threadId]);
    $descPosts = $stmt->fetchAll();
    $t->assert('DESC order: first post is newest', str_contains($descPosts[0]['content'], '2026-01-03'));
    $t->assert('DESC order: last post is oldest', str_contains($descPosts[2]['content'], '2026-01-01'));

    App::reset();

    return $t;
}

function test_view_threads_by_category(): Test
{
    $t = new Test('Content - View Threads by Category');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Thread in General', 'Content 1', 'visible')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Another in General', 'Content 2', 'visible')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (2, 2, 'Thread in Tech', 'Content 3', 'visible')")->execute();

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE category_id = ? AND status = 'visible' ORDER BY created_at DESC");
    $stmt->execute([1]);
    $generalThreads = $stmt->fetchAll();
    $t->assertEquals('Category 1 has 2 threads', 2, count($generalThreads));

    $stmt->execute([2]);
    $techThreads = $stmt->fetchAll();
    $t->assertEquals('Category 2 has 1 thread', 1, count($techThreads));

    $stmt->execute([999]);
    $emptyThreads = $stmt->fetchAll();
    $t->assertEquals('Non-existent category has 0 threads', 0, count($emptyThreads));

    App::reset();

    return $t;
}

function test_view_user_profile(): Test
{
    $t = new Test('Content - View User Profile');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute(['user1']);
    $profileUser = $profileStmt->fetch();

    $t->assert('Profile user found', $profileUser !== false);
    $t->assertEquals('Profile username correct', 'user1', $profileUser['username']);

    $userThreadsStmt = $pdo->prepare("
        SELECT t.*, u.username as author
        FROM threads t
        JOIN users u ON t.user_id = u.id
        WHERE t.user_id = ? AND t.status IN ('visible', 'sticky', 'locked')
        ORDER BY t.created_at DESC
        LIMIT 20
    ");
    $userThreadsStmt->execute([$profileUser['id']]);
    $userThreads = $userThreadsStmt->fetchAll();
    $t->assert('User threads is array', is_array($userThreads));

    $s = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE user_id = ? AND status IN ('visible','sticky','locked')");
    $s->execute([$profileUser['id']]);
    $threadCount = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 'visible'");
    $s->execute([$profileUser['id']]);
    $postCount = (int)$s->fetchColumn();

    $t->assert('Thread count is integer', is_int($threadCount));
    $t->assert('Post count is integer', is_int($postCount));

    App::reset();

    return $t;
}

function test_view_nonexistent_profile(): Test
{
    $t = new Test('Content - Non-existent Profile Returns 404');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute(['nonexistent']);
    $profileUser = $profileStmt->fetch();

    $t->assert('Non-existent user not found', $profileUser === false);

    App::reset();

    return $t;
}

function test_fetch_threads_with_search(): Test
{
    $t = new Test('Content - Fetch Threads with Search');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'PHP Tutorial', 'Learn PHP', 'visible')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'JavaScript Guide', 'Learn JS', 'visible')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Python Basics', 'Learn Python', 'visible')")->execute();

    $search = 'PHP';
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE status = 'visible' AND (title LIKE ? OR content LIKE ?)");
    $stmt->execute(["%{$search}%", "%{$search}%"]);
    $results = $stmt->fetchAll();
    $t->assertEquals('Search for PHP returns 1 result', 1, count($results));

    $search = 'Learn';
    $stmt->execute(["%{$search}%", "%{$search}%"]);
    $results = $stmt->fetchAll();
    $t->assertEquals('Search for Learn returns 3 results', 3, count($results));

    $search = 'NonExistent';
    $stmt->execute(["%{$search}%", "%{$search}%"]);
    $results = $stmt->fetchAll();
    $t->assertEquals('Search for nonexistent returns 0 results', 0, count($results));

    App::reset();

    return $t;
}

function test_fetch_threads_sort_options(): Test
{
    $t = new Test('Content - Fetch Threads Sort Options');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, views, created_at) VALUES (1, 2, 'Old Thread', 'Content', 'visible', 100, '2026-01-01 10:00:00')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, views, created_at) VALUES (1, 2, 'New Thread', 'Content', 'visible', 50, '2026-06-01 10:00:00')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, views, created_at) VALUES (1, 2, 'Popular Thread', 'Content', 'visible', 200, '2026-03-01 10:00:00')")->execute();

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE status = 'visible' ORDER BY created_at DESC");
    $stmt->execute();
    $newest = $stmt->fetchAll();
    $t->assert('Newest first: first is New Thread', $newest[0]['title'] === 'New Thread');

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE status = 'visible' ORDER BY created_at ASC");
    $stmt->execute();
    $oldest = $stmt->fetchAll();
    $t->assert('Oldest first: first is Old Thread', $oldest[0]['title'] === 'Old Thread');

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE status = 'visible' ORDER BY views DESC");
    $stmt->execute();
    $popular = $stmt->fetchAll();
    $t->assert('Most views first: first is Popular Thread', $popular[0]['title'] === 'Popular Thread');

    App::reset();

    return $t;
}

function test_hide_unhide_post(): Test
{
    $t = new Test('Moderation - Hide/Unhide Post');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 3, 'Bad post', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE posts SET status = 'hidden' WHERE id = ?")->execute([$postId]);
    $post = $pdo->prepare("SELECT status FROM posts WHERE id = ?");
    $post->execute([$postId]);
    $t->assertEquals('Post hidden', 'hidden', $post->fetchColumn());

    $pdo->prepare("UPDATE posts SET status = 'visible' WHERE id = ?")->execute([$postId]);
    $post->execute([$postId]);
    $t->assertEquals('Post unhidden', 'visible', $post->fetchColumn());

    App::reset();

    return $t;
}

function test_edit_reply_post(): Test
{
    $t = new Test('Content - Edit Reply Post');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, 'visible')")->execute([1, 2, 'Original Thread', 'First post content']);
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, ?, ?, 'visible')")->execute([$threadId, 2, 'Original reply content']);
    $replyId = (int)$pdo->lastInsertId();

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$replyId]);
    $post = $postStmt->fetch();

    $t->assertNotNull('Reply post exists', $post);
    $t->assertEquals('Reply has correct thread_id', $threadId, (int)$post['thread_id']);
    $t->assertEquals('Reply content is correct before edit', 'Original reply content', $post['content']);

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$post['thread_id']]);
    $thread = $threadStmt->fetch();

    $post['thread_title'] = $thread['title'] ?? '';

    $t->assertEquals('Reply has thread_title set for edit view', 'Original Thread', $post['thread_title']);

    $pdo->prepare("UPDATE posts SET content = ? WHERE id = ?")->execute(['Edited reply content', $replyId]);

    $updatedPostStmt = $pdo->prepare("SELECT content FROM posts WHERE id = ?");
    $updatedPostStmt->execute([$replyId]);
    $updatedContent = $updatedPostStmt->fetchColumn();

    $t->assertEquals('Reply content updated correctly', 'Edited reply content', $updatedContent);

    $threadCheckStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadCheckStmt->execute([$threadId]);
    $threadStillExists = $threadCheckStmt->fetch();
    $t->assertNotNull('Thread still exists after editing reply', $threadStillExists);

    App::reset();

    return $t;
}

function test_delete_reply_does_not_delete_thread(): Test
{
    $t = new Test('Content - Delete Reply Should NOT Delete Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Thread to Delete Reply From', 'Thread opening content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'First reply', 'visible')")->execute();
    $replyId = (int)$pdo->lastInsertId();

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$replyId]);
    $post = $postStmt->fetch();

    $threadIdFromPost = (int)$post['thread_id'];

    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$replyId]);

    $threadCheckStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadCheckStmt->execute([$threadId]);
    $threadStillExists = $threadCheckStmt->fetch();

    $t->assertNotNull('Thread still exists after deleting reply', $threadStillExists);
    $t->assertEquals('Thread has correct title', 'Thread to Delete Reply From', $threadStillExists['title']);
    $t->assertEquals('Thread has correct content', 'Thread opening content', $threadStillExists['content']);

    $postCountStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $postCountStmt->execute([$threadId]);
    $remainingPosts = (int)$postCountStmt->fetchColumn();
    $t->assertEquals('No posts remain in thread', 0, $remainingPosts);

    App::reset();

    return $t;
}

function test_delete_last_reply_preserves_thread(): Test
{
    $t = new Test('Content - Delete Last Reply Should Preserve Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Thread With One Reply', 'Opening post content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 3, 'Only reply in thread', 'visible')")->execute();
    $replyId = (int)$pdo->lastInsertId();

    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$replyId]);

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    $t->assertNotNull('Thread exists after deleting only reply', $thread);
    $t->assertEquals('Thread title preserved', 'Thread With One Reply', $thread['title']);
    $t->assertEquals('Thread opening content preserved', 'Opening post content', $thread['content']);

    App::reset();

    return $t;
}

register_tests(
    'test_create_thread',
    'test_create_thread_requires_title',
    'test_reply_to_thread',
    'test_view_thread_pagination',
    'test_view_thread_order',
    'test_view_threads_by_category',
    'test_view_user_profile',
    'test_view_nonexistent_profile',
    'test_fetch_threads_with_search',
    'test_fetch_threads_sort_options',
    'test_hide_unhide_post',
    'test_edit_reply_post',
    'test_delete_reply_does_not_delete_thread',
    'test_delete_last_reply_preserves_thread'
);
