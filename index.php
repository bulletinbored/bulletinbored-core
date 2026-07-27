<?php
// Forum Nuovo - Single File Version
// Minimal MVC Forum with Plugin Support
// Just upload this file and go!

session_start();

// Configuration
$config = [
    'db_path' => __DIR__ . '/data/database.sqlite',
    'site_name' => 'Forum Nuovo',
    'admin_user' => 'admin',
    'admin_pass' => 'changeme123'
];

// Create directories
foreach (['data', 'plugins'] as $dir) {
    if (!is_dir(__DIR__ . "/$dir")) mkdir(__DIR__ . "/$dir", 0755, true);
}

// Database
$dbPath = $config['db_path'];
if (!file_exists($dbPath)) {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->exec("
        CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT, role TEXT DEFAULT 'user');
        CREATE TABLE threads (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, content TEXT, status TEXT DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER, user_id INTEGER, content TEXT, status TEXT DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO users (username, password, role) VALUES ('admin', '" . password_hash($config['admin_pass'], PASSWORD_DEFAULT) . "', 'admin');
    ");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Helpers
function escape($s) { return htmlspecialchars($s); }
function is_logged_in() { return isset($_SESSION['user_id']); }
function is_admin() { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function redirect($url) { header("Location: $url"); exit; }

// Load plugins
foreach (glob(__DIR__ . '/plugins/*.php') as $file) {
    include $file;
    $name = basename($file, '.php');
    if (function_exists($name . '_init')) {
        $name . '_init'()();
    }
}

// Router
$action = $_GET['action'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

// Routes
if ($action === 'home' || $action === '') {
    // List threads
    $threads = $pdo->query("SELECT t.*, u.username as author FROM threads t LEFT JOIN users u ON t.user_id = u.id WHERE t.status = 'visible' ORDER BY t.created_at DESC LIMIT 20")->fetchAll();
    include __DIR__ . '/views/home.php';
} elseif ($action === 'thread' && isset($_GET['id'])) {
    $thread = $pdo->prepare("SELECT * FROM threads WHERE id = ?")->execute([$_GET['id']])->fetch();
    if (!$thread) die('Thread not found');
    $posts = $pdo->prepare("SELECT p.*, u.username as author FROM posts p LEFT JOIN users u ON p.user_id = u.id WHERE p.thread_id = ? AND p.status = 'visible'")->execute([$_GET['id']])->fetchAll();
    include __DIR__ . '/views/thread.php';
} elseif ($action === 'new_thread') {
    if (!is_logged_in()) die('Login required');
    include __DIR__ . '/views/new_thread.php';
} elseif ($action === 'create_thread' && $method === 'POST') {
    if (!is_logged_in()) die('Login required');
    $stmt = $pdo->prepare("INSERT INTO threads (user_id, title, content) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_POST['title'], $_POST['content']]);
    redirect('/?action=home');
} elseif ($action === 'reply' && $method === 'POST') {
    if (!is_logged_in()) die('Login required');
    $stmt = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['thread_id'], $_SESSION['user_id'], $_POST['content']]);
    redirect('/?action=thread&id=' . $_POST['thread_id']);
} elseif ($action === 'login') {
    if (is_logged_in()) redirect('/?action=home');
    include __DIR__ . '/views/login.php';
} elseif ($action === 'do_login' && $method === 'POST') {
    $user = $pdo->prepare("SELECT * FROM users WHERE username = ?")->execute([$_POST['username']])->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        redirect('/?action=home');
    }
    $error = 'Invalid credentials';
    include __DIR__ . '/views/login.php';
} elseif ($action === 'logout') {
    session_destroy();
    redirect('/?action=home');
} elseif ($action === 'register') {
    include __DIR__ . '/views/register.php';
} elseif ($action === 'do_register' && $method === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
    $stmt->execute([$_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT)]);
    redirect('/?action=login');
} elseif ($action === 'admin') {
    if (!is_admin()) die('Admin required');
    $pending = $pdo->query("SELECT * FROM threads WHERE status = 'pending'")->fetchAll();
    include __DIR__ . '/views/admin.php';
} elseif ($action === 'moderate' && $method === 'POST' && is_admin()) {
    if ($_POST['do'] === 'approve') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$_POST['id']]);
    }
    if ($_POST['do'] === 'delete') {
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$_POST['id']]);
    }
    redirect('/?action=admin');
} else {
    http_response_code(404);
    echo 'Page not found';
}