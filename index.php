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
        default:
            return $base . '/?' . http_build_query(array_merge(['action' => $action], $params));
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
        "users" => "id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255), role VARCHAR(50) DEFAULT 'user', avatar VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "categories" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, position INT DEFAULT 0",
        "threads" => "id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT, title TEXT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "posts" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "uploads" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, post_id INT, user_id INT, filename VARCHAR(255), original_name VARCHAR(255), size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "thread_watchers" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_watch (thread_id, user_id)"
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
} catch (PDOException $e) {}

// Helper functions
function escape($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function marked_parse($text) {
    if (empty($text)) return '';
    if (function_exists('marked')) {
        $html = marked($text);
    } else {
        return nl2br(escape($text));
    }
    $html = preg_replace_callback('/(https?:\/\/[^\s<>"\']+)/', function($matches) {
        $url = $matches[1];
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
            $id = $m[1];
            return '<div class="link-preview-wrap" style="position:relative;margin:0.8em 0;"><div class="link-preview link-preview--youtube" style="position:relative;padding-top:56.25%;background:#000;border-radius:12px;overflow:hidden;"><iframe src="https://www.youtube.com/embed/' . $id . '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe></div></div>';
        }
        if (preg_match('/(?:twitter|x)\.com\/[a-zA-Z0-9_]+\/status\/(\d+)/', $url, $m)) {
            $tid = $m[1];
            return '<div class="link-preview-wrap" style="position:relative;margin:0.8em 0;"><div class="link-preview link-preview--twitter" style="background:#000;border-radius:12px;overflow:hidden;"><iframe src="https://platform.twitter.com/embed/Tweet.html?id=' . $tid . '" style="border:none;width:100%;height:350px;display:block;"></iframe></div></div>';
        }
        if (preg_match('/instagram\.com\/(?:[a-zA-Z0-9_.]+\/)?p\/[a-zA-Z0-9_-]+\/?/', $url)) {
            return '<div class="link-preview-wrap" style="position:relative;margin:0.8em 0;"><div class="link-preview link-preview--instagram" style="min-height:400px;background:#fff;border:1px solid #dbdbdb;border-radius:12px;overflow:hidden;"><blockquote class="instagram-media" data-instgrm-captioned data-instgrm-permalink="' . $url . '" data-instgrm-version="15" style="background:#FFF;border:0;border-radius:3px;box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15);margin:1px;max-width:540px;min-width:326px;padding:0;width:99.375%;width:-webkit-calc(100% - 2px);width:calc(100% - 2px);"><div style="padding:16px;"><a href="' . $url . '" style="background:#FFFFFF;line-height:0;padding:0 0;text-align:center;text-decoration:none;width:100%;" target="_blank"><div style="display:flex;flex-direction:row;align-items:center;"><div style="background-color:#F4F4F4;border-radius:50%;flex-grow:0;height:40px;margin-right:14px;width:40px;"></div><div style="display:flex;flex-direction:column;flex-grow:1;justify-content:center;"><div style="background-color:#F4F4F4;border-radius:4px;flex-grow:0;height:14px;margin-bottom:6px;width:100px;"></div><div style="background-color:#F4F4F4;border-radius:4px;flex-grow:0;height:14px;width:60px;"></div></div></div><div style="padding:19% 0;"></div><div style="display:block;height:50px;margin:0 auto 12px;width:50px;"><svg width="50px" height="50px" viewBox="0 0 60 60" version="1.1" xmlns="https://www.w3.org/2000/svg"><g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"><g transform="translate(-511.000000, -20.000000)" fill="#000000"><g><path d="M556.869,30.41 C554.814,30.41 553.148,32.076 553.148,34.131 C553.148,36.186 554.814,37.852 556.869,37.852 C558.924,37.852 560.59,36.186 560.59,34.131 C560.59,32.076 558.924,30.41 556.869,30.41 M541,60.657 C535.114,60.657 530.342,55.887 530.342,50 C530.342,44.114 535.114,39.342 541,39.342 C546.887,39.342 551.658,44.114 551.658,50 C551.658,55.887 546.887,60.657 541,60.657 M541,33.886 C532.1,33.886 524.886,41.1 524.886,50 C524.886,58.899 532.1,66.113 541,66.113 C549.9,66.113 557.115,58.899 557.115,50 C557.115,41.1 549.9,33.886 541,33.886 M565.378,62.101 C565.244,65.022 564.756,66.606 564.346,67.663 C563.803,69.06 563.154,70.057 562.106,71.106 C561.058,72.155 560.06,72.803 558.662,73.347 C557.607,73.757 556.021,74.244 553.102,74.378 C549.944,74.521 548.997,74.552 541,74.552 C533.003,74.552 532.056,74.521 528.898,74.378 C525.979,74.244 524.393,73.757 523.338,73.347 C521.94,72.803 520.942,72.155 519.894,71.106 C518.846,70.057 518.197,69.06 517.654,67.663 C517.244,66.606 516.755,65.022 516.623,62.101 C516.479,58.943 516.448,57.996 516.448,50 C516.448,42.003 516.479,41.056 516.623,37.899 C516.755,34.978 517.244,33.391 517.654,32.338 C518.197,30.938 518.846,29.942 519.894,28.894 C520.942,27.846 521.94,27.196 523.338,26.654 C524.393,26.244 525.979,25.756 528.898,25.623 C532.057,25.479 533.004,25.448 541,25.448 C548.997,25.448 549.943,25.479 553.102,25.623 C556.021,25.756 557.607,26.244 558.662,26.654 C560.06,27.196 561.058,27.846 562.106,28.894 C563.154,29.942 563.803,30.938 564.346,32.338 C564.756,33.391 565.244,34.978 565.378,37.899 C565.522,41.056 565.552,42.003 565.552,50 C565.552,57.996 565.522,58.943 565.378,62.101"></path></g></g></g></svg></div><div style="padding-top:8px;"><div style="color:#3897f0;font-family:Arial,sans-serif;font-size:14px;font-style:normal;font-weight:550;line-height:18px;">Visualizza questo post su Instagram</div></div><div style="padding:12.5% 0;"></div></a></div></blockquote></div></div>';
        }
        if (preg_match('/facebook\.com\/(?:watch\/\?v=|reel\/|(?:[a-zA-Z0-9.]+\/)?(?:videos|posts)\/)/', $url)) {
            return '<div class="link-preview-wrap" style="position:relative;margin:0.8em 0;"><div class="link-preview link-preview--facebook" style="min-height:200px;background:#f0f2f5;border-radius:12px;overflow:hidden;padding:16px;display:flex;align-items:center;justify-content:center;"><div class="fb-post" data-href="' . $url . '" data-width="540" data-show-text="false" style="width:100%;"></div></div></div>';
        }
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $url)) {
            return '<div class="link-preview-wrap" style="position:relative;margin:0.8em 0;"><div class="link-preview link-preview--image" style="border-radius:12px;overflow:hidden;margin:0.6em 0;"><img src="' . $url . '" alt="Image" style="max-width:100%;display:block;border-radius:12px;"></div></div>';
        }
        $domain = parse_url($url, PHP_URL_HOST) ?: preg_replace('/^https?:\/\//', '', $url);
        $favicon = 'https://www.google.com/s2/favicons?domain=' . $domain . '&sz=32';
        return '<div class="link-preview-wrap" style="position:relative;margin:0.8em 0;"><div class="link-preview link-preview--generic" style="border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;background:#fff;"><a href="' . $url . '" target="_blank" style="display:flex;align-items:center;gap:12px;padding:12px 16px;text-decoration:none;color:inherit;"><img src="' . $favicon . '" alt="" style="width:32px;height:32px;border-radius:6px;flex-shrink:0;background:#f5f5f5;" onerror="this.style.display=\'none\'"><div style="min-width:0;"><div style="font-weight:600;font-size:14px;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . $domain . '</div><div style="font-size:12px;color:#888;margin-top:2px;">' . $domain . '</div></div></a></div></div>';
    }, $html);
    return $html;
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

