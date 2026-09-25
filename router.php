<?php
// Minimal router for PHP built-in server.
//
// Only explicitly allow-listed static assets are served directly; every other
// request goes through index.php. This prevents the built-in server from
// exposing secrets (config.json), the database (data/*), private uploads or
// CLI files.

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$base = basename($uri);

// The installers must still execute directly on a fresh deployment.
if (in_array($base, ['install.php', 'install2.php', 'install3.php'], true)) {
    return false;
}

// Never serve secrets or CLI/dev entrypoints.
if (preg_match('#^/(config\.json|config\.php|bb\.php|router\.php)$#i', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

// Never serve the data directory or private uploads.
if (preg_match('#^/(data|uploads/private)(/|$)#i', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

$file = __DIR__ . $uri;

// Serve only known static asset types directly.
if ($uri !== '/' && is_file($file)
    && preg_match('#\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|txt|xml|pdf|zip|mp4|webm|mp3)$#i', $uri)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
