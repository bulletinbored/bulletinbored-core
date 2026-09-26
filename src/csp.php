<?php

require_once __DIR__ . '/App.php';

function generate_csp_nonce(): string {
    $app = App::getInstance();
    if (empty($app->cspNonce)) {
        $app->cspNonce = base64_encode(random_bytes(18));
    }
    return $app->cspNonce;
}

function csp_nonce(): string {
    return generate_csp_nonce();
}

function send_security_headers(string $nonce): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    // Only advertise HSTS over an actual HTTPS connection: sending it over
    // plain HTTP has no effect (and would be ignored by browsers anyway).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if (!headers_sent()) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://platform.twitter.com https://cdn.syndication.twimg.com; style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; style-src-attr 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; img-src 'self' data: https: https://*.twimg.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com https://publish.twitter.com https://syndication.twitter.com https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com; connect-src 'self' https://publish.twitter.com https://platform.twitter.com https://syndication.twitter.com https://cdn.syndication.twimg.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}
