<?php

function handle_users_action(string $action, string $method): \Bulletin\Response|bool
{
    switch ($action) {
        case 'login':
            return handle_login($method);
        case 'register':
            return handle_register($method);
        case 'logout':
            return handle_logout();
        case 'verify_email':
            return handle_verify_email();
        case 'profile':
            return handle_profile();
        case 'edit_profile':
            return handle_edit_profile($method);
        case 'remove_avatar':
            return handle_remove_avatar($method);
        case 'forgot_password':
            return handle_forgot_password($method);
        case 'reset_password':
            return handle_reset_password($method);
        default:
            return false;
    }
}

function handle_login(string $method): \Bulletin\Response|bool
{
    global $pdo, $pluginManager;
    if (is_logged_in()) {
        return redirect(url('home'));
    }

    $error = '';

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $error = 'CSRF token invalid';
            log_security_event('csrf_fail', ['action' => 'login']);
            include __DIR__ . '/../../views/login.php';
            return true;
        }

        $rlKey = rate_limit_client_ip() . '|' . ($_POST['username'] ?? '');
        if (!rate_limit('login', 5, 900, $rlKey)) {
            $error = 'Too many login attempts. Please try again later.';
            include __DIR__ . '/../../views/login.php';
            return true;
        }

        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (isset($pluginManager)) {
            $user = $pluginManager->filter('auth_before_verify', $user ?: null, $username, $password);
        }

        if ($user && password_verify($password, $user['password'])) {
            if (isset($pluginManager) && $pluginManager->checkHook('auth_login_block', $user)) {
                $error = 'login_blocked';
                log_security_event('login_blocked_by_plugin', ['username' => $username]);
                include __DIR__ . '/../../views/login.php';
                return true;
            }

            if ($user['status'] === 'banned') {
                $error = 'user_banned';
            } elseif ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() < $user['suspension_time']) {
                $error = 'user_suspended';
            } elseif (empty($user['email_verified'])) {
                $error = 'email_not_verified';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'] ?? '';
                $_SESSION['avatar'] = $user['avatar'] ?? '';
                $_SESSION['user_status'] = $user['status'];
                $_SESSION['user_suspension_time'] = $user['suspension_time'] ?? 0;
                session_regenerate_id(true);
                if ($user['status'] === 'suspended' && !empty($user['suspension_time']) && time() >= $user['suspension_time']) {
                    $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$user['id']]);
                    $_SESSION['user_status'] = 'active';
                    $_SESSION['user_suspension_time'] = 0;
                }

                if (isset($pluginManager)) {
                    $pluginManager->runHook('auth_after_login', $user['id'], $user);
                }

                session_write_close();
                return redirect(url('home'));
            }
        } else {
            $error = 'Invalid credentials';
            log_security_event('login_failed', ['username' => $username]);
            if (isset($pluginManager)) {
                $pluginManager->runHook('auth_login_failed', $username);
            }
        }
    }

    include __DIR__ . '/../../views/login.php';
    return true;
}

function handle_register(string $method): \Bulletin\Response|bool
{
    global $pdo, $config, $pluginManager;
    $error = '';

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $error = 'CSRF token invalid';
            include __DIR__ . '/../../views/register.php';
            return true;
        }

        if (!rate_limit('register', 5, 3600)) {
            $error = 'Too many registration attempts. Please try again later.';
            include __DIR__ . '/../../views/register.php';
            return true;
        }

        $username = validate_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username and password are required';
            include __DIR__ . '/../../views/register.php';
            return true;
        }

        $pwErrors = validate_password_strength($password);
        if (!empty($pwErrors)) {
            $error = t($pwErrors[0]);
            include __DIR__ . '/../../views/register.php';
            return true;
        }

        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $existsStmt->execute([$username]);
        $exists = $existsStmt->fetchColumn();
        if ($exists > 0) {
            $error = 'Username already taken';
            include __DIR__ . '/../../views/register.php';
            return true;
        }

        $email = validate_input($_POST['email'] ?? '');

        $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified) VALUES (?, ?, ?, 'user', 0)")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);

        $userId = $pdo->lastInsertId();
        $pluginManager->runHook('user_registered', $userId, $username);

        if (!empty($email)) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
                ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), hash('sha256', $token), $expires]);

            $verifyLink = url('verify_email', ['token' => $token], true);
            $subject = 'Confirm your email';
            $body = '<p>Hello '.escape($username).',</p>
                    <p>Thank you for registering! Please click the button below to confirm your email address:</p>
                    <p style="text-align:center;"><a class="btn" href="'.$verifyLink.'">Verify Email</a></p>
                    <p>Or copy this link: <br><code>'.$verifyLink.'</code></p>
                    <p>This link expires in 24 hours.</p>';
            send_email($email, $subject, $body);
        }

        notify_admin_new_user($username, $email);

        return redirect(url('login', ['registered' => 1]));
    }

    include __DIR__ . '/../../views/register.php';
    return true;
}

