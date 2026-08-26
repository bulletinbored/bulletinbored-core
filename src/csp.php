<?php

function generate_csp_nonce(): string {
    if (empty($GLOBALS['CSP_NONCE'])) {
        $GLOBALS['CSP_NONCE'] = base64_encode(random_bytes(18));
    }
    return $GLOBALS['CSP_NONCE'];
}

function csp_nonce(): string {
    return generate_csp_nonce();
}

function send_security_headers(string $nonce): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!headers_sent()) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}' 'unsafe-hashes'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https: data:; img-src 'self' data: https:; frame-src https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com; connect-src 'self' https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://platform.twitter.com https://www.google.com");
    }
}
