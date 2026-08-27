<?php
/**
 * index.php — front controller.
 *
 * This file is intentionally tiny: it wires up the bootstrap, database,
 * managers and router, then delegates all request handling to src/actions.php.
 * Core logic lives in src/ (helpers.php, Router.php, setup.php, actions.php).
 */

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/markdown.php';
require __DIR__ . '/src/setup.php';

// Load managers
require __DIR__ . '/lib/PluginManager.php';
require __DIR__ . '/lib/ThemeManager.php';
require __DIR__ . '/lib/UpdateManager.php';

$pluginManager = new PluginManager(__DIR__ . '/plugins', $config['plugin_manifest'] ?? __DIR__ . '/data/plugins.json');
$GLOBALS['pluginManager'] = $pluginManager;
$themeManager = new ThemeManager(
    __DIR__ . '/themes',
    $config['theme_manifest'] ?? __DIR__ . '/data/themes.json',
    $config['theme'] ?? 'default'
);

// Load plugin/theme translations into their own namespaced scopes, separate
// from the core, before plugins run their init hooks.
$pluginManager->loadTranslations($lang);
$themeManager->loadTranslations($lang);

$pluginManager->loadEnabled();

$updateManager = new UpdateManager(
    $config['update_manifest'] ?? __DIR__ . '/data/updates.json',
    !empty($config['update_server']) ? $config['update_server'] : null,
    !empty($config['github_token']) ? $config['github_token'] : null,
    !empty($config['update_mirror']) ? $config['update_mirror'] : null
);

$activeTheme = $themeManager->getActive();
$themeApiVersion = $activeTheme ? $themeManager->getVersion($activeTheme) : '1.0.0';
$themeCssUrl = $themeManager->getCssUrl();
$themeCssPath = $themeManager->getCssPath();
$themeName = $activeTheme;

// Pretty URL support: populate $_GET['action'] from the request path.
Bulletin\Router::resolve();

// Routing
$action = $_GET['action'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

// Redirect banned users to home
if (is_logged_in() && (is_banned() || is_suspended())) {
    session_destroy();
    redirect(url('home'));
}

require __DIR__ . '/src/actions.php';
