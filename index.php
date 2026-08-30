<?php
/**
 * index.php — front controller.
 *
 * Wires up the bootstrap, database, managers and router, then registers
 * all routes and dispatches the request. Core handlers live in src/actions/.
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

// Load all action handlers
require __DIR__ . '/src/actions/posts.php';
require __DIR__ . '/src/actions/users.php';
require __DIR__ . '/src/actions/content.php';
require __DIR__ . '/src/actions/misc.php';
require __DIR__ . '/src/actions/admin.php';

// Redirect banned/suspended users
if (is_logged_in() && (is_banned() || is_suspended())) {
    session_destroy();
    redirect(url('home'));
}

// Create router and let plugins register their routes/middleware
$router = new Bulletin\Router();
$pluginManager->setRouter($router);
$pluginManager->applyRoutes();

// --- Public routes ---
$router->get('/', function() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $sort = $_GET['sort'] ?? 'latest';
    $listing = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'sticky_first' => false]);
    $threads = $listing['threads'];
    $total = $listing['total'];
    $totalPages = $listing['pages'];
    $page = $listing['page'];
    $sort = $listing['sort'];
    $listContext = 'home';
    $categories = sidebar_categories();
    include __DIR__ . '/views/home.php';
});

$router->get('/thread/{id:\d+}', fn($p) => handle_thread_view());
$router->get('/thread/{id:\d+}-{slug}', fn($p) => handle_thread_view());
$router->get('/category/{id:\d+}', fn($p) => handle_category());
$router->get('/category/{id:\d+}-{slug}', fn($p) => handle_category());
$router->get('/u/{user}', fn($p) => handle_profile());
$router->get('/search', fn() => handle_search());
$router->get('/download/{id:\d+}', fn($p) => handle_download());

// --- Guest-only routes ---
$router->middleware('guest')->group(function($router) {
    $router->get('/login', fn() => handle_login('GET'));
    $router->post('/login', fn() => handle_login('POST'));
    $router->get('/register', fn() => handle_register('GET'));
    $router->post('/register', fn() => handle_register('POST'));
    $router->get('/forgot-password', fn() => handle_forgot_password('GET'));
    $router->post('/forgot-password', fn() => handle_forgot_password('POST'));
    $router->get('/reset-password', fn() => handle_reset_password('GET'));
    $router->post('/reset-password', fn() => handle_reset_password('POST'));
});

// --- Verified email ---
$router->get('/verify-email', fn() => handle_verify_email());

// --- Authenticated routes ---
$router->middleware('auth')->group(function($router) {
    $router->get('/new-thread', fn() => handle_new_thread('GET'));
    $router->post('/new-thread', fn() => handle_new_thread('POST'));
    $router->post('/reply', fn() => handle_reply_post());
    $router->get('/edit-post/{id:\d+}', fn($p) => handle_edit_post('GET'));
    $router->post('/edit-post/{id:\d+}', fn($p) => handle_edit_post('POST'));
    $router->post('/delete-post/{id:\d+}', fn($p) => handle_delete_post());
    $router->get('/edit-thread/{id:\d+}', fn($p) => handle_edit_thread('GET'));
    $router->post('/edit-thread/{id:\d+}', fn($p) => handle_edit_thread('POST'));
    $router->post('/delete-thread/{id:\d+}', fn($p) => handle_delete_thread());
    $router->get('/watch', fn() => handle_watch());
    $router->get('/unwatch', fn() => handle_unwatch());
    $router->post('/upload-image', fn() => handle_upload_image());
    $router->get('/logout', fn() => handle_logout());
    $router->get('/edit-profile', fn() => handle_edit_profile('GET'));
    $router->post('/edit-profile', fn() => handle_edit_profile('POST'));
    $router->post('/remove-avatar', fn() => handle_remove_avatar('POST'));
    $router->post('/preview', fn() => handle_markdown_preview());
    $router->get('/mention-users', fn() => handle_mention_users());
});

// --- Admin routes ---
$router->middleware('admin')->group(function($router) {
    $router->get('/admin', fn() => handle_admin_dashboard());
    $router->get('/admin/settings', fn() => handle_admin_settings_get());
    $router->post('/admin/settings', fn() => handle_admin_settings_post());
    $router->get('/admin/smtp', fn() => handle_admin_smtp_get());
    $router->post('/admin/smtp', fn() => handle_admin_smtp_post());
    $router->post('/admin/upload-site-image', fn() => handle_admin_upload_site_image());
    $router->get('/admin/get-images', fn() => handle_admin_get_images());
    $router->get('/admin/moderation', fn() => handle_admin_moderation_get());
    $router->post('/admin/moderate', fn() => handle_moderate_post());
    $router->post('/admin/front-moderate', fn() => handle_frontend_moderate_post());
    $router->post('/admin/split-thread', fn() => handle_split_thread_post());
    $router->post('/admin/merge-thread', fn() => handle_merge_thread_post());
    $router->get('/admin/roles', fn() => handle_admin_roles_get());
    $router->post('/admin/roles-action', fn() => handle_admin_roles_action_post());
    $router->get('/admin/users', fn() => handle_admin_users_get());
    $router->get('/admin/users/{id:\d+}/edit', fn($p) => handle_admin_user_edit('GET'));
    $router->post('/admin/users/{id:\d+}/edit', fn($p) => handle_admin_user_edit('POST'));
    $router->post('/admin/create-user', fn() => handle_admin_create_user_post());
    $router->get('/admin/categories', fn() => handle_admin_categories('GET'));
    $router->post('/admin/categories', fn() => handle_admin_categories('POST'));
    $router->post('/admin/delete-category', fn() => handle_delete_category_post());
    $router->post('/admin/update-category-order', fn() => handle_update_category_order_post());
    $router->get('/admin/langs', fn() => handle_admin_langs('GET'));
    $router->post('/admin/langs', fn() => handle_admin_langs('POST'));
    $router->get('/admin/diagnostics', fn() => handle_admin_diagnostics_get());
    $router->get('/admin/plugins', fn() => handle_admin_plugins('GET'));
    $router->post('/admin/plugins', fn() => handle_admin_plugins('POST'));
    $router->get('/admin/themes', fn() => handle_admin_themes('GET'));
    $router->post('/admin/themes', fn() => handle_admin_themes('POST'));
    $router->get('/admin/catalog', fn() => handle_admin_catalog('GET'));
    $router->post('/admin/catalog', fn() => handle_admin_catalog('POST'));
    $router->get('/admin/updates', fn() => handle_admin_updates('GET'));
    $router->post('/admin/updates', fn() => handle_admin_updates('POST'));
    $router->post('/admin/delete-user', fn() => handle_delete_user_post());
    $router->post('/admin/ban-user', fn() => handle_ban_user_post());
    $router->post('/admin/unban-user', fn() => handle_unban_user_post());
    $router->post('/admin/suspend-user', fn() => handle_suspend_user_post());
});

$router->dispatch();
