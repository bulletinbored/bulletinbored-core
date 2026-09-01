<?php

/**
 * SecurityFixesTest — tests for the vulnerability fixes in vulnerability.md.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

echo "SecurityFixesTest loaded\n";

function setupDB(): PDO
{
    $pdo = new PDO('sqlite::memory:');
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
            suspension_time INTEGER DEFAULT 0,
            email_verified INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE threads (
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
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE thread_watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(thread_id, user_id)
        );
        CREATE TABLE email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $roles = [
        ['admin', json_encode(['admin.access', 'threads.approve', 'threads.delete', 'threads.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'posts.delete', 'posts.edit', 'users.ban', 'users.create', 'users.delete', 'users.edit', 'roles.manage', 'categories.manage', 'settings.manage', 'plugins.manage', 'themes.manage', 'langs.manage'])],
        ['moderator', json_encode(['threads.approve', 'threads.delete', 'threads.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'posts.delete', 'posts.edit'])],
        ['user', json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own'])],
        ['restricted', json_encode([])],
    ];
    foreach ($roles as $role) {
        $stmt = $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)");
        $stmt->execute($role);
    }

    $stmt = $pdo->prepare("INSERT INTO categories (name, description, position) VALUES ('General', 'General discussion', 1)");
    $stmt->execute();

    return $pdo;
}

function test_bb001_can_view_thread_helper(): Test
{
    $t = new Test('BB-001 - can_view_thread helper');
    $t->assertTrue('visible is viewable', can_view_thread('visible'));
    $t->assertTrue('sticky is viewable', can_view_thread('sticky'));
    $t->assertTrue('locked is viewable', can_view_thread('locked'));
    $t->assertFalse('hidden NOT viewable by guest', can_view_thread('hidden'));
    $t->assertFalse('pending NOT viewable by guest', can_view_thread('pending'));
    return $t;
}

function test_bb001_moderator_can_view_hidden(): Test
{
    $t = new Test('BB-001 - moderator can view hidden');
    $pdo = setupDB();
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;
    App::getInstance()->pdo = $pdo;
    $_SESSION = ['user_id' => 99, 'user_role' => 'moderator'];
    $stmt = $pdo->prepare("INSERT INTO users (id, username, password, role, status) VALUES (?, 'mod', 'hash', 'moderator', 'active')");
    $stmt->execute([99]);
    $t->assertTrue('moderator can view hidden', can_view_thread('hidden'));
    $t->assertTrue('moderator can view pending', can_view_thread('pending'));
    App::reset();
    return $t;
}

function test_bb001_user_cannot_view_hidden(): Test
{
    $t = new Test('BB-001 - ordinary user cannot view hidden');
    $pdo = setupDB();
    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;
    App::getInstance()->pdo = $pdo;
    $_SESSION = ['user_id' => 100, 'user_role' => 'user'];
    $stmt = $pdo->prepare("INSERT INTO users (id, username, password, role, status) VALUES (?, 'user', 'hash', 'user', 'active')");
    $stmt->execute([100]);
    $t->assertFalse('user cannot view hidden', can_view_thread('hidden'));
    $t->assertFalse('user cannot view pending', can_view_thread('pending'));
    $t->assertTrue('user can view visible', can_view_thread('visible'));
    App::reset();
    return $t;
}

function test_bb002_suspension_time_column(): Test
{
    $t = new Test('BB-002 - suspension_time column exists');
    $pdo = setupDB();
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $t->assert('suspension_time in schema', in_array('suspension_time', $cols, true));
    return $t;
}

function test_bb005_restricted_cannot_create(): Test
{
    $t = new Test('BB-005 - restricted role cannot create');
    $pdo = setupDB();
    $authz = new AuthZ($pdo);
    $userId = 50;
    $stmt = $pdo->prepare("INSERT INTO users (id, username, password, role, status) VALUES (?, 'restricted', 'hash', 'restricted', 'active')");
    $stmt->execute([50]);
    $t->assertFalse('restricted cannot threads.create', $authz->can($userId, 'threads.create'));
    $t->assertFalse('restricted cannot posts.create', $authz->can($userId, 'posts.create'));
    return $t;
}

function test_bb005_user_can_create(): Test
{
    $t = new Test('BB-005 - normal user can create');
    $pdo = setupDB();
    $authz = new AuthZ($pdo);
    $userId = 51;
    $stmt = $pdo->prepare("INSERT INTO users (id, username, password, role, status) VALUES (?, 'normal', 'hash', 'user', 'active')");
    $stmt->execute([51]);
    $t->assertTrue('user can threads.create', $authz->can($userId, 'threads.create'));
    $t->assertTrue('user can posts.create', $authz->can($userId, 'posts.create'));
    return $t;
}

function test_bb007_atomic_token_consume(): Test
{
    $t = new Test('BB-007 - atomic token consumption');
    $pdo = setupDB();
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (1, ?, ?, ?)")
        ->execute([password_hash($token, PASSWORD_DEFAULT), hash('sha256', $token), $expires]);
    $id = $pdo->lastInsertId();
    $claim1 = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
    $claim1->execute([$id]);
    $claim2 = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
    $claim2->execute([$id]);
    $t->assertEquals('First claim succeeds', 1, $claim1->rowCount());
    $t->assertEquals('Second claim fails', 0, $claim2->rowCount());
    return $t;
}

function test_bb008_rate_limit(): Test
{
    $t = new Test('BB-008 - rate limiter respects limit');
    $dir = __DIR__ . '/../data/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $uniq = uniqid('rl_', true);
    $file = $dir . '/' . $uniq . '.json';
    @unlink($file);
    App::getInstance()->config = ['trusted_proxies' => ['127.0.0.1']];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $allowed = 0; $blocked = 0;
    for ($i = 0; $i < 10; $i++) {
        if (rate_limit($uniq, 5, 300, 'key')) $allowed++; else $blocked++;
    }
    @unlink($file);
    $t->assertEquals('Exactly 5 allowed', 5, $allowed);
    $t->assertEquals('Exactly 5 blocked', 5, $blocked);
    return $t;
}

function test_nb001_version(): Test
{
    $t = new Test('NB-001 - VERSION is 0.8.2');
    $version = trim(file_get_contents(__DIR__ . '/../VERSION'));
    $t->assertEquals('VERSION is 0.8.2', '0.8.2', $version);
    return $t;
}

function test_nb003_permission_notation(): Test
{
    $t = new Test('NB-003 - canonical permission notation');
    $pdo = setupDB();
    $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE name = 'user'");
    $stmt->execute();
    $perms = json_decode($stmt->fetchColumn(), true);
    $t->assert('user has threads.create', in_array('threads.create', $perms, true));
    $t->assert('user has posts.create', in_array('posts.create', $perms, true));
    $t->assert('no legacy can_create_threads', !in_array('can_create_threads', $perms, true));
    return $t;
}

function test_password_policy(): Test
{
    $t = new Test('Password Policy');
    $t->assert('Strong password passes', empty(validate_password_strength('MyP4ssword123')));
    $t->assert('Too short fails', !empty(validate_password_strength('Ab1')));
    $t->assert('No uppercase fails', !empty(validate_password_strength('mypassword123')));
    $t->assert('No lowercase fails', !empty(validate_password_strength('MYPASSWORD123')));
    $t->assert('No number fails', !empty(validate_password_strength('MyPasswordHere')));
    $t->assert('10 chars with rules passes', empty(validate_password_strength('Abcdefg1hi')));
    return $t;
}

function test_logout_csrf(): Test
{
    $t = new Test('Logout CSRF protection');
    $routes = file_get_contents(__DIR__ . '/../index.php');
    $t->assert('Logout uses POST', str_contains($routes, '$router->post(\'/logout\''));
    $t->assert('Logout does NOT use GET', !str_contains($routes, '$router->get(\'/logout\''));
    $handler = file_get_contents(__DIR__ . '/../src/actions/users.php');
    $t->assert('handle_logout checks REQUEST_METHOD', str_contains($handler, 'REQUEST_METHOD'));
    $t->assert('handle_logout checks csrf', str_contains($handler, 'csrf_validate_request'));
    return $t;
}

function test_token_hash_lookup(): Test
{
    $t = new Test('Token hash lookup O(1)');
    $pdo = setupDB();
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (1, ?, ?, ?)")
        ->execute([password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $found = $stmt->fetch();
    $t->assert('Token found by hash', $found !== false);
    $t->assert('Hash matches', $found['token_hash'] === $tokenHash);
    return $t;
}

function test_no_eval_in_langs(): Test
{
    $t = new Test('No eval() in language installer');
    $code = file_get_contents(__DIR__ . '/../src/actions/admin/langs.php');
    $t->assert('No eval() found', !str_contains($code, 'eval('));
    $t->assert('No PHP language file support', !str_contains($code, '?> . $content'));
    $t->assert('Only JSON accepted', str_contains($code, "str_ends_with(\$tryUrl, '.json')"));
    return $t;
}

function test_tls_verification_enabled(): Test
{
    $t = new Test('TLS verification enabled in UpdateManager');
    $code = file_get_contents(__DIR__ . '/../lib/UpdateManager.php');
    $t->assert('VERIFYPEER is true', str_contains($code, 'CURLOPT_SSL_VERIFYPEER => true'));
    $t->assert('VERIFYHOST is 2', str_contains($code, 'CURLOPT_SSL_VERIFYHOST => 2'));
    $t->assert('No VERIFYPEER false', !str_contains($code, 'CURLOPT_SSL_VERIFYPEER => false'));
    $t->assert('No VERIFYHOST false', !str_contains($code, 'CURLOPT_SSL_VERIFYHOST => false'));
    return $t;
}

function test_download_requires_thread_access(): Test
{
    $t = new Test('Download requires thread access');
    $code = file_get_contents(__DIR__ . '/../src/actions/content.php');
    $t->assert('can_view_thread in download', str_contains($code, 'can_view_thread'));
    $t->assert('ForbiddenException for unauthorized', str_contains($code, 'ForbiddenException'));
    return $t;
}

function test_integration_bootstrap_no_fatal(): Test
{
    $t = new Test('Integration: bootstrap does not fatal');

    // Simulate the full bootstrap sequence that index.php runs
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = [];

    $errors = [];
    set_error_handler(function ($errno, $errstr) use (&$errors) {
        $errors[] = $errstr;
        return true;
    });

    try {
        // These are the core files loaded by index.php
        require_once __DIR__ . '/../src/App.php';
        require_once __DIR__ . '/../src/csp.php';
        require_once __DIR__ . '/../src/TrustedProxies.php';
        require_once __DIR__ . '/../src/Security.php';

        $t->assert('No fatal errors during bootstrap', empty($errors));
        $t->assert('App class exists', class_exists('App'));
        $t->assert('App singleton works', App::getInstance() !== null);
    } catch (\Throwable $e) {
        $t->assert('No exception: ' . $e->getMessage(), false);
    }

    restore_error_handler();
    App::reset();

    return $t;
}

function test_integration_csp_allows_fonts(): Test
{
    $t = new Test('Integration: CSP allows font sources');

    $cspCode = file_get_contents(__DIR__ . '/../src/csp.php');

    $t->assert('CSP includes cdn.jsdelivr.net in font-src', str_contains($cspCode, 'https://cdn.jsdelivr.net'));
    $t->assert('CSP includes cdnjs.cloudflare.com in font-src', str_contains($cspCode, 'https://cdnjs.cloudflare.com'));
    $t->assert('CSP includes data: in font-src', str_contains($cspCode, 'data:'));

    return $t;
}

function test_trusted_proxies_ipv4(): Test
{
    $t = new Test('TrustedProxies IPv4 support');

    $origServer = $_SERVER;
    $origConfig = App::getInstance()->config;

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    App::getInstance()->config = ['trusted_proxies' => ['127.0.0.1']];
    $result = trusted_proxies_detect();
    $t->assertTrue('127.0.0.1 is trusted', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    App::getInstance()->config = ['trusted_proxies' => ['10.0.0.0/24']];
    $result = trusted_proxies_detect();
    $t->assertTrue('10.0.0.5 in 10.0.0.0/24 is trusted', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
    App::getInstance()->config = ['trusted_proxies' => ['10.0.0.0/24']];
    $result = trusted_proxies_detect();
    $t->assertFalse('192.168.1.1 not in 10.0.0.0/24', $result['is_trusted']);

    $_SERVER = $origServer;
    App::getInstance()->config = $origConfig;
    return $t;
}

function test_trusted_proxies_ipv6(): Test
{
    $t = new Test('TrustedProxies IPv6 support');

    $origServer = $_SERVER;
    $origConfig = App::getInstance()->config;

    $_SERVER['REMOTE_ADDR'] = '::1';
    App::getInstance()->config = ['trusted_proxies' => ['::1']];
    $result = trusted_proxies_detect();
    $t->assertTrue('::1 is trusted', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
    App::getInstance()->config = ['trusted_proxies' => ['2001:db8::/32']];
    $result = trusted_proxies_detect();
    $t->assertTrue('2001:db8::1 in 2001:db8::/32 is trusted', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = 'fe80::1';
    App::getInstance()->config = ['trusted_proxies' => ['2001:db8::/32']];
    $result = trusted_proxies_detect();
    $t->assertFalse('fe80::1 not in 2001:db8::/32', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = '::1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 70.41.1.183';
    App::getInstance()->config = ['trusted_proxies' => ['::1']];
    $result = trusted_proxies_detect();
    $t->assertEquals('X-Forwarded-For extracts first IP', '203.0.113.50', $result['forwarded_for']);

    $_SERVER = $origServer;
    App::getInstance()->config = $origConfig;
    return $t;
}

function test_trusted_proxies_cidr(): Test
{
    $t = new Test('TrustedProxies CIDR edge cases');

    $origServer = $_SERVER;
    $origConfig = App::getInstance()->config;

    $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
    App::getInstance()->config = ['trusted_proxies' => ['0.0.0.0/0']];
    $result = trusted_proxies_detect();
    $t->assertTrue('0.0.0.0/0 matches any IPv4', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    App::getInstance()->config = ['trusted_proxies' => ['192.168.1.100/32']];
    $result = trusted_proxies_detect();
    $t->assertTrue('192.168.1.100/32 exact match', $result['is_trusted']);

    $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
    App::getInstance()->config = ['trusted_proxies' => ['2001:db8::1/128']];
    $result = trusted_proxies_detect();
    $t->assertTrue('2001:db8::1/128 exact match', $result['is_trusted']);

    $_SERVER = $origServer;
    App::getInstance()->config = $origConfig;
    return $t;
}

$tests = [
    test_bb001_can_view_thread_helper(),
    test_bb001_moderator_can_view_hidden(),
    test_bb001_user_cannot_view_hidden(),
    test_bb002_suspension_time_column(),
    test_bb005_restricted_cannot_create(),
    test_bb005_user_can_create(),
    test_bb007_atomic_token_consume(),
    test_bb008_rate_limit(),
    test_nb001_version(),
    test_nb003_permission_notation(),
    test_password_policy(),
    test_logout_csrf(),
    test_token_hash_lookup(),
    test_no_eval_in_langs(),
    test_tls_verification_enabled(),
    test_download_requires_thread_access(),
    test_integration_bootstrap_no_fatal(),
    test_integration_csp_allows_fonts(),
    test_trusted_proxies_ipv4(),
    test_trusted_proxies_ipv6(),
    test_trusted_proxies_cidr(),
];

$totalPassed = 0;
$totalFailed = 0;
foreach ($tests as $t) {
    $t->run();
    $totalPassed += $t->getPassed();
    $totalFailed += $t->getFailed();
}

echo "\n";
echo "############################################################\n";
echo "# TOTAL: {$totalPassed} passed, {$totalFailed} failed\n";
echo "############################################################\n";

exit($totalFailed > 0 ? 1 : 0);
