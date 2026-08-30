<?php

/**
 * session_setup.php — configure and start the session.
 *
 * Uses a dedicated session cookie name (BBSESSID) instead of PHPSESSID
 * to avoid stale-cookie conflicts on shared hosting.
 */

function session_setup(): void
{
    $sessionDir = realpath(__DIR__ . '/../data/sessions') ?: (__DIR__ . '/../data/sessions');
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0755, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    session_name('BBSESSID');

    $config = $GLOBALS['config'] ?? [];
    $forwardedProto = $GLOBALS['forwarded_proto'] ?? null;
    $forwardedSsl = $GLOBALS['forwarded_ssl'] ?? null;
    $forceHttps = $config['force_https'] ?? true;

    $configIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($forwardedProto === 'https')
        || ($forwardedSsl === 'on');
    $sessionSecure = $config['cookie_secure'] ?? ($configIsHttps && $forceHttps);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (bool) $sessionSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
