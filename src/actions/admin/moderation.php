<?php

function handle_admin_moderation_get(): \Bulletin\Response|bool
{
    include __DIR__ . '/../../../views/admin_moderation.php';
    return true;
}

function handle_moderate_post(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $authz = App::getInstance()->authz;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    $threadId = (int)($params['id'] ?? $_POST['id'] ?? 0);
    $action = $_POST['do'] ?? '';

    if ($threadId <= 0) {
        throw new \Bulletin\NotFoundException('Invalid thread ID');
    }

    if ($action === 'approve') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_approve', ['thread_id' => $threadId]);
    } elseif ($action === 'hide') {
        $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_hide', ['thread_id' => $threadId]);
    } elseif ($action === 'delete') {
        $catIdStmt = $pdo->prepare("SELECT category_id FROM threads WHERE id = ?");
        $catIdStmt->execute([$threadId]);
        $catId = (int)($catIdStmt->fetchColumn() ?: 0);
        $pdo->prepare("DELETE FROM posts WHERE thread_id = ?")->execute([$threadId]);
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_delete', ['thread_id' => $threadId, 'category_id' => $catId]);
        if ($catId > 0) {
            return redirect(url('category', ['id' => $catId]));
        }
        return redirect(url('home'));
    }

    return redirect(url('admin_moderation'));
}

function handle_frontend_moderate_post(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $authz = App::getInstance()->authz;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $threadId = (int)($_POST['id'] ?? 0);
    $modAction = $_POST['do'] ?? '';
    if ($threadId <= 0) {
        throw new \Bulletin\NotFoundException('Invalid thread ID');
    }
    $userId = (int)$_SESSION['user_id'];
    if (!$authz->can($userId, 'moderation.manage')) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }
    if ($modAction === 'lock') {
        $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_lock', ['thread_id' => $threadId]);
    } elseif ($modAction === 'unlock') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_unlock', ['thread_id' => $threadId]);
    } elseif ($modAction === 'sticky') {
        $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_sticky', ['thread_id' => $threadId]);
    } elseif ($modAction === 'unsticky') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_unsticky', ['thread_id' => $threadId]);
    } elseif ($modAction === 'hide') {
        $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_hide', ['thread_id' => $threadId]);
    } elseif ($modAction === 'delete') {
        $catIdStmt = $pdo->prepare("SELECT category_id FROM threads WHERE id = ?");
        $catIdStmt->execute([$threadId]);
        $catId = (int)($catIdStmt->fetchColumn() ?: 0);
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_delete', ['thread_id' => $threadId, 'category_id' => $catId]);
        if ($catId > 0) {
            return redirect(url('category', ['id' => $catId]));
        }
        return redirect(url('home'));
    } elseif ($modAction === 'move') {
        $targetCat = (int)($_POST['category_id'] ?? 0);
        if ($targetCat > 0) {
            $pdo->prepare("UPDATE threads SET category_id = ? WHERE id = ?")->execute([$targetCat, $threadId]);
            log_admin_action('thread_move', ['thread_id' => $threadId, 'category_id' => $targetCat]);
        }
    } elseif ($modAction === 'copy') {
        $targetCat = (int)($_POST['category_id'] ?? 0);
        if ($targetCat > 0) {
            $src = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
            $src->execute([$threadId]);
            $srcThread = $src->fetch();
            if ($srcThread) {
                $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
                $ins->execute([$targetCat, $srcThread['user_id'], $srcThread['title'], $srcThread['content'], $srcThread['created_at']]);
                $newThreadId = (int)$pdo->lastInsertId();
                $postsStmt = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ? AND status = 'visible'");
                $postsStmt->execute([$threadId]);
                $posts = $postsStmt->fetchAll();
                $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) VALUES (?, ?, ?, 'visible', ?)");
                foreach ($posts as $post) {
                    $postIns->execute([$newThreadId, $post['user_id'], $post['content'], $post['created_at']]);
                }
                log_admin_action('thread_copy', ['thread_id' => $threadId, 'new_thread_id' => $newThreadId, 'category_id' => $targetCat]);
            }
        }
    }
    $threadTitleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
    $threadTitleStmt->execute([$threadId]);
    $threadTitle = $threadTitleStmt->fetchColumn();
    return redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle ?? '')]));
}

