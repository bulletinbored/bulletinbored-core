<?php

require_once __DIR__ . '/admin/settings.php';
require_once __DIR__ . '/admin/moderation.php';
require_once __DIR__ . '/admin/users.php';
require_once __DIR__ . '/admin/categories.php';
require_once __DIR__ . '/admin/langs.php';
require_once __DIR__ . '/admin/diagnostics.php';
require_once __DIR__ . '/admin/plugins.php';
require_once __DIR__ . '/admin/themes.php';
require_once __DIR__ . '/admin/catalog.php';
require_once __DIR__ . '/admin/updates.php';

function handle_admin_action(string $action, string $method): \Bulletin\Response|bool
{
    $adminActions = [
        'admin', 'admin_settings', 'admin_smtp', 'admin_moderation', 'admin_roles', 'admin_roles_action',
        'admin_users', 'admin_user_edit', 'admin_create_user',
        'admin_categories', 'delete_category', 'update_category_order',
        'admin_langs', 'admin_diagnostics', 'admin_plugins', 'admin_themes',
        'admin_catalog', 'admin_updates', 'admin_upload_site_image', 'admin_get_images',
        'moderate', 'frontend_moderate', 'split_thread', 'merge_thread',
        'delete_user', 'ban_user', 'unban_user', 'suspend_user',
    ];
    if (!in_array($action, $adminActions, true)) {
        return false;
    }

    switch ($action) {
        case 'admin':
            return handle_admin_dashboard();
        case 'admin_settings':
            return $method === 'POST' ? handle_admin_settings_post() : handle_admin_settings_get();
        case 'admin_smtp':
            return $method === 'POST' ? handle_admin_smtp_post() : handle_admin_smtp_get();
        case 'admin_upload_site_image':
            return handle_admin_upload_site_image();
        case 'admin_get_images':
            return handle_admin_get_images();
        case 'admin_moderation':
            return handle_admin_moderation_get();
        case 'moderate':
            return $method === 'POST' ? handle_moderate_post() : false;
        case 'frontend_moderate':
            return $method === 'POST' ? handle_frontend_moderate_post() : false;
        case 'split_thread':
            return $method === 'POST' ? handle_split_thread_post() : false;
        case 'merge_thread':
            return $method === 'POST' ? handle_merge_thread_post() : false;
        case 'admin_roles':
            return handle_admin_roles_get();
        case 'admin_roles_action':
            return $method === 'POST' ? handle_admin_roles_action_post() : false;
        case 'admin_users':
            return handle_admin_users_get();
        case 'admin_user_edit':
            return handle_admin_user_edit($method);
        case 'admin_create_user':
            return $method === 'POST' ? handle_admin_create_user_post() : false;
        case 'admin_categories':
            return handle_admin_categories($method);
        case 'delete_category':
            return $method === 'POST' ? handle_delete_category_post() : false;
        case 'update_category_order':
            return $method === 'POST' ? handle_update_category_order_post() : false;
        case 'admin_langs':
            return handle_admin_langs($method);
        case 'admin_diagnostics':
            return handle_admin_diagnostics_get();
        case 'admin_plugins':
            return handle_admin_plugins($method);
        case 'admin_themes':
            return handle_admin_themes($method);
        case 'admin_catalog':
            return handle_admin_catalog($method);
        case 'admin_updates':
            return handle_admin_updates($method);
        case 'delete_user':
            return $method === 'POST' ? handle_delete_user_post() : false;
        case 'ban_user':
            return $method === 'POST' ? handle_ban_user_post() : false;
        case 'unban_user':
            return $method === 'POST' ? handle_unban_user_post() : false;
        case 'suspend_user':
            return $method === 'POST' ? handle_suspend_user_post() : false;
        default:
            return false;
    }
}

function handle_admin_dashboard(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo; $config = App::getInstance()->config;

    $pendingStmt = $pdo->prepare("
        SELECT t.*, u.username as author
        FROM threads t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.status = 'pending'
        ORDER BY t.created_at DESC
    ");
    $pendingStmt->execute();
    $pendingThreads = $pendingStmt->fetchAll();

    $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();

    $adminError = '';
    $adminSuccess = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        if (!csrf_validate_request()) {
            $adminError = 'Invalid CSRF token';
        } else {
            $siteName = trim($_POST['site_name'] ?? $config['site_name']);
            $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
            $availableLangs = array_filter(array_map('trim', explode(',', $_POST['available_langs'] ?? implode(',', $config['available_langs'] ?? ['en']))));

            $config['site_name'] = $siteName;
            $config['default_lang'] = $defaultLang;
            $config['available_langs'] = array_values($availableLangs);

            if (file_put_contents(__DIR__ . '/../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
                $adminSuccess = 'Settings saved successfully';
            } else {
                $adminError = 'Failed to save settings';
            }
        }

        return redirect(url('admin_settings'));
    }

    include __DIR__ . '/../../views/admin.php';
    return true;
}
