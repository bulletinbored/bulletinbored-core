<?php

/**
 * File upload validation and management.
 */

function validate_upload(string $tmpPath, string $origName, array $allowed, int $maxSize): ?array
{
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return null;
    }
    if (filesize($tmpPath) > $maxSize) {
        return null;
    }

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpPath);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
    }
    if (!is_string($mime) || !isset($allowed[$mime])) {
        return null;
    }

    if (str_starts_with($mime, 'image/')) {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return null;
        }
    }

    $ext = $allowed[$mime];
    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;

    return ['mime' => $mime, 'ext' => $ext, 'safe_name' => $safeName];
}

function validate_uploaded_file(string $tmpPath, string $origName, array $allowed, int $maxSize): ?array {
    return validate_upload($tmpPath, $origName, $allowed, $maxSize);
}

function get_uploaded_images(): array {
    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        return [];
    }
    $images = [];
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];
    $files = @scandir($uploadDir);
    if (!$files) {
        return [];
    }
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }
        $path = $uploadDir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        $images[] = [
            'url' => base_url() . '/uploads/' . $file,
            'filename' => $file,
            'path' => $path,
        ];
    }
    usort($images, fn($a, $b) => filemtime($b['path']) - filemtime($a['path']));
    return $images;
}
