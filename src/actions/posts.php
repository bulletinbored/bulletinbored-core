<?php

function handle_posts_action(string $action, string $method): bool
{
    switch ($action) {
        case 'thread':
            return $method === 'GET' ? handle_thread_view() : false;
        case 'new_thread':
            return handle_new_thread($method);
        case 'reply':
            return $method === 'POST' ? handle_reply_post() : false;
        case 'edit_post':
            return handle_edit_post($method);
        case 'delete_post':
            return $method === 'POST' ? handle_delete_post() : false;
        case 'edit_thread':
            return handle_edit_thread($method);
        case 'delete_thread':
            return $method === 'POST' ? handle_delete_thread() : false;
        case 'watch':
            return is_logged_in() ? handle_watch() : false;
        case 'unwatch':
            return is_logged_in() ? handle_unwatch() : false;
        default:
            return false;
    }
}

function handle_thread_view(): bool
{
    global $pdo;

    $threadId = (int)($_GET['id'] ?? 0);
    if ($threadId <= 0) {
        die('Thread not found');
    }

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

    if (!$thread) {
        die('Thread not found');
    }

    $seenThreads = $_SESSION['viewed_threads'] ?? [];
    if (!in_array($threadId, $seenThreads, true)) {
        try {
            $pdo->prepare("UPDATE threads SET views = COALESCE(views, 0) + 1 WHERE id = ?")->execute([$threadId]);
            $thread['view_count'] = (int)$thread['view_count'] + 1;
        } catch (PDOException $e) {}
        $seenThreads[] = $threadId;
        $_SESSION['viewed_threads'] = array_slice($seenThreads, -200);
    }

    $postPage = max(1, (int)($_GET['post_page'] ?? 1));
    $perPage = 15;
    $offset = ($postPage - 1) * $perPage;

    $totalStmt = $pdo->prepare("
        SELECT COUNT(*) FROM posts
        WHERE thread_id = ? AND status = 'visible'
    ");
    $totalStmt->execute([$threadId]);
    $totalPosts = $totalStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalPosts / $perPage));

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

    include __DIR__ . '/../../views/thread.php';
    return true;
}

function handle_new_thread(string $method): bool
{
    global $pdo, $config;
    if (!is_logged_in()) {
        die('Login required');
    }

    if ($method === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }

        if (!rate_limit('new_thread', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            http_response_code(429);
            die('You are posting too fast. Please try again later.');
        }

        $title = validate_input($_POST['title'] ?? '');
        $content = validate_input($_POST['content'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 1);

        $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $allowedRoles = $catStmt->fetchColumn();
        if ($allowedRoles && $allowedRoles !== 'all' && !is_admin()) {
            if ($allowedRoles === 'moderator' && !in_array($_SESSION['user_role'] ?? 'user', ['admin', 'moderator'], true)) {
                die(t('not_authorized_category'));
            } elseif ($allowedRoles === 'admin' && ($_SESSION['user_role'] ?? 'user') !== 'admin') {
                die(t('not_authorized_category'));
            } else {
                $allowed = json_decode($allowedRoles, true);
                if ($allowed && is_array($allowed) && !in_array($_SESSION['user_role'] ?? 'user', $allowed)) {
                    die(t('not_authorized_category'));
                }
            }
        }

        if ($title === '' || $content === '') {
            $_SESSION['new_thread_error'] = 'Title and content are required';
            $_SESSION['new_thread_title'] = $_POST['title'] ?? '';
            $_SESSION['new_thread_content'] = $_POST['content'] ?? '';
            $_SESSION['new_thread_category'] = $categoryId;
            include __DIR__ . '/../../views/new_thread.php';
            return true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO threads (category_id, user_id, title, content)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$categoryId, $_SESSION['user_id'], $title, $content]);
        $threadId = $pdo->lastInsertId();

        if (!empty($config['attachments_enabled']) && !empty($_FILES['attachments']['name'][0])) {
            // Attachment handling code would go here
        }

        redirect(url('thread', ['id' => $threadId, 'slug' => slugify($title)]));
    }

    include __DIR__ . '/../../views/new_thread.php';
    return true;
}

function handle_reply_post(): bool
{
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token invalid');
    }

    if (!rate_limit('reply', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        http_response_code(429);
        die('You are posting too fast. Please try again later.');
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
    $content = validate_input($_POST['content'] ?? '');

    if ($threadId <= 0 || $content === '') {
        $_SESSION['reply_error'] = 'Content is required';
        $_SESSION['reply_content'] = $_POST['content'] ?? '';
        redirect(url('thread', ['id' => $threadId]));
        return true;
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        die('Thread not found');
    }

    if ($thread['status'] === 'locked' && !is_admin()) {
        die('Thread is locked');
    }

    $stmt = $pdo->prepare("
        INSERT INTO posts (thread_id, user_id, content)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$threadId, $_SESSION['user_id'], $content]);
    $postId = $pdo->lastInsertId();

    notify_thread_reply($thread, $_SESSION['user_id'], $content);

    redirect(url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]));
    return true;
}

