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
    header('Referrer-Policy: no-referrer-when-downgrade');
    if (!headers_sent()) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; img-src 'self' data: https:; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}
