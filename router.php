<?php
// Minimal router for PHP built-in server
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

// Pretty URL rewriting (mirrors the rules in .htaccess)
$path = ltrim($uri, '/');
if (preg_match('#^thread/([0-9]+)(?:-[^/]+)?$#', $path, $m)) {
    $_GET['action'] = 'thread';
    $_GET['id'] = $m[1];
} elseif (preg_match('#^category/([0-9]+)(?:-[^/]+)?$#', $path, $m)) {
    $_GET['action'] = 'category';
    $_GET['id'] = $m[1];
} elseif (preg_match('#^u/([^/]+)$#', $path, $m)) {
    $_GET['action'] = 'profile';
    $_GET['user'] = urldecode($m[1]);
}

require __DIR__ . '/index.php';
return true;