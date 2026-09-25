<?php

/**
 * Test bootstrap — prepares the test environment.
 *
 * Sets up:
 * - Test configuration (in-memory SQLite, temporary directories)
 * - Session handling for tests
 * - App singleton reset
 * - Error reporting
 * - Autoloader for Bulletin namespace
 */

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

// Define test mode constant
define('BULLETIN_TEST_MODE', true);

// Reset App singleton for clean test state
require_once __DIR__ . '/../src/App.php';
App::reset();

// Create temporary directories for tests
$tempDir = sys_get_temp_dir() . '/bulletinbored_tests_' . uniqid();
$dirs = [
    $tempDir . '/data/sessions',
    $tempDir . '/data/uploads/private',
    $tempDir . '/data/uploads/avatars',
    $tempDir . '/data/cache',
    $tempDir . '/lang',
];

foreach ($dirs as $dir) {
    @mkdir($dir, 0755, true);
}

// Test configuration
$config = [
    'db_driver' => 'sqlite',
    'db_path' => $tempDir . '/data/db.sqlite',
    'base_url' => 'http://localhost/test',
    'default_lang' => 'en',
    'available_langs' => ['en'],
    'cookie_secure' => false,
    'force_https' => false,
    'session_path' => $tempDir . '/data/sessions',
    'upload_path' => $tempDir . '/data/uploads',
    'cache_path' => $tempDir . '/data/cache',
];

// Write test config
$configPath = $tempDir . '/config.json';
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Set environment variables for test config
$_ENV['TEST_CONFIG_PATH'] = $configPath;
putenv("TEST_CONFIG_PATH={$configPath}");

// Mock $_SERVER for CLI tests
if (!isset($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/test.php';
}
if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}
if (!isset($_SERVER['SERVER_NAME'])) {
    $_SERVER['SERVER_NAME'] = 'localhost';
}
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

// Initialize App singleton early (needed for TrustedProxies)
require_once __DIR__ . '/../src/App.php';
App::reset();
$app = App::getInstance();
$app->config = $config;

// Session setup with test config (copied from session_setup.php but without main bootstrap dependencies)
$sessionDir = $config['session_path'];
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

session_name('BBSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Start session BEFORE sending headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load minimal required files (no main bootstrap)
require_once __DIR__ . '/../src/csp.php';
require_once __DIR__ . '/../src/Errors.php';
require_once __DIR__ . '/../src/Response.php';

$cspNonce = generate_csp_nonce();
// Don't send headers in test bootstrap - they interfere with session operations
// send_security_headers($cspNonce);

require_once __DIR__ . '/../src/TrustedProxies.php';
$proxyInfo = trusted_proxies_detect();

$app->forwardedProto = $proxyInfo['forwarded_proto'];
$app->forwardedSsl = $proxyInfo['forwarded_ssl'];
$app->forwardedFor = $proxyInfo['forwarded_for'];

// PSR-4 autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Bulletin\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Load translation
$app->i18n = [];
$coreLangFile = __DIR__ . '/../lang/en.json';
if (file_exists($coreLangFile)) {
    $raw = file_get_contents($coreLangFile);
    $data = json_decode($raw, true);
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $app->i18n['core'][$k] = $v;
            }
        }
    }
}
$translations = &$app->i18n['core'];

// Helper functions for tests
function get_test_config(): array
{
    global $config;
    return $config;
}

function get_test_temp_dir(): string
{
    global $tempDir;
    return $tempDir;
}

function create_test_pdo(): PDO
{
    global $config;
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function create_test_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            email_verified INTEGER DEFAULT 0,
            session_version INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration TEXT NOT NULL UNIQUE,
            batch INTEGER NOT NULL,
            ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
}

// Cleanup function for tests
function cleanup_test_env(): void
{
    global $tempDir;
    if (isset($tempDir) && is_dir($tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($tempDir);
    }
}

// Register shutdown cleanup
register_shutdown_function('cleanup_test_env');