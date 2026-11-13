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

/**
 * Build the HSTS header value from configuration, or null when HSTS is disabled.
 *
 * includeSubDomains is opt-out (config: hsts_include_subdomains = false) because
 * it also covers every other host under the domain, including ones that may not
 * have a valid certificate yet. hsts_max_age = 0 disables HSTS entirely, for
 * example while a certificate is being rolled out.
 */
function build_hsts_header(array $config = []): ?string
{
    $maxAge = $config['hsts_max_age'] ?? 31536000;
    if (!is_numeric($maxAge)) {
        $maxAge = 31536000;
    }
    $maxAge = (int)$maxAge;
    if ($maxAge <= 0) {
        return null;
    }
    $header = 'max-age=' . $maxAge;
    if (!empty($config['hsts_include_subdomains']) || !array_key_exists('hsts_include_subdomains', $config)) {
        $header .= '; includeSubDomains';
    }
    return $header;
}

function send_security_headers(string $nonce, array $config = []): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    // Only advertise HSTS over an actual HTTPS connection: sending it over
    // plain HTTP has no effect (and would be ignored by browsers anyway).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        $hsts = build_hsts_header($config);
        if ($hsts !== null) {
            header('Strict-Transport-Security: ' . $hsts);
        }
    }
    if (!headers_sent()) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://platform.twitter.com https://cdn.syndication.twimg.com; style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; style-src-attr 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; img-src 'self' data: https: https://*.twimg.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com https://publish.twitter.com https://syndication.twitter.com https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com; connect-src 'self' https://publish.twitter.com https://platform.twitter.com https://syndication.twitter.com https://cdn.syndication.twimg.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}
