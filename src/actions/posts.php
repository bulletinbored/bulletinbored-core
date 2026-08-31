<?php

function handle_posts_action(string $action, string $method): \Bulletin\Response|bool
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
        case 'upload_image':
            return $method === 'POST' ? handle_upload_image() : false;
        default:
            return false;
    }
}

/**
 * Accept an image uploaded from the Markdown editor, validate it strictly with
 * validate_uploaded_file() (real MIME + getimagesize, never the extension),
 * store it under uploads/ and record it in the uploads table. Returns JSON.
 */
function handle_upload_image(): \Bulletin\Response|bool
{
    global $pdo;
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
    $maxSize = $GLOBALS['config']['image_max_size'] ?? 5 * 1024 * 1024;
    $info = validate_uploaded_file($_FILES['image']['tmp_name'], $_FILES['image']['name'] ?? '', $allowed, $maxSize);
    if ($info === null) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Invalid image'], 400);
    }

    $uploadDir = __DIR__ . '/../../uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $dest = $uploadDir . '/' . $info['safe_name'];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Move failed'], 500);
    }

    $stmt = $pdo->prepare("INSERT INTO uploads (user_id, filename, original_name, size, mime_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        $info['safe_name'],
        basename($_FILES['image']['name'] ?? 'image.' . $info['ext']),
        filesize($dest),
        $info['mime'],
    ]);

    $url = base_url() . '/uploads/' . $info['safe_name'];
    return \Bulletin\Response::json(['ok' => true, 'url' => $url, 'markdown' => '![](' . $url . ')']);
}

