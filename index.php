<?php
session_start();

// Load configuration
require __DIR__.'/config.php';

// Localization
$lang = $_GET['lang'] ?? $config['default_lang'] ?? 'en';
if (!in_array($lang, $config['available_langs'] ?? ['en'])) {
    $lang = $config['default_lang'] ?? 'en';
}
setcookie('lang', $lang, time() + 365*24*60*60, '/');
$translations = [];
$langFile = __DIR__.'/lang/'.$lang.'.php';
if (file_exists($langFile)) {
    $translations = include $langFile;
}

function t($key, $params = []) {
    global $translations;
    $text = $translations[$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace('{'.$k.'}', $v, $text);
    }
    return $text;
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    return empty($text) ? 'n-a' : $text;
}

function url($action, $params = []) {
    $base = base_url();
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
            return $base . '/';
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
        case 'reply':
            return $base . '/reply' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'watch':
            return $base . '/watch' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'unwatch':
            return $base . '/unwatch' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'notifications':
            return $base . '/notifications' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'profile':
            $user = $params['user'] ?? '';
            unset($query['user']);
            return $base . '/u/' . urlencode($user) . (!empty($query) ? '?' . http_build_query($query) : '');
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
        case 'admin_settings':
            return $base . '/admin/settings' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_langs':
            return $base . '/admin/langs' . (!empty($query) ? '?' . http_build_query($query) : '');
        case 'admin_plugins':
            return $base . '/admin/plugins' . (!empty($query) ? '?' . http_build_query($query) : '');
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
case 'delete_user':
             return $base . '/admin/delete-user' . (!empty($query) ? '?' . http_build_query($query) : '');
         case 'ban_user':
             return $base . '/admin/ban-user' . (!empty($query) ? '?' . http_build_query($query) : '');
         case 'unban_user':
             return $base . '/admin/unban-user' . (!empty($query) ? '?' . http_build_query($query) : '');
         case 'do_login':
        case 'do_register':
        case 'do_forgot_password':
        case 'do_reset_password':
        case 'create_thread':
        case 'update_profile':
        case 'upload_avatar':
        case 'editbored_upload':
            $path = [
                'do_login' => '/login',
                'do_register' => '/register',
                'do_forgot_password' => '/forgot-password',
                'do_reset_password' => '/reset-password',
                'create_thread' => '/new-thread',
                'update_profile' => '/edit-profile',
                'upload_avatar' => '/edit-profile',
                'editbored_upload' => '/editbored-upload',
            ][$action];
            return $base . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        default:
            return $base . '/' . ltrim($action, '/') . (!empty($query) ? '?' . http_build_query($query) : '');
    }
}

