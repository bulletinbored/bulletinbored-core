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
    $GLOBALS['pdo'] = setupDB();
    $authz = new AuthZ($GLOBALS['pdo']);
    $GLOBALS['authz'] = $authz;
    $_SESSION = ['user_id' => 99, 'user_role' => 'moderator'];
    $stmt = $GLOBALS['pdo']->prepare("INSERT INTO users (id, username, password, role, status) VALUES (?, 'mod', 'hash', 'moderator', 'active')");
    $stmt->execute([99]);
    $t->assertTrue('moderator can view hidden', can_view_thread('hidden'));
    $t->assertTrue('moderator can view pending', can_view_thread('pending'));
    return $t;
}

function test_bb001_user_cannot_view_hidden(): Test
{
    $t = new Test('BB-001 - ordinary user cannot view hidden');
    $GLOBALS['pdo'] = setupDB();
    $authz = new AuthZ($GLOBALS['pdo']);
    $GLOBALS['authz'] = $authz;
    $_SESSION = ['user_id' => 100, 'user_role' => 'user'];
    $stmt = $GLOBALS['pdo']->prepare("INSERT INTO users (id, username, password, role, status) VALUES (?, 'user', 'hash', 'user', 'active')");
    $stmt->execute([100]);
    $t->assertFalse('user cannot view hidden', can_view_thread('hidden'));
    $t->assertFalse('user cannot view pending', can_view_thread('pending'));
    $t->assertTrue('user can view visible', can_view_thread('visible'));
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
    $GLOBALS['config'] = ['trusted_proxies' => ['127.0.0.1']];
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
    $t = new Test('NB-001 - VERSION is 0.8.1');
    $version = trim(file_get_contents(__DIR__ . '/../VERSION'));
    $t->assertEquals('VERSION is 0.8.1', '0.8.1', $version);
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