function handle_logout(): \Bulletin\Response|bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return redirect(url('home'));
    }
    if (!csrf_validate_request()) {
        return redirect(url('home'));
    }
    session_regenerate_id(true);
    session_destroy();
    return redirect(url('home'));
}

function handle_verify_email(): \Bulletin\Response|bool
{
    global $pdo;

    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        $error = 'verify_email_invalid';
        include __DIR__ . '/../../views/verify_email.php';
        return true;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tokenHash]);
    $validToken = $stmt->fetch();

    if (!$validToken || !password_verify($token, $validToken['token'])) {
        $error = 'verify_email_invalid';
        include __DIR__ . '/../../views/verify_email.php';
        return true;
    }

    $consumeStmt = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE id = ? AND used = 0");
    $consumeStmt->execute([$validToken['id']]);
    if ($consumeStmt->rowCount() !== 1) {
        $error = 'verify_email_invalid';
        include __DIR__ . '/../../views/verify_email.php';
        return true;
    }

    $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$validToken['user_id']]);

    $success = 'verify_email_success';
    include __DIR__ . '/../../views/verify_email.php';
    return true;
}

function handle_profile(): \Bulletin\Response|bool
{
    global $pdo;

    $username = $_GET['user'] ?? '';
    $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $profileStmt->execute([$username]);
    $profileUser = $profileStmt->fetch();

    if (!$profileUser) {
        throw new \Bulletin\NotFoundException('User not found');
    }

    $userThreadsStmt = $pdo->prepare("
        SELECT t.*, u.username as author
        FROM threads t
        JOIN users u ON t.user_id = u.id
        WHERE t.user_id = ? AND t.status IN ('visible', 'sticky', 'locked')
        ORDER BY (t.status = 'sticky') DESC, t.created_at DESC
        LIMIT 20
    ");
    $userThreadsStmt->execute([$profileUser['id']]);
    $userThreads = $userThreadsStmt->fetchAll();

    $profileStats = ['threads' => 0, 'posts' => 0];
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE user_id = ? AND status IN ('visible','sticky','locked')");
        $s->execute([$profileUser['id']]);
        $profileStats['threads'] = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 'visible'");
        $s->execute([$profileUser['id']]);
        $profileStats['posts'] = (int)$s->fetchColumn();
    } catch (PDOException $e) {}

    include __DIR__ . '/../../views/profile.php';
    return true;
}

function handle_edit_profile(string $method): \Bulletin\Response|bool
{
    global $pdo, $config;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $_SESSION['profile_error'] = 'CSRF token invalid';
            return redirect(url('edit_profile'));
        }

        if (!empty($_FILES['avatar']['name'])) {
            $avatarDir = __DIR__ . '/../../uploads/avatars/';
            if (!is_dir($avatarDir)) {
                @mkdir($avatarDir, 0777, true);
            }

            if (empty($_FILES['avatar']['name']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['avatar_upload_error'] = 'No file uploaded or upload error occurred.';
                return redirect(url('edit_profile'));
            }

            $allowed = $config['avatar_allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = $config['avatar_max_size'] ?? 2*1024*1024;

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['avatar']['tmp_name']);

            if (!in_array($mime, $allowed)) {
                $_SESSION['avatar_upload_error'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.';
                return redirect(url('edit_profile'));
            }

            if ($_FILES['avatar']['size'] > $maxSize) {
                $_SESSION['avatar_upload_error'] = 'File is too large. Max 2MB.';
                return redirect(url('edit_profile'));
            }

            $ext = match($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'bin',
            };

            $safeName = 'avatar_'.$_SESSION['user_id'].'.'.$ext;
            $uploadPath = $avatarDir . $safeName;

            foreach (glob($avatarDir . 'avatar_'.$_SESSION['user_id'].'.*') as $oldAvatar) {
                @unlink($oldAvatar);
            }

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                try {
                    $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")
                        ->execute([$safeName, $_SESSION['user_id']]);
                    $_SESSION['avatar'] = $safeName;
                    $_SESSION['avatar_upload_success'] = 'Avatar uploaded successfully.';
                } catch (Exception $e) {
                    $_SESSION['avatar_upload_error'] = 'Database error: ' . $e->getMessage();
                    @unlink($uploadPath);
                }
            } else {
                $_SESSION['avatar_upload_error'] = 'Failed to move uploaded file. Check directory permissions.';
            }

            return redirect(url('edit_profile'));
        } else {
            $updates = [];
            $params = [];

            if (!empty($_POST['username'])) {
                $newUsername = validate_input($_POST['username']);
                $existingStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
                $existingStmt->execute([$newUsername, $_SESSION['user_id']]);
                $existing = $existingStmt->fetchColumn();
                if ($existing) {
                    $_SESSION['profile_error'] = 'Username already taken';
                    return redirect(url('edit_profile'));
                }
                $updates[] = "username = ?";
                $params[] = $newUsername;
            }

            if (isset($_POST['email'])) {
                $newEmail = validate_input($_POST['email']);
                $updates[] = "email = ?";
                $params[] = $newEmail;
            }

            if (!empty($_POST['password'])) {
                $pwErrors = validate_password_strength($_POST['password']);
                if (!empty($pwErrors)) {
                    $_SESSION['profile_error'] = t($pwErrors[0]);
                    return redirect(url('edit_profile'));
                }
                $updates[] = "password = ?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }

            if (!empty($updates)) {
                $params[] = $_SESSION['user_id'];
                $pdo->prepare("UPDATE users SET ".implode(', ', $updates)." WHERE id = ?")
                    ->execute($params);

                if (!empty($_POST['username'])) {
                    $_SESSION['username'] = validate_input($_POST['username']);
                }
            }

            return redirect(url('profile', ['user' => $_SESSION['username']]));
        }
    }

    include __DIR__ . '/../../views/edit_profile.php';
    return true;
}

