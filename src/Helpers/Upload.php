<?php

/**
 * File upload validation and management.
 */

/**
 * Ensure the private uploads directory exists and carries a deny-all .htaccess
 * (defense in depth for Apache; nginx/IIS are covered by the shipped server
 * configs). This is invoked on every request from setup.php, and from the
 * upload handler before writing, so the protection exists on any deployment —
 * the directory itself is not tracked in git.
 *
 * @return string The private uploads directory path.
 */
function ensure_private_uploads_dir(): string
{
    $dir = __DIR__ . '/../../uploads/private';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Deny all direct access to private uploads. These files are served\n"
            . "# only by the authenticated /download handler.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

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
