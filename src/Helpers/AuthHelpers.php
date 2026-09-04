<?php

/**
 * Authentication and authorization helpers.
 */

function is_logged_in(): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if (!isset($_SESSION['session_version'])) {
        session_destroy();
        return false;
    }
    $pdo = App::getInstance()->pdo;
    $stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $dbVersion = $stmt->fetchColumn();
    if ($dbVersion !== false && (int)$dbVersion !== (int)$_SESSION['session_version']) {
        session_destroy();
        return false;
    }
    return true;
}
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

function user_has_permission(string $permission): bool
{
    if (is_admin()) return true;
    $userId = $_SESSION['user_id'] ?? 0;
    $authz = App::getInstance()->authz;
    if (isset($authz) && $userId > 0) {
        return $authz->can($userId, $permission);
    }
    $roleName = $_SESSION['user_role'] ?? 'user';
    $pdo = App::getInstance()->pdo;
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE name = ?");
        $stmt->execute([$roleName]);
        $perms = json_decode($stmt->fetchColumn() ?: '[]', true) ?: [];
        return in_array($permission, $perms, true);
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