function handle_remove_avatar(string $method): \Bulletin\Response|bool
{
    global $pdo;

    if (!is_logged_in()) {
        return redirect(url('login')) ?? true;
    }

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $_SESSION['avatar_upload_error'] = 'CSRF token invalid';
            return redirect(url('edit_profile'));
        }

        $avatarDir = __DIR__ . '/../../uploads/avatars/';
        foreach (glob($avatarDir . 'avatar_' . $_SESSION['user_id'] . '.*') as $oldAvatar) {
            @unlink($oldAvatar);
        }

        try {
            $pdo->prepare("UPDATE users SET avatar = '' WHERE id = ?")
                ->execute([$_SESSION['user_id']]);
            $_SESSION['avatar'] = '';
            $_SESSION['avatar_upload_success'] = t('avatar_removed');
        } catch (Exception $e) {
            $_SESSION['avatar_upload_error'] = 'Database error: ' . $e->getMessage();
        }
    }

    return redirect(url('edit_profile'));
}

function handle_forgot_password(string $method): \Bulletin\Response|bool
{
    global $pdo, $config;

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $error = 'CSRF token invalid';
            include __DIR__ . '/../../views/forgot_password.php';
            return true;
        }

        if (!rate_limit('forgot_password', 5, 3600)) {
            $error = 'Too many requests. Please try again later.';
            include __DIR__ . '/../../views/forgot_password.php';
            return true;
        }

        $email = validate_input($_POST['email'] ?? '');
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
                ->execute([$user['id'], password_hash($token, PASSWORD_DEFAULT), hash('sha256', $token), $expires]);

            $resetLink = url('reset_password', ['token' => $token], true);
            $subject = 'Password Reset Request';
            $body = '<p>Hello '.escape($user['username']).',</p>
                    <p>You requested a password reset. Click the button below to reset your password:</p>
                    <p style="text-align:center;"><a class="btn" href="'.$resetLink.'">Reset Password</a></p>
                    <p>Or copy this link: <br><code>'.$resetLink.'</code></p>
                    <p>This link expires in 1 hour.</p>
                    <p>If you did not request this, please ignore this email.</p>';
            send_email($email, $subject, $body);
        }

        $success = 'If an account with that email exists, a password reset link has been sent.';
    }

    include __DIR__ . '/../../views/forgot_password.php';
    return true;
}

function handle_reset_password(string $method): \Bulletin\Response|bool
{
    global $pdo;

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            $error = 'CSRF token invalid';
            include __DIR__ . '/../../views/reset_password.php';
            return true;
        }

        if (!rate_limit('reset_password', 10, 3600)) {
            $error = 'Too many attempts. Please try again later.';
            include __DIR__ . '/../../views/reset_password.php';
            return true;
        }

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
            include __DIR__ . '/../../views/reset_password.php';
            return true;
        }
        $pwErrors = validate_password_strength($password);
        if (!empty($pwErrors)) {
            $error = t($pwErrors[0]);
            include __DIR__ . '/../../views/reset_password.php';
            return true;
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP");
        $stmt->execute([$tokenHash]);
        $validToken = $stmt->fetch();

        if (!$validToken || !password_verify($token, $validToken['token'])) {
            $error = 'Invalid or expired reset token.';
            include __DIR__ . '/../../views/reset_password.php';
            return true;
        }

        $consumeStmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
        $consumeStmt->execute([$validToken['id']]);
        if ($consumeStmt->rowCount() !== 1) {
            $error = 'Invalid or expired reset token.';
            include __DIR__ . '/../../views/reset_password.php';
            return true;
        }

        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $validToken['user_id']]);

        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$validToken['user_id']]);
        $user = $userStmt->fetch();
        if ($user && !empty($user['email'])) {
            $subject = 'Password Reset Successful';
            $body = '<p>Hello '.escape($user['username']).',</p>
                    <p>Your password has been successfully reset.</p>
                    <p>If you did not make this change, please contact an administrator immediately.</p>';
            send_email($user['email'], $subject, $body);
        }

        return redirect(url('login'));
    }

    if (!isset($_GET['token'])) {
        throw new \Bulletin\NotFoundException('Page not found');
    }

    include __DIR__ . '/../../views/reset_password.php';
    return true;
}
