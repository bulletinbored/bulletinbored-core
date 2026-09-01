<?php

function handle_admin_roles_get(): \Bulletin\Response|bool
{
    include __DIR__ . '/../../../views/admin_roles.php';
    return true;
}

function handle_admin_roles_action_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $roleAction = $_POST['do'] ?? '';
    if ($roleAction === 'create') {
        $roleName = validate_input($_POST['role_name'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        if ($roleName !== '') {
            $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
                ->execute([$roleName, json_encode($permissions)]);
            log_admin_action('role_create', ['role' => $roleName]);
        }
    } elseif ($roleAction === 'update') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $permissions = $_POST['permissions'] ?? [];
        if ($roleId > 0) {
            $pdo->prepare("UPDATE roles SET permissions = ? WHERE id = ?")
                ->execute([json_encode($permissions), $roleId]);
            log_admin_action('role_update', ['role_id' => $roleId]);
        }
    } elseif ($roleAction === 'delete') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($roleId > 0) {
            $pdo->prepare("DELETE FROM roles WHERE id = ? AND name <> 'admin'")->execute([$roleId]);
            log_admin_action('role_delete', ['role_id' => $roleId]);
        }
    }
    return redirect(url('admin_roles'));
}

function handle_admin_users_get(): \Bulletin\Response|bool
{
    include __DIR__ . '/../../../views/admin_users.php';
    return true;
}

function handle_admin_user_edit(string $method): \Bulletin\Response|bool
{
    global $pdo;

    $editUserId = (int)($_GET['id'] ?? 0);
    if ($editUserId <= 0) {
        return redirect(url('admin_users'));
    }
    $editUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $editUser->execute([$editUserId]);
    $editUser = $editUser->fetch();
    if (!$editUser) {
        return redirect(url('admin_users'));
    }
    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }
        $newUsername = trim($_POST['username'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $newRole = $_POST['role'] ?? 'user';
        $newStatus = $_POST['status'] ?? 'active';
        if ($newUsername !== '') {
            $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ?, status = ? WHERE id = ?")
                ->execute([$newUsername, $newEmail, $newRole, $newStatus, $editUserId]);
            log_admin_action('user_update', ['target_id' => $editUserId, 'username' => $newUsername, 'role' => $newRole, 'status' => $newStatus]);
        }
        return redirect(url('admin_user_edit', ['id' => $editUserId]));
    }
    include __DIR__ . '/../../../views/admin_user_edit.php';
    return true;
}

function handle_admin_create_user_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }

    $username = validate_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = validate_input($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $status = $_POST['status'] ?? 'active';
    $emailVerified = isset($_POST['email_verified']) ? 1 : 0;

    if ($username === '' || $password === '') {
        throw new \Bulletin\ValidationException(['input' => 'Username and password are required']);
    }

    $pwErrors = validate_password_strength($password);
    if (!empty($pwErrors)) {
        throw new \Bulletin\ValidationException(['password' => t($pwErrors[0])]);
    }

    $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $existsStmt->execute([$username]);
    $exists = $existsStmt->fetchColumn();
    if ($exists > 0) {
        throw new \Bulletin\ConflictException('Username already taken');
    }

    if ($email === '') {
        $emailVerified = 1;
    }

    $pdo->prepare("INSERT INTO users (username, password, email, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, $role, $status, $emailVerified]);

    $userId = $pdo->lastInsertId();
    log_admin_action('user_create', ['new_user_id' => $userId, 'username' => $username, 'role' => $role]);

    if (!empty($email) && empty($emailVerified)) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $pdo->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)")
            ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $expires]);

        $verifyLink = url('verify_email', ['token' => $token], true);
        $subject = 'Confirm your email';
        $body = '<p>Hello '.escape($username).',</p>
                <p>Your account has been created. Please click the button below to confirm your email address:</p>
                <p style="text-align:center;"><a class="btn" href="'.$verifyLink.'">Verify Email</a></p>
                <p>Or copy this link: <br><code>'.$verifyLink.'</code></p>
                <p>This link expires in 24 hours.</p>';
        send_email($email, $subject, $body);
    }

    notify_admin_new_user($username, $email);

    return redirect(url('admin_users'));
}

function handle_delete_user_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($userId > 0) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'");
        $stmt->execute([$userId]);
        if ($stmt->rowCount() === 0) {
            $_SESSION['admin_error'] = t('user_not_deleted') ?? 'User could not be deleted (does not exist or is an admin).';
        } else {
            log_admin_action('user_delete', ['target_id' => $userId]);
        }
    } else {
        $_SESSION['admin_error'] = t('invalid_user_id') ?? 'Invalid user id.';
    }
    return redirect(url('admin_users'));
}

function handle_unban_user_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$userId]);
        log_admin_action('user_unban', ['target_id' => $userId]);
    }
    return redirect(url('admin_users'));
}

function handle_ban_user_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role <> 'admin'")->execute([$userId]);
        log_admin_action('user_ban', ['target_id' => $userId]);
    }
    return redirect(url('admin_users'));
}

function handle_suspend_user_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $days = max(1, (int)($_POST['days'] ?? 1));
    $suspensionTime = time() + ($days * 86400);
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET status = 'suspended', suspension_time = ? WHERE id = ?")
            ->execute([$suspensionTime, $userId]);
        log_admin_action('user_suspend', ['target_id' => $userId, 'days' => $days]);
    }
    return redirect(url('admin_users'));
}
