<?php

require_once __DIR__ . '/actions/admin.php';
require_once __DIR__ . '/actions/posts.php';
require_once __DIR__ . '/actions/users.php';
require_once __DIR__ . '/actions/content.php';
require_once __DIR__ . '/actions/misc.php';

// Redirect banned users to home
if (is_logged_in() && (is_banned() || is_suspended())) {
    session_destroy();
    redirect(url('home'));
}

$action = $_GET['action'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'home' || $action === '') {
        // All discussions listing
        $page = max(1, (int)($_GET['page'] ?? 1));
        $sort = $_GET['sort'] ?? 'latest';

        $listing     = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'sticky_first' => false]);
        $threads     = $listing['threads'];
        $total       = $listing['total'];
        $totalPages  = $listing['pages'];
        $page        = $listing['page'];
        $sort        = $listing['sort'];
        $listContext = 'home';

        $categories = sidebar_categories();
        include __DIR__ . '/../views/home.php';
    } 
    elseif (handle_admin_action($action, $method)) {
        // handled in src/actions/admin.php
    }
    elseif (handle_posts_action($action, $method)) {
        // handled in src/actions/posts.php
    }
    elseif (handle_users_action($action, $method)) {
        // handled in src/actions/users.php
    }
    elseif (handle_content_action($action, $method)) {
        // handled in src/actions/content.php
    }
    elseif (handle_misc_action($action, $method)) {
        // handled in src/actions/misc.php
    }
    else {
        // Not found
        http_response_code(404);
        echo 'Page not found';
    }
} catch (Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if (!empty($config['debug']) || (isset($_GET['debug']) && $_GET['debug'] === '1')) {
        echo 'DEBUG ERROR: ' . escape($e->getMessage()) . "\n\n" . escape($e->getTraceAsString());
    } else {
        echo 'An error occurred. Please try again later.';
    }
}
?>
