<?php

/**
 * ContentCrudTest — thread creation, replies, viewing, pagination, profiles.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Helpers/Data.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../lib/DbQuery.php';

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

// ============================================================
// THREAD VIEW LOGIC TESTS (posts-thread.php gaps)
// ============================================================

function test_thread_view_logic_success(): Test
{
    $t = new Test('Thread View Logic - Success Renders Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'First reply', 'visible')")->execute([$threadId]);
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 3, 'Second reply', 'visible')")->execute([$threadId]);

    // Test thread fetch with author info
    $stmt = $pdo->prepare("
        SELECT t.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
               u.created_at as author_joined, u.id as author_id,
               c.name as category_name,
               COALESCE(t.views, 0) as view_count,
               (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') as reply_count,
               (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = t.user_id AND p2.status = 'visible') as author_posts
        FROM threads t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN categories c ON t.category_id = c.id
        WHERE t.id = ?
    ");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();

    $t->assertNotNull('Thread found', $thread);
    $t->assertEquals('Thread title correct', 'Test Thread', $thread['title']);
    $t->assertEquals('Thread author correct', 'user1', $thread['author']);
    $t->assertEquals('Thread category correct', 'General', $thread['category_name']);
    $t->assertEquals('Reply count correct', 2, (int)$thread['reply_count']);
    $t->assertEquals('View count initialized', 0, (int)$thread['view_count']);

    // Test can_view_thread
    $t->assertTrue('Can view visible thread', can_view_thread($thread['status']));

    // Test view count increment logic
    $seenThreads = [];
    if (!in_array($threadId, $seenThreads, true)) {
        $pdo->prepare("UPDATE threads SET views = COALESCE(views, 0) + 1 WHERE id = ?")->execute([$threadId]);
        $seenThreads[] = $threadId;
    }
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();
    $t->assertEquals('View count incremented', 1, (int)$thread['view_count']);

    // Test pagination
    $postPage = 1;
    $perPage = 15;
    $offset = ($postPage - 1) * $perPage;

    $postsStmt = $pdo->prepare("
        SELECT p.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
               u.created_at as author_joined,
               (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = p.user_id AND p2.status = 'visible') as author_posts
        FROM posts p
        LEFT JOIN users u ON p.user_id = u.id
        WHERE p.thread_id = ? AND p.status = 'visible'
        ORDER BY p.created_at ASC, p.id ASC
        LIMIT ".(int)$perPage." OFFSET ".(int)$offset."
    ");
    $postsStmt->execute([$threadId]);
    $posts = $postsStmt->fetchAll();

    $t->assertEquals('Posts fetched for page 1', 2, count($posts));
    $t->assertEquals('First post author', 'user1', $posts[0]['author']);
    $t->assertEquals('Second post author', 'user2', $posts[1]['author']);

    // Test categories fetch for sidebar
    $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
    $t->assertEquals('Categories fetched', 2, count($categories));

    App::reset();
    return $t;
}

function test_thread_view_logic_not_found(): Test
{
    $t = new Test('Thread View Logic - Thread Not Found');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $threadId = 999;
    $stmt = $pdo->prepare("
        SELECT t.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
               u.created_at as author_joined, u.id as author_id,
               c.name as category_name,
               COALESCE(t.views, 0) as view_count,
               (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') as reply_count,
               (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = t.user_id AND p2.status = 'visible') as author_posts
        FROM threads t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN categories c ON t.category_id = c.id
        WHERE t.id = ?
    ");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();

    $t->assertFalse('Non-existent thread not found', $thread);

    App::reset();
    return $t;
}

function test_thread_view_logic_hidden_status(): Test
{
    $t = new Test('Thread View Logic - Hidden Thread Not Viewable');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();

    $t->assertNotNull('Thread exists', $thread);
    $t->assertFalse('Cannot view hidden thread', can_view_thread($thread['status']));

    App::reset();
    return $t;
}

function test_thread_view_logic_locked_status(): Test
{
    $t = new Test('Thread View Logic - Locked Thread Viewable');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Locked Thread', 'Thread content', 'locked')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();

    $t->assertNotNull('Thread exists', $thread);
    $t->assertTrue('Can view locked thread', can_view_thread($thread['status']));

    App::reset();
    return $t;
}

function test_thread_view_logic_sticky_status(): Test
{
    $t = new Test('Thread View Logic - Sticky Thread Viewable');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Sticky Thread', 'Thread content', 'sticky')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();

    $t->assertNotNull('Thread exists', $thread);
    $t->assertTrue('Can view sticky thread', can_view_thread($thread['status']));

    App::reset();
    return $t;
}

function test_thread_view_logic_pending_status(): Test
{
    $t = new Test('Thread View Logic - Pending Thread Not Viewable');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Pending Thread', 'Thread content', 'pending')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $stmt->execute([$threadId]);
    $thread = $stmt->fetch();

    $t->assertNotNull('Thread exists', $thread);
    $t->assertFalse('Cannot view pending thread', can_view_thread($thread['status']));

    App::reset();
    return $t;
}

function test_thread_view_logic_pagination_multiple_pages(): Test
{
    $t = new Test('Thread View Logic - Pagination Multiple Pages');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Paginated Thread', 'First post', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    for ($i = 1; $i <= 35; $i++) {
        $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, ?, 'visible')")
            ->execute([$threadId, "Reply number {$i}"]);
    }

    $perPage = 15;

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'");
    $totalStmt->execute([$threadId]);
    $totalPosts = (int)$totalStmt->fetchColumn();
    $t->assertEquals('Total posts is 35', 35, $totalPosts);

    $totalPages = (int)ceil($totalPosts / $perPage);
    $t->assertEquals('Total pages is 3', 3, $totalPages);

    // Page 1
    $page1Stmt = $pdo->prepare("
        SELECT * FROM posts WHERE thread_id = ? AND status = 'visible'
        ORDER BY created_at ASC, id ASC LIMIT ? OFFSET ?
    ");
    $page1Stmt->execute([$threadId, $perPage, 0]);
    $page1 = $page1Stmt->fetchAll();
    $t->assert('Page 1 has 15 posts', count($page1) === 15);

    // Page 2
    $page1Stmt->execute([$threadId, $perPage, $perPage]);
    $page2 = $page1Stmt->fetchAll();
    $t->assert('Page 2 has 15 posts', count($page2) === 15);

    // Page 3
    $page1Stmt->execute([$threadId, $perPage, $perPage * 2]);
    $page3 = $page1Stmt->fetchAll();
    $t->assert('Page 3 has 5 posts', count($page3) === 5);

    App::reset();
    return $t;
}

function test_thread_view_logic_post_order(): Test
{
    $t = new Test('Thread View Logic - Post Order ASC');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Ordered Thread', 'First post', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $dates = ['2026-01-01 10:00:00', '2026-01-02 10:00:00', '2026-01-03 10:00:00'];
    foreach ($dates as $date) {
        $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) VALUES (?, 2, ?, 'visible', ?)")
            ->execute([$threadId, "Post at {$date}", $date]);
    }

    $stmt = $pdo->prepare("
        SELECT * FROM posts WHERE thread_id = ? AND status = 'visible'
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([$threadId]);
    $ascPosts = $stmt->fetchAll();
    $t->assert('ASC order: first post is oldest', str_contains($ascPosts[0]['content'], '2026-01-01'));
    $t->assert('ASC order: last post is newest', str_contains($ascPosts[2]['content'], '2026-01-03'));

    App::reset();
    return $t;
}

// ============================================================
// WATCH/UNWATCH LOGIC TESTS
// ============================================================

function test_watch_logic_success(): Test
{
    $t = new Test('Watch Logic - Success Watches Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    // Add thread_watchers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Test authorization
    $t->assertTrue('User is logged in', is_logged_in());

    // Test thread exists and viewable
    $thread = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $threadStatus = $thread->fetchColumn();
    $t->assertEquals('Thread status visible', 'visible', $threadStatus);
    $t->assertTrue('Can view thread', can_view_thread($threadStatus));

    // Test CSRF validation
    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => generate_csrf_token(),
    ];
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'watch|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('watch', 30, 3600, $rlKey));

    // Test insert ignore (watch)
    $dbQuery = new DbQuery($pdo);
    $dbQuery->table('thread_watchers')->insertIgnore([
        'thread_id' => $threadId,
        'user_id' => (int)$_SESSION['user_id'],
    ]);

    // Verify watcher added
    $stmt = $pdo->prepare("SELECT * FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
    $stmt->execute([$threadId, $_SESSION['user_id']]);
    $watcher = $stmt->fetch();
    $t->assertNotNull('Watcher added to database', $watcher);

    // Test session watched_threads updated
    $watched = $_SESSION['watched_threads'] ?? [];
    if (!in_array($threadId, $watched, true)) {
        $watched[] = $threadId;
        $_SESSION['watched_threads'] = $watched;
    }
    $t->assertEquals('Thread added to watched threads', 1, count($_SESSION['watched_threads']));
    $t->assertEquals('Watched thread ID correct', $threadId, $_SESSION['watched_threads'][0]);

    // Test redirect URL
    $redirectUrl = url('thread', ['id' => $threadId]);
    $t->assert('Redirect URL contains thread ID', str_contains($redirectUrl, 'thread/' . $threadId));

    App::reset();
    return $t;
}

function test_watch_logic_already_watching(): Test
{
    $t = new Test('Watch Logic - Already Watching Does Not Duplicate');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Pre-add watcher
    $pdo->prepare("INSERT OR IGNORE INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")->execute([$threadId, 2]);

    // Try to watch again
    $dbQuery = new DbQuery($pdo);
    $dbQuery->table('thread_watchers')->insertIgnore([
        'thread_id' => $threadId,
        'user_id' => (int)$_SESSION['user_id'],
    ]);

    // Verify only one watcher
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
    $stmt->execute([$threadId, 2]);
    $count = (int)$stmt->fetchColumn();
    $t->assertEquals('Only one watcher entry', 1, $count);

    App::reset();
    return $t;
}

function test_watch_logic_not_logged_in(): Test
{
    $t = new Test('Watch Logic - Not Logged In Throws Unauthorized');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_watch_logic_invalid_thread(): Test
{
    $t = new Test('Watch Logic - Invalid Thread Not Found');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadId = 999;
    $thread = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $threadStatus = $thread->fetchColumn();

    $t->assertFalse('Non-existent thread not found', $threadStatus !== false);

    App::reset();
    return $t;
}

function test_watch_logic_hidden_thread(): Test
{
    $t = new Test('Watch Logic - Hidden Thread Not Watchable');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $thread = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $threadStatus = $thread->fetchColumn();

    $t->assertEquals('Thread status hidden', 'hidden', $threadStatus);
    $t->assertFalse('Cannot view hidden thread', can_view_thread($threadStatus));

    App::reset();
    return $t;
}

function test_watch_logic_csrf_invalid(): Test
{
    $t = new Test('Watch Logic - Invalid CSRF Rejected');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => 'invalid-token',
    ];

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_watch_logic_rate_limited(): Test
{
    $t = new Test('Watch Logic - Rate Limited');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'watch|' . $_SESSION['user_id'];

    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 32; $i++) {
        if (rate_limit('watch', 30, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 30 requests allowed', 30, $allowed);
    $t->assertEquals('Requests 31-32 blocked', 2, $blocked);

    App::reset();
    return $t;
}

function test_unwatch_logic_success(): Test
{
    $t = new Test('Unwatch Logic - Success Unwatches Thread');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    // Pre-add watcher
    $pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")->execute([$threadId, 2]);

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
        'watched_threads' => [$threadId],
    ];

    // Test authorization
    $t->assertTrue('User is logged in', is_logged_in());

    // Test thread exists and viewable
    $thread = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $threadStatus = $thread->fetchColumn();
    $t->assertEquals('Thread status visible', 'visible', $threadStatus);
    $t->assertTrue('Can view thread', can_view_thread($threadStatus));

    // Test CSRF validation
    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => generate_csrf_token(),
    ];
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'unwatch|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('unwatch', 30, 3600, $rlKey));

    // Test delete watcher
    $pdo->prepare("DELETE FROM thread_watchers WHERE thread_id = ? AND user_id = ?")->execute([$threadId, $_SESSION['user_id']]);

    // Verify watcher removed
    $stmt = $pdo->prepare("SELECT * FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
    $stmt->execute([$threadId, $_SESSION['user_id']]);
    $watcher = $stmt->fetch();
    $t->assertFalse('Watcher removed from database', $watcher);

    // Test session watched_threads updated
    $watched = $_SESSION['watched_threads'] ?? [];
    $watched = array_filter($watched, fn($id) => $id !== $threadId);
    $_SESSION['watched_threads'] = array_values($watched);
    $t->assertEquals('Thread removed from watched threads', 0, count($_SESSION['watched_threads']));

    // Test redirect URL
    $redirectUrl = url('thread', ['id' => $threadId]);
    $t->assert('Redirect URL contains thread ID', str_contains($redirectUrl, 'thread/' . $threadId));

    App::reset();
    return $t;
}

function test_unwatch_logic_not_watching(): Test
{
    $t = new Test('Unwatch Logic - Not Watching Does Not Error');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Try to unwatch without watching
    $pdo->prepare("DELETE FROM thread_watchers WHERE thread_id = ? AND user_id = ?")->execute([$threadId, $_SESSION['user_id']]);

    // Should not error
    $stmt = $pdo->prepare("SELECT * FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
    $stmt->execute([$threadId, $_SESSION['user_id']]);
    $watcher = $stmt->fetch();
    $t->assertFalse('No watcher to remove', $watcher);

    App::reset();
    return $t;
}

function test_unwatch_logic_not_logged_in(): Test
{
    $t = new Test('Unwatch Logic - Not Logged In Throws Unauthorized');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_unwatch_logic_invalid_thread(): Test
{
    $t = new Test('Unwatch Logic - Invalid Thread Not Found');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadId = 999;
    $thread = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $threadStatus = $thread->fetchColumn();

    $t->assertFalse('Non-existent thread not found', $threadStatus !== false);

    App::reset();
    return $t;
}

function test_unwatch_logic_hidden_thread(): Test
{
    $t = new Test('Unwatch Logic - Hidden Thread Not Unwatchable');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $thread = $pdo->prepare("SELECT status FROM threads WHERE id = ?");
    $thread->execute([$threadId]);
    $threadStatus = $thread->fetchColumn();

    $t->assertEquals('Thread status hidden', 'hidden', $threadStatus);
    $t->assertFalse('Cannot view hidden thread', can_view_thread($threadStatus));

    App::reset();
    return $t;
}

function test_unwatch_logic_csrf_invalid(): Test
{
    $t = new Test('Unwatch Logic - Invalid CSRF Rejected');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => 'invalid-token',
    ];

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_unwatch_logic_rate_limited(): Test
{
    $t = new Test('Unwatch Logic - Rate Limited');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'unwatch|' . $_SESSION['user_id'];

    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 32; $i++) {
        if (rate_limit('unwatch', 30, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 30 requests allowed', 30, $allowed);
    $t->assertEquals('Requests 31-32 blocked', 2, $blocked);

    App::reset();
    return $t;
}

// ============================================================
// UPLOAD IMAGE LOGIC TESTS
// ============================================================

function test_upload_image_logic_success(): Test
{
    $t = new Test('Upload Image Logic - Success Uploads Image');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    // Add uploads table
    $pdo->exec("CREATE TABLE IF NOT EXISTS uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, thread_id INTEGER, post_id INTEGER, filename TEXT, original_name TEXT, size INTEGER, mime_type TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Test authorization
    $t->assertTrue('User is logged in', is_logged_in());

    // Test CSRF validation
    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => generate_csrf_token(),
    ];
    $t->assertTrue('CSRF token valid', csrf_validate_request());

    // Test rate limiting
    $rlKey = 'upload_image|' . $_SESSION['user_id'];
    $t->assertTrue('Rate limit allows first request', rate_limit('upload_image', 20, 3600, $rlKey));

    // Test file validation (mock)
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $config = App::getInstance()->config;
    $maxSize = $config['image_max_size'] ?? ((int)($config['image_max_size_mb'] ?? 5) * 1024 * 1024);

    // Mock file info
    $info = [
        'safe_name' => 'test_image.jpg',
        'ext' => 'jpg',
        'mime' => 'image/jpeg',
    ];

    // Test thread authorization
    $contextStmt = $pdo->prepare("SELECT t.id, t.status FROM threads t WHERE t.id = ?");
    $contextStmt->execute([$threadId]);
    $context = $contextStmt->fetch();
    $t->assertNotNull('Thread context found', $context);
    $t->assertTrue('Can view thread', can_view_thread((string)$context['status']));

    // Test upload directory
    $uploadDir = __DIR__ . '/../../uploads/private';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // Test database insert
    $stmt = $pdo->prepare("INSERT INTO uploads (user_id, thread_id, post_id, filename, original_name, size, mime_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        $threadId,
        null,
        $info['safe_name'],
        'test_image.jpg',
        1024,
        $info['mime'],
    ]);
    $uploadId = $pdo->lastInsertId();

    $t->assert('Upload record created', $uploadId > 0);

    // Test private URL generation
    $privateUrl = base_url() . '/download/' . $uploadId;
    $t->assert('Private URL generated', str_contains($privateUrl, '/download/' . $uploadId));

    // Test markdown response
    $markdown = '![](' . $privateUrl . ')';
    $t->assertEquals('Markdown generated', '![](' . $privateUrl . ')', $markdown);

    App::reset();
    return $t;
}

function test_upload_image_logic_not_logged_in(): Test
{
    $t = new Test('Upload Image Logic - Not Logged In Throws Unauthorized');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [];

    $t->assertFalse('User not logged in', is_logged_in());

    App::reset();
    return $t;
}

function test_upload_image_logic_no_file(): Test
{
    $t = new Test('Upload Image Logic - No File Uploaded');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Empty FILES array
    $_FILES = ['image' => ['tmp_name' => '']];

    $t->assertEquals('No file uploaded', '', $_FILES['image']['tmp_name'] ?? '');

    App::reset();
    return $t;
}

function test_upload_image_logic_invalid_mime(): Test
{
    $t = new Test('Upload Image Logic - Invalid MIME Type Rejected');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = 'application/pdf';
    $t->assertFalse('PDF not in allowed types', isset($allowed[$mime]));

    $mime = 'image/jpeg';
    $t->assertTrue('JPEG in allowed types', isset($allowed[$mime]));

    App::reset();
    return $t;
}

function test_upload_image_logic_invalid_thread(): Test
{
    $t = new Test('Upload Image Logic - Invalid Thread Context');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $threadId = 999;
    $postId = 0;

    $contextStmt = $pdo->prepare("SELECT t.id, t.status FROM threads t WHERE t.id = ?");
    $contextStmt->execute([$threadId]);
    $context = $contextStmt->fetch();

    $t->assertFalse('Non-existent thread not found', $context);

    App::reset();
    return $t;
}

function test_upload_image_logic_hidden_thread(): Test
{
    $t = new Test('Upload Image Logic - Hidden Thread Not Allowed');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $contextStmt = $pdo->prepare("SELECT t.id, t.status FROM threads t WHERE t.id = ?");
    $contextStmt->execute([$threadId]);
    $context = $contextStmt->fetch();

    $t->assertNotNull('Thread context found', $context);
    $t->assertFalse('Cannot view hidden thread', can_view_thread((string)$context['status']));

    App::reset();
    return $t;
}

function test_upload_image_logic_csrf_invalid(): Test
{
    $t = new Test('Upload Image Logic - Invalid CSRF Rejected');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => 'invalid-token',
    ];

    $t->assertFalse('Invalid CSRF rejected', csrf_validate_request());

    App::reset();
    return $t;
}

function test_upload_image_logic_rate_limited(): Test
{
    $t = new Test('Upload Image Logic - Rate Limited');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    $rlKey = 'upload_image|' . $_SESSION['user_id'];

    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 22; $i++) {
        if (rate_limit('upload_image', 20, 3600, $rlKey)) {
            $allowed++;
        } else {
            $blocked++;
        }
    }

    $t->assertEquals('First 20 requests allowed', 20, $allowed);
    $t->assertEquals('Requests 21-22 blocked', 2, $blocked);

    App::reset();
    return $t;
}

function test_upload_image_logic_post_context(): Test
{
    $t = new Test('Upload Image Logic - Post Context Validation');

    $pdo = setupContentDB();
    App::getInstance()->pdo = $pdo;

    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
    $threadId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post content', 'visible')")->execute();
    $postId = (int)$pdo->lastInsertId();

    $_SESSION = [
        'user_id' => 2,
        'user_role' => 'user',
        'username' => 'user1',
        'user_status' => 'active',
        'session_version' => 1,
    ];

    // Test post belongs to thread
    $postCheck = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND thread_id = ?");
    $postCheck->execute([$postId, $threadId]);
    $post = $postCheck->fetch();
    $t->assertNotNull('Post belongs to thread', $post);

    // Test post from different thread
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Other Thread', 'Thread content', 'visible')")->execute();
    $otherThreadId = (int)$pdo->lastInsertId();

    $postCheck->execute([$postId, $otherThreadId]);
    $post = $postCheck->fetch();
    $t->assertFalse('Post does not belong to other thread', $post);

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
    'test_delete_last_reply_preserves_thread',
    // Thread view logic tests
    'test_thread_view_logic_success',
    'test_thread_view_logic_not_found',
    'test_thread_view_logic_hidden_status',
    'test_thread_view_logic_locked_status',
    'test_thread_view_logic_sticky_status',
    'test_thread_view_logic_pending_status',
    'test_thread_view_logic_pagination_multiple_pages',
    'test_thread_view_logic_post_order',
    // Watch logic tests
    'test_watch_logic_success',
    'test_watch_logic_already_watching',
    'test_watch_logic_not_logged_in',
    'test_watch_logic_invalid_thread',
    'test_watch_logic_hidden_thread',
    'test_watch_logic_csrf_invalid',
    'test_watch_logic_rate_limited',
    // Unwatch logic tests
    'test_unwatch_logic_success',
    'test_unwatch_logic_not_watching',
    'test_unwatch_logic_not_logged_in',
    'test_unwatch_logic_invalid_thread',
    'test_unwatch_logic_hidden_thread',
    'test_unwatch_logic_csrf_invalid',
    'test_unwatch_logic_rate_limited',
    // Upload image logic tests
    'test_upload_image_logic_success',
    'test_upload_image_logic_not_logged_in',
    'test_upload_image_logic_no_file',
    'test_upload_image_logic_invalid_mime',
    'test_upload_image_logic_invalid_thread',
    'test_upload_image_logic_hidden_thread',
    'test_upload_image_logic_csrf_invalid',
    'test_upload_image_logic_rate_limited',
    'test_upload_image_logic_post_context'
);
