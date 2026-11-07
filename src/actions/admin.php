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
        throw new \Bulletin\NotFoundException('Admin action not found');
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
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_moderate_post();
        case 'frontend_moderate':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_frontend_moderate_post();
        case 'split_thread':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_split_thread_post();
        case 'merge_thread':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_merge_thread_post();
        case 'admin_roles':
            return handle_admin_roles_get();
        case 'admin_roles_action':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_admin_roles_action_post();
        case 'admin_users':
            return handle_admin_users_get();
        case 'admin_user_edit':
            return handle_admin_user_edit($method);
        case 'admin_create_user':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_admin_create_user_post();
        case 'admin_categories':
            return handle_admin_categories($method);
        case 'delete_category':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_delete_category_post();
        case 'update_category_order':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_update_category_order_post();
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
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_delete_user_post();
        case 'ban_user':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_ban_user_post();
        case 'unban_user':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_unban_user_post();
        case 'suspend_user':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_suspend_user_post();
        default:
            throw new \Bulletin\NotFoundException('Admin action not found');
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
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        } elseif (!rate_limit('admin_dashboard_settings', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            throw new \Bulletin\TooManyRequestsException('You are changing settings too fast. Please try again later.');
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
                throw new \Bulletin\HttpException('Failed to save settings', 500);
            }
        }

        return redirect(url('admin_settings'));
    }

    include __DIR__ . '/../../views/admin.php';
    return true;
}
