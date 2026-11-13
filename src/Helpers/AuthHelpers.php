<?php

/**
 * Authentication and authorization helpers.
 */

/**
 * Invalidate the current session.
 *
 * Session data is cleared before the session is destroyed so that a stale
 * identity cannot be reused and so callers holding $_SESSION in memory see an
 * empty array instead of revoked credentials.
 */
function session_invalidate(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function is_logged_in(): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if (!isset($_SESSION['session_version'])) {
        session_invalidate();
        return false;
    }

    $pdo = App::getInstance()->pdo;
    if (!$pdo instanceof PDO) {
        // No database handle: the identity cannot be confirmed, fail closed.
        session_invalidate();
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $dbVersion = $stmt->fetchColumn();
    } catch (\PDOException $e) {
        // A database error must never be reported as a valid session, but the
        // session is left intact so a transient failure does not log everyone
        // out permanently.
        error_log('is_logged_in: database error: ' . $e->getMessage());
        return false;
    }

    // false means the user row no longer exists (account deleted). That is as
    // invalid as a version mismatch: never keep a session alive for an identity
    // that is gone.
    if ($dbVersion === false || (int)$dbVersion !== (int)$_SESSION['session_version']) {
        session_invalidate();
        return false;
    }

    return true;
}

/**
 * Session-only role check.
 *
 * The role cached in the session is only trustworthy because changing a user's
 * role or status bumps users.session_version, which invalidates the session on
 * the next is_logged_in() call. Endpoints that skip bootstrap.php (such as
 * api/install.php) must verify the role against the database instead — see
 * AdminAuth::verifySession().
 */
function is_admin() { return ($_SESSION['user_role'] ?? '') === 'admin'; }

function validate_password_strength(string $password): array
{
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'password_too_short';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'password_no_lowercase';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'password_no_uppercase';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'password_no_number';
    }
    return $errors;
}

function can_view_thread(string $threadStatus): bool
{
    if (in_array($threadStatus, ['visible', 'sticky', 'locked'], true)) {
        return true;
    }
    if (!is_logged_in()) {
        return false;
    }
    $authz = App::getInstance()->authz;
    if (isset($authz) && $authz->can((int)($_SESSION['user_id'] ?? 0), 'threads.approve')) {
        return true;
    }
    return false;
}

function is_banned() { return ($_SESSION['user_status'] ?? '') === 'banned'; }

function is_suspended()
{
    $status = $_SESSION['user_status'] ?? '';
    $suspensionTime = $_SESSION['user_suspension_time'] ?? 0;
    if ($status !== 'suspended') return false;
    if ($suspensionTime > 0 && time() >= $suspensionTime) return false;
    return true;
}
