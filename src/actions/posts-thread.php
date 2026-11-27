<?php

require_once __DIR__ . '/../../lib/DbQuery.php';

function handle_upload_image(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    if (!is_logged_in()) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Login required'], 401);
    }
    if (!csrf_validate_request()) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'CSRF token invalid'], 403);
    }
    if (!rate_limit('upload_image', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'You are uploading too fast. Please try again later.'], 429);
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

    $threadId = (int)($_POST['thread_id'] ?? 0);
    $postId = (int)($_POST['post_id'] ?? 0);
    if ($threadId <= 0 && $postId <= 0) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Invalid attachment context'], 400);
    }

    // Authorization check BEFORE moving file
    $profileVisibilitySelect = bb_profile_visibility_select('u');
    $contextSql = "SELECT t.*, {$profileVisibilitySelect}
                   FROM threads t LEFT JOIN users u ON u.id = t.user_id
                   WHERE t.id = ?";
    $contextParams = [$threadId];
    if ($threadId <= 0) {
        // Verify post belongs to thread
        $contextSql = "SELECT t.*, {$profileVisibilitySelect}
                       FROM threads t
                       JOIN posts p ON p.thread_id = t.id
                       LEFT JOIN users u ON u.id = t.user_id
                       WHERE p.id = ?";
        $contextParams = [$postId];
    }

    $contextStmt = $pdo->prepare($contextSql);
    $contextStmt->execute($contextParams);
    $context = $contextStmt->fetch();
    if ($context === false
        || !can_view_thread((string)$context['status'])
        || !bb_can_view_thread($context)) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Thread not found'], 404);
    }

    // If post_id provided, verify it belongs to the thread
    if ($postId > 0 && $threadId > 0) {
        $postCheck = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND thread_id = ?");
        $postCheck->execute([$postId, $threadId]);
        if ($postCheck->fetch() === false) {
            return \Bulletin\Response::json(['ok' => false, 'error' => 'Post does not belong to thread'], 400);
        }
    }

    $uploadDir = function_exists('ensure_private_uploads_dir')
        ? ensure_private_uploads_dir()
        : __DIR__ . '/../../uploads/private';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $dest = $uploadDir . '/' . $info['safe_name'];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Move failed'], 500);
    }

    try {
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
    } catch (\Throwable $e) {
        // Cleanup uploaded file if DB insert fails
        if (file_exists($dest)) {
            @unlink($dest);
        }
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Database error'], 500);
    }

    $privateUrl = base_url() . '/download/' . $pdo->lastInsertId();
    return \Bulletin\Response::json(['ok' => true, 'url' => $privateUrl, 'markdown' => '![](' . $privateUrl . ')']);
}

/**
 * Load visible posts of a thread, optionally restricted to top-level comments
 * or to the direct children of a set of comments. The author metadata matches
 * the flat thread query so both render modes share the same post shape.
 *
 * @param int[]|null $parentIds
 */
