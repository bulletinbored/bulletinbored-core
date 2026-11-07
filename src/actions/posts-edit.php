<?php

function handle_reply_post(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        throw new \Bulletin\UnauthorizedException();
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->can($userId, 'posts.create')) {
        throw new \Bulletin\ForbiddenException('Not authorized to create replies');
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    if (!rate_limit('reply', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are posting too fast. Please try again later.');
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
    $content = validate_input($_POST['content'] ?? '');

    if ($threadId <= 0 || $content === '') {
        throw new \Bulletin\ValidationException(['content' => 'Content is required']);
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        throw new \Bulletin\NotFoundException('Thread not found');
    }

    if ($thread['status'] === 'locked' && !$authz->can((int)$_SESSION['user_id'], 'threads.lock')) {
        throw new \Bulletin\ForbiddenException('Thread is locked');
    }

    if (!can_view_thread($thread['status'])) {
        throw new \Bulletin\ForbiddenException('Not authorized to reply to this thread');
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
            throw new \Bulletin\ForbiddenException('Post creation blocked');
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO posts (thread_id, user_id, content, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$postData['thread_id'], $postData['user_id'], $postData['content'], $postData['created_at']]);
    $postId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE threads SET updated_at = ? WHERE id = ?")->execute([$postData['created_at'], $threadId]);

    if (isset($pluginManager)) {
        $pluginManager->runHook('post_after_create', $postId, $postData, $thread);
    }

    notify_thread_reply($thread, (int)$_SESSION['user_id'], $content);
    notify_mentioned_users(
        $pdo,
        $content,
        $threadId,
        $thread['title'] ?? '',
        (string)($_SESSION['username'] ?? ''),
        (int)$_SESSION['user_id']
    );

    return redirect(url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]));
}

function handle_edit_post(string $method, array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        throw new \Bulletin\UnauthorizedException();
    }

    $postId = (int)($params['id'] ?? $_POST['post_id'] ?? $_GET['id'] ?? 0);
    if ($postId <= 0) {
        throw new \Bulletin\NotFoundException('Post not found');
    }

    if (!rate_limit('edit_post', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are editing too fast. Please try again later.');
    }

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        throw new \Bulletin\NotFoundException('Post not found');
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$post['thread_id']]);
    $thread = $threadStmt->fetch();
    $post['thread_title'] = $thread['title'] ?? '';

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
            $sqlParams = [];
            foreach ($updateData as $col => $val) {
                $setParts[] = "{$col} = ?";
                $sqlParams[] = $val;
            }
            $sqlParams[] = $postId;
            $pdo->prepare("UPDATE posts SET " . implode(', ', $setParts) . " WHERE id = ?")->execute($sqlParams);

            if (isset($pluginManager)) {
                $pluginManager->runHook('post_after_update', $postId, $updateData, $post);
            }
        }

        $slug = slugify($post['thread_title'] ?? '');
        $redirectUrl = $slug !== 'n-a'
            ? url('thread', ['id' => $post['thread_id'], 'slug' => $slug])
            : url('thread', ['id' => $post['thread_id']]);
        return redirect($redirectUrl);
    }

    $editThreadTitle = false;
    include __DIR__ . '/../../views/edit_post.php';
    return true;
}

function handle_delete_post(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        throw new \Bulletin\UnauthorizedException();
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    if (!rate_limit('delete_post', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are deleting too fast. Please try again later.');
    }

    $postId = (int)($params['id'] ?? $_GET['id'] ?? 0);
    if ($postId <= 0) {
        throw new \Bulletin\NotFoundException('Post not found');
    }

    $postStmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch();

    if (!$post) {
        throw new \Bulletin\NotFoundException('Post not found');
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

    return redirect(url('thread', ['id' => $threadId]));
}

function handle_edit_thread(string $method, array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        throw new \Bulletin\UnauthorizedException();
    }

    $threadId = (int)($params['id'] ?? $_GET['id'] ?? 0);
    if ($threadId <= 0) {
        throw new \Bulletin\NotFoundException('Thread not found');
    }

    if (!rate_limit('edit_thread', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are editing too fast. Please try again later.');
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        throw new \Bulletin\NotFoundException('Thread not found');
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

        $slug = slugify($thread['title'] ?? '');
        $redirectUrl = $slug !== 'n-a'
            ? url('thread', ['id' => $threadId, 'slug' => $slug])
            : url('thread', ['id' => $threadId]);
        return redirect($redirectUrl);
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
        throw new \Bulletin\UnauthorizedException();
    }

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    if (!rate_limit('delete_thread', 10, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are deleting threads too fast. Please try again later.');
    }

    $threadId = (int)($params['id'] ?? $_GET['id'] ?? 0);
    if ($threadId <= 0) {
        throw new \Bulletin\NotFoundException('Thread not found');
    }

    $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread) {
        throw new \Bulletin\NotFoundException('Thread not found');
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
