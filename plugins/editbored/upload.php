<?php
session_start();
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!empty($config['csrf_token']) && !hash_equals($config['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token invalid']);
    exit;
}

if (empty($_FILES['editbored_image']['tmp_name']) || $_FILES['editbored_image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['editbored_image']['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}

$ext = match($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    default => 'bin',
};

$safeName = 'editbored_' . $_SESSION['user_id'] . '_' . uniqid() . '.' . $ext;
$uploadDir = __DIR__ . '/../../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (move_uploaded_file($_FILES['editbored_image']['tmp_name'], $uploadDir . $safeName)) {
    $base = rtrim($config['base_url'] ?? '', '/');
    $url = $base . '/uploads/' . rawurlencode($safeName);
    echo json_encode(['url' => $url, 'filename' => $safeName]);
    exit;
}

http_response_code(500);
echo json_encode(['error' => 'Failed to move uploaded file']);
exit;