// Ensure directories exist
foreach (['data','plugins','uploads','uploads/avatars'] as $d) {
    $dir = __DIR__.'/'.$d;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$dbPath = $config['db_path'];
$dbDriver = $config['db_driver'] ?? 'sqlite';

// Database initialization
if ($dbDriver === 'mysql') {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // MySQL tables
    $tables = [
        "users" => "id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255), role VARCHAR(50) DEFAULT 'user', avatar VARCHAR(255), status VARCHAR(50) DEFAULT 'active', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "categories" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, position INT DEFAULT 0",
        "threads" => "id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT, title TEXT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "posts" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "uploads" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, post_id INT, user_id INT, filename VARCHAR(255), original_name VARCHAR(255), size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "thread_watchers" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_watch (thread_id, user_id)",
        "notifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) DEFAULT 'info', title TEXT NOT NULL, message TEXT, link TEXT, read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "private_messages" => "id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, subject TEXT DEFAULT '', content TEXT NOT NULL, read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "roles" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, permissions TEXT DEFAULT '[]', created_at DATETIME DEFAULT CURRENT_TIMESTAMP"
    ];
    
    foreach ($tables as $name => $schema) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($schema)");
    }
    
    // Create admin user if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')")
            ->execute([$config['admin_user'], password_hash($config['admin_pass'], PASSWORD_DEFAULT)]);
    }
    
    // Create default roles if not exists
    $defaultRoles = [
        ['admin', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads', 'can_ban_users', 'can_manage_roles'])],
        ['moderator', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads'])],
        ['user', json_encode(['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts'])],
    ];
    foreach ($defaultRoles as $role) {
        $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute($role);
    }
    
    // Create default category
    $pdo->prepare("INSERT IGNORE INTO categories (name, description, position) VALUES ('General', 'General discussion', 1)")->execute();
} else {
    // SQLite handling
    if (!file_exists($dbPath)) {
        // New database - create all tables
        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $pdo->exec("
CREATE TABLE users (
                 id INTEGER PRIMARY KEY AUTOINCREMENT,
                 username TEXT UNIQUE NOT NULL,
                 password TEXT NOT NULL,
                 email TEXT,
                 role TEXT DEFAULT 'user',
                 avatar TEXT,
                 status TEXT DEFAULT 'active',
                 created_at DATETIME DEFAULT CURRENT_TIMESTAMP
             );
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                position INTEGER DEFAULT 0
            );
            CREATE TABLE threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                user_id INTEGER,
                title TEXT NOT NULL,
                content TEXT,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                user_id INTEGER,
                content TEXT NOT NULL,
                status TEXT DEFAULT 'visible',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE uploads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER,
                post_id INTEGER,
                user_id INTEGER,
                filename TEXT,
                original_name TEXT,
                size INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE thread_watchers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(thread_id, user_id)
            );
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                title TEXT NOT NULL,
                message TEXT,
                link TEXT,
                read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE private_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                subject TEXT DEFAULT '',
                content TEXT NOT NULL,
                read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                permissions TEXT DEFAULT '[]',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                permissions TEXT DEFAULT '[]',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
        
        // Insert default data
        $pdo->exec("INSERT INTO users (username, password, role) VALUES ('admin', '".password_hash($config['admin_pass'], PASSWORD_DEFAULT)."', 'admin')");
        $pdo->exec("INSERT INTO categories (name, description, position) VALUES ('General', 'General discussion', 1)");
    } else {
        // Existing database - just connect
        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Safe column addition for threads table
        try {
            $cols = $pdo->query("PRAGMA table_info(threads)")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category_id', $cols)) {
                $pdo->exec("ALTER TABLE threads ADD COLUMN category_id INTEGER");
            }
            if (!in_array('updated_at', $cols)) {
                $pdo->exec("ALTER TABLE threads ADD COLUMN updated_at DATETIME");
            }
        } catch (PDOException $e) {
            // Ignore errors if columns already exist
        }
        
        // Safe column addition for users table (email, created_at, avatar)
        try {
            $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('email', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
            }
            if (!in_array('created_at', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            if (!in_array('avatar', $cols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN avatar TEXT");
            }
        } catch (PDOException $e) {}
        
        // Safe column addition for posts table
        try {
            $cols = $pdo->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN);
            // Add any missing columns for posts if needed
        } catch (PDOException $e) {}
        
        // Create password_resets table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token TEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {}
        
        // Create thread_watchers table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS thread_watchers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(thread_id, user_id)
                )
            ");
        } catch (PDOException $e) {}

        // Create notifications table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    type VARCHAR(50) DEFAULT 'info',
                    title TEXT NOT NULL,
                    message TEXT,
                    link TEXT,
                    read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {}

        // Create private_messages table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS private_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sender_id INTEGER NOT NULL,
                    recipient_id INTEGER NOT NULL,
                    subject TEXT DEFAULT '',
                    content TEXT NOT NULL,
                    read INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {}

        // Create roles table if not exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    permissions TEXT DEFAULT '[]',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {}

        // Insert default roles if not exists
        try {
            $defaultRoles = [
                ['admin', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads', 'can_ban_users', 'can_manage_roles'])],
                ['moderator', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads'])],
                ['user', json_encode(['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts'])],
            ];
            foreach ($defaultRoles as $role) {
                $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute($role);
            }
        } catch (PDOException $e) {}
    }
}

// Handle legacy database - add email + created_at if missing (SQLite migration for existing DB)
try {
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
    }
    if (!in_array('created_at', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('avatar', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar TEXT");
    }
    if (!in_array('status', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'active'");
    }
} catch (PDOException $e) {}

// Helper functions
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

function marked_parse($text) {
    if (empty($text)) return '';
    // Check if content is HTML (saved by editbored editor)
    // The content is escaped by validate_input(), so HTML tags appear as <tag>
    if (str_contains($text, '&lt;')) {
        // Unescape HTML and output directly
        return '<div class="markdown-content">' . html_entity_decode($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    // Content is markdown, JavaScript renderMarkdownContent() will parse it with marked.parse()
    return '<div class="markdown-content">' . $text . '</div>';
}
function is_logged_in() { return isset($_SESSION['user_id']); }
function is_admin() { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function redirect($url) { header("Location: $url"); exit; }
function base_url() {
    static $baseUrl = null;
    if ($baseUrl === null) {
        if (!empty($config['base_url'])) {
            $baseUrl = rtrim($config['base_url'], '/');
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
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function validate_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
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
        .header { background: linear-gradient(135deg, #4e73df, #224abe); color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; }
        .footer { background: #f8f9fc; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background: #4e73df; color: white; text-decoration: none; border-radius: 5px; }
    </style></head><body>
    <div class="container">
        <div class="header"><h2>'.escape($config['site_name'] ?? 'bulletinbored').'</h2></div>
        <div class="content">'.$body.'</div>
        <div class="footer">&copy; '.date('Y').' '.escape($config['site_name'] ?? 'bulletinbored').'</div>
    </div></body></html>';
    
    if ($config['mail_method'] === 'smtp') {
        // SMTP support placeholder - extend as needed
        return mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers);
    }
    return mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers);
}

// Load managers
require __DIR__.'/lib/PluginManager.php';
require __DIR__.'/lib/ThemeManager.php';
require __DIR__.'/lib/UpdateManager.php';

$pluginManager = new PluginManager(__DIR__.'/plugins', $config['plugin_manifest'] ?? __DIR__.'/data/plugins.json');
$pluginManager->loadEnabled();

$themeManager = new ThemeManager(
    __DIR__.'/themes',
    $config['theme_manifest'] ?? __DIR__.'/data/themes.json',
    $config['theme'] ?? 'default'
);

$updateManager = new UpdateManager(
    $config['update_manifest'] ?? __DIR__.'/data/updates.json',
    !empty($config['update_server']) ? $config['update_server'] : null
);

$activeTheme = $themeManager->getActive();
$themeApiVersion = $activeTheme ? $themeManager->getVersion($activeTheme) : '1.0.0';
$themeCssUrl = $themeManager->getCssUrl();
$themeCssPath = $themeManager->getCssPath();
$themeName = $activeTheme;

$pluginHeadAssets = '';
$pluginManager->captureHook('before_render');
$pluginManager->captureHook('frontend_before_render');
if (is_admin()) {
    $pluginManager->captureHook('admin_before_render');
}
$pluginHeadAssets = $pluginManager->getCapturedHead(false);

// Pretty URL support: when no rewrite layer (Apache .htaccess or router.php)
// has populated $_GET, parse /thread/N-slug, /category/N-slug and /u/user
// from the request path so the correct page is rendered.
if (!isset($_GET['action'])) {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $reqPath = ltrim($reqPath, '/');
    $base = trim(base_url(), '/');
    if ($base !== '' && str_starts_with($reqPath, $base . '/')) {
        $reqPath = substr($reqPath, strlen($base) + 1);
    }

    if (preg_match('#^thread/([0-9]+)(?:-[^/]+)?$#', $reqPath, $m)) {
        $_GET['action'] = 'thread';
        $_GET['id'] = $m[1];
    } elseif (preg_match('#^category/([0-9]+)(?:-[^/]+)?$#', $reqPath, $m)) {
        $_GET['action'] = 'category';
        $_GET['id'] = $m[1];
    } elseif (preg_match('#^u/([^/]+)$#', $reqPath, $m)) {
        $_GET['action'] = 'profile';
        $_GET['user'] = urldecode($m[1]);
    } elseif (preg_match('#^edit-post/([0-9]+)$#', $reqPath, $m)) {
        $_GET['action'] = 'edit_post';
        $_GET['id'] = (int)$m[1];
    } elseif (preg_match('#^delete-post/([0-9]+)$#', $reqPath, $m)) {
        $_GET['action'] = 'delete_post';
        $_GET['id'] = (int)$m[1];
    } elseif ($reqPath === '' || $reqPath === 'index.php') {
        $_GET['action'] = 'home';
    } elseif (preg_match('#^admin$#', $reqPath)) {
        $_GET['action'] = 'admin';
    } elseif (preg_match('#^admin/moderation$#', $reqPath)) {
        $_GET['action'] = 'admin_moderation';
    } elseif (preg_match('#^admin/categories$#', $reqPath)) {
        $_GET['action'] = 'admin_categories';
    } elseif (preg_match('#^admin/users$#', $reqPath)) {
        $_GET['action'] = 'admin_users';
    } elseif (preg_match('#^admin/settings$#', $reqPath)) {
        $_GET['action'] = 'admin_settings';
    } elseif (preg_match('#^admin/langs$#', $reqPath)) {
        $_GET['action'] = 'admin_langs';
    } elseif (preg_match('#^admin/plugins$#', $reqPath)) {
        $_GET['action'] = 'admin_plugins';
    } elseif (preg_match('#^admin/themes$#', $reqPath)) {
        $_GET['action'] = 'admin_themes';
} elseif (preg_match('#^admin/users/([0-9]+)/edit$#', $reqPath, $m)) {
          $_GET['action'] = 'admin_user_edit';
          $_GET['id'] = (int)$m[1];
      } elseif (preg_match('#^admin/updates$#', $reqPath)) {
         $_GET['action'] = 'admin_updates';
     } elseif (preg_match('#^admin/roles$#', $reqPath)) {
         $_GET['action'] = 'admin_roles';
     } elseif (preg_match('#^admin/roles-action$#', $reqPath)) {
         $_GET['action'] = 'admin_roles_action';
     } elseif (preg_match('#^admin/moderate$#', $reqPath)) {
        $_GET['action'] = 'moderate';
    } elseif (preg_match('#^admin/delete-category$#', $reqPath)) {
        $_GET['action'] = 'delete_category';
} elseif (preg_match('#^admin/delete-user$#', $reqPath)) {
         $_GET['action'] = 'delete_user';
     } elseif (preg_match('#^admin/ban-user$#', $reqPath)) {
         $_GET['action'] = 'ban_user';
     } elseif (preg_match('#^admin/unban-user$#', $reqPath)) {
         $_GET['action'] = 'unban_user';
     } elseif (preg_match('#^edit-profile$#', $reqPath)) {
        $_GET['action'] = 'edit_profile';
    } elseif (preg_match('#^forgot-password$#', $reqPath)) {
        $_GET['action'] = 'forgot_password';
    } elseif (preg_match('#^reset-password$#', $reqPath)) {
        $_GET['action'] = 'reset_password';
    } elseif (preg_match('#^new-thread$#', $reqPath)) {
        $_GET['action'] = 'new_thread';
    } elseif (preg_match('#^editbored-upload$#', $reqPath)) {
        $_GET['action'] = 'editbored_upload';
    } elseif (preg_match('#^[^/]+$#', $reqPath, $m)) {
        $_GET['action'] = $m[0];
    }
}

// Routing
$action = $_GET['action'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

// Redirect banned users to home
if (is_logged_in() && (is_banned() || is_suspended())) {
    session_destroy();
    redirect(url('home'));
}

try {
    if ($action === 'home' || $action === '') {
        // Home page - list threads with categories
        $threads = $pdo->query("
            SELECT t.*, u.username as author, c.name as category_name 
            FROM threads t 
            LEFT JOIN users u ON t.user_id = u.id 
            LEFT JOIN categories c ON t.category_id = c.id 
            WHERE t.status IN ('visible', 'sticky', 'locked') 
            ORDER BY (t.status = 'sticky') DESC, t.created_at DESC 
            LIMIT 20
        ")->fetchAll();
        
        $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
        include __DIR__.'/views/home.php';
    } 
    elseif ($action === 'thread' && isset($_GET['id'])) {
        // View single thread
        $threadId = (int)$_GET['id'];
        $stmt = $pdo->prepare("
            SELECT t.*, u.username as author, u.avatar as author_avatar, c.name as category_name 
            FROM threads t 
            LEFT JOIN users u ON t.user_id = u.id 
            LEFT JOIN categories c ON t.category_id = c.id 
            WHERE t.id = ?
        ");
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch();
        
        if (!$thread) {
            die('Thread not found');
        }
        
        // Get posts for this thread with pagination
        $postPage = max(1, (int)($_GET['post_page'] ?? 1));
        $perPage = 5;
        $offset = ($postPage - 1) * $perPage;
        
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM posts 
            WHERE thread_id = ? AND status = 'visible'
        ");
        $totalStmt->execute([$threadId]);
        $totalPosts = $totalStmt->fetchColumn();
        
        $totalPages = max(1, (int)ceil($totalPosts / $perPage));
        
        $postsStmt = $pdo->prepare("
            SELECT p.*, u.username as author, u.avatar as author_avatar 
            FROM posts p 
            LEFT JOIN users u ON p.user_id = u.id 
            WHERE p.thread_id = ? AND p.status = 'visible' 
            ORDER BY p.created_at ASC 
            LIMIT ? OFFSET ?
        ");
        $postsStmt->execute([$threadId, $perPage, $offset]);
        $posts = $postsStmt->fetchAll();
        
        include __DIR__.'/views/thread.php';
    }
    elseif ($action === 'new_thread') {
        // Show new thread form / handle submission
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            
            $title = validate_input($_POST['title'] ?? '');
            $content = validate_input($_POST['content'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 1);
            
            if ($title === '' || $content === '') {
                die('Title and content are required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO threads (category_id, user_id, title, content) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$categoryId, $_SESSION['user_id'], $title, $content]);
            $threadId = $pdo->lastInsertId();
            
            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                foreach ($_FILES['attachments']['name'] as $index => $originalName) {
                    if ($_FILES['attachments']['error'][$index] === UPLOAD_ERR_OK) {
                        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        $safeName = uniqid().'.'.$extension;
                        $uploadPath = __DIR__.'/uploads/'.$safeName;
                        
                        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$index], $uploadPath)) {
                            $pdo->prepare("
                                INSERT INTO uploads (thread_id, user_id, filename, original_name, size) 
                                VALUES (?, ?, ?, ?, ?)
                            ")->execute([
                                $threadId,
                                $_SESSION['user_id'],
                                $safeName,
                                basename($originalName),
                                $_FILES['attachments']['size'][$index]
                            ]);
                        }
                    }
                }
            }
            
            $pluginManager->runHook('after_thread', $threadId);
            
            redirect(url('thread', ['id' => $threadId, 'slug' => slugify($_POST['title'] ?? '')]));
        }
        $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
        include __DIR__.'/views/new_thread.php';
    }
    elseif ($action === 'reply' && $method === 'POST') {
        // Handle reply submission
        if (!is_logged_in()) {
            die('Login required');
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $content = validate_input($_POST['content'] ?? '');
        
        if ($threadId <= 0 || $content === '') {
            die('Invalid thread or empty content');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO posts (thread_id, user_id, content) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$threadId, $_SESSION['user_id'], $content]);
        
        $pluginManager->runHook('after_post', $threadId, $pdo->lastInsertId());
        
        // Notify watchers
        $watchersStmt = $pdo->prepare("
            SELECT u.email, u.username 
            FROM thread_watchers w 
            JOIN users u ON w.user_id = u.id 
            WHERE w.thread_id = ? AND w.user_id <> ?
        ");
        $watchersStmt->execute([$threadId, $_SESSION['user_id']]);
        $watchers = $watchersStmt->fetchAll();
        
        $threadTitleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
        $threadTitleStmt->execute([$threadId]);
        $threadTitle = $threadTitleStmt->fetchColumn();
        
        foreach ($watchers as $watcher) {
            if (!empty($watcher['email'])) {
                $subject = t('reply_notification_subject', ['title' => $threadTitle]);
                $replyLink = url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)]);
                $body = t('reply_notification_body', [
                    'username' => escape($watcher['username']),
                    'title' => escape($threadTitle),
                    'author' => escape($_SESSION['username'] ?? 'Someone'),
                    'link' => $replyLink
                ]);
                send_email($watcher['email'], $subject, $body);
            }
        }
        
        redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)]));
    }
    elseif ($action === 'edit_post' && isset($_GET['id'])) {
        // Show edit post form / handle post update
        if (!is_logged_in()) {
            die('Login required');
        }
        
        $postId = (int)$_GET['id'];
        $postStmt = $pdo->prepare("
            SELECT p.*, t.title as thread_title, t.id as thread_id 
            FROM posts p 
            JOIN threads t ON p.thread_id = t.id 
            WHERE p.id = ?
        ");
        $postStmt->execute([$postId]);
        $post = $postStmt->fetch();
        
        if (!$post) {
            die('Post not found');
        }
        
        // Check permissions
        if ($post['user_id'] !== $_SESSION['user_id'] && !is_admin()) {
            die('Not authorized');
        }
        
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            
            $postId = (int)($_POST['post_id'] ?? 0);
            $content = validate_input($_POST['content'] ?? '');
            
            if ($postId <= 0 || $content === '') {
                die('Invalid post or empty content');
            }
            
            // Check permissions
            $postStmt2 = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $postStmt2->execute([$postId]);
            $post = $postStmt2->fetch();
            if (!$post || ($post['user_id'] !== $_SESSION['user_id'] && !is_admin())) {
                die('Not authorized');
            }
            
            $pdo->prepare("UPDATE posts SET content = ? WHERE id = ?")
                ->execute([$content, $postId]);
            
            // Get thread ID for redirect
            $tidStmt = $pdo->prepare("SELECT thread_id FROM posts WHERE id = ?");
            $tidStmt->execute([$postId]);
            $threadId = $tidStmt->fetchColumn();
            $titleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
            $titleStmt->execute([$threadId]);
            $threadTitle = $titleStmt->fetchColumn();
            redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)]));
        }
        
        include __DIR__.'/views/edit_post.php';
    }
    elseif ($action === 'delete_post' && isset($_GET['id']) && $method === 'POST') {
        // Handle post deletion
        if (!is_logged_in()) {
            die('Login required');
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        
        $postId = (int)($_GET['id'] ?? 0);
        $postStmt3 = $pdo->prepare("SELECT user_id, thread_id FROM posts WHERE id = ?");
        $postStmt3->execute([$postId]);
        $post = $postStmt3->fetch();
        
        if (!$post || ($post['user_id'] !== $_SESSION['user_id'] && !is_admin())) {
            die('Not authorized');
        }
        
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
        redirect(url('thread', ['id' => $post['thread_id']]));
    }
    elseif ($action === 'watch' && is_logged_in()) {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        if ($threadId > 0) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
            $checkStmt->execute([$threadId, $_SESSION['user_id']]);
            if ($checkStmt->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")
                    ->execute([$threadId, $_SESSION['user_id']]);
            }
        }
        redirect($_SERVER['HTTP_REFERER'] ?? url('home'));
    }
    elseif ($action === 'unwatch' && is_logged_in()) {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        if ($threadId > 0) {
            $pdo->prepare("DELETE FROM thread_watchers WHERE thread_id = ? AND user_id = ?")
                ->execute([$threadId, $_SESSION['user_id']]);
        }
        redirect($_SERVER['HTTP_REFERER'] ?? url('home'));
    }
    elseif ($action === 'login') {
        // Show login form / handle login submission
        if (is_logged_in()) {
            redirect(url('home'));
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'banned') {
                    $error = 'user_banned';
                } elseif ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() < $user['suspension_time']) {
                    $error = 'user_suspended';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'] ?? '';
                    $_SESSION['avatar'] = $user['avatar'] ?? '';
                    $_SESSION['user_status'] = $user['status'];
                    $_SESSION['user_suspension_time'] = $user['suspension_time'] ?? 0;
                    if ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() >= $user['suspension_time']) {
                        $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$user['id']]);
                        $_SESSION['user_status'] = 'active';
                        $_SESSION['user_suspension_time'] = 0;
                    }
                    redirect(url('home'));
                }
            } else {
                $error = 'Invalid credentials';
            }
        }
        include __DIR__.'/views/login.php';
    }
    elseif ($action === 'register') {
        // Show registration form / handle registration
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            
            $username = validate_input($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if ($username === '' || $password === '') {
                die('Username and password are required');
            }
            
            // Check if username exists
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $existsStmt->execute([$username]);
            $exists = $existsStmt->fetchColumn();
            if ($exists > 0) {
                die('Username already taken');
            }
            
            $email = validate_input($_POST['email'] ?? '');
            
            $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'user')")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
            
            $pluginManager->runHook('user_registered', $pdo->lastInsertId(), $username);
            
            // Send welcome email
            if (!empty($email)) {
                $subject = 'Welcome to '.($config['site_name'] ?? 'bulletinbored');
                $body = '<p>Hello '.escape($username).',</p>
                        <p>Welcome to '.($config['site_name'] ?? 'bulletinbored').'!</p>
                        <p>Your account has been successfully created. You can now login and start participating in discussions.</p>';
                send_email($email, $subject, $body);
            }
            
            redirect(url('login'));
        }
        include __DIR__.'/views/register.php';
    }
    elseif ($action === 'logout') {
        // Handle logout
        session_destroy();
        redirect(url('home'));
    }
    elseif ($action === 'profile' && isset($_GET['user'])) {
        // View user profile
        $username = $_GET['user'];
        $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $profileStmt->execute([$username]);
        $profileUser = $profileStmt->fetch();
        
        if (!$profileUser) {
            die('User not found');
        }
        
        $userThreadsStmt = $pdo->prepare("
            SELECT t.*, u.username as author 
            FROM threads t 
            JOIN users u ON t.user_id = u.id 
            WHERE t.user_id = ? AND t.status IN ('visible', 'sticky', 'locked') 
            ORDER BY (t.status = 'sticky') DESC, t.created_at DESC 
            LIMIT 20
        ");
        $userThreadsStmt->execute([$profileUser['id']]);
        $userThreads = $userThreadsStmt->fetchAll();
        
        include __DIR__.'/views/profile.php';
    }
    elseif ($action === 'edit_profile') {
        // Show edit profile form / handle profile update / upload avatar
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            if (!empty($_FILES['avatar']['name'])) {
                $avatarDir = __DIR__.'/uploads/avatars/';
                if (!is_dir($avatarDir)) {
                    @mkdir($avatarDir, 0777, true);
                }

                if (empty($_FILES['avatar']['name']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    $_SESSION['avatar_upload_error'] = 'No file uploaded or upload error occurred.';
                    redirect(url('edit_profile'));
                }

                $allowed = $config['avatar_allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $maxSize = $config['avatar_max_size'] ?? 2*1024*1024;

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['avatar']['tmp_name']);

                if (!in_array($mime, $allowed)) {
                    $_SESSION['avatar_upload_error'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.';
                    redirect(url('edit_profile'));
                }

                if ($_FILES['avatar']['size'] > $maxSize) {
                    $_SESSION['avatar_upload_error'] = 'File is too large. Max 2MB.';
                    redirect(url('edit_profile'));
                }

                $ext = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'bin',
                };

                $safeName = 'avatar_'.$_SESSION['user_id'].'.'.$ext;
                $uploadPath = $avatarDir . $safeName;

                foreach (glob($avatarDir . 'avatar_'.$_SESSION['user_id'].'.*') as $oldAvatar) {
                    @unlink($oldAvatar);
                }

                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                    try {
                        $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")
                            ->execute([$safeName, $_SESSION['user_id']]);
                        $_SESSION['avatar'] = $safeName;
                        $_SESSION['avatar_upload_success'] = 'Avatar uploaded successfully.';
                    } catch (Exception $e) {
                        $_SESSION['avatar_upload_error'] = 'Database error: ' . $e->getMessage();
                        @unlink($uploadPath);
                    }
                } else {
                    $_SESSION['avatar_upload_error'] = 'Failed to move uploaded file. Check directory permissions.';
                }

                redirect(url('edit_profile'));
            } else {
                $updates = [];
                $params = [];
                
                if (!empty($_POST['username'])) {
                    $newUsername = validate_input($_POST['username']);
                    $existingStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
                    $existingStmt->execute([$newUsername, $_SESSION['user_id']]);
                    $existing = $existingStmt->fetchColumn();
                    if ($existing) {
                        die('Username already taken');
                    }
                    $updates[] = "username = ?";
                    $params[] = $newUsername;
                }
                
                if (isset($_POST['email'])) {
                    $newEmail = validate_input($_POST['email']);
                    $updates[] = "email = ?";
                    $params[] = $newEmail;
                }
                
                if (!empty($_POST['password'])) {
                    $updates[] = "password = ?";
                    $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
                
                if (!empty($updates)) {
                    $params[] = $_SESSION['user_id'];
                    $pdo->prepare("UPDATE users SET ".implode(', ', $updates)." WHERE id = ?")
                        ->execute($params);
                    
                    if (!empty($_POST['username'])) {
                        $_SESSION['username'] = validate_input($_POST['username']);
                    }
                }
                
                redirect(url('profile', ['user' => $_SESSION['username']]));
            }
        }
        include __DIR__.'/views/edit_profile.php';
    }
    elseif ($action === 'search') {
        // Search functionality
        $query = $_GET['q'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        
        if ($query !== '') {
            $searchTerm = "%$query%";
            $threadStmt = $pdo->prepare("
                SELECT t.*, c.name as category_name, u.username as author 
                FROM threads t 
                LEFT JOIN categories c ON t.category_id = c.id 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE t.status IN ('visible', 'sticky', 'locked') 
                AND (t.title LIKE ? OR t.content LIKE ? OR c.name LIKE ? OR u.username LIKE ?)
                ORDER BY (t.status = 'sticky') DESC, t.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $threadStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $perPage, $offset]);
            $threads = $threadStmt->fetchAll();
            
            $totalStmt2 = $pdo->prepare("
                SELECT COUNT(*) FROM threads t 
                LEFT JOIN categories c ON t.category_id = c.id 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE t.status IN ('visible', 'sticky', 'locked') 
                AND (t.title LIKE ? OR t.content LIKE ? OR c.name LIKE ? OR u.username LIKE ?)
            ");
            $totalStmt2->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $total = $totalStmt2->fetchColumn();
        } else {
            $threadStmt2 = $pdo->prepare("
                SELECT t.*, c.name as category_name, u.username as author 
                FROM threads t 
                LEFT JOIN categories c ON t.category_id = c.id 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE t.status IN ('visible', 'sticky', 'locked') 
                ORDER BY (t.status = 'sticky') DESC, t.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $threadStmt2->execute([$perPage, $offset]);
            $threads = $threadStmt2->fetchAll();
            
            $total = $pdo->query("SELECT COUNT(*) FROM threads WHERE status IN ('visible', 'sticky', 'locked')")->fetchColumn();
        }
        
        $totalPages = max(1, (int)ceil($total / $perPage));
        $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
        include __DIR__.'/views/home.php';
    }
    elseif ($action === 'category' && isset($_GET['id'])) {
        // View category
        $categoryId = (int)$_GET['id'];
        $catStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $category = $catStmt->fetch();
        
        if (!$category) {
            die('Category not found');
        }
        
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        
        $catThreadStmt = $pdo->prepare("
            SELECT t.*, u.username as author 
            FROM threads t 
            LEFT JOIN users u ON t.user_id = u.id 
            WHERE t.category_id = ? AND t.status IN ('visible', 'sticky', 'locked') 
            ORDER BY (t.status = 'sticky') DESC, t.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $catThreadStmt->execute([$categoryId, $perPage, $offset]);
        $threads = $catThreadStmt->fetchAll();
        
        $catTotalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM threads 
            WHERE category_id = ? AND status IN ('visible', 'sticky', 'locked')
        ");
        $catTotalStmt->execute([$categoryId]);
        $total = $catTotalStmt->fetchColumn();
        
        $totalPages = max(1, (int)ceil($total / $perPage));
        include __DIR__.'/views/category.php';
    }
    elseif ($action === 'admin') {
        // Admin panel
        if (!is_admin()) {
            die('Admin required');
        }
        
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
        
        // Admin settings
        $adminError = '';
        $adminSuccess = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $adminError = 'Invalid CSRF token';
            } else {
                $siteName = trim($_POST['site_name'] ?? $config['site_name']);
                $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;
                $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
                $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
                $availableLangs = array_filter(array_map('trim', explode(',', $_POST['available_langs'] ?? implode(',', $config['available_langs'] ?? ['en']))));
                
                $config['site_name'] = $siteName;
                $config['allow_registration'] = $allowRegistration;
                $config['maintenance_mode'] = $maintenanceMode;
                $config['default_lang'] = $defaultLang;
                $config['available_langs'] = array_values($availableLangs);
                
                $configContent = "<?php\n";
                foreach ($config as $key => $value) {
                    if (is_string($value)) {
                        $configContent .= "\$config['$key'] = '" . addslashes($value) . "';\n";
                    } elseif (is_array($value)) {
                        $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
                    } else {
                        $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
                    }
                }
                
            if (file_put_contents(__DIR__.'/config.php', $configContent) !== false) {
                $adminSuccess = 'Settings saved successfully';
            } else {
                $adminError = 'Failed to save settings';
            }
        }
        
        redirect(url('admin_settings'));
    }
    
    include __DIR__.'/views/admin.php';
}
elseif ($action === 'moderate' && $method === 'POST' && is_admin()) {
         // Handle moderation actions
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         
         $threadId = (int)($_POST['id'] ?? 0);
         $action = $_POST['do'] ?? '';
         
         if ($threadId <= 0) {
             die('Invalid thread ID');
         }
         
         if ($action === 'approve') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'delete') {
             $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'lock') {
             $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'unlock') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'sticky') {
             $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'unsticky') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'hide') {
             $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
         }
         
