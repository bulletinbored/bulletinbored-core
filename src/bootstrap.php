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

// Test mode detection - skip session/install logic if test bootstrap already loaded
if (defined('BULLETIN_TEST_MODE') && BULLETIN_TEST_MODE) {
    date_default_timezone_set('UTC');
    // Still load autoloader for tests
    // PSR-4 autoloader
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
    return;
}

// --- Configuration ----------------------------------------------------------
// Loaded before the security headers so HSTS settings (hsts_max_age,
// hsts_include_subdomains) can be applied to the response.
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

// --- Trusted proxies --------------------------------------------------------
// Resolved before the security headers: behind a reverse proxy HTTPS terminates
// at the proxy and PHP receives plain HTTP, so HSTS can only be decided after
// the forwarded headers have been validated against the trusted proxy list.
require_once __DIR__ . '/TrustedProxies.php';
$proxyInfo = trusted_proxies_detect();

$app = App::getInstance();
$app->config = $config;
$app->forwardedProto = $forwardedProto = $proxyInfo['forwarded_proto'];
$app->forwardedSsl = $forwardedSsl = $proxyInfo['forwarded_ssl'];
$app->forwardedFor = $forwardedFor = $proxyInfo['forwarded_for'];

$isHttps = request_is_https($proxyInfo);

// --- Security headers -------------------------------------------------------
require_once __DIR__ . '/csp.php';
$cspNonce = generate_csp_nonce();
send_security_headers($cspNonce, $config, $isHttps);

// --- Force HTTPS ------------------------------------------------------------
// If the deployment is HTTPS-only (cookie_secure enabled), redirect any plain
// HTTP request to the equivalent HTTPS URL before starting the session, so the
// Secure session cookie is never emitted over an unencrypted channel. This keeps
// login/sessions working while upgrading every visitor to HTTPS automatically.
// The redirect can be disabled with "force_https": false in config.json. That
// escape hatch matters on hosts where the domain has no valid certificate yet
// (or during local development): without it every URL would 301 to an https://
// address the browser cannot open, locking the site out entirely. It is also
// skipped for CLI/built-in-server requests, which have no host to redirect to.
$forceHttps = $config['force_https'] ?? true;
$redirectHttps = $forceHttps && !$isHttps && PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server';
if ($redirectHttps) {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $host = preg_replace('/[\x00-\x1F\x7F\r\n\/]/', '', $host);
    $reqUri = $_SERVER['REQUEST_URI'] ?? '/';
    if ($host !== '' && !preg_match('#^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#i', $host)) {
        header('Location: https://' . $host . $reqUri, true, 301);
        exit;
    }
}

// --- Session setup ----------------------------------------------------------
require_once __DIR__ . '/session_setup.php';
session_setup();

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

/**
 * Append an unhandled error to data/logs/error.log.
 * Best effort: logging must never throw inside the exception handler.
 */
function bb_log_unhandled_error(string $summary, \Throwable $e): void
{
    try {
        $dir = __DIR__ . '/../data/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $file = $dir . '/error.log';
        // Keep the file bounded: a broken install can 500 on every request.
        if (is_file($file) && filesize($file) > 2097152) {
            @file_put_contents($file, '');
        }
        $config = isset($GLOBALS['config']) && is_array($GLOBALS['config']) ? $GLOBALS['config'] : [];
        $context = [
            'uri' => $_SERVER['REQUEST_URI'] ?? '-',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '-',
            'php' => PHP_VERSION,
            'db_driver' => $config['db_driver'] ?? 'unknown',
            'db_host' => $config['db_host'] ?? '',
            'db_name' => $config['db_name'] ?? '',
        ];
        if ($e instanceof \PDOException) {
            $context['sql_state'] = (string)($e->errorInfo[0] ?? $e->getCode());
        }
        $entry = '[' . date('c') . '] ' . $summary . PHP_EOL
            . '  ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
            . $e->getTraceAsString() . PHP_EOL . PHP_EOL;
        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $ignored) {
        // Never let logging break the error response.
    }
}

// --- Global exception safety net -------------------------------------------
// The router only converts HttpException into a response; anything else
// (PDOException, TypeError, ...) would otherwise become an uncaught fatal,
// possibly leaking paths/stack traces when display_errors is on. Log it and
// return a generic 500 instead. Not registered in test mode (handlers must be
// able to throw so assertThrows() works).
set_exception_handler(function (\Throwable $e): void {
    $summary = sprintf(
        'bulletinbored unhandled %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    @error_log($summary);

    // Shared hosting rarely exposes the PHP error log. Keep a copy inside
    // data/logs (denied by the shipped server configs) so the administrator can
    // read the real cause of a 500 instead of guessing.
    bb_log_unhandled_error($summary, $e);

    if (!headers_sent()) {
        http_response_code(500);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with(ltrim($path, '/'), 'api/');
    if (!headers_sent()) {
        header('Content-Type: ' . ($wantsJson ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'));
    }
    echo $wantsJson ? json_encode(['error' => 'Internal Server Error']) : 'Internal Server Error';
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

// All timestamps are stored in UTC (SQLite CURRENT_TIMESTAMP is UTC, and MySQL
// sessions are pinned to +00:00 in setup.php). Pin PHP to UTC as well so that
// PHP-generated timestamps (e.g. threads.updated_at) stay comparable with
// database defaults instead of drifting by the server's local offset.
date_default_timezone_set('UTC');

// Localization
$lang = $_GET['lang'] ?? $config['default_lang'] ?? 'en';
if (!in_array($lang, $config['available_langs'] ?? ['en'])) {
    $lang = $config['default_lang'] ?? 'en';
}
setcookie('lang', $lang, time() + 365 * 24 * 60 * 60, '/');

// Translation registry: scope => [key => text].
$app->i18n = [];

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
$app->i18n['core'] = load_lang_file($coreLangFile);
// Back-compat alias: code that still does `global $translations` keeps working.
$translations = &$app->i18n['core'];

function t($key, $params = [], $scope = 'core') {
    $registry = App::getInstance()->i18n;
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