function handle_edit_post(string $method): bool
{
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) {
        redirect(url('home'));
    }

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        redirect(url('home'));
    }

    if ($post['user_id'] !== $_SESSION['user_id'] && !is_admin()) {
        die('Not authorized');
    }

    if ($method === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }

        $content = validate_input($_POST['content'] ?? '');
        if ($content !== '') {
            $pdo->prepare("UPDATE posts SET content = ? WHERE id = ?")
                ->execute([$content, $postId]);
        }

        $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $threadStmt->execute([$post['thread_id']]);
        $thread = $threadStmt->fetch();

        redirect(url('thread', ['id' => $post['thread_id'], 'slug' => slugify($thread['title'] ?? '')]));
    }

    include __DIR__ . '/../../views/edit_post.php';
    return true;
}

function handle_edit_thread(string $method): bool
{
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    $threadId = (int)($_GET['id'] ?? 0);
    if ($threadId <= 0) {
        redirect(url('home'));
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        redirect(url('home'));
    }

    if ($thread['user_id'] !== $_SESSION['user_id'] && !is_admin()) {
        die('Not authorized');
    }

    if ($method === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }

        $content = validate_input($_POST['content'] ?? '');
        if ($content !== '') {
            $pdo->prepare("UPDATE threads SET content = ? WHERE id = ?")
                ->execute([$content, $threadId]);
        }
        if (!empty($_POST['title'])) {
            $pdo->prepare("UPDATE threads SET title = ? WHERE id = ?")
                ->execute([validate_input($_POST['title']), $threadId]);
        }

        redirect(url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]));
    }

    $editThreadTitle = true;
    $post = $thread;
    $post['thread_id'] = $thread['id'];
    $post['thread_title'] = $thread['title'];

    include __DIR__ . '/../../views/edit_post.php';
    return true;
}

function handle_delete_post(): bool
{
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token invalid');
    }

    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) {
        redirect(url('home'));
    }

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        redirect(url('home'));
    }

    if ($post['user_id'] !== $_SESSION['user_id'] && !is_admin()) {
        die('Not authorized');
    }

    $threadId = $post['thread_id'];

    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $countStmt->execute([$threadId]);
    if (empty($countStmt->fetchColumn())) {
        $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $threadStmt->execute([$threadId]);
        $thread = $threadStmt->fetch();
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        redirect(url('category', ['id' => $thread['category_id']]));
    }

    redirect(url('thread', ['id' => $threadId]));
    return true;
}

function handle_delete_thread(): bool
{
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token invalid');
    }

    $threadId = (int)($_GET['id'] ?? 0);
    if ($threadId <= 0) {
        redirect(url('home'));
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        redirect(url('home'));
    }

    if ($thread['user_id'] !== $_SESSION['user_id'] && !is_admin()) {
        die('Not authorized');
    }

    $pdo->prepare("DELETE FROM posts WHERE thread_id = ?")->execute([$threadId]);
    $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);

    redirect(url('category', ['id' => $thread['category_id']]));
    return true;
}

function handle_watch(): bool
{
    global $pdo;

    $threadId = (int)($_GET['thread_id'] ?? 0);
    if ($threadId > 0 && is_logged_in()) {
        try {
            $pdo->prepare("INSERT OR IGNORE INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")
                ->execute([$threadId, $_SESSION['user_id']]);
        } catch (PDOException $e) {}
        $watched = $_SESSION['watched_threads'] ?? [];
        if (!in_array($threadId, $watched, true)) {
            $watched[] = $threadId;
            $_SESSION['watched_threads'] = $watched;
        }
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? url('thread', ['id' => $threadId]);
    redirect($referer);
    return true;
}

function handle_unwatch(): bool
{
    global $pdo;

    $threadId = (int)($_GET['thread_id'] ?? 0);
    if ($threadId > 0 && is_logged_in()) {
        try {
            $pdo->prepare("DELETE FROM thread_watchers WHERE thread_id = ? AND user_id = ?")
                ->execute([$threadId, $_SESSION['user_id']]);
        } catch (PDOException $e) {}
        $watched = $_SESSION['watched_threads'] ?? [];
        $watched = array_filter($watched, fn($id) => $id !== $threadId);
        $_SESSION['watched_threads'] = array_values($watched);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? url('thread', ['id' => $threadId]);
    redirect($referer);
    return true;
}