redirect(url('admin_moderation'));
     }
     elseif ($action === 'frontend_moderate' && $method === 'POST' && is_logged_in()) {
         // Frontend moderation actions (from thread view)
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         $threadId = (int)($_POST['id'] ?? 0);
         $modAction = $_POST['do'] ?? '';
         if ($threadId <= 0) {
             die('Invalid thread ID');
         }
         // Check if user is admin or has moderation permissions
         $userRole = $_SESSION['user_role'] ?? 'user';
         if ($userRole !== 'admin' && $userRole !== 'moderator') {
             die('Not authorized');
         }
         if ($modAction === 'lock') {
             $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
         } elseif ($modAction === 'unlock') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($modAction === 'sticky') {
             $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
         } elseif ($modAction === 'unsticky') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($modAction === 'hide') {
             $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
} elseif ($modAction === 'delete') {
              $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
              redirect(url('admin_moderation'));
          } elseif ($modAction === 'approve') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         }
         $threadTitleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
         $threadTitleStmt->execute([$threadId]);
         $threadTitle = $threadTitleStmt->fetchColumn();
         redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle ?? '')]));
     }
     elseif ($action === 'admin_categories') {
        // Show categories management page / handle create & edit
        if (!is_admin()) {
            die('Admin required');
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            if (isset($_GET['id'])) {
                $catId = (int)$_GET['id'];
                $name = validate_input($_POST['name'] ?? '');
                $description = validate_input($_POST['description'] ?? '');
                if ($catId > 0 && $name !== '') {
                    $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?")->execute([$name, $description, $catId]);
                }
            } else {
                $name = validate_input($_POST['name'] ?? '');
                $description = validate_input($_POST['description'] ?? '');
                if ($name !== '') {
                    $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$name, $description]);
                }
            }
            redirect(url('admin_categories'));
        }
        include __DIR__.'/views/admin_categories.php';
    }
    elseif ($action === 'delete_category' && $method === 'POST' && is_admin()) {
        // Delete category
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $catId = (int)($_GET['id'] ?? 0);
        if ($catId > 0) {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);
        }
        redirect(url('admin_categories'));
    }