function bb_fetch_thread_posts(PDO $pdo, int $threadId, ?array $parentIds = null, bool $rootsOnly = false, int $limit = 0, int $offset = 0): array
{
    $where = "p.thread_id = ? AND p.status = 'visible'";
    $params = [$threadId];

    if ($rootsOnly) {
        $where .= " AND p.parent_id IS NULL";
    } elseif ($parentIds !== null) {
        if ($parentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $where .= " AND p.parent_id IN ({$placeholders})";
        foreach ($parentIds as $parentId) {
            $params[] = (int)$parentId;
        }
    }

    $sql = "
        SELECT p.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
               u.created_at as author_joined,
               (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = p.user_id AND p2.status = 'visible') as author_posts
        FROM posts p
        LEFT JOIN users u ON p.user_id = u.id
        WHERE {$where}
        ORDER BY p.created_at ASC, p.id ASC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function handle_thread_view(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $pluginManager = App::getInstance()->pluginManager;

    $threadId = (int)($params['id'] ?? $_POST['thread_id'] ?? $_GET['id'] ?? 0);
    if ($threadId <= 0) {
        return redirect(url('home'));
    }

    $profileVisibilitySelect = bb_profile_visibility_select('u');
    $stmt = $pdo->prepare("
        SELECT t.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
               u.created_at as author_joined, u.id as author_id,
               {$profileVisibilitySelect},
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
    if (!can_view_thread($threadStatus) || !bb_can_view_thread($thread)) {
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
    $perPage = POSTS_PER_PAGE;
    $offset = ($postPage - 1) * $perPage;

    $config = App::getInstance()->config;
    $threaded = (bool)($config['threaded_comments'] ?? true);

    $postNumbers = [];
    $allById = [];
    $childrenMap = [];
    $rootIds = [];
    $descendantCounts = [];

    if ($threaded) {
        // Pagination applies to top-level comments; every descendant of the
        // comments on this page is loaded so a reply never loses its context.
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM posts
            WHERE thread_id = ? AND status = 'visible' AND parent_id IS NULL
        ");
        $totalStmt->execute([$threadId]);
        $totalPosts = (int)$totalStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalPosts / $perPage));

        $rootPosts = bb_fetch_thread_posts($pdo, $threadId, null, true, $perPage, $offset);

        $posts = $rootPosts;
        $frontier = array_map(fn($p) => (int)$p['id'], $rootPosts);
        $seen = array_fill_keys($frontier, true);

        while ($frontier !== []) {
            $children = bb_fetch_thread_posts($pdo, $threadId, $frontier);
            $next = [];
            foreach ($children as $child) {
                $childId = (int)$child['id'];
                if (isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $posts[] = $child;
                $next[] = $childId;
            }
            $frontier = $next;
        }

        // Stable #N numbers independent of the nesting order shown on screen.
        $numbersStmt = $pdo->prepare("
            SELECT id FROM posts
            WHERE thread_id = ? AND status = 'visible'
            ORDER BY created_at ASC, id ASC
        ");
        $numbersStmt->execute([$threadId]);
        $number = 2;
        foreach ($numbersStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $postNumbers[(int)$id] = $number++;
        }
    } else {
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM posts
            WHERE thread_id = ? AND status = 'visible'
        ");
        $totalStmt->execute([$threadId]);
        $totalPosts = $totalStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalPosts / $perPage));

        $posts = bb_fetch_thread_posts($pdo, $threadId, null, false, $perPage, $offset);
    }

    if (isset($pluginManager)) {
        $posts = $pluginManager->filter('thread_posts_before_view', $posts, $thread);
    }

    if ($threaded) {
        foreach ($posts as $postRow) {
            $allById[(int)$postRow['id']] = $postRow;
        }
        foreach ($posts as $postRow) {
            $postId = (int)$postRow['id'];
            $parentId = (int)($postRow['parent_id'] ?? 0);
            if ($parentId > 0 && isset($allById[$parentId])) {
                $childrenMap[$parentId][] = $postId;
            } else {
                $rootIds[] = $postId;
            }
        }

        // Number of descendants for each comment, used by the collapse control
        // to show how many hidden comments sit under a branch.
        $descendantCounts = [];
        $visiting = [];
        $countDescendants = function (int $id) use (&$countDescendants, &$descendantCounts, &$visiting, $childrenMap): int {
            if (isset($descendantCounts[$id])) {
                return $descendantCounts[$id];
            }
            if (isset($visiting[$id])) {
                return 0;
            }
            $visiting[$id] = true;
            $total = 0;
            foreach ($childrenMap[$id] ?? [] as $childId) {
                $total += 1 + $countDescendants((int)$childId);
            }
            unset($visiting[$id]);
            return $descendantCounts[$id] = $total;
        };
        foreach (array_keys($allById) as $id) {
            $countDescendants((int)$id);
        }
    }

    $lastReplyStmt = $pdo->prepare("
        SELECT created_at FROM posts
        WHERE thread_id = ? AND status = 'visible'
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $lastReplyStmt->execute([$threadId]);
    $lastReplyAt = $lastReplyStmt->fetchColumn() ?: null;

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
        return redirect(url('thread', ['id' => (int)($_POST['thread_id'] ?? 0)]));
    }

    if (!rate_limit('watch', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are watching too many threads. Please try again later.');
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
    if ($threadId <= 0) {
        return redirect(url('home'));
    }

    $thread = $pdo->prepare("
        SELECT t.*, " . bb_profile_visibility_select('u') . "
        FROM threads t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.id = ?
    ");
    $thread->execute([$threadId]);
    $threadRow = $thread->fetch();

    if ($threadRow === false
        || !can_view_thread((string)$threadRow['status'])
        || !bb_can_view_thread($threadRow)) {
        throw new \Bulletin\ForbiddenException('Thread not available');
    }

    try {
        // DbQuery::insertIgnore selects the correct syntax per driver
        // (INSERT IGNORE on MySQL, INSERT OR IGNORE on SQLite), so watching a
        // thread is actually persisted on both backends — the previous
        // hardcoded "INSERT OR IGNORE" silently failed on MySQL.
        (new DbQuery($pdo))->table('thread_watchers')->insertIgnore([
            'thread_id' => $threadId,
            'user_id' => (int)$_SESSION['user_id'],
        ]);
    } catch (PDOException $e) {}

    $watched = $_SESSION['watched_threads'] ?? [];
    if (!in_array($threadId, $watched, true)) {
        $watched[] = $threadId;
        $_SESSION['watched_threads'] = $watched;
    }

    return redirect(url('thread', ['id' => $threadId]));
}

function handle_unwatch(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if (!csrf_validate_request()) {
        return redirect(url('thread', ['id' => (int)($_POST['thread_id'] ?? 0)]));
    }

    if (!rate_limit('unwatch', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are unwatching too many threads. Please try again later.');
    }

    $threadId = (int)($_POST['thread_id'] ?? 0);
    if ($threadId <= 0) {
        return redirect(url('home'));
    }

    $thread = $pdo->prepare("
        SELECT t.*, " . bb_profile_visibility_select('u') . "
        FROM threads t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.id = ?
    ");
    $thread->execute([$threadId]);
    $threadRow = $thread->fetch();

    if ($threadRow === false
        || !can_view_thread((string)$threadRow['status'])
        || !bb_can_view_thread($threadRow)) {
        throw new \Bulletin\ForbiddenException('Thread not available');
    }

    try {
        $pdo->prepare("DELETE FROM thread_watchers WHERE thread_id = ? AND user_id = ?")
            ->execute([$threadId, $_SESSION['user_id']]);
    } catch (PDOException $e) {}

    $watched = $_SESSION['watched_threads'] ?? [];
    $watched = array_filter($watched, fn($id) => $id !== $threadId);
    $_SESSION['watched_threads'] = array_values($watched);

    return redirect(url('thread', ['id' => $threadId]));
}
