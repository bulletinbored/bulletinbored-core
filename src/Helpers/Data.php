<?php

/**
 * Data fetching helpers for sidebar, statistics, and thread listing.
 */

function sidebar_categories() {
    $pdo = App::getInstance()->pdo;
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY position ASC, name ASC");
    return $stmt->fetchAll();
}

function forum_statistics() {
    $pdo = App::getInstance()->pdo;
    return [
        'threads' => (int)$pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn(),
        'posts' => (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
        'members' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'contributors' => (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM posts")->fetchColumn(),
        'newest_member' => $pdo->query("SELECT username, avatar FROM users ORDER BY created_at DESC LIMIT 1")->fetch(),
    ];
}

function thread_sort_options() {
    return ['latest' => t('sort_latest'), 'replies' => t('sort_replies'), 'views' => t('sort_views'), 'newest' => t('sort_newest'), 'oldest' => t('sort_oldest')];
}

function fetch_threads(array $opts = []) {
    $pdo = App::getInstance()->pdo;
    $statusFilter = $opts['status'] ?? ['visible', 'sticky', 'locked'];
    $categoryId = $opts['category_id'] ?? null;
    $search = $opts['search'] ?? '';
    $sort = $opts['sort'] ?? 'latest';
    $page = max(1, $opts['page'] ?? 1);
    $perPage = $opts['per_page'] ?? 20;
    $offset = ($page - 1) * $perPage;

    $where = ["t.status IN ('" . implode("','", $statusFilter) . "')"];
    $params = [];
    if ($categoryId) {
        $where[] = "t.category_id = ?";
        $params[] = $categoryId;
    }
    if ($search) {
        $where[] = "(t.title LIKE ? OR t.content LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $whereSql = implode(' AND ', $where);

    $orderBy = match($sort) {
        'replies' => '(SELECT COUNT(*) FROM posts WHERE thread_id = t.id) DESC',
        'views' => 't.views DESC',
        'newest' => 't.created_at DESC',
        'oldest' => 't.created_at ASC',
        default => 't.updated_at DESC',
    };

    $stmt = $pdo->prepare("
        SELECT t.*, u.username AS author, u.avatar AS author_avatar,
               c.name AS category_name, c.id AS category_id,
               COALESCE(t.views, 0) AS view_count,
               (SELECT COUNT(*) FROM posts WHERE thread_id = t.id AND status = 'visible') AS reply_count
        FROM threads t
        JOIN users u ON t.user_id = u.id
        LEFT JOIN categories c ON t.category_id = c.id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$params, $perPage, $offset]);
    $threads = $stmt->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM threads t WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    return [
        'threads' => $threads,
        'total' => $total,
        'pages' => $totalPages,
        'page' => $page,
        'sort' => $sort,
    ];
}
