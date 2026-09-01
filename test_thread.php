<?php
$_SERVER['REQUEST_URI'] = '/thread/1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = ['user_id' => 1, 'user_role' => 'admin'];
$_GET = ['id' => 1];

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'src/bootstrap.php';
require 'src/setup.php';

// Create a test thread
$pdo = App::getInstance()->pdo;
$pdo->exec("INSERT OR IGNORE INTO categories (id, name, position) VALUES (1, 'Test', 1)");
$pdo->exec("INSERT OR IGNORE INTO users (id, username, password, role) VALUES (1, 'testuser', 'hash', 'admin')");
$pdo->exec("INSERT OR IGNORE INTO threads (id, category_id, user_id, title, content, status) VALUES (1, 1, 1, 'Test Thread', 'Hello World', 'visible')");

// Test handle_thread_view
try {
    $result = handle_thread_view(['id' => 1]);
    echo "Result: " . var_export($result, true) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
