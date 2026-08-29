<?php
/**
 * helpers.php — framework-agnostic helper functions.
 *
 * Loaded after bootstrap.php. Everything here is pure PHP and depends only on
 * the global $config (for base_url / mail settings) so it can be reused from
 * anywhere once the bootstrap has run.
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

    if ($reqPath === '' || $reqPath === 'index.php') {
        return 'home';
    }

    $map = [
        '#^thread/([0-9]+)(?:-[^/]+)?$#'            => 'thread',
        '#^category/([0-9]+)(?:-[^/]+)?$#'          => 'category',
        '#^u/([^/]+)$#'                              => 'profile',
        '#^edit-post/([0-9]+)$#'                    => 'edit_post',
        '#^delete-post/([0-9]+)$#'                  => 'delete_post',
        '#^edit-thread/([0-9]+)$#'                  => 'edit_thread',
        '#^delete-thread/([0-9]+)$#'                => 'delete_thread',
        '#^download/([0-9]+)$#'                     => 'download',
        '#^admin$#'                                  => 'admin',
        '#^admin/moderation$#'                       => 'admin_moderation',
        '#^admin/categories$#'                       => 'admin_categories',
        '#^admin/users$#'                            => 'admin_users',
        '#^admin/users/([0-9]+)/edit$#'             => 'admin_user_edit',
        '#^admin/create-user$#'                      => 'admin_create_user',
        '#^admin/settings$#'                         => 'admin_settings',
        '#^admin/langs$#'                           => 'admin_langs',
        '#^admin/catalog$#'                         => 'admin_catalog',
        '#^admin/plugins$#'                         => 'admin_plugins',
        '#^admin/themes$#'                          => 'admin_themes',
        '#^admin/diagnostics$#'                     => 'admin_diagnostics',
        '#^admin/updates$#'                         => 'admin_updates',
        '#^admin/roles$#'                           => 'admin_roles',
        '#^admin/roles-action$#'                    => 'admin_roles_action',
        '#^admin/moderate$#'                        => 'moderate',
        '#^admin/delete-category$#'                 => 'delete_category',
        '#^admin/update-category-order$#'            => 'update_category_order',
        '#^admin/delete-user$#'                     => 'delete_user',
        '#^admin/ban-user$#'                        => 'ban_user',
        '#^admin/unban-user$#'                      => 'unban_user',
        '#^edit-profile$#'                          => 'edit_profile',
        '#^remove-avatar$#'                         => 'remove_avatar',
        '#^forgot-password$#'                       => 'forgot_password',
        '#^reset-password$#'                        => 'reset_password',
        '#^verify-email$#'                          => 'verify_email',
        '#^new-thread$#'                            => 'new_thread',
        '#^messages$#'                              => 'messages',
        '#^notifications$#'                        => 'notifications',
    ];

    foreach ($map as $pattern => $action) {
        if (preg_match($pattern, $reqPath)) {
            return $action;
        }
    }

    if (preg_match('#^[^/]+$#', $reqPath)) {
        return $reqPath;
    }

    return 'home';
}

function escape($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function is_banned() { return ($_SESSION['user_status'] ?? '') === 'banned'; }

function is_suspended() {
    $status = $_SESSION['user_status'] ?? '';
    $suspensionTime = $_SESSION['user_suspension_time'] ?? 0;
    if ($status === 'suspended' && $suspensionTime > 0 && time() >= $suspensionTime) {
        return false;
    }
    return $status === 'suspended';
}

/**
 * Render post content for display.
 *
 * Delegates to bb_render_content() which parses Markdown securely on the
 * server. User HTML is never trusted, so the core renders Markdown only.
 * Kept under the historical name because every view calls marked_parse().
 */
function marked_parse($text) {
    return bb_render_content($text);
}

/**
 * Validate an uploaded file against a strict MIME + extension whitelist and
 * return a safe, randomly-named destination filename derived from the real
 * MIME type. Returns null when the file is rejected.
 *
 * Zero-dependency: uses finfo (or mime_content_type as fallback) on the
 * temporary file, never trusts the client-supplied extension or content-type.
 *
 * @param string $tmpPath  Path to the uploaded temporary file.
 * @param string $origName Original client filename (used only for the DB record).
 * @param array  $allowed  Map of real MIME => safe extension, e.g.
 *                         ['image/jpeg' => 'jpg', 'image/png' => 'png'].
 * @param int    $maxSize  Maximum allowed size in bytes.
 * @return array|null      ['mime' => ..., 'ext' => ..., 'safe_name' => ...] or null.
 */