function handle_split_thread_post(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $authz = App::getInstance()->authz;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $threadId = (int)($_POST['thread_id'] ?? 0);
    $postIds = $_POST['post_ids'] ?? '';
    $newTitle = trim($_POST['new_title'] ?? '');
    if (!is_array($postIds)) {
        $postIds = array_filter(array_map('trim', explode(',', (string)$postIds)));
    }
    if ($threadId <= 0 || empty($postIds) || $newTitle === '') {
        throw new \Bulletin\ValidationException(['input' => 'Invalid input']);
    }
    $userId = (int)$_SESSION['user_id'];
    if (!$authz->can($userId, 'threads.split')) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }
    $srcThreadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $srcThreadStmt->execute([$threadId]);
    $srcThread = $srcThreadStmt->fetch();
    if (!$srcThread) {
        throw new \Bulletin\NotFoundException('Thread not found');
    }

    $intIds = array_map('intval', $postIds);
    $placeholders = implode(',', array_fill(0, count($intIds), '?'));
    $selStmt = $pdo->prepare("SELECT id, content, user_id, created_at FROM posts WHERE thread_id = ? AND id IN ($placeholders) ORDER BY created_at ASC, id ASC");
    $selStmt->execute(array_merge([$threadId], $intIds));
    $selPosts = $selStmt->fetchAll();
    if (empty($selPosts)) {
        throw new \Bulletin\ValidationException(['posts' => 'No valid posts selected']);
    }

    $firstPost = $selPosts[0];

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
        $ins->execute([$srcThread['category_id'], $firstPost['user_id'], $newTitle, $firstPost['content'], $firstPost['created_at']]);
        $newThreadId = (int)$pdo->lastInsertId();

        $replyIds = array_slice($intIds, 1);
        if (!empty($replyIds)) {
            $replyPlaceholders = implode(',', array_fill(0, count($replyIds), '?'));
            $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) SELECT ?, user_id, content, status, created_at FROM posts WHERE thread_id = ? AND id IN ($replyPlaceholders)");
            $postIns->execute(array_merge([$newThreadId, $threadId], $replyIds));
        }

        $delSql = "DELETE FROM posts WHERE thread_id = ? AND id IN ($placeholders)";
        $pdo->prepare($delSql)->execute(array_merge([$threadId], $intIds));

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'");
        $countStmt->execute([$threadId]);
        if (empty($countStmt->fetchColumn())) {
            $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'");
    $countStmt->execute([$threadId]);
    if (empty($countStmt->fetchColumn())) {
        return redirect(url('home'));
    }
    return redirect(url('thread', ['id' => $newThreadId, 'slug' => slugify($newTitle)]));
}

function handle_merge_thread_post(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $authz = App::getInstance()->authz;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $threadId = (int)($_POST['thread_id'] ?? 0);
    $targetTitle = trim($_POST['target_title'] ?? '');
    if ($threadId <= 0 || $targetTitle === '') {
        throw new \Bulletin\ValidationException(['input' => 'Invalid input']);
    }
    $userId = (int)$_SESSION['user_id'];
    if (!$authz->can($userId, 'threads.merge')) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }
    $targetThreadStmt = $pdo->prepare("SELECT * FROM threads WHERE title LIKE ? LIMIT 1");
    $targetThreadStmt->execute(["%$targetTitle%"]);
    $targetThread = $targetThreadStmt->fetch();
    if (!$targetThread) {
        throw new \Bulletin\NotFoundException('Target thread not found');
    }
    $targetThreadId = (int)$targetThread['id'];
    if ($threadId === $targetThreadId) {
        throw new \Bulletin\ConflictException('Cannot merge a thread into itself');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE posts SET thread_id = ? WHERE thread_id = ?")->execute([$targetThreadId, $threadId]);
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return redirect(url('thread', ['id' => $targetThreadId, 'slug' => slugify($targetThread['title'] ?? '')]));
}
