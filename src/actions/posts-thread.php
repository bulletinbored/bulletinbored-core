<?php

function handle_upload_image(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    if (!is_logged_in()) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Login required'], 401);
    }
    if (!csrf_validate_request()) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'CSRF token invalid'], 403);
    }
    if (empty($_FILES['image']['tmp_name'])) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'No file uploaded'], 400);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $maxSize = App::getInstance()->config['image_max_size'] ?? 5 * 1024 * 1024;
    $info = validate_uploaded_file($_FILES['image']['tmp_name'], $_FILES['image']['name'] ?? '', $allowed, $maxSize);
    if ($info === null) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Invalid image'], 400);
    }

    $uploadDir = __DIR__ . '/../../uploads/private';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $dest = $uploadDir . '/' . $info['safe_name'];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Move failed'], 500);
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
    $postId = (int)($_POST['post_id'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO uploads (user_id, thread_id, post_id, filename, original_name, size, mime_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        $threadId > 0 ? $threadId : null,
        $postId > 0 ? $postId : null,
        $info['safe_name'],
        basename($_FILES['image']['name'] ?? 'image.' . $info['ext']),
        filesize($dest),
        $info['mime'],
    ]);

    $privateUrl = base_url() . '/download/' . $pdo->lastInsertId();
    return \Bulletin\Response::json(['ok' => true, 'url' => $privateUrl, 'markdown' => '![](' . $privateUrl . ')']);
}

function handle_thread_view(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;

    $threadId = (int)($params['id'] ?? $_POST['thread_id'] ?? $_GET['id'] ?? 0);
    if ($threadId <= 0) {
        return redirect(url('home'));
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
        if (isset($pluginManager)) {
            $fallback = $pluginManager->applyHook('thread_not_found', $threadId);
            if ($fallback !== null) {
                echo $fallback;
                return true;
            }
        }
        throw new \Bulletin\NotFoundException('Thread not found');
    }

    $threadStatus = $thread['status'] ?? 'visible';
    if (!can_view_thread($threadStatus)) {
        throw new \Bulletin\ForbiddenException('Thread not available');
    }

    if (isset($pluginManager)) {
        $thread = $pluginManager->filter('thread_before_view', $thread);
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

    if (isset($pluginManager)) {
        $posts = $pluginManager->filter('thread_posts_before_view', $posts, $thread);
    }

    $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();

    if (isset($pluginManager)) {
        $pluginManager->runHook('thread_before_render', $thread, $posts);
    }

    include __DIR__ . '/../../views/thread.php';

    if (isset($pluginManager)) {
        $pluginManager->runHook('thread_after_render', $thread, $posts);
    }

    return true;
}

function handle_watch(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        return redirect($_SERVER['HTTP_REFERER'] ?? url('home'));
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
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
    return redirect($referer);
}

function handle_unwatch(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        return redirect($_SERVER['HTTP_REFERER'] ?? url('home'));
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
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
    return redirect($referer);
}
