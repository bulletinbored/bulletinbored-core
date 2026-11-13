<?php

/**
 * ApiInstallAuthorizationTest — api/install.php must authorize against the
 * database, not against the role cached in the session.
 *
 * The endpoint runs outside src/bootstrap.php, so before this change a session
 * created while the user was an admin kept working after the account had been
 * demoted, deleted or banned. These tests boot the real endpoint in a
 * subprocess with a real PHP session file and a real (SQLite) database.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/AdminAuth.php';

function api_install_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        status TEXT NOT NULL DEFAULT 'active',
        session_version INTEGER DEFAULT 1
    )");
    return $pdo;
}

function api_install_add_user(PDO $pdo, string $username, string $role, string $status = 'active'): int
{
    $pdo->prepare("INSERT INTO users (username, role, status, session_version) VALUES (?, ?, ?, 1)")
        ->execute([$username, $role, $status]);
    return (int)$pdo->lastInsertId();
}

/**
 * Build a throw-away installation root that mirrors the real one just enough
 * for api/install.php: config.json, data/sessions, and symlinks to lib/ and src/.
 */
function api_install_fixture(string $dbPath): string
{
    $root = sys_get_temp_dir() . '/bb_api_install_' . uniqid('', true);
    @mkdir($root . '/data/sessions', 0755, true);
    @mkdir($root . '/api', 0755, true);
    @symlink(realpath(__DIR__ . '/../lib'), $root . '/lib');
    @symlink(realpath(__DIR__ . '/../src'), $root . '/src');
    copy(__DIR__ . '/../api/install.php', $root . '/api/install.php');
    file_put_contents($root . '/config.json', json_encode([
        'db_driver' => 'sqlite',
        'db_path' => $dbPath,
        'base_url' => 'http://localhost',
        'mail_method' => 'mail',
        'mail_from' => 'noreply@example.com',
        'site_name' => 'Test Forum',
    ], JSON_PRETTY_PRINT));
    return $root;
}

function api_install_delete_fixture(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}

function api_install_session_data(array $data): string
{
    $out = '';
    foreach ($data as $key => $value) {
        // The "php" session serializer stores "key|<serialized>;" and
        // serialize() already ends scalars with ';'.
        $out .= $key . '|' . rtrim(serialize($value), ';') . ';';
    }
    return $out;
}

/**
 * Run api/install.php in a subprocess with the given session contents.
 * Returns the decoded JSON response (or null when nothing was printed).
 */
function api_install_call(string $root, array $session): ?array
{
    if (!function_exists('proc_open')) {
        return null;
    }

    $sid = bin2hex(random_bytes(16));
    $script = <<<'PHP'
<?php
$root = $argv[1];
$sid = $argv[2];
$sessionData = $argv[3];
$target = $root . '/data/sessions/sess_' . $sid;
file_put_contents($target, $sessionData);
$_COOKIE['BBSESSID'] = $sid;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/install.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
chdir($root);
include $root . '/api/install.php';
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'bb_api_');
    $tmpFile = $tmp . '.php';
    @rename($tmp, $tmpFile);
    file_put_contents($tmpFile, $script);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpFile)
        . ' ' . escapeshellarg($root)
        . ' ' . escapeshellarg($sid)
        . ' ' . escapeshellarg(api_install_session_data($session));

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, sys_get_temp_dir());
    if (!is_resource($proc)) {
        @unlink($tmpFile);
        return null;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmpFile);
    @unlink($root . '/data/sessions/sess_' . $sid);

    $decoded = json_decode(trim((string)$stdout), true);
    if (!is_array($decoded)) {
        error_log('api_install_call stderr: ' . trim((string)$stderr) . ' stdout: ' . trim((string)$stdout));
        return null;
    }
    return $decoded;
}