function validate_upload(string $tmpPath, string $origName, array $allowed, int $maxSize): ?array
{
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return null;
    }
    if (filesize($tmpPath) > $maxSize) {
        return null;
    }

    // Detect the real MIME type from the file content, never the extension.
    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpPath);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
    }
    if (!is_string($mime) || !isset($allowed[$mime])) {
        return null;
    }

    // For images, run an extra structural check via getimagesize().
    if (str_starts_with($mime, 'image/')) {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return null;
        }
    }

    $ext = $allowed[$mime];
    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;

    return ['mime' => $mime, 'ext' => $ext, 'safe_name' => $safeName];
}
function is_logged_in() { return isset($_SESSION['user_id']); }
function is_admin() { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function user_has_permission(string $permission): bool {
    if (is_admin()) return true;
    $roleName = $_SESSION['user_role'] ?? 'user';
    global $pdo, $pluginManager;
    $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE name = ?");
    $stmt->execute([$roleName]);
    $perms = json_decode($stmt->fetchColumn() ?: '[]', true) ?: [];
    if (in_array($permission, $perms, true)) return true;
    if (isset($pluginManager)) {
        return $pluginManager->checkHook('permission_' . $permission, $roleName);
    }
    return false;
}
function redirect($url) { header("Location: $url"); exit; }
function base_url() {
    static $baseUrl = null;
    if ($baseUrl === null) {
        if (!empty($GLOBALS['config']['base_url'])) {
            $baseUrl = rtrim($GLOBALS['config']['base_url'], '/');
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

function log_security_event(string $event, array $context = []): void {
    static $logDir = null;
    if ($logDir === null) {
        $logDir = __DIR__ . '/../data/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    if (!is_dir($logDir) || !is_writable($logDir)) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $line = sprintf(
        "[%s] %s ip=%s %s\n",
        date('c'),
        $event,
        $ip,
        json_encode($context, JSON_UNESCAPED_UNICODE)
    );
    @file_put_contents($logDir . '/security.log', $line, FILE_APPEND | LOCK_EX);
}

function log_admin_action(string $action, array $context = []): void {
    $userId = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? 'unknown';
    $ctx = array_merge(['admin_id' => $userId, 'admin_user' => $username], $context);
    log_security_event('admin_' . $action, $ctx);
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function validate_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
function csrf_validate_request(): bool
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token($token)) {
        return false;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
}
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . escape($token) . '">';
}

/**
 * File-based rate limiter (no dependencies).
 *
 * Tracks attempts per (action, key) within a sliding window and returns false
 * once the limit is exceeded. The key is normally the client IP, optionally
 * combined with the user id for login-style limits. State lives under
 * data/ratelimit/ as one small JSON file per bucket.
 *
 * @param string $action   Logical bucket, e.g. 'login', 'register', 'post'.
 * @param int    $max      Max attempts allowed in the window.
 * @param int    $window   Window length in seconds.
 * @param string $key      Bucket discriminator (defaults to client IP).
 * @return bool            true if the request is allowed, false if throttled.
 */
function rate_limit($action, $max = 10, $window = 300, $key = null) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (is_string($ip) && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $key = $key ?? $ip;
    $bucket = preg_replace('/[^a-z0-9._-]/i', '_', $action . '_' . $key);

    $dir = __DIR__ . '/../data/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . $bucket . '.json';

    $now = time();
    $hits = [];
    if (is_file($file)) {
        $decoded = json_decode(@file_get_contents($file), true);
        if (is_array($decoded)) {
            // Keep only timestamps inside the window.
            $hits = array_values(array_filter($decoded, function ($ts) use ($now, $window) {
                return is_int($ts) && ($now - $ts) < $window;
            }));
        }
    }

    if (count($hits) >= $max) {
        return false;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function validate_input($data) {
    if (!is_string($data)) {
        return '';
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function clean_text($data) {
    if (!is_string($data)) {
        return '';
    }
    return stripslashes(trim($data));
}

/* ------------------------------------------------------------------
 * Presentation helpers used by the frontend views
 * ------------------------------------------------------------------ */

// Human readable "3 days ago" style timestamps.
function time_ago($datetime) {
    if (empty($datetime)) return '';
    $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    if (!$ts) return escape((string)$datetime);

    $diff = time() - $ts;
    if ($diff < 0) $diff = 0;

    if ($diff < 60)      return t('time_now');
    if ($diff < 3600)    { $n = (int)floor($diff / 60);       return t($n === 1 ? 'time_minute' : 'time_minutes', ['n' => $n]); }
    if ($diff < 86400)   { $n = (int)floor($diff / 3600);     return t($n === 1 ? 'time_hour'   : 'time_hours',   ['n' => $n]); }
    if ($diff < 2592000) { $n = (int)floor($diff / 86400);    return t($n === 1 ? 'time_day'    : 'time_days',    ['n' => $n]); }
    if ($diff < 31536000){ $n = (int)floor($diff / 2592000);  return t($n === 1 ? 'time_month'  : 'time_months',  ['n' => $n]); }
    $n = (int)floor($diff / 31536000);
    return t($n === 1 ? 'time_year' : 'time_years', ['n' => $n]);
}

// 1200 => 1.2K, 1500000 => 1.5M
function compact_number($n) {
    $n = (int)$n;
    if ($n < 1000) return (string)$n;
    if ($n < 1000000) return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.').'K';
    return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.').'M';
}

// Plain text preview of a post, HTML/markdown stripped.
function excerpt($text, $length = 110) {
    $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text);
    $text = strip_tags($text);
    $text = preg_replace('/!?\[([^\]]*)\]\([^\)]*\)/', '$1', $text);   // md links/images
    $text = preg_replace('/[#>*_`~]+/', ' ', $text);                   // md decorations
    $text = preg_replace('/^\s*[-+]\s+/m', '', $text);                 // md list bullets
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $length) {
        return mb_substr($text, 0, $length, 'UTF-8').'…';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $length) {
        return substr($text, 0, $length).'…';
    }
    return $text;
}

// Initial letter used by the fallback avatar.
function avatar_initial($name) {
    $name = trim((string)$name);
    if ($name === '') return '?';
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
}

// Stable pastel-ish colour derived from the username.
function avatar_color($name) {
    $palette = ['#4c6ef5', '#7048e8', '#e8590c', '#2b8a3e', '#c2255c', '#1098ad', '#5f3dc4', '#b08900'];
    return $palette[abs(crc32((string)$name)) % count($palette)];
}

// Renders the avatar of a user (uploaded image or coloured initial).
function render_avatar($username, $avatar = '', $size = 44, $class = '') {
    $classes = trim('avatar '.$class);
    if (!empty($avatar)) {
        return '<img src="'.base_url().'/uploads/avatars/'.escape($avatar).'" alt="'.escape($username).'" '
             . 'class="'.escape($classes).'" style="width:'.(int)$size.'px;height:'.(int)$size.'px">';
    }
    return '<span class="'.escape($classes).' avatar-initial" style="width:'.(int)$size.'px;height:'.(int)$size.'px;'
         . 'background-color:'.avatar_color($username).';font-size:'.round($size * 0.42).'px" aria-hidden="true">'
         . escape(avatar_initial($username)).'</span>';
}

// Categories with their discussion counters, used by the sidebar.
function sidebar_categories() {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = $pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM threads t
                      WHERE t.category_id = c.id
                        AND t.status IN ('visible','sticky','locked')) AS thread_count
            FROM categories c
            ORDER BY c.position, c.name
        ")->fetchAll();
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

// Aggregated board counters shown in the sidebar statistics block.
function forum_statistics() {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;

    $stats = [
        'threads' => 0, 'posts' => 0, 'members' => 0,
        'categories' => 0, 'contributors' => 0, 'newest_member' => null,
    ];
    try {
        $stats['threads']    = (int)$pdo->query("SELECT COUNT(*) FROM threads WHERE status IN ('visible','sticky','locked')")->fetchColumn();
        $stats['posts']      = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'visible'")->fetchColumn();
        $stats['members']    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['categories'] = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        $stats['contributors'] = (int)$pdo->query("
            SELECT COUNT(*) FROM (
                SELECT user_id FROM threads WHERE status IN ('visible','sticky','locked')
                UNION
                SELECT user_id FROM posts WHERE status = 'visible'
            ) AS c
        ")->fetchColumn();
        $stats['newest_member'] = $pdo->query("SELECT username, avatar FROM users ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    } catch (PDOException $e) {}

    $cache = $stats;
    return $cache;
}

// Sorting options offered on every discussion listing.
function thread_sort_options() {
    return [
        'latest'  => 'sort_latest',
        'replies' => 'sort_replies',
        'views'   => 'sort_views',
        'newest'  => 'sort_newest',
        'oldest'  => 'sort_oldest',
    ];
}

/**
 * Single entry point for every discussion listing (home, category, search,
 * profile). Returns the threads enriched with reply counters and last
 * activity, plus the pagination metadata.
 */
function fetch_threads(array $opts = []) {
    global $pdo;

    $page    = max(1, (int)($opts['page'] ?? 1));
    $perPage = max(1, (int)($opts['per_page'] ?? 15));
    $offset  = ($page - 1) * $perPage;

    $where  = ["t.status IN ('visible','sticky','locked')"];
    $params = [];

    if (!empty($opts['category_id'])) {
        $where[]  = 't.category_id = ?';
        $params[] = (int)$opts['category_id'];
    }
    if (!empty($opts['user_id'])) {
        $where[]  = 't.user_id = ?';
        $params[] = (int)$opts['user_id'];
    }
    if (isset($opts['search']) && $opts['search'] !== '') {
        $where[] = '(t.title LIKE ? OR t.content LIKE ? OR c.name LIKE ? OR u.username LIKE ?)';
        $like    = '%'.$opts['search'].'%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = 'WHERE '.implode(' AND ', $where);

    $sort   = $opts['sort'] ?? 'latest';
    $stickyFirst = $opts['sticky_first'] ?? true;
    $stickyOrder = $stickyFirst ? 'sticky_first DESC, ' : '';
    $orders = [
        'latest'  => $stickyOrder."last_activity DESC",
        'replies' => $stickyOrder."reply_count DESC, last_activity DESC",
        'views'   => $stickyOrder."view_count DESC, last_activity DESC",
        'newest'  => $stickyOrder."t.created_at DESC, t.id DESC",
        'oldest'  => $stickyOrder."t.created_at ASC, t.id ASC",
    ];
    $orderSql = $orders[$sort] ?? $orders['latest'];

    $joins = "
        FROM threads t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN categories c ON t.category_id = c.id
    ";

    $total = 0;
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) $joins $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {}

    $sql = "
        SELECT t.*,
               u.username AS author,
               u.avatar   AS author_avatar,
               c.name     AS category_name,
               COALESCE(t.views, 0) AS view_count,
               CASE WHEN t.status = 'sticky' THEN 1 ELSE 0 END AS sticky_first,
               (SELECT COUNT(*) FROM posts p
                 WHERE p.thread_id = t.id AND p.status = 'visible') AS reply_count,
               lp.id         AS last_post_id,
               lp.content    AS last_post_content,
               lp.created_at AS last_post_at,
               lu.username   AS last_author,
               lu.avatar     AS last_author_avatar,
               COALESCE(lp.created_at, t.created_at) AS last_activity
        $joins
        LEFT JOIN posts lp ON lp.id = (
            SELECT p2.id FROM posts p2
             WHERE p2.thread_id = t.id AND p2.status = 'visible'
             ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1
        )
        LEFT JOIN users lu ON lp.user_id = lu.id
        $whereSql
        ORDER BY $orderSql
        LIMIT ".(int)$perPage." OFFSET ".(int)$offset."
    ";

    $threads = [];
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $threads = $stmt->fetchAll();
    } catch (PDOException $e) {}

    return [
        'threads'  => $threads,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => max(1, (int)ceil($total / $perPage)),
        'sort'     => isset($orders[$sort]) ? $sort : 'latest',
    ];
}

// Email notification helper
function send_email($to, $subject, $body) {
    global $config;
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$config['mail_from_name']} <{$config['mail_from']}>\r\n";
    $headers .= "X-Mailer: bulletinbored/1.0\r\n";

    $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; background: #f8f9fc; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #550296, #3d046f); color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; }
        .footer { background: #f8f9fc; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background: #550296; color: white; text-decoration: none; border-radius: 5px; }
    </style></head><body>
    <div class="container">
        <div class="header"><h2>'.escape($config['site_name'] ?? 'bulletinbored').'</h2></div>
        <div class="content">'.$body.'</div>
        <div class="footer">&copy; '.date('Y').' '.escape($config['site_name'] ?? 'bulletinbored').'</div>
    </div></body></html>';

    $envelope = '-f' . ($config['mail_from'] ?? '');
    if ($config['mail_method'] === 'smtp') {
        $host = $config['mail_host'] ?? 'localhost';
        $port = (int)($config['mail_port'] ?? 25);
        $username = $config['mail_username'] ?? '';
        $password = $config['mail_password'] ?? '';
        $secure = strtolower($config['mail_secure'] ?? '');
        $timeout = (int)($config['mail_timeout'] ?? 10);

        $connectHost = $secure === 'ssl' ? 'ssl://' . $host : $host;

        $fp = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            error_log("SMTP connect failed: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($fp, $timeout);

        $readResponse = function($fp) {
            $response = '';
            while (($line = fgets($fp, 515)) !== false) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        $sendCommand = function($fp, $command) {
            fwrite($fp, $command . "\r\n");
        };

        $readResponse($fp);
        $sendCommand($fp, 'EHLO ' . (php_uname('n') ?: 'localhost'));
        $readResponse($fp);

        if ($secure === 'tls') {
            $sendCommand($fp, 'STARTTLS');
            $readResponse($fp);
            if (stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $sendCommand($fp, 'EHLO ' . (php_uname('n') ?: 'localhost'));
                $readResponse($fp);
            }
        }

        if ($username !== '' && $password !== '') {
            $sendCommand($fp, 'AUTH LOGIN');
            $readResponse($fp);
            $sendCommand($fp, base64_encode($username));
            $readResponse($fp);
            $sendCommand($fp, base64_encode($password));
            $readResponse($fp);
        }

        $sendCommand($fp, 'MAIL FROM:<' . $config['mail_from'] . '>');
        $readResponse($fp);
        $sendCommand($fp, 'RCPT TO:<' . $to . '>');
        $readResponse($fp);
        $sendCommand($fp, 'DATA');
        $readResponse($fp);

        fwrite($fp, "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n");
        fwrite($fp, "From: {$config['mail_from_name']} <{$config['mail_from']}>\r\n");
        fwrite($fp, "To: {$to}\r\n");
        fwrite($fp, "MIME-Version: 1.0\r\n");
        fwrite($fp, "Content-Type: text/html; charset=UTF-8\r\n");
        fwrite($fp, "\r\n");
        fwrite($fp, $htmlBody . "\r\n");
        fwrite($fp, ".\r\n");
        $readResponse($fp);

        $sendCommand($fp, 'QUIT');
        $readResponse($fp);
        fclose($fp);
        return true;
    }
    return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers, $envelope);
}

// Notify the thread author and watchers about a new reply (case 1).
// Creates in-app notifications; skips the user who posted the reply.
function notify_thread_reply($thread, int $authorId, string $content): void
{
    global $pdo;
    if (!isset($pdo) || !$pdo) {
        return;
    }
    $threadId = (int)($thread['id'] ?? 0);
    if ($threadId <= 0) {
        return;
    }
    $title = $thread['title'] ?? '';
    $authorName = '';
    $authorStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $authorStmt->execute([$authorId]);
    $authorName = (string)($authorStmt->fetchColumn() ?: '');

    $subject = t('reply_notification_subject', ['title' => $title]);
    $body = t('reply_notification_body', [
        'username' => $authorName,
        'author' => $authorName,
        'title' => $title,
        'link' => url('thread', ['id' => $threadId, 'slug' => slugify($title)], true),
    ]);

    $recipients = [];
    // Thread author.
    if (!empty($thread['user_id']) && (int)$thread['user_id'] !== $authorId) {
        $recipients[(int)$thread['user_id']] = true;
    }
    // Watchers.
    try {
        $wStmt = $pdo->prepare("SELECT user_id FROM thread_watchers WHERE thread_id = ?");
        $wStmt->execute([$threadId]);
        foreach ($wStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $uid = (int)$uid;
            if ($uid !== $authorId) {
                $recipients[$uid] = true;
            }
        }
    } catch (Throwable $e) {}

    $now = date('Y-m-d H:i:s');
    $link = url('thread', ['id' => $threadId, 'slug' => slugify($title)], true);
    $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES (?, 'reply', ?, ?, ?, 0, ?)");
    foreach (array_keys($recipients) as $uid) {
        try {
            $ins->execute([$uid, $subject, $body, $link, $now]);
        } catch (Throwable $e) {}
    }
}

// Notify the administrator about a new user registration (cases 6).
function notify_admin_new_user($username, $email = '') {
    global $config;
    $recipient = !empty($config['notify_admin_email']) ? $config['notify_admin_email'] : ($config['mail_from'] ?? '');
    if (empty($recipient)) {
        return false;
    }
    $subject = t('new_user_subject', ['username' => $username]);
    $body = t('new_user_body', [
        'site' => $config['site_name'] ?? 'bulletinbored',
        'username' => escape($username),
        'email' => $email !== '' ? escape($email) : '-',
        'link' => url('admin_users', [], true),
    ]);
    return send_email($recipient, $subject, $body);
}

// Notify mentioned users about a new post (case 7). Returns count of emails sent.
function notify_mentioned_users($pdo, $content, $threadId, $threadTitle, $authorName) {
    if (!preg_match_all('/@([a-zA-Z0-9_]+)/', $content, $matches)) {
        return 0;
    }
    $usernames = array_unique($matches[1]);
    $sent = 0;
    $threadLink = url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)], true);
    foreach ($usernames as $username) {
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE username = ? AND email IS NOT NULL AND email <> ''");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user) {
            continue;
        }
        $subject = t('mentioned_subject', ['title' => $threadTitle]);
        $body = t('mentioned_body', [
            'username' => escape($user['username'] ?? $username),
            'author' => escape($authorName),
            'title' => escape($threadTitle),
            'link' => $threadLink,
        ]);
        if (send_email($user['email'], $subject, $body)) {
            $sent++;
        }
    }
    return $sent;
}

// Idempotently ensure the private_messages table exists. The table is normally
// created by setup.php, but a migrated/production database may be missing it,
// which would otherwise cause a 500 on the messages page and the textmebored
// API. Works on both MySQL and SQLite.
function ensure_private_messages_table($pdo) {
    $cfg = $GLOBALS['config'] ?? [];
    $driver = ($cfg['db_driver'] ?? 'sqlite');
    if ($driver === 'mysql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS private_messages (
                id INT PRIMARY KEY AUTO_INCREMENT,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                subject TEXT,
                content TEXT NOT NULL,
                is_read INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        try { $pdo->exec("CREATE INDEX idx_pm_recipient ON private_messages(recipient_id, is_read, created_at)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_pm_sender ON private_messages(sender_id, created_at)"); } catch (Throwable $e) {}
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS private_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                subject TEXT DEFAULT '',
                content TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_recipient ON private_messages(recipient_id, is_read, created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_sender ON private_messages(sender_id, created_at)");
    }
}

function create_notification(PDO $pdo, int $userId, string $type, string $title, string $message, string $link = ''): void
{
    $cfg = $GLOBALS['config'] ?? [];
    $driver = ($cfg['db_driver'] ?? 'sqlite');
    if ($driver === 'mysql') {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $title, $message, $link]);
    } else {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $title, $message, $link]);
    }
}

/**
 * Render a human-readable label for a notification row. The stored message may
 * be a raw key (e.g. "pm_notification") emitted by plugins, so we translate the
 * known types and fall back to the stored message text when it is already
 * readable.
 */
function notification_label(array $n): string
{
    $type = $n['type'] ?? '';
    $msg = $n['message'] ?? '';
    $map = [
        'pm'             => 'pm_notification',
        'pm_notification'=> 'pm_notification',
        'vote'           => 'vote_notification',
        'reply'          => 'reply_notification',
        'mention'        => 'mentioned_notification',
        'follow'         => 'new_follower_notification',
        'role'           => 'role_updated_notification',
        'note'           => 'note_notification',
        'note_notification' => 'note_notification',
    ];
    if (isset($map[$type])) {
        return t($map[$type]);
    }
    // If the message looks like a key (no spaces), translate via i18n too.
    // Only do so when the key actually exists, otherwise we would echo the
    // raw key (e.g. "note_notification") to the user.
    if (preg_match('/^[a-z_]+$/', $msg)) {
        $translated = t($msg);
        if ($translated !== $msg) {
            return $translated;
        }
    }
    return $msg;
}
