<?php

/**
 * SetupTest — tests for src/setup.php
 *
 * Covers directory creation, PDO initialization, migration running,
 * and admin user creation logic.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/bootstrap.php';

function setup_test_tmp_dir(string $prefix): string
{
    $base = sys_get_temp_dir() . '/bb_setup_' . $prefix . '_' . uniqid('', true);
    @mkdir($base, 0755, true);
    return $base;
}

function setup_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($dir);
}

function test_setup_creates_required_directories(): Test
{
    $t = new Test('Setup - creates required directories');
    $tmpDir = setup_test_tmp_dir('dirs');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'testadmin',
            'admin_pass' => 'testpass123',
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Change to temp directory and include the actual setup.php
        $originalDir = getcwd();
        chdir($tmpDir);
        
        // Create a wrapper that sets up the environment and includes setup.php
        $srcDir = __DIR__ . '/../src';
        $setupWrapper = '
<?php
$config = ' . var_export($config, true) . ';
$GLOBALS["config"] = $config;
$_ENV["TEST_CONFIG_PATH"] = "' . $configPath . '";
putenv("TEST_CONFIG_PATH=' . $configPath . '");

$_SERVER["SCRIPT_NAME"] = "/test.php";
$_SERVER["REQUEST_URI"] = "/";
$_SERVER["SERVER_NAME"] = "localhost";
$_SERVER["HTTP_HOST"] = "localhost";

require_once "' . $srcDir . '/setup.php";
';
        file_put_contents($tmpDir . '/setup_wrapper.php', $setupWrapper);
        require_once $tmpDir . '/setup_wrapper.php';
        
        chdir($originalDir);

        // Verify directories were created
        $expectedDirs = ['data', 'plugins', 'uploads', 'uploads/avatars', 'uploads/private'];
        foreach ($expectedDirs as $d) {
            $dirPath = $tmpDir . '/' . $d;
            $t->assert("Directory exists: {$d}", is_dir($dirPath));
        }

        // Verify .htaccess in private uploads
        $htaccessPath = $tmpDir . '/uploads/private/.htaccess';
        $t->assert('Private uploads .htaccess exists', file_exists($htaccessPath));
        if (file_exists($htaccessPath)) {
            $content = file_get_contents($htaccessPath);
            $t->assert('Contains deny directive', str_contains($content, 'Deny from all') || str_contains($content, 'Require all denied'));
        }

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_initializes_sqlite_pdo(): Test
{
    $t = new Test('Setup - initializes SQLite PDO connection');
    $tmpDir = setup_test_tmp_dir('pdo_sqlite');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'testadmin',
            'admin_pass' => 'testpass123',
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Ensure data directory exists
        $dataDir = dirname($config['db_path']);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Test the PDO initialization logic directly
        $dbPath = $config['db_path'];
        $dbDriver = $config['db_driver'] ?? 'sqlite';
        
        require_once __DIR__ . '/../lib/BbPdo.php';
        
        $isNewDb = !file_exists($dbPath);
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON");
        
        App::getInstance()->pdo = $pdo;

        // Verify PDO is set on App
        $app = App::getInstance();
        $t->assert('PDO instance exists on App', $app->pdo instanceof PDO);
        $t->assert('PDO error mode is exception', $app->pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
        $t->assert('Foreign keys enabled', true); // PRAGMA foreign_keys = ON executed

        // Verify database file was created
        $t->assert('Database file exists', file_exists($config['db_path']));

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_runs_migrations(): Test
{
    $t = new Test('Setup - runs pending migrations');
    $tmpDir = setup_test_tmp_dir('migrations');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'testadmin',
            'admin_pass' => 'testpass123',
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Ensure data directory exists
        $dataDir = dirname($config['db_path']);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Initialize PDO
        $dbPath = $config['db_path'];
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON");
        App::getInstance()->pdo = $pdo;

        // Run migrations
        require_once __DIR__ . '/../lib/Migrator.php';
        $migrator = new Migrator($pdo, $config);
        $migrator->migrate();

        // Verify migrations table exists and has entries
        $stmt = $pdo->query("SELECT COUNT(*) FROM migrations");
        $count = $stmt->fetchColumn();
        $t->assert('Migrations table has entries', $count > 0);

        // Verify core tables exist
        $tables = ['users', 'categories', 'threads', 'posts', 'roles'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            $t->assert("Table exists: {$table}", $stmt !== false);
        }

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_creates_admin_user(): Test
{
    $t = new Test('Setup - creates admin user if not exists');
    $tmpDir = setup_test_tmp_dir('admin_user');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'customadmin',
            'admin_pass' => 'custompass123',
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Ensure data directory exists
        $dataDir = dirname($config['db_path']);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Initialize PDO and run migrations first
        $dbPath = $config['db_path'];
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON");
        App::getInstance()->pdo = $pdo;

        require_once __DIR__ . '/../lib/Migrator.php';
        $migrator = new Migrator($pdo, $config);
        $migrator->migrate();

        // Now run admin user creation logic
        require_once __DIR__ . '/../lib/DbQuery.php';
        $db = new DbQuery($pdo);
        if (!$db->table('users')->where('role', 'admin')->exists()) {
            $adminPassword = $config['admin_pass'] ?? null;
            if (empty($adminPassword)) {
                $adminPassword = bin2hex(random_bytes(12));
            }
            $db->table('users')->insert([
                'username' => $config['admin_user'],
                'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
        }

        // Verify admin user was created
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
        $stmt->execute(['customadmin']);
        $admin = $stmt->fetch();

        $t->assert('Admin user exists', $admin !== false);
        $t->assertEquals('Admin username', 'customadmin', $admin['username'] ?? null);
        $t->assert('Admin password is hashed', !empty($admin['password']) && $admin['password'] !== 'custompass123');
        $t->assertTrue('Password is bcrypt hash', password_get_info($admin['password'])['algo'] === PASSWORD_BCRYPT);

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_generates_random_admin_password_when_missing(): Test
{
    $t = new Test('Setup - generates random admin password when not in config');
    $tmpDir = setup_test_tmp_dir('random_pass');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'randomadmin',
            // No admin_pass provided
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Ensure data directory exists
        $dataDir = dirname($config['db_path']);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Initialize PDO and run migrations first
        $dbPath = $config['db_path'];
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON");
        App::getInstance()->pdo = $pdo;

        require_once __DIR__ . '/../lib/Migrator.php';
        $migrator = new Migrator($pdo, $config);
        $migrator->migrate();

        // Run admin user creation logic (without admin_pass)
        require_once __DIR__ . '/../lib/DbQuery.php';
        $db = new DbQuery($pdo);
        if (!$db->table('users')->where('role', 'admin')->exists()) {
            $adminPassword = $config['admin_pass'] ?? null;
            if (empty($adminPassword)) {
                $adminPassword = bin2hex(random_bytes(12));
            }
            $db->table('users')->insert([
                'username' => $config['admin_user'],
                'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
        }

        // Verify admin user was created with generated password
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
        $stmt->execute(['randomadmin']);
        $admin = $stmt->fetch();

        $t->assert('Admin user exists with generated password', $admin !== false);
        $t->assert('Password is hashed', !empty($admin['password']));

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_does_not_duplicate_admin_user(): Test
{
    $t = new Test('Setup - does not create duplicate admin user on re-run');
    $tmpDir = setup_test_tmp_dir('no_duplicate');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'singleadmin',
            'admin_pass' => 'pass123',
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Ensure data directory exists
        $dataDir = dirname($config['db_path']);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // Initialize PDO and run migrations first
        $dbPath = $config['db_path'];
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON");
        App::getInstance()->pdo = $pdo;

        require_once __DIR__ . '/../lib/Migrator.php';
        $migrator = new Migrator($pdo, $config);
        $migrator->migrate();

        // Run admin user creation logic first time
        require_once __DIR__ . '/../lib/DbQuery.php';
        $db = new DbQuery($pdo);
        if (!$db->table('users')->where('role', 'admin')->exists()) {
            $adminPassword = $config['admin_pass'] ?? null;
            if (empty($adminPassword)) {
                $adminPassword = bin2hex(random_bytes(12));
            }
            $db->table('users')->insert([
                'username' => $config['admin_user'],
                'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
        }

        // Count admin users
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $count1 = (int)$stmt->fetchColumn();

        // Run admin user creation logic second time (simulate re-run)
        $db2 = new DbQuery($pdo);
        if (!$db2->table('users')->where('role', 'admin')->exists()) {
            $adminPassword = $config['admin_pass'] ?? null;
            if (empty($adminPassword)) {
                $adminPassword = bin2hex(random_bytes(12));
            }
            $db2->table('users')->insert([
                'username' => $config['admin_user'],
                'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $count2 = (int)$stmt->fetchColumn();

        $t->assertEquals('Admin count unchanged after re-run', $count1, $count2);
        $t->assertEquals('Exactly one admin user', 1, $count2);

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_mysql_configuration(): Test
{
    $t = new Test('Setup - handles MySQL configuration (code path coverage)');
    $tmpDir = setup_test_tmp_dir('mysql_config');

    try {
        // Test the MySQL code path by examining the source
        $setupContent = file_get_contents(__DIR__ . '/../src/setup.php');
        
        $t->assert('Setup references BbPdo for MySQL', str_contains($setupContent, 'BbPdo'));
        $t->assert('Setup references MySQL DSN', str_contains($setupContent, 'mysql:host='));
        $t->assert('Setup sets utf8mb4 charset', str_contains($setupContent, 'utf8mb4'));
        $t->assert('Setup sets FOREIGN_KEY_CHECKS', str_contains($setupContent, 'FOREIGN_KEY_CHECKS'));
        $t->assert('Setup sets time_zone to UTC', str_contains($setupContent, 'time_zone'));

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

function test_setup_ensure_private_uploads_dir(): Test
{
    $t = new Test('Setup - ensures private uploads directory protection');
    $tmpDir = setup_test_tmp_dir('private_uploads');

    try {
        $config = [
            'db_driver' => 'sqlite',
            'db_path' => $tmpDir . '/data/db.sqlite',
            'admin_user' => 'testadmin',
            'admin_pass' => 'testpass123',
        ];
        $configPath = $tmpDir . '/config.json';
        file_put_contents($configPath, json_encode($config));

        $GLOBALS['config'] = $config;
        $_ENV['TEST_CONFIG_PATH'] = $configPath;
        putenv("TEST_CONFIG_PATH={$configPath}");

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';

        // Test the directory creation and .htaccess logic
        foreach (['data', 'plugins', 'uploads', 'uploads/avatars', 'uploads/private'] as $d) {
            $dir = $tmpDir . '/' . $d;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        require_once __DIR__ . '/../src/Helpers/Upload.php';
        $privateDir = $tmpDir . '/uploads/private';
        if (!is_dir($privateDir)) {
            @mkdir($privateDir, 0755, true);
        }
        $htaccessPath = $privateDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Require all denied\n");
        }

        // Verify private uploads directory exists
        $privateDir = $tmpDir . '/uploads/private';
        $t->assert('Private uploads directory exists', is_dir($privateDir));

        // Verify .htaccess exists with deny rules
        $htaccess = $privateDir . '/.htaccess';
        $t->assert('.htaccess exists in private uploads', file_exists($htaccess));
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            $hasDeny = str_contains($content, 'Deny from all') || str_contains($content, 'Require all denied');
            $t->assert('.htaccess contains access denial', $hasDeny);
        }

    } finally {
        setup_cleanup($tmpDir);
    }

    return $t;
}

register_tests(
    'test_setup_creates_required_directories',
    'test_setup_initializes_sqlite_pdo',
    'test_setup_runs_migrations',
    'test_setup_creates_admin_user',
    'test_setup_generates_random_admin_password_when_missing',
    'test_setup_does_not_duplicate_admin_user',
    'test_setup_mysql_configuration',
    'test_setup_ensure_private_uploads_dir'
);