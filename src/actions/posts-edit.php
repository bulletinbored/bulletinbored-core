<?php

function handle_reply_post(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->can($userId, 'posts.create')) {
        throw new \Bulletin\ForbiddenException('Not authorized to create replies');
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

function handle_edit_post(string $method, array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    $postId = (int)($params['id'] ?? $_POST['post_id'] ?? $_GET['id'] ?? 0);
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

function handle_delete_post(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    $postId = (int)($params['id'] ?? $_GET['id'] ?? 0);
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

function handle_edit_thread(string $method, array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    $threadId = (int)($params['id'] ?? $_GET['id'] ?? 0);
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

function handle_delete_thread(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    $threadId = (int)($params['id'] ?? $_GET['id'] ?? 0);
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
