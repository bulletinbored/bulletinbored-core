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
    $stmt = $pdo->prepare("SELECT * FROM uploads WHERE id = ?");
    $stmt->execute([$uploadId]);
    $upload = $stmt->fetch();

    if (!$upload) {
        throw new \Bulletin\NotFoundException('File not found');
    }

    $threadId = (int)($upload['thread_id'] ?? 0);
    if ($threadId <= 0 && !empty($upload['post_id'])) {
        $postStmt = $pdo->prepare("SELECT thread_id FROM posts WHERE id = ?");
        $postStmt->execute([(int)$upload['post_id']]);
        $threadId = (int)($postStmt->fetchColumn() ?: 0);
    }

    if ($threadId <= 0) {
        throw new \Bulletin\NotFoundException('File not found');
    }

    $threadStmt = $pdo->prepare("
        SELECT t.*, " . bb_profile_visibility_select('u') . "
        FROM threads t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.id = ?
    ");
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();

    if (!$thread
        || !can_view_thread((string)($thread['status'] ?? 'visible'))
        || !bb_can_view_thread($thread)) {
        throw new \Bulletin\NotFoundException('File not found');
    }

    $privatePath = __DIR__ . '/../../uploads/private/' . basename($upload['filename']);
    $publicPath = __DIR__ . '/../../uploads/' . basename($upload['filename']);

    if (is_file($privatePath)) {
        $filePath = $privatePath;
    } elseif (is_file($publicPath)) {
        $filePath = $publicPath;
    } else {
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
    $safeName = preg_replace('/[\x00-\x1F\x7F\r\n]/', '', $upload['original_name'] ?? '');
    $safeName = basename($safeName);
    $quotedName = str_replace(['\\', '"'], '', $safeName);
    $utf8Name = rawurlencode($safeName);
    header('Content-Disposition: ' . $disposition . '; filename="' . $quotedName . '"; filename*=UTF-8\'\'' . $utf8Name);
    header('Content-Length: ' . (string)filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    readfile($filePath);
    exit;
}