// Routing
$action = $_GET['action'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'home' || $action === '') {
        // Home page - list threads with categories
        $threads = $pdo->query("
            SELECT t.*, u.username as author, c.name as category_name 
            FROM threads t 
            LEFT JOIN users u ON t.user_id = u.id 
            LEFT JOIN categories c ON t.category_id = c.id 
            WHERE t.status = 'visible' 
            ORDER BY t.created_at DESC 
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
        // Show new thread form
        if (!is_logged_in()) {
            die('Login required');
        }
        
        $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
        include __DIR__.'/views/new_thread.php';
    }
    elseif ($action === 'create_thread' && $method === 'POST') {
        // Handle new thread submission
        if (!is_logged_in()) {
            die('Login required');
        }
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
            foreach ($_FILES['attachments']['tmp_name'] as $index => $tmpName) {
                if ($_FILES['attachments']['error'][$index] === UPLOAD_ERR_OK) {
                    $originalName = basename($_FILES['attachments']['name'][$index]);
                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $safeName = uniqid().'.'.$extension;
                    $uploadPath = __DIR__.'/uploads/'.$safeName;
                    
                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        $pdo->prepare("
                            INSERT INTO uploads (thread_id, user_id, filename, original_name, size) 
                            VALUES (?, ?, ?, ?, ?)
                        ")->execute([
                            $threadId,
                            $_SESSION['user_id'],
                            $safeName,
                            $originalName,
                            $_FILES['attachments']['size'][$index]
                        ]);
                    }
                }
            }
        }
        
        $pluginManager->runHook('after_thread', $threadId);
        
        redirect(url('thread', ['id' => $threadId, 'slug' => slugify($_POST['title'] ?? '')]));
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
        // Show edit post form
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
        
        include __DIR__.'/views/edit_post.php';
    }
    elseif ($action === 'update_post' && $method === 'POST') {
        // Handle post update
        if (!is_logged_in()) {
            die('Login required');
        }
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
    elseif ($action === 'upload_avatar' && $method === 'POST' && is_logged_in()) {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }

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
    }
    elseif ($action === 'login') {
        // Show login form
        if (is_logged_in()) {
            redirect(url('home'));
        }
        include __DIR__.'/views/login.php';
    }
    elseif ($action === 'do_login' && $method === 'POST') {
        // Handle login submission
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'] ?? '';
            $_SESSION['avatar'] = $user['avatar'] ?? '';
            redirect(url('home'));
        } else {
            $error = 'Invalid credentials';
            include __DIR__.'/views/login.php';
        }
    }
    elseif ($action === 'register') {
        // Show registration form
        include __DIR__.'/views/register.php';
    }
    elseif ($action === 'do_register' && $method === 'POST') {
        // Handle registration
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
                    <p>Welcome to '.escape($config['site_name'] ?? 'bulletinbored').'!</p>
                    <p>Your account has been successfully created. You can now login and start participating in discussions.</p>';
            send_email($email, $subject, $body);
        }
        
        redirect(url('login'));
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
            WHERE t.user_id = ? AND t.status = 'visible' 
            ORDER BY t.created_at DESC 
            LIMIT 20
        ");
        $userThreadsStmt->execute([$profileUser['id']]);
        $userThreads = $userThreadsStmt->fetchAll();
        
        include __DIR__.'/views/profile.php';
    }
    elseif ($action === 'edit_profile') {
        // Show edit profile form
        if (!is_logged_in()) {
            die('Login required');
        }
        include __DIR__.'/views/edit_profile.php';
    }
    elseif ($action === 'update_profile' && $method === 'POST') {
        // Handle profile update
        if (!is_logged_in()) {
            die('Login required');
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        
        $updates = [];
        $params = [];
        
        if (!empty($_POST['username'])) {
            $newUsername = validate_input($_POST['username']);
            // Check if username is taken by another user
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
                WHERE t.status = 'visible' 
                AND (t.title LIKE ? OR t.content LIKE ? OR c.name LIKE ? OR u.username LIKE ?)
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $threadStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $perPage, $offset]);
            $threads = $threadStmt->fetchAll();
            
            $totalStmt2 = $pdo->prepare("
                SELECT COUNT(*) FROM threads t 
                LEFT JOIN categories c ON t.category_id = c.id 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE t.status = 'visible' 
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
                WHERE t.status = 'visible' 
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $threadStmt2->execute([$perPage, $offset]);
            $threads = $threadStmt2->fetchAll();
            
            $total = $pdo->query("SELECT COUNT(*) FROM threads WHERE status = 'visible'")->fetchColumn();
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
            WHERE t.category_id = ? AND t.status = 'visible' 
            ORDER BY t.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $catThreadStmt->execute([$categoryId, $perPage, $offset]);
        $threads = $catThreadStmt->fetchAll();
        
        $catTotalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM threads 
            WHERE category_id = ? AND status = 'visible'
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
        }
        
        redirect(url('admin'));
    }
    elseif ($action === 'create_category' && $method === 'POST' && is_admin()) {
        // Create new category
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $name = validate_input($_POST['name'] ?? '');
        $description = validate_input($_POST['description'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$name, $description]);
        }
        redirect(url('admin'));
    }
    elseif ($action === 'edit_category' && $method === 'POST' && is_admin()) {
        // Edit category
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $catId = (int)($_GET['id'] ?? 0);
        $name = validate_input($_POST['name'] ?? '');
        $description = validate_input($_POST['description'] ?? '');
        if ($catId > 0 && $name !== '') {
            $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?")->execute([$name, $description, $catId]);
        }
        redirect(url('admin'));
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
        redirect(url('admin'));
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
    elseif ($action === 'admin_categories') {
        // Show categories management page
        if (!is_admin()) {
            die('Admin required');
        }
        include __DIR__.'/views/admin_categories.php';
    }
    elseif ($action === 'admin_users') {
        // Show users management page
        if (!is_admin()) {
            die('Admin required');
        }
        include __DIR__.'/views/admin_users.php';
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
        // Show forgot password form
        include __DIR__.'/views/forgot_password.php';
    }
    elseif ($action === 'do_forgot_password' && $method === 'POST') {
        // Handle forgot password request
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
        include __DIR__.'/views/forgot_password.php';
    }
    elseif ($action === 'reset_password' && isset($_GET['token'])) {
        // Show reset password form
        include __DIR__.'/views/reset_password.php';
    }
    elseif ($action === 'do_reset_password' && $method === 'POST') {
        // Handle password reset
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
    elseif ($action === 'editbored_upload' && $method === 'POST') {
        // Image upload for Editbored editor
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