function handle_thread_view(): \Bulletin\Response|bool
{
    global $pdo, $pluginManager;

    $threadId = (int)($_POST['thread_id'] ?? $_GET['id'] ?? 0);
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

function handle_new_thread(string $method): \Bulletin\Response|bool
{
    global $pdo, $config, $pluginManager, $authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $_SESSION['new_thread_error'] = 'CSRF token invalid';
            include __DIR__ . '/../../views/new_thread.php';
            return true;
        }

        if (!rate_limit('new_thread', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            $_SESSION['new_thread_error'] = 'You are posting too fast. Please try again later.';
            include __DIR__ . '/../../views/new_thread.php';
            return true;
        }

        $title = clean_text($_POST['title'] ?? '');
        $content = validate_input($_POST['content'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 1);

        $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $allowedRoles = $catStmt->fetchColumn();
        $userId = (int)$_SESSION['user_id'];
        if ($allowedRoles && $allowedRoles !== 'all' && !$authz->can($userId, 'admin.access')) {
            if ($allowedRoles === 'moderator' && !$authz->can($userId, 'moderation.manage')) {
                throw new \Bulletin\ForbiddenException(t('not_authorized_category'));
            } elseif ($allowedRoles === 'admin' && !$authz->hasRole($userId, 'admin')) {
                throw new \Bulletin\ForbiddenException(t('not_authorized_category'));
            } else {
                $allowed = json_decode($allowedRoles, true);
                if ($allowed && is_array($allowed) && !in_array($_SESSION['user_role'] ?? 'user', $allowed)) {
                    throw new \Bulletin\ForbiddenException(t('not_authorized_category'));
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

        $threadData = [
            'category_id' => $categoryId,
            'user_id' => $_SESSION['user_id'],
            'title' => $title,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (isset($pluginManager)) {
            $threadData = $pluginManager->filter('thread_before_create', $threadData);
            if ($pluginManager->checkHook('thread_create_block', $threadData)) {
                $_SESSION['new_thread_error'] = t('thread_creation_blocked');
                include __DIR__ . '/../../views/new_thread.php';
                return true;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO threads (category_id, user_id, title, content, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$threadData['category_id'], $threadData['user_id'], $threadData['title'], $threadData['content'], $threadData['created_at']]);
        $threadId = $pdo->lastInsertId();

        if (isset($pluginManager)) {
            $pluginManager->runHook('thread_after_create', $threadId, $threadData);
        }

        if (!empty($config['attachments_enabled']) && !empty($_FILES['attachments']['name'][0])) {
            // Attachment handling code would go here
        }

        return redirect(url('thread', ['id' => $threadId, 'slug' => slugify($title)]));
    }

    include __DIR__ . '/../../views/new_thread.php';
    return true;
}

function handle_reply_post(): \Bulletin\Response|bool
{
    global $pdo, $pluginManager, $authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        $_SESSION['reply_error'] = 'CSRF token invalid';
        return redirect(url('thread', ['id' => (int)($_POST['thread_id'] ?? 0)]));
    }

    if (!rate_limit('reply', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        $_SESSION['reply_error'] = 'You are posting too fast. Please try again later.';
        return redirect(url('thread', ['id' => (int)($_POST['thread_id'] ?? 0)]));
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
    $content = validate_input($_POST['content'] ?? '');

    if ($threadId <= 0 || $content === '') {
        $_SESSION['reply_error'] = 'Content is required';
        $_SESSION['reply_content'] = $_POST['content'] ?? '';
        return redirect(url('thread', ['id' => $threadId]));
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        throw new \Bulletin\NotFoundException('Thread not found');
    }

    if ($thread['status'] === 'locked' && !$authz->can((int)$_SESSION['user_id'], 'threads.lock')) {
        $_SESSION['reply_error'] = 'Thread is locked';
        return redirect(url('thread', ['id' => $threadId]));
    }

    $postData = [
        'thread_id' => $threadId,
        'user_id' => $_SESSION['user_id'],
        'content' => $content,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if (isset($pluginManager)) {
        $postData = $pluginManager->filter('post_before_create', $postData, $thread);
        if ($pluginManager->checkHook('post_create_block', $postData, $thread)) {
            $_SESSION['reply_error'] = t('post_creation_blocked');
            return redirect(url('thread', ['id' => $threadId]));
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO posts (thread_id, user_id, content, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$postData['thread_id'], $postData['user_id'], $postData['content'], $postData['created_at']]);
    $postId = $pdo->lastInsertId();

    if (isset($pluginManager)) {
        $pluginManager->runHook('post_after_create', $postId, $postData, $thread);
    }

    notify_thread_reply($thread, $_SESSION['user_id'], $content);

    return redirect(url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]));
}

function handle_edit_post(string $method): \Bulletin\Response|bool
{
    global $pdo, $pluginManager, $authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    $postId = (int)($_POST['post_id'] ?? $_GET['id'] ?? 0);
    if ($postId <= 0) {
        return redirect(url('home'));
    }

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        return redirect(url('home'));
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->canOnOwned($userId, 'posts.edit', (int)$post['user_id'])) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }

    $editThreadTitle = false;

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }

        $content = validate_input($_POST['content'] ?? '');
        if ($content !== '') {
            $updateData = ['content' => $content];

            if (isset($pluginManager)) {
                $updateData = $pluginManager->filter('post_before_update', $updateData, $post);
            }

            $setParts = [];
            $params = [];
            foreach ($updateData as $col => $val) {
                $setParts[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = $postId;
            $pdo->prepare("UPDATE posts SET " . implode(', ', $setParts) . " WHERE id = ?")->execute($params);

            if (isset($pluginManager)) {
                $pluginManager->runHook('post_after_update', $postId, $updateData, $post);
            }
        }

        $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $threadStmt->execute([$post['thread_id']]);
        $thread = $threadStmt->fetch();

        return redirect(url('thread', ['id' => $post['thread_id'], 'slug' => slugify($thread['title'] ?? '')]));
    }

    include __DIR__ . '/../../views/edit_post.php';
    return true;
}

function handle_edit_thread(string $method): \Bulletin\Response|bool
{
    global $pdo, $pluginManager, $authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    $threadId = (int)($_GET['id'] ?? 0);
    if ($threadId <= 0) {
        return redirect(url('home'));
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        return redirect(url('home'));
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->canOnOwned($userId, 'threads.edit', (int)$thread['user_id'])) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }

        $content = validate_input($_POST['content'] ?? '');
        $updateData = [];
        if ($content !== '') {
            $updateData['content'] = $content;
        }
        if (!empty($_POST['title'])) {
            $updateData['title'] = clean_text($_POST['title']);
        }

        if (!empty($updateData)) {
            if (isset($pluginManager)) {
                $updateData = $pluginManager->filter('thread_before_update', $updateData, $thread);
            }

            $setParts = [];
            $params = [];
            foreach ($updateData as $col => $val) {
                $setParts[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = $threadId;
            $pdo->prepare("UPDATE threads SET " . implode(', ', $setParts) . " WHERE id = ?")->execute($params);

            if (isset($pluginManager)) {
                $pluginManager->runHook('thread_after_update', $threadId, $updateData, $thread);
            }
        }

        return redirect(url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]));
    }

    $editThreadTitle = true;
    $post = $thread;
    $post['thread_id'] = $thread['id'];
    $post['thread_title'] = $thread['title'];

    include __DIR__ . '/../../views/edit_post.php';
    return true;
}

function handle_delete_post(): \Bulletin\Response|bool
{
    global $pdo, $pluginManager, $authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) {
        return redirect(url('home'));
    }

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        return redirect(url('home'));
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->canOnOwned($userId, 'posts.delete', (int)$post['user_id'])) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }

    $threadId = $post['thread_id'];

    if (isset($pluginManager) && $pluginManager->checkHook('post_delete_block', $post)) {
        throw new \Bulletin\ForbiddenException(t('post_deletion_blocked'));
    }

    if (isset($pluginManager)) {
        $pluginManager->runHook('post_before_delete', $postId, $post);
    }

    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);

    if (isset($pluginManager)) {
        $pluginManager->runHook('post_after_delete', $postId, $threadId);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ?");
    $countStmt->execute([$threadId]);
    if ((int)$countStmt->fetchColumn() === 0) {
        $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $threadStmt->execute([$threadId]);
        $thread = $threadStmt->fetch();
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        return redirect(url('category', ['id' => $thread['category_id']]));
    }

    return redirect(url('thread', ['id' => $threadId]));
}

function handle_delete_thread(): \Bulletin\Response|bool
{
    global $pdo, $pluginManager, $authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    $threadId = (int)($_GET['id'] ?? 0);
    if ($threadId <= 0) {
        return redirect(url('home'));
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        return redirect(url('home'));
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->canOnOwned($userId, 'threads.delete', (int)$thread['user_id'])) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }

    if (isset($pluginManager) && $pluginManager->checkHook('thread_delete_block', $thread)) {
        throw new \Bulletin\ForbiddenException(t('thread_deletion_blocked'));
    }

    if (isset($pluginManager)) {
        $pluginManager->runHook('thread_before_delete', $threadId, $thread);
    }

    $pdo->prepare("DELETE FROM posts WHERE thread_id = ?")->execute([$threadId]);
    $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);

    if (isset($pluginManager)) {
        $pluginManager->runHook('thread_after_delete', $threadId, $thread);
    }

    return redirect(url('category', ['id' => $thread['category_id']]));
}

function handle_watch(): \Bulletin\Response|bool
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
    return redirect($referer);
}

function handle_unwatch(): \Bulletin\Response|bool
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
    return redirect($referer);
}
