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
    global $pdo;

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

function handle_category(): \Bulletin\Response|bool
{
    global $pdo;
    global $pdo;

    $categoryId = (int)($_GET['id'] ?? 0);
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

function handle_download(): \Bulletin\Response|bool
{
    global $pdo;
    global $pdo;

    $uploadId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM uploads WHERE id = ?");
    $stmt->execute([$uploadId]);
    $upload = $stmt->fetch();

    if (!$upload) {
        throw new \Bulletin\NotFoundException('File not found');
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
