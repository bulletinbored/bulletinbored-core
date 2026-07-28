<?php
session_start();

// Configuration
$config = [
    'db_driver' => 'sqlite',
    'db_path' => __DIR__.'/data/database.sqlite',
    'site_name' => 'Forum Nuovo',
    'admin_user' => 'admin',
    'admin_pass' => 'changeme123'
];

// Ensure directories exist
foreach (['data','plugins','uploads'] as $d) {
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
        "users" => "id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(50) DEFAULT 'user'",
        "categories" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, position INT DEFAULT 0",
        "threads" => "id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT, title TEXT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "posts" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        "uploads" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, post_id INT, user_id INT, filename VARCHAR(255), original_name VARCHAR(255), size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP"
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
                role TEXT DEFAULT 'user'
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
        
        // Safe column addition for posts table
        try {
            $cols = $pdo->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN);
            // Add any missing columns for posts if needed
        } catch (PDOException $e) {}
    }
}

// Helper functions
function escape($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function is_logged_in() { return isset($_SESSION['user_id']); }
function is_admin() { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function redirect($url) { header("Location: $url"); exit; }
function base_url() {
    static $baseUrl = null;
    if ($baseUrl === null) {
        $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($baseUrl === '' || $baseUrl === '\\') {
            $baseUrl = '';
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

// Load plugins
foreach (glob(__DIR__.'/plugins/*.php') as $file) {
    include $file;
    $pluginName = basename($file, '.php');
    $initFunction = $pluginName.'_init';
    if (function_exists($initFunction)) {
        $initFunction(); // Call init function
    }
}

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
            SELECT t.*, u.username as author, c.name as category_name 
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
            SELECT p.*, u.username as author 
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
        
        redirect(base_url().'/?action=thread&id='.$threadId);
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
        
        redirect(base_url().'/?action=thread&id='.$threadId);
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
        redirect(base_url().'/?action=thread&id='.$threadId);
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
        redirect(base_url().'/?action=thread&id='.$post['thread_id']);
    }
    elseif ($action === 'login') {
        // Show login form
        if (is_logged_in()) {
            redirect(base_url().'/?action=home');
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
            redirect(base_url().'/?action=home');
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
        
        $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        
        redirect(base_url().'/?action=login');
    }
    elseif ($action === 'logout') {
        // Handle logout
        session_destroy();
        redirect(base_url().'/?action=home');
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
        
        redirect(base_url().'/?action=profile&user='.$_SESSION['username']);
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
        
        redirect(base_url().'/?action=admin');
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