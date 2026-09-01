<?php

/**
 * URL generation and slug utilities.
 */

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    return empty($text) ? 'n-a' : $text;
}

function url($action, $params = [], $absolute = false) {
    $base = base_url();
    if ($absolute) {
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $scheme = 'https';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host . $base;
    }
    $query = $params;
    switch ($action) {
        case 'thread':
            $id = $params['id'] ?? 0;
            $slug = $params['slug'] ?? '';
            unset($query['id'], $query['slug']);
            return $base . '/thread/' . $id . ($slug ? '-' . $slug : '') . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'category':
            $id = $params['id'] ?? 0;
            $slug = $params['slug'] ?? '';
            unset($query['id'], $query['slug']);
            return $base . '/category/' . $id . ($slug ? '-' . $slug : '') . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'profile':
            $user = $params['user'] ?? '';
            unset($query['user']);
            return $base . '/u/' . urlencode($user) . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'home':
            return $base . '/' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'login':
            return $base . '/login' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'register':
            return $base . '/register' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'logout':
            return $base . '/logout' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'forgot_password':
            return $base . '/forgot-password' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'reset_password':
            return $base . '/reset-password' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'verify_email':
            return $base . '/verify-email' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'new_thread':
            return $base . '/new-thread' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'edit_profile':
            return $base . '/edit-profile' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'edit_post':
            $id = $params['id'] ?? 0;
            unset($query['id']);
            return $base . '/edit-post/' . $id . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'delete_post':
            $id = $params['id'] ?? 0;
            unset($query['id']);
            return $base . '/delete-post/' . $id . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'edit_thread':
            $id = $params['id'] ?? 0;
            unset($query['id']);
            return $base . '/edit-thread/' . $id . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'delete_thread':
            $id = $params['id'] ?? 0;
            unset($query['id']);
            return $base . '/delete-thread/' . $id . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'reply':
            return $base . '/reply' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'watch':
            return $base . '/watch' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'unwatch':
            return $base . '/unwatch' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'notifications':
            return $base . '/notifications' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'search':
            return $base . '/search' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin':
            return $base . '/admin' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_moderation':
            return $base . '/admin/moderation' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_categories':
            return $base . '/admin/categories' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_users':
            return $base . '/admin/users' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_user_edit':
            return $base . '/admin/users/' . ($params['id'] ?? 0) . '/edit' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_create_user':
            return $base . '/admin/create-user' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_settings':
            return $base . '/admin/settings' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_smtp':
            return $base . '/admin/smtp' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_langs':
            return $base . '/admin/langs' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_plugins':
            return $base . '/admin/plugins' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_diagnostics':
            return $base . '/admin/diagnostics' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_catalog':
            return $base . '/admin/catalog' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_themes':
            return $base . '/admin/themes' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_updates':
            return $base . '/admin/updates' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_roles':
            return $base . '/admin/roles' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_roles_action':
            return $base . '/admin/roles-action' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'moderate':
            return $base . '/admin/moderate' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'frontend_moderate':
            return $base . '/admin/front-moderate' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'split_thread':
            return $base . '/admin/split-thread' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'merge_thread':
            return $base . '/admin/merge-thread' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'create_category':
        case 'edit_category':
            return $base . '/admin/categories' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'delete_category':
            return $base . '/admin/delete-category' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'update_category_order':
            return $base . '/admin/update-category-order' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'delete_user':
            return $base . '/admin/delete-user' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'ban_user':
            return $base . '/admin/ban-user' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'unban_user':
            return $base . '/admin/unban-user' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'download':
            $id = (int)($params['id'] ?? 0);
            unset($query['id']);
            return $base . '/download/' . $id . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'do_login':
        case 'do_register':
        case 'do_forgot_password':
        case 'do_reset_password':
        case 'create_thread':
        case 'update_profile':
        case 'upload_avatar':
        case 'remove_avatar':
            $path = [
                'do_login' => '/login',
                'do_register' => '/register',
                'do_forgot_password' => '/forgot-password',
                'do_reset_password' => '/reset-password',
                'create_thread' => '/new-thread',
                'update_profile' => '/edit-profile',
                'upload_avatar' => '/edit-profile',
                'remove_avatar' => '/remove-avatar',
            ][$action];
            return $base . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        default:
            return $base . '/' . ltrim($action, '/') . (!empty($query) ? '?' . http_build_query($query) : '');
    }
}

function current_route_action(): string
{
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $reqPath = ltrim($reqPath, '/');
    $base = trim(base_url(), '/');
    if ($base !== '') {
        if ($reqPath === $base) {
            $reqPath = '';
        } elseif (str_starts_with($reqPath, $base . '/')) {
            $reqPath = substr($reqPath, strlen($base) + 1);
        }
    }
    $segments = explode('/', $reqPath);
    $action = $segments[0] ?? '';
    // Normalize dashes to underscores for route matching
    $action = str_replace('-', '_', $action);
    return $action ?: 'home';
}
