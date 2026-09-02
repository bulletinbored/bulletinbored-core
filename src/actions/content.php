<?php

function handle_content_action(string $action, string $method): \Bulletin\Response|bool
{
    switch ($action) {
        case 'search':
            return handle_search();
        case 'category':
            return handle_category();
        case 'download':
            return handle_download();
        default:
            return false;
    }
}

function handle_search(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    $query = $_GET['q'] ?? '';
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $sort  = $_GET['sort'] ?? 'latest';

    $listing     = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'search' => $query, 'sticky_first' => false]);
    $threads     = $listing['threads'];
    $total       = $listing['total'];
    $totalPages  = $listing['pages'];
    $page        = $listing['page'];
    $sort        = $listing['sort'];
    $listContext = 'search';

    $categories = sidebar_categories();
    include __DIR__ . '/../../views/home.php';
    return true;
}

function handle_category(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    $categoryId = (int)($params['id'] ?? $_GET['id'] ?? 0);
    $catStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $category = $catStmt->fetch();

    if (!$category) {
        throw new \Bulletin\NotFoundException('Category not found');
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $sort = $_GET['sort'] ?? 'latest';

    $listing     = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'category_id' => $categoryId]);
    $threads     = $listing['threads'];
    $total       = $listing['total'];
    $totalPages  = $listing['pages'];
    $page        = $listing['page'];
    $sort        = $listing['sort'];
    $listContext = 'category';

    include __DIR__ . '/../../views/category.php';
    return true;
}

function handle_download(array $params = []): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    $uploadId = (int)($params['id'] ?? $_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT u.*, COALESCE(t.status, t2.status) AS thread_status
        FROM uploads u
        LEFT JOIN threads t ON u.thread_id = t.id
        LEFT JOIN posts p ON u.post_id = p.id
        LEFT JOIN threads t2 ON p.thread_id = t2.id
        WHERE u.id = ?
    ");
    $stmt->execute([$uploadId]);
    $upload = $stmt->fetch();

    if (!$upload) {
        throw new \Bulletin\NotFoundException('File not found');
    }

    $threadStatus = $upload['thread_status'] ?? null;
    if ($threadStatus === null) {
        if (!is_logged_in()) {
            throw new \Bulletin\ForbiddenException('Not authorized');
        }
    } elseif (!can_view_thread($threadStatus)) {
        throw new \Bulletin\ForbiddenException('Not authorized');
    }

    $filePath = __DIR__ . '/../../uploads/' . basename($upload['filename']);
    if (!is_file($filePath)) {
        throw new \Bulletin\NotFoundException('File not found');
    }

    $mime = $upload['mime_type'] ?? (function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream');
    if (!is_string($mime) || $mime === '') {
        $mime = 'application/octet-stream';
    }

    $isImage = str_starts_with($mime, 'image/');
    $disposition = $isImage ? 'inline' : 'attachment';

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition . '; filename="' . addslashes(basename($upload['original_name'])) . '"');
    header('Content-Length: ' . (string)filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    readfile($filePath);
    exit;
}