elseif ($action === 'delete_user' && $method === 'POST' && is_admin()) {
         // Delete user
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         $userId = (int)($_GET['id'] ?? 0);
         if ($userId > 0) {
             $pdo->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'")->execute([$userId]);
         }
         redirect(url('admin_users'));
     }
elseif ($action === 'ban_user' && $method === 'POST' && is_admin()) {
          // Ban a user
          if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
              die('CSRF token invalid');
          }
          $userId = (int)($_GET['id'] ?? 0);
          $username = '';
          if ($userId > 0) {
              $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
              $userStmt->execute([$userId]);
              $username = $userStmt->fetchColumn() ?? '';
              $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role <> 'admin'")->execute([$userId]);
          }
          $redirect = $_POST['redirect'] ?? '';
          if ($redirect !== '' && str_starts_with($redirect, '/')) {
              $redirect = base_url() . $redirect;
          }
          if ($redirect && $username) {
              redirect($redirect);
          } elseif ($username) {
              redirect(url('profile', ['user' => $username]));
          } else {
              redirect(url('admin_users'));
          }
      }
elseif ($action === 'unban_user' && $method === 'POST' && is_admin()) {
        // Unban a user
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $userId = (int)($_GET['id'] ?? 0);
        $username = '';
        if ($userId > 0) {
            $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $username = $userStmt->fetchColumn() ?? '';
            $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$userId]);
          }
          $redirect = $_POST['redirect'] ?? '';
          if ($redirect !== '' && str_starts_with($redirect, '/')) {
              $redirect = base_url() . $redirect;
          }
          if ($redirect && $username) {
            redirect($redirect);
        } elseif ($username) {
            redirect(url('profile', ['user' => $username]));
        } else {
            redirect(url('admin_users'));
        }
    }
    elseif ($action === 'suspend_user' && $method === 'POST' && is_admin()) {
        // Suspend a user for specified days
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $userId = (int)($_GET['id'] ?? 0);
        $days = max(1, (int)($_POST['days'] ?? 1));
        $suspensionTime = time() + ($days * 86400);
        $username = '';
        if ($userId > 0) {
            $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $username = $userStmt->fetchColumn() ?? '';
            $pdo->prepare("UPDATE users SET status = 'suspended', suspension_time = ? WHERE id = ?")->execute([$suspensionTime, $userId]);
          }
          $redirect = $_POST['redirect'] ?? '';
          if ($redirect !== '' && str_starts_with($redirect, '/')) {
              $redirect = base_url() . $redirect;
          }
          if ($redirect && $username) {
            redirect($redirect);
        } elseif ($username) {
            redirect(url('profile', ['user' => $username]));
        } else {
            redirect(url('admin_users'));
        }
    }
    elseif ($action === 'admin_settings' && $method === 'POST' && is_admin()) {
        // Save admin settings
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $siteName = trim($_POST['site_name'] ?? $config['site_name']);
        $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;
        $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
        $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
        $availableLangs = array_filter(array_map('trim', explode(',', $_POST['available_langs'] ?? implode(',', $config['available_langs'] ?? ['en']))));

        $config['site_name'] = $siteName;
        $config['allow_registration'] = $allowRegistration;
        $config['maintenance_mode'] = $maintenanceMode;
        $config['default_lang'] = $defaultLang;
        $config['available_langs'] = array_values($availableLangs);

        $configContent = "<?php\n";
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $configContent .= "\$config['$key'] = '" . addslashes($value) . "';\n";
            } else {
                $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
            }
        }

        file_put_contents(__DIR__.'/config.php', $configContent);
        redirect(url('admin_settings'));
    }
