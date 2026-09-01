<?php

function handle_new_thread(string $method): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $config = App::getInstance()->config;
    $pluginManager = App::getInstance()->pluginManager;
    $authz = App::getInstance()->authz;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    $userId = (int)$_SESSION['user_id'];
    if (!$authz->can($userId, 'threads.create')) {
        throw new \Bulletin\ForbiddenException('Not authorized to create threads');
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

        return redirect(url('thread', ['id' => $threadId, 'slug' => slugify($title)]));
    }

    include __DIR__ . '/../../views/new_thread.php';
    return true;
}
