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
// that need no app knowledge live here; CSP uses a per-request nonce instead
// of 'unsafe-inline' so inline scripts are still allowed but bound to this
// request only.
require_once __DIR__ . '/csp.php';
$cspNonce = generate_csp_nonce();
send_security_headers($cspNonce);

// --- Force HTTPS ------------------------------------------------------------
// If the deployment is HTTPS-only (cookie_secure enabled), redirect any plain
// HTTP request to the equivalent HTTPS URL before starting the session, so the
// Secure session cookie is never emitted over an unencrypted channel. This keeps
// login/sessions working while upgrading every visitor to HTTPS automatically.
if (!isset($config) || !is_array($config)) {
    $cfgPath = __DIR__ . '/../config.json';
    $legacyPath = __DIR__ . '/../config.php';
    if (file_exists($cfgPath)) {
        $config = json_decode(file_get_contents($cfgPath), true) ?: [];
    } elseif (file_exists($legacyPath)) {
        $config = [];
        @include $legacyPath;
        if (!is_array($config)) { $config = []; }
    } else {
        $config = [];
    }
}
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
$redirectHttps = !$isHttps;
if ($redirectHttps) {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $reqUri = $_SERVER['REQUEST_URI'] ?? '/';
    if ($host !== '') {
        header('Location: https://' . $host . $reqUri, true, 301);
        exit;
    }
}

// --- Session storage --------------------------------------------------------
// Use an app-owned, writable directory for session files. The system default
// (/var/lib/php/sessions) is frequently not writable for the site user on
// shared hosting, which makes every request start a fresh session and breaks
// CSRF validation (login, posting, etc.). Fall back to data/sessions whenever
// the PHP default is not usable, so this works out of the box on new installs.
// Must run before session_start().
$sessionDir = realpath(__DIR__ . '/../data/sessions') ?: (__DIR__ . '/../data/sessions');
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0755, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

// --- Session hardening ------------------------------------------------------
// Use a dedicated session cookie name (BBSESSID) instead of the PHP default
// PHPSESSID. On shared/legacy deployments an old non-secure PHPSESSID cookie
// can linger on the domain; when we then try to set a Secure PHPSESSID the
// browser rejects it ("cookie rejected because a secure cookie already exists"
// reversed), which silently breaks the session and every CSRF check. A new
// name sidesteps that stale-cookie conflict without manual browser cleanup.
// The Secure flag is taken from config so it is deterministic per deployment
// (no flaky HTTPS detection behind proxies), defaulting to on when the site
// is served over HTTPS.
session_name('BBSESSID');

$configIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');
$sessionSecure = $config['cookie_secure'] ?? $configIsHttps;

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

$installerPages = ['install.php', 'install2.php', 'install3.php'];
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');

$hasConfig = file_exists(__DIR__ . '/../config.json') || file_exists(__DIR__ . '/../config.php');
if (!$hasConfig && !in_array($scriptName, $installerPages)) {
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
$configPath = __DIR__ . '/../config.json';
$legacyConfigPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
    if (!is_array($config)) {
        $config = [];
    }
} elseif (file_exists($legacyConfigPath)) {
    $config = [];
    @include $legacyConfigPath;
    if (!is_array($config)) {
        $config = [];
    }
    if (file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        @unlink($legacyConfigPath);
    }
}

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

/**
 * Load a translation file as a plain array. Translation files are JSON only
 * (no PHP include) so a malicious upload cannot achieve RCE: an uploaded lang
 * file is parsed as data, never executed.
 *
 * @return array<string,string>
 */
function load_lang_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $out[$k] = $v;
        }
    }
    return $out;
}

$coreLangFile = __DIR__ . '/../lang/' . $lang . '.json';
$GLOBALS['i18n']['core'] = load_lang_file($coreLangFile);
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