elseif ($action === 'admin_moderation') {
         // Show moderation page
         if (!is_admin()) {
             die('Admin required');
         }
         include __DIR__.'/views/admin_moderation.php';
     }
     elseif ($action === 'admin_roles') {
         // Show roles/permissions management page
         if (!is_admin()) {
             die('Admin required');
         }
         include __DIR__.'/views/admin_roles.php';
     }
     elseif ($action === 'admin_roles_action' && $method === 'POST' && is_admin()) {
         // Handle roles/permissions actions
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         $roleAction = $_POST['do'] ?? '';
         if ($roleAction === 'create') {
             $roleName = validate_input($_POST['role_name'] ?? '');
             $permissions = $_POST['permissions'] ?? [];
             if ($roleName !== '') {
                 $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
                     ->execute([$roleName, json_encode($permissions)]);
             }
         } elseif ($roleAction === 'update') {
             $roleId = (int)($_POST['role_id'] ?? 0);
             $permissions = $_POST['permissions'] ?? [];
             if ($roleId > 0) {
                 $pdo->prepare("UPDATE roles SET permissions = ? WHERE id = ?")
                     ->execute([json_encode($permissions), $roleId]);
             }
         } elseif ($roleAction === 'delete') {
             $roleId = (int)($_POST['role_id'] ?? 0);
             if ($roleId > 0) {
                 $pdo->prepare("DELETE FROM roles WHERE id = ? AND name <> 'admin'")->execute([$roleId]);
             }
         }
         redirect(url('admin_roles'));
     }
