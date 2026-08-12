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