function test_admin_auth_verify_session_matrix(): Test
{
    $t = new Test('AdminAuth - database-backed session matrix');

    $pdo = api_install_pdo();
    $adminId = api_install_add_user($pdo, 'admin', 'admin');
    $userId = api_install_add_user($pdo, 'member', 'user');
    $bannedId = api_install_add_user($pdo, 'banned', 'admin', 'banned');
    $suspendedId = api_install_add_user($pdo, 'suspended', 'admin', 'suspended');

    $t->assertNull('No session user id', AdminAuth::verifySession($pdo, []));
    $t->assertNull('Missing session_version', AdminAuth::verifySession($pdo, ['user_id' => $adminId]));

    $valid = AdminAuth::verifySession($pdo, ['user_id' => $adminId, 'session_version' => 1, 'user_role' => 'admin']);
    $t->assertNotNull('Live admin accepted', $valid);
    $t->assertEquals('Returns the database identity', 'admin', $valid['role'] ?? '');

    $t->assertNull('Stale session_version rejected', AdminAuth::verifySession($pdo, ['user_id' => $adminId, 'session_version' => 0]));
    $t->assertNull('Deleted account rejected', AdminAuth::verifySession($pdo, ['user_id' => 999999, 'session_version' => 1]));
    $t->assertNull('Non-admin rejected even with admin role in session', AdminAuth::verifySession($pdo, [
        'user_id' => $userId, 'session_version' => 1, 'user_role' => 'admin',
    ]));
    $t->assertNull('Banned admin rejected', AdminAuth::verifySession($pdo, ['user_id' => $bannedId, 'session_version' => 1]));
    $t->assertNull('Suspended admin rejected', AdminAuth::verifySession($pdo, ['user_id' => $suspendedId, 'session_version' => 1]));

    return $t;
}

function test_admin_auth_ignores_session_role_after_demotion(): Test
{
    $t = new Test('AdminAuth - session role ignored after demotion');

    $pdo = api_install_pdo();
    $id = api_install_add_user($pdo, 'ex-admin', 'admin');

    $session = ['user_id' => $id, 'session_version' => 1, 'user_role' => 'admin'];
    $t->assertNotNull('Admin accepted before demotion', AdminAuth::verifySession($pdo, $session));

    // Demotion performed by the admin panel bumps session_version; even if it
    // did not, the role is read from the database.
    $pdo->prepare("UPDATE users SET role = 'user', session_version = session_version + 1 WHERE id = ?")->execute([$id]);
    $t->assertNull('Demoted admin rejected (version bumped)', AdminAuth::verifySession($pdo, $session));

    $pdo->prepare("UPDATE users SET role = 'user', session_version = 1 WHERE id = ?")->execute([$id]);
    $t->assertNull('Demoted admin rejected (same version, DB role wins)', AdminAuth::verifySession($pdo, $session));

    return $t;
}

function test_api_install_requires_live_admin(): Test
{
    $t = new Test('API install - authorization uses the database');

    $dbPath = sys_get_temp_dir() . '/bb_api_install_db_' . uniqid('', true) . '.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        status TEXT NOT NULL DEFAULT 'active',
        session_version INTEGER DEFAULT 1
    )");
    $adminId = api_install_add_user($pdo, 'admin', 'admin');
    $demotedId = api_install_add_user($pdo, 'demoted', 'user');
    $deletedId = api_install_add_user($pdo, 'deleted', 'admin');

    $root = api_install_fixture($dbPath);

    try {
        $anonymous = api_install_call($root, []);
        $t->assertEquals('Anonymous request denied', 'Admin required', $anonymous['message'] ?? null);

        $forged = api_install_call($root, ['user_role' => 'admin']);
        $t->assertEquals('Role in session without user id denied', 'Admin required', $forged['message'] ?? null);

        $stale = api_install_call($root, [
            'user_id' => $demotedId,
            'user_role' => 'admin',
            'session_version' => 1,
        ]);
        $t->assertEquals('Demoted admin session denied', 'Admin required', $stale['message'] ?? null);

        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$deletedId]);
        $gone = api_install_call($root, [
            'user_id' => $deletedId,
            'user_role' => 'admin',
            'session_version' => 1,
        ]);
        $t->assertEquals('Deleted admin session denied', 'Admin required', $gone['message'] ?? null);

        $liveAdmin = api_install_call($root, [
            'user_id' => $adminId,
            'user_role' => 'admin',
            'session_version' => 1,
        ]);
        $t->assertEquals('Real admin passes the gate', 'Invalid CSRF token', $liveAdmin['message'] ?? null);
    } finally {
        api_install_delete_fixture($root);
        @unlink($dbPath);
        unset($pdo);
    }

    return $t;
}

register_tests(
    'test_admin_auth_verify_session_matrix',
    'test_admin_auth_ignores_session_role_after_demotion',
    'test_api_install_requires_live_admin'
);