elseif ($action === 'admin_users') {
         // Show users management page
         if (!is_admin()) {
             die('Admin required');
         }
         include __DIR__.'/views/admin_users.php';
     }
     elseif ($action === 'admin_user_edit' && isset($_GET['id'])) {
         // Edit user page
         if (!is_admin()) {
             die('Admin required');
         }
         $editUserId = (int)($_GET['id'] ?? 0);
         if ($editUserId <= 0) {
             redirect(url('admin_users'));
         }
         $editUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
         $editUser->execute([$editUserId]);
         $editUser = $editUser->fetch();
         if (!$editUser) {
             redirect(url('admin_users'));
         }
         if ($method === 'POST') {
             if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                 die('CSRF token invalid');
             }
             $newUsername = trim($_POST['username'] ?? '');
             $newEmail = trim($_POST['email'] ?? '');
             $newRole = $_POST['role'] ?? 'user';
             $newStatus = $_POST['status'] ?? 'active';
             if ($newUsername !== '') {
                 $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ?, status = ? WHERE id = ?")
                     ->execute([$newUsername, $newEmail, $newRole, $newStatus, $editUserId]);
             }
             redirect(url('admin_user_edit', ['id' => $editUserId]));
         }
         include __DIR__.'/views/admin_user_edit.php';
     }
     elseif ($action === 'admin_settings') {
        // Show settings page
        if (!is_admin()) {
            die('Admin required');
        }
        include __DIR__.'/views/admin_settings.php';
    }
    elseif ($action === 'admin_langs') {
        // Language file management
        if (!is_admin()) {
            die('Admin required');
        }

        $langError = '';
        $langSuccess = '';
        if ($method === 'POST' && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $langError = 'Invalid CSRF token';
            } else {
                if (isset($_POST['upload_lang']) && !empty($_FILES['lang_file']['tmp_name'])) {
                    $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                    if ($langCode === '') {
                        $langError = 'Invalid language code';
                    } else {
                        $dest = __DIR__.'/lang/'.$langCode.'.php';
                        if (file_exists($dest)) {
                            $langError = 'Language file already exists: '.escape($langCode);
                        } elseif (move_uploaded_file($_FILES['lang_file']['tmp_name'], $dest)) {
                            $langSuccess = 'Language file uploaded: '.escape($langCode);
                        } else {
                            $langError = 'Failed to upload language file';
                        }
                    }
                } elseif (isset($_POST['delete_lang'])) {
                    $langCode = $_POST['lang_code'] ?? '';
                    $langCode = preg_replace('/[^a-z_]/', '', strtolower($langCode));
                    $dest = __DIR__.'/lang/'.$langCode.'.php';
                    if ($langCode === $config['default_lang']) {
                        $langError = 'Cannot delete the default language';
                    } elseif (file_exists($dest)) {
                        @unlink($dest);
                        $langSuccess = 'Language file deleted: '.escape($langCode);
                    } else {
                        $langError = 'Language file not found';
                    }
                }
            }
        }

        $langFiles = glob(__DIR__.'/lang/*.php');
        $langOptions = [];
        foreach ($langFiles as $file) {
            $code = basename($file, '.php');
            $langOptions[] = $code;
        }
        include __DIR__.'/views/admin_langs.php';
    }
    elseif ($action === 'admin_plugins') {
        // Plugin management
        if (!is_admin()) {
            die('Admin required');
        }

        $adminPluginError = '';
        $adminPluginSuccess = '';
        if ($method === 'POST' && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $adminPluginError = 'Invalid CSRF token';
            } else {
                if (isset($_POST['install_plugin']) && !empty($_FILES['plugin_zip']['tmp_name'])) {
                    $tmpPath = $_FILES['plugin_zip']['tmp_name'];
                    $result = $pluginManager->installFromZip($tmpPath);
                    if ($result['success']) {
                        $adminPluginSuccess = $result['message'];
                    } else {
                        $adminPluginError = $result['message'];
                    }
                } elseif (isset($_POST['delete_plugin'])) {
                    $pluginName = $_POST['plugin_name'] ?? '';
                    $result = $pluginManager->delete($pluginName);
                    if ($result['success']) {
                        $adminPluginSuccess = $result['message'];
                    } else {
                        $adminPluginError = $result['message'];
                    }
                } elseif (isset($_POST['action'])) {
                    $pluginName = $_POST['plugin_name'] ?? '';
                    if ($_POST['action'] === 'enable') {
                        if ($pluginManager->enable($pluginName)) {
                            $adminPluginSuccess = 'Plugin enabled';
                        } else {
                            $adminPluginError = 'Plugin not found';
                        }
                    } elseif ($_POST['action'] === 'disable') {
                        if ($pluginManager->disable($pluginName)) {
                            $adminPluginSuccess = 'Plugin disabled';
                        } else {
                            $adminPluginError = 'Plugin not found';
                        }
                    }
                }
            }
        }

        $allPlugins = $pluginManager->getAll();
        include __DIR__.'/views/admin_plugins.php';
    }
    elseif ($action === 'admin_themes') {
        // Theme management
        if (!is_admin()) {
            die('Admin required');
        }

        $adminThemeError = '';
        $adminThemeSuccess = '';
        if ($method === 'POST' && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $adminThemeError = 'Invalid CSRF token';
            } else {
                if (isset($_POST['install_theme']) && !empty($_FILES['theme_zip']['tmp_name'])) {
                    $tmpPath = $_FILES['theme_zip']['tmp_name'];
                    $result = $themeManager->installFromZip($tmpPath);
                    if ($result['success']) {
                        $adminThemeSuccess = $result['message'];
                    } else {
                        $adminThemeError = $result['message'];
                    }
                } elseif (isset($_POST['activate_theme'])) {
                    $themeName = $_POST['theme_name'] ?? '';
                    if ($themeManager->activate($themeName)) {
                        $adminThemeSuccess = 'Theme activated';
                    } else {
                        $adminThemeError = 'Theme not found';
                    }
                } elseif (isset($_POST['delete_theme'])) {
                    $themeName = $_POST['theme_name'] ?? '';
                    $result = $themeManager->delete($themeName);
                    if ($result['success']) {
                        $adminThemeSuccess = $result['message'];
                    } else {
                        $adminThemeError = $result['message'];
                    }
                }
            }
        }

        $allThemes = $themeManager->getAll();
        include __DIR__.'/views/admin_themes.php';
    }
    elseif ($action === 'admin_updates') {
        // Update management
        if (!is_admin()) {
            die('Admin required');
        }

        $updateResults = null;
        $updateError = '';
        $updateSuccess = '';

        if ($method === 'POST' && isset($_POST['check_updates'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $updateError = 'Invalid CSRF token';
            } else {
                $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager);
            }
        }

        if ($method === 'POST' && isset($_POST['apply_update'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $updateError = 'Invalid CSRF token';
            } else {
                $type = $_POST['type'] ?? '';
                $name = $_POST['name'] ?? '';

                if (!empty($_FILES['update_package']['tmp_name'])) {
                    $tmpPath = $_FILES['update_package']['tmp_name'];
                    $result = $updateManager->applyUpdate($type, $name, $tmpPath);
                    if ($result) {
                        $updateSuccess = 'Update applied successfully';
                    } else {
                        $updateError = 'Failed to apply update';
                    }
                } else {
                    $updateError = 'No update package uploaded';
                }
            }
        }

        $updateStatus = $updateResults ?? null;
        include __DIR__.'/views/admin_updates.php';
    }
    elseif ($action === 'forgot_password') {
        // Show forgot password form / handle request
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            $email = validate_input($_POST['email'] ?? '');
            $userStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $userStmt->execute([$email]);
            $user = $userStmt->fetch();
            
            if ($user) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
                    ->execute([$user['id'], password_hash($token, PASSWORD_DEFAULT), $expires]);
                
                // Send email
                $resetLink = url('reset_password', ['token' => $token]);
                $subject = 'Password Reset Request';
                $body = '<p>Hello '.escape($user['username']).',</p>
                        <p>You requested a password reset. Click the button below to reset your password:</p>
                        <p style="text-align:center;"><a class="btn" href="'.$resetLink.'">Reset Password</a></p>
                        <p>Or copy this link: <br><code>'.$resetLink.'</code></p>
                        <p>This link expires in 1 hour.</p>
                        <p>If you did not request this, please ignore this email.</p>';
                send_email($email, $subject, $body);
            }
            
            // Always show success (don't reveal if email exists)
            $success = 'If an account with that email exists, a password reset link has been sent.';
        }
        include __DIR__.'/views/forgot_password.php';
    }
    elseif ($action === 'reset_password') {
        // Show reset password form / handle reset
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            $token = $_POST['token'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            
            if ($password !== $confirm) {
                $error = 'Passwords do not match.';
                include __DIR__.'/views/reset_password.php';
                exit;
            }
            if (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
                include __DIR__.'/views/reset_password.php';
                exit;
            }
            
            // Find valid token
            $tokensStmt = $pdo->prepare("SELECT * FROM password_resets WHERE used = 0 AND expires_at > datetime('now') ORDER BY created_at DESC");
            $tokensStmt->execute();
            $validToken = null;
            foreach ($tokensStmt->fetchAll() as $row) {
                if (password_verify($token, $row['token'])) {
                    $validToken = $row;
                    break;
                }
            }
            
            if (!$validToken) {
                $error = 'Invalid or expired reset token.';
                include __DIR__.'/views/reset_password.php';
                exit;
            }
            
            // Update password
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $validToken['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$validToken['id']]);
            
            // Send confirmation email
            $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $userStmt->execute([$validToken['user_id']]);
            $user = $userStmt->fetch();
            if ($user && !empty($user['email'])) {
                $subject = 'Password Reset Successful';
                $body = '<p>Hello '.escape($user['username']).',</p>
                        <p>Your password has been successfully reset.</p>
                        <p>If you did not make this change, please contact an administrator immediately.</p>';
                send_email($user['email'], $subject, $body);
            }
            
            redirect(url('login'));
        }
        if (!isset($_GET['token'])) {
            http_response_code(404);
            echo 'Page not found';
            exit;
        }
        include __DIR__.'/views/reset_password.php';
    }
    elseif ($action === 'editbored_upload' && $method === 'POST') {
        // Image upload for editbored editor
        if (!is_logged_in()) {
            http_response_code(403);
            echo json_encode(['error' => 'Login required']);
            exit;
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
        if (empty($_FILES['editbored_image']['tmp_name']) || $_FILES['editbored_image']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No image uploaded']);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['editbored_image']['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type']);
            exit;
        }
        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $safeName = 'editbored_' . $_SESSION['user_id'] . '_' . uniqid() . '.' . $ext;
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        if (move_uploaded_file($_FILES['editbored_image']['tmp_name'], $uploadDir . $safeName)) {
            $url = base_url() . '/uploads/' . rawurlencode($safeName);
            echo json_encode(['url' => $url, 'filename' => $safeName]);
            exit;
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }
    elseif ($action === 'notifications') {
        // Notification center
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST' && validate_csrf_token($_POST['csrf_token'] ?? '')) {
            if (isset($_POST['do']) && $_POST['do'] === 'mark_read' && isset($_GET['id'])) {
                $id = (int)$_GET['id'];
                if ($id > 0) {
                    $pdo->prepare("UPDATE notifications SET read = 1 WHERE id = ? AND user_id = ?")
                        ->execute([$id, $_SESSION['user_id']]);
                }
            }
            if (isset($_POST['do']) && $_POST['do'] === 'mark_all_read') {
                $pdo->prepare("UPDATE notifications SET read = 1 WHERE user_id = ? AND read = 0")
                    ->execute([$_SESSION['user_id']]);
            }
            redirect(url('notifications'));
        }
        $notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
        $notifications->execute([$_SESSION['user_id']]);
        $notifications = $notifications->fetchAll();
        include __DIR__.'/views/notifications.php';
    }
    elseif ($action === 'messages') {
        // Private messages center
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST' && isset($_POST['content']) && validate_csrf_token($_POST['csrf_token'] ?? '')) {
            $recipientId = (int)($_GET['conversation'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            if ($recipientId > 0 && $content !== '') {
                $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (?, ?, '', ?)");
                $stmt->execute([$_SESSION['user_id'], $recipientId, $content]);
            }
            redirect(url('messages', ['conversation' => $recipientId]));
        }
        $conversationUserId = (int)($_GET['conversation'] ?? 0);
        if ($conversationUserId > 0) {
            $messages = $pdo->prepare("
                SELECT pm.*, u.username as sender_name
                FROM private_messages pm
                JOIN users u ON pm.sender_id = u.id
                WHERE (pm.sender_id = :me AND pm.recipient_id = :other) OR (pm.sender_id = :other AND pm.recipient_id = :me)
                ORDER BY pm.created_at ASC
            ");
            $messages->execute(['me' => $_SESSION['user_id'], 'other' => $conversationUserId]);
            $messages = $messages->fetchAll();

            $pdo->prepare("UPDATE private_messages SET read = 1 WHERE recipient_id = :me AND sender_id = :other AND read = 0")
                ->execute(['me' => $_SESSION['user_id'], 'other' => $conversationUserId]);

            $otherUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $otherUser->execute([$conversationUserId]);
            $otherUsername = $otherUser->fetchColumn();

            include __DIR__.'/views/messages.php';
        } else {
            $conversations = $pdo->prepare("
                SELECT
                    CASE WHEN sender_id = :uid THEN recipient_id ELSE sender_id END as other_user_id,
                    MAX(created_at) as last_message_at,
                    MAX(read) as last_read,
                    (SELECT content FROM private_messages pm2
                     WHERE ((pm2.sender_id = :uid AND pm2.recipient_id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END)
                         OR (pm2.recipient_id = :uid AND pm2.sender_id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END))
                     ORDER BY pm2.created_at DESC LIMIT 1) as last_message,
                    (SELECT username FROM users u WHERE u.id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END) as other_username,
                    SUM(CASE WHEN recipient_id = :uid AND read = 0 THEN 1 ELSE 0 END) as unread_count
                FROM private_messages pm
                WHERE sender_id = :uid OR recipient_id = :uid
                GROUP BY other_user_id
                ORDER BY last_message_at DESC
            ");
            $conversations->execute(['uid' => $_SESSION['user_id']]);
            $conversations = $conversations->fetchAll();
            include __DIR__.'/views/messages.php';
        }
    }
    else {
        // Not found
        http_response_code(404);
        echo 'Page not found';
    }
} catch (Exception $e) {
    // Log error and show friendly message
    error_log($e->getMessage());
    http_response_code(500);
    echo 'An error occurred. Please try again later.';
}
?>