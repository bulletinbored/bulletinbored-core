<?php
/**
 * helpers.php — framework-agnostic helper functions.
 *
 * Loaded after bootstrap.php. Functions are organized into logical modules
 * under src/Helpers/ for maintainability.
 */

require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Helpers/Url.php';
require_once __DIR__ . '/Helpers/AuthHelpers.php';
require_once __DIR__ . '/Helpers/Upload.php';
require_once __DIR__ . '/Helpers/Mail.php';
require_once __DIR__ . '/Helpers/Notifications.php';
require_once __DIR__ . '/Helpers/Text.php';
require_once __DIR__ . '/Helpers/Avatar.php';
require_once __DIR__ . '/Helpers/Data.php';

/**
 * Number of posts shown per page inside a thread.
 * Kept in one place so pagination maths (e.g. the "last activity" link)
 * can never drift from the query in actions/posts-thread.php.
 */
if (!defined('POSTS_PER_PAGE')) {
    define('POSTS_PER_PAGE', 15);
}

/**
 * Generate a redirect response.
 */
function redirect(string $url, int $status = 302): \Bulletin\Response {
    return \Bulletin\Response::redirect($url, $status);
}

/**
 * Get the base URL of the forum.
 */
function base_url() {
    static $baseUrl = null;
    if ($baseUrl === null) {
        $base = App::getInstance()->config['base_url'] ?? '';
        if (!empty($base)) {
            $baseUrl = rtrim($base, '/');
        } else {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            if ($baseUrl === '' || $baseUrl === '\\') {
                $baseUrl = '';
            }
        }
    }
    return $baseUrl;
}
