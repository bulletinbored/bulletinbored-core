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
 * Build CSP header from configuration with secure defaults.
 *
 * @param array $config Application configuration (may contain 'csp' key)
 * @param string $nonce Per-request CSP nonce
 * @return string Complete CSP header value
 */
function build_csp_header(array $config, string $nonce): string {
    $cspConfig = $config['csp'] ?? [];
    $validated = validate_csp_config($cspConfig);

    // Secure defaults matching current hardcoded CSP
    $defaults = [
        'default_src' => ["'self'"],
        'script_src' => ["'self'", "https://platform.twitter.com", "https://cdn.syndication.twimg.com"],
        'style_src' => ["'self'", "https://cdn.jsdelivr.net", "https://fonts.googleapis.com", "https://cdnjs.cloudflare.com"],
        'style_src_attr' => ["'unsafe-inline'"],
        'font_src' => ["'self'", "https://fonts.gstatic.com", "https://cdn.jsdelivr.net", "https://cdnjs.cloudflare.com", "data:"],
        'img_src' => ["'self'", "data:", "https:", "https://*.twimg.com"],
        'frame_src' => ["https://www.youtube.com", "https://www.youtube-nocookie.com", "https://platform.twitter.com", "https://publish.twitter.com", "https://syndication.twitter.com", "https://www.instagram.com", "https://connect.facebook.net", "https://www.facebook.com", "https://facebook.com"],
        'connect_src' => ["'self'", "https://publish.twitter.com", "https://platform.twitter.com", "https://syndication.twitter.com", "https://cdn.syndication.twimg.com"],
        'object_src' => ["'none'"],
        'base_uri' => ["'self'"],
        'form_action' => ["'self'"],
        'frame_ancestors' => ["'none'"],
    ];

    // Merge config with defaults (config overrides defaults)
    // Replace entire directive arrays when config provides them
    $merged = $defaults;
    foreach ($validated as $directive => $values) {
        $merged[$directive] = $values;
    }

    // Ensure nonce is always in script_src and style_src
    if (!in_array("'nonce-{$nonce}'", $merged['script_src'], true)) {
        array_unshift($merged['script_src'], "'nonce-{$nonce}'");
    }
    if (!in_array("'nonce-{$nonce}'", $merged['style_src'], true)) {
        array_unshift($merged['style_src'], "'nonce-{$nonce}'");
    }

    // Build directive strings
    $directives = [];
    foreach ($merged as $directive => $values) {
        $directiveName = str_replace('_', '-', $directive);
        $directives[] = "$directiveName " . implode(' ', $values);
    }

    return implode('; ', $directives);
}

/**
 * Validate and sanitize CSP configuration.
 *
 * Rejects known-dangerous values for security-critical directives.
 * Logs warnings for rejected values.
 *
 * @param array $cspConfig Raw CSP configuration from config.json
 * @return array Validated and sanitized configuration
 */
function validate_csp_config(array $cspConfig): array {
    $validated = [];
    $dangerousScriptValues = ["'unsafe-inline'", "'unsafe-eval'", "'wasm-unsafe-eval'"];
    $dangerousStyleValues = ["'unsafe-inline'"];

    foreach ($cspConfig as $directive => $values) {
        if (!is_array($values)) {
            trigger_error("CSP config: directive '$directive' must be an array, ignoring", E_USER_WARNING);
            continue;
        }

        // Allow explicit empty arrays to override defaults
        if (empty($values)) {
            $validated[$directive] = [];
            continue;
        }

        $cleanValues = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                trigger_error("CSP config: directive '$directive' contains non-string value, ignoring", E_USER_WARNING);
                continue;
            }

            // Validate script_src
            if ($directive === 'script_src' && in_array($value, $dangerousScriptValues, true)) {
                trigger_error("CSP config: '$value' not allowed in script_src for security, ignoring", E_USER_WARNING);
                continue;
            }

            // Validate style_src
            if ($directive === 'style_src' && in_array($value, $dangerousStyleValues, true)) {
                trigger_error("CSP config: '$value' not allowed in style_src for security, ignoring", E_USER_WARNING);
                continue;
            }

            $cleanValues[] = $value;
        }

        if (!empty($cleanValues)) {
            $validated[$directive] = $cleanValues;
        }
    }

    return $validated;
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
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if (!headers_sent()) {
        $cspHeader = build_csp_header($config, $nonce);
        header("Content-Security-Policy: $cspHeader");
    }
}