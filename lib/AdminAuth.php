<?php

/**
 * AdminAuth.php — database-backed verification of administrative sessions.
 *
 * Most of the application runs behind src/bootstrap.php, which resolves the
 * session identity once and then trusts it (any change of role, status or
 * password bumps users.session_version and therefore invalidates the session).
 *
 * Endpoints that do not go through bootstrap.php — most notably
 * api/install.php — have no such guarantee, so they must never authorize on the
 * role stored in $_SESSION. This class answers one question against the
 * database: "is the user behind this session currently an active admin?"
 *
 * Usage:
 *   $pdo = AdminAuth::connect($config);
 *   $admin = AdminAuth::verifySession($pdo, $_SESSION);
 *   if ($admin === null) { // 403 }
 */

require_once __DIR__ . '/BbPdo.php';

class AdminAuth
{
    /**
     * Build a database connection from the forum configuration.
     * Mirrors src/setup.php without running migrations.
     */
    public static function connect(array $config): PDO
    {
        $driver = strtolower((string)($config['db_driver'] ?? 'sqlite'));

        if ($driver === 'mysql') {
            $dsn = 'mysql:host=' . ($config['db_host'] ?? 'localhost')
                . ';dbname=' . ($config['db_name'] ?? '')
                . ';charset=utf8mb4';
            $pdo = new BbPdo($dsn, $config['db_user'] ?? null, $config['db_pass'] ?? null);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } else {
            $dbPath = $config['db_path'] ?? dirname(__DIR__) . '/data/database.sqlite';
            $pdo = new BbPdo('sqlite:' . $dbPath);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /**
     * Load the user row referenced by a session, or null when the session is
     * not backed by a live, currently-privileged account.
     *
     * A session is rejected when:
     *   - it has no user id or no session_version;
     *   - the user row no longer exists (deleted account);
     *   - the stored session_version is stale (role/status/password changed);
     *   - the current role is not 'admin';
     *   - the account is banned or suspended.
     */
    public static function verifySession(PDO $pdo, array $session): ?array
    {
        $userId = (int)($session['user_id'] ?? 0);
        if ($userId <= 0 || !isset($session['session_version'])) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id, username, role, status, session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }
        if ((int)$user['session_version'] !== (int)$session['session_version']) {
            return null;
        }
        if (($user['role'] ?? '') !== 'admin') {
            return null;
        }
        if (in_array(strtolower((string)($user['status'] ?? 'active')), ['banned', 'suspended'], true)) {
            return null;
        }

        return $user;
    }
}
