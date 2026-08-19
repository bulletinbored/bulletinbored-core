<?php
/**
 * bootstrap.php — session, install check, config, i18n, autoloader.
 *
 * Loaded once at the very top of index.php (or the built-in server router).
 * After this file runs, the following are available in the global scope:
 *   - $_SESSION (started)
 *   - $config (from config.php)
 *   - $lang (active language code)
 *   - i18n helpers: t(), pt(), tt()
 *   - the PSR-4 autoloader for the Bulletin\ namespace
 */

// --- Security headers -------------------------------------------------------
// Sent on every request (including the built-in server). Hardening headers
// that need no app knowledge live here; CSP is intentionally permissive to
// allow the CDN scripts the editor relies on, but blocks injection vectors.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://connect.facebook.net https://www.instagram.com https://platform.twitter.com https://www.youtube.com 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https: data:; img-src 'self' data: https:; frame-src https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com; connect-src 'self' https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://platform.twitter.com https://www.google.com");
}

// --- Session storage --------------------------------------------------------
// Use an app-owned, writable directory for session files. The system default
// (/var/lib/php/sessions) is frequently not writable for the site user on
// shared hosting, which makes every request start a fresh session and breaks
// CSRF validation (login, posting, etc.). Must run before session_start().
$sessionDir = __DIR__ . '/../data/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0755, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

// --- Session hardening ------------------------------------------------------
// Configure the session cookie before starting the session so the flags are
// applied on the very first Set-Cookie. Secure is enabled only when we are
// actually on HTTPS, to avoid breaking local HTTP dev installs.
$sessionSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $sessionSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$installerPages = ['install.php', 'install2.php', 'install3.php'];
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!file_exists(__DIR__ . '/../config.php') && !in_array($scriptName, $installerPages)) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base === '' || $base === '/') {
        $base = '';
    }
    header('Location: ' . $base . '/install.php');
    exit;
}

// PSR-4 autoloader (no Composer required). Maps Bulletin\Foo\Bar -> src/Foo/Bar.php
spl_autoload_register(function ($class) {
    $prefix = 'Bulletin\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Load configuration
require __DIR__ . '/../config.php';

// Localization
$lang = $_GET['lang'] ?? $config['default_lang'] ?? 'en';
if (!in_array($lang, $config['available_langs'] ?? ['en'])) {
    $lang = $config['default_lang'] ?? 'en';
}
setcookie('lang', $lang, time() + 365 * 24 * 60 * 60, '/');

// Translation registry: scope => [key => text].
// The core uses the 'core' scope; each plugin/theme uses its own namespaced
// scope so their strings stay fully separated from the core and from each
// other (no key collisions). A translation that is missing simply returns the
// key itself, so a plugin/theme that ships no lang file still works unchanged.
$GLOBALS['i18n'] = [];
$coreLangFile = __DIR__ . '/../lang/' . $lang . '.php';
$GLOBALS['i18n']['core'] = file_exists($coreLangFile) ? include $coreLangFile : [];
// Back-compat alias: code that still does `global $translations` keeps working.
$translations = &$GLOBALS['i18n']['core'];

function t($key, $params = [], $scope = 'core') {
    $registry = $GLOBALS['i18n'] ?? [];
    $text = $registry[$scope][$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
}

// Plugin-scoped translation, e.g. pt('editbored', 'bold').
function pt($pluginName, $key, $params = []) {
    return t($key, $params, 'plugin:' . strtolower($pluginName));
}

// Theme-scoped translation, e.g. tt('freshbored', 'some_key').
function tt($themeName, $key, $params = []) {
    return t($key, $params, 'theme:' . strtolower($themeName));
}
