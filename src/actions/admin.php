<?php

function handle_admin_action(string $action, string $method): bool
{
    // Only handle admin-prefixed actions and a few legacy moderation actions.
    // Return false for anything else so the router can try the next handler.
    $adminActions = [
        'admin', 'admin_settings', 'admin_moderation', 'admin_roles', 'admin_roles_action',
        'admin_users', 'admin_user_edit', 'admin_create_user',
        'admin_categories', 'delete_category', 'update_category_order',
        'admin_langs', 'admin_diagnostics', 'admin_plugins', 'admin_themes',
        'admin_catalog', 'admin_updates',
        'moderate', 'frontend_moderate', 'split_thread', 'merge_thread',
        'delete_user', 'ban_user', 'unban_user', 'suspend_user',
    ];
    if (!in_array($action, $adminActions, true)) {
        return false;
    }

    if (!is_admin()) {
        die('Admin required');
    }

    switch ($action) {
        case 'admin':
            return handle_admin_dashboard();
        case 'admin_settings':
            return $method === 'POST' ? handle_admin_settings_post() : handle_admin_settings_get();
        case 'admin_moderation':
            return handle_admin_moderation_get();
        case 'moderate':
            return $method === 'POST' ? handle_moderate_post() : false;
        case 'frontend_moderate':
            return $method === 'POST' ? handle_frontend_moderate_post() : false;
        case 'split_thread':
            return $method === 'POST' ? handle_split_thread_post() : false;
        case 'merge_thread':
            return $method === 'POST' ? handle_merge_thread_post() : false;
        case 'admin_roles':
            return handle_admin_roles_get();
        case 'admin_roles_action':
            return $method === 'POST' ? handle_admin_roles_action_post() : false;
        case 'admin_users':
            return handle_admin_users_get();
        case 'admin_user_edit':
            return handle_admin_user_edit($method);
        case 'admin_create_user':
            return $method === 'POST' ? handle_admin_create_user_post() : false;
        case 'admin_categories':
            return handle_admin_categories($method);
        case 'delete_category':
            return $method === 'POST' ? handle_delete_category_post() : false;
        case 'update_category_order':
            return $method === 'POST' ? handle_update_category_order_post() : false;
        case 'admin_langs':
            return handle_admin_langs($method);
        case 'admin_diagnostics':
            return handle_admin_diagnostics_get();
        case 'admin_plugins':
            return handle_admin_plugins($method);
        case 'admin_themes':
            return handle_admin_themes($method);
        case 'admin_catalog':
            return handle_admin_catalog($method);
        case 'admin_updates':
            return handle_admin_updates($method);
        case 'delete_user':
            return $method === 'POST' ? handle_delete_user_post() : false;
        case 'ban_user':
            return $method === 'POST' ? handle_ban_user_post() : false;
        case 'unban_user':
            return $method === 'POST' ? handle_unban_user_post() : false;
        case 'suspend_user':
            return $method === 'POST' ? handle_suspend_user_post() : false;
        default:
            return false;
    }
}

function handle_admin_dashboard(): bool
{
    global $pdo, $config;

    $pendingStmt = $pdo->prepare("
        SELECT t.*, u.username as author
        FROM threads t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.status = 'pending'
        ORDER BY t.created_at DESC
    ");
    $pendingStmt->execute();
    $pendingThreads = $pendingStmt->fetchAll();

    $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();

    $adminError = '';
    $adminSuccess = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        if (!csrf_validate_request()) {
            $adminError = 'Invalid CSRF token';
        } else {
            $siteName = trim($_POST['site_name'] ?? $config['site_name']);
            $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
            $availableLangs = array_filter(array_map('trim', explode(',', $_POST['available_langs'] ?? implode(',', $config['available_langs'] ?? ['en']))));

            $config['site_name'] = $siteName;
            $config['default_lang'] = $defaultLang;
            $config['available_langs'] = array_values($availableLangs);

            if (file_put_contents(__DIR__ . '/../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
                $adminSuccess = 'Settings saved successfully';
            } else {
                $adminError = 'Failed to save settings';
            }
        }

        redirect(url('admin_settings'));
    }

    include __DIR__ . '/../../views/admin.php';
    return true;
}

function handle_admin_settings_post(): ?string
{
    global $config;

    if (!csrf_validate_request()) {
        return 'CSRF token invalid';
    }

    if (isset($_POST['send_test_email'])) {
        $testEmail = trim($_POST['test_email_address'] ?? '');
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['email_test_error'] = t('invalid_email_address');
        } else {
            $subject = 'Test email from ' . ($config['site_name'] ?? 'bulletinbored');
            $body = '<p>This is a test email sent from your forum\'s admin panel.</p>'
                  . '<p>If you received this, email sending is configured correctly.</p>';
            $sent = send_email($testEmail, $subject, $body);
            if ($sent) {
                $_SESSION['email_test_success'] = $testEmail;
            } else {
                $err = error_get_last();
                $_SESSION['email_test_error'] = $err['message'] ?? 'Unknown error';
            }
        }
        redirect(url('admin_settings'));
    }

    $siteName = trim($_POST['site_name'] ?? $config['site_name']);
    $siteTagline = trim($_POST['site_tagline'] ?? $config['site_tagline']);
    $siteIcon = trim($_POST['site_icon'] ?? $config['site_icon']);
    $timezone = trim($_POST['timezone'] ?? $config['timezone']);
    $dateFormat = trim($_POST['date_format'] ?? $config['date_format']);
    $timeFormat = trim($_POST['time_format'] ?? $config['time_format']);
    $mailFrom = trim($_POST['mail_from'] ?? $config['mail_from'] ?? '');
    $mailFromName = trim($_POST['mail_from_name'] ?? $config['mail_from_name'] ?? '');
    $notifyAdminEmail = trim($_POST['notify_admin_email'] ?? $config['notify_admin_email'] ?? '');
    $attachmentsEnabled = !empty($_POST['attachments_enabled']) ? 1 : 0;
    $allowCatalogOnly = !empty($_POST['allow_catalog_only']) ? 1 : 0;
    $pluginVerifyFiles = !empty($_POST['plugin_verify_files']) ? 1 : 0;

    $config['site_name'] = $siteName;
    $config['site_tagline'] = $siteTagline;
    $config['site_icon'] = $siteIcon;
    $config['timezone'] = $timezone;
    $config['date_format'] = $dateFormat;
    $config['time_format'] = $timeFormat;
    $config['mail_from'] = $mailFrom;
    $config['mail_from_name'] = $mailFromName;
    $config['notify_admin_email'] = $notifyAdminEmail;
    $config['attachments_enabled'] = $attachmentsEnabled;
    $config['allow_catalog_only'] = $allowCatalogOnly;
    $config['plugin_verify_files'] = $pluginVerifyFiles;

    file_put_contents(__DIR__ . '/../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $_SESSION['settings_saved'] = true;
    redirect(url('admin_settings'));

    return null;
}

function handle_admin_settings_get(): bool
{
    include __DIR__ . '/../../views/admin_settings.php';
    return true;
}

function handle_admin_moderation_get(): bool
{
    include __DIR__ . '/../../views/admin_moderation.php';
    return true;
}

function handle_moderate_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }

    $threadId = (int)($_POST['id'] ?? 0);
    $action = $_POST['do'] ?? '';

    if ($threadId <= 0) {
        die('Invalid thread ID');
    }

    if ($action === 'approve') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_approve', ['thread_id' => $threadId]);
    } elseif ($action === 'delete') {
        $catIdStmt = $pdo->prepare("SELECT category_id FROM threads WHERE id = ?");
        $catIdStmt->execute([$threadId]);
        $catId = (int)($catIdStmt->fetchColumn() ?: 0);
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_delete', ['thread_id' => $threadId, 'category_id' => $catId]);
        if ($catId > 0) {
            redirect(url('category', ['id' => $catId]));
        }
        redirect(url('home'));
    }

    redirect(url('admin_moderation'));
    return true;
}

function handle_frontend_moderate_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $threadId = (int)($_POST['id'] ?? 0);
    $modAction = $_POST['do'] ?? '';
    if ($threadId <= 0) {
        die('Invalid thread ID');
    }
    $userRole = $_SESSION['user_role'] ?? 'user';
    if ($userRole !== 'admin' && $userRole !== 'moderator') {
        die('Not authorized');
    }
    if ($modAction === 'lock') {
        $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_lock', ['thread_id' => $threadId]);
    } elseif ($modAction === 'unlock') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_unlock', ['thread_id' => $threadId]);
    } elseif ($modAction === 'sticky') {
        $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_sticky', ['thread_id' => $threadId]);
    } elseif ($modAction === 'unsticky') {
        $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_unsticky', ['thread_id' => $threadId]);
    } elseif ($modAction === 'hide') {
        $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_hide', ['thread_id' => $threadId]);
    } elseif ($modAction === 'delete') {
        $catIdStmt = $pdo->prepare("SELECT category_id FROM threads WHERE id = ?");
        $catIdStmt->execute([$threadId]);
        $catId = (int)($catIdStmt->fetchColumn() ?: 0);
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        log_admin_action('thread_delete', ['thread_id' => $threadId, 'category_id' => $catId]);
        if ($catId > 0) {
            redirect(url('category', ['id' => $catId]));
        }
        redirect(url('home'));
    } elseif ($modAction === 'move') {
        $targetCat = (int)($_POST['category_id'] ?? 0);
        if ($targetCat > 0) {
            $pdo->prepare("UPDATE threads SET category_id = ? WHERE id = ?")->execute([$targetCat, $threadId]);
            log_admin_action('thread_move', ['thread_id' => $threadId, 'category_id' => $targetCat]);
        }
    } elseif ($modAction === 'copy') {
        $targetCat = (int)($_POST['category_id'] ?? 0);
        if ($targetCat > 0) {
            $src = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
            $src->execute([$threadId]);
            $srcThread = $src->fetch();
            if ($srcThread) {
                $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
                $ins->execute([$targetCat, $srcThread['user_id'], $srcThread['title'], $srcThread['content'], $srcThread['created_at']]);
                $newThreadId = (int)$pdo->lastInsertId();
                $postsStmt = $pdo->prepare("SELECT * FROM posts WHERE thread_id = ? AND status = 'visible'");
                $postsStmt->execute([$threadId]);
                $posts = $postsStmt->fetchAll();
                $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) VALUES (?, ?, ?, 'visible', ?)");
                foreach ($posts as $post) {
                    $postIns->execute([$newThreadId, $post['user_id'], $post['content'], $post['created_at']]);
                }
                log_admin_action('thread_copy', ['thread_id' => $threadId, 'new_thread_id' => $newThreadId, 'category_id' => $targetCat]);
            }
        }
    }
    $threadTitleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
    $threadTitleStmt->execute([$threadId]);
    $threadTitle = $threadTitleStmt->fetchColumn();
    redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle ?? '')]));
    return true;
}

function handle_split_thread_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $threadId = (int)($_POST['thread_id'] ?? 0);
    $postIds = $_POST['post_ids'] ?? '';
    $newTitle = trim($_POST['new_title'] ?? '');
    if (!is_array($postIds)) {
        $postIds = array_filter(array_map('trim', explode(',', (string)$postIds)));
    }
    if ($threadId <= 0 || empty($postIds) || $newTitle === '') {
        die('Invalid input');
    }
    $userRole = $_SESSION['user_role'] ?? 'user';
    if ($userRole !== 'admin' && $userRole !== 'moderator') {
        die('Not authorized');
    }
    $srcThreadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
    $srcThreadStmt->execute([$threadId]);
    $srcThread = $srcThreadStmt->fetch();
    if (!$srcThread) {
        die('Thread not found');
    }

    $intIds = array_map('intval', $postIds);
    $placeholders = implode(',', array_fill(0, count($intIds), '?'));
    // Only move posts that actually belong to the source thread (prevents a
    // moderator from pulling posts from other threads into the new one).
    $selStmt = $pdo->prepare("SELECT id, content, user_id, created_at FROM posts WHERE thread_id = ? AND id IN ($placeholders) ORDER BY created_at ASC, id ASC");
    $selStmt->execute(array_merge([$threadId], $intIds));
    $selPosts = $selStmt->fetchAll();
    if (empty($selPosts)) {
        die('No valid posts selected');
    }

    $firstPost = $selPosts[0];
    $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
    $ins->execute([$srcThread['category_id'], $firstPost['user_id'], $newTitle, $firstPost['content'], $firstPost['created_at']]);
    $newThreadId = (int)$pdo->lastInsertId();

    // The first selected post becomes the new thread's opening post (it is stored
    // as threads.content, exactly like the OP of any thread), so it must NOT also
    // be inserted into posts — otherwise it would appear twice. Only the
    // remaining selected posts become replies.
    $replyIds = array_slice($intIds, 1);
    if (!empty($replyIds)) {
        $replyPlaceholders = implode(',', array_fill(0, count($replyIds), '?'));
        $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) SELECT ?, user_id, content, status, created_at FROM posts WHERE thread_id = ? AND id IN ($replyPlaceholders)");
        $postIns->execute(array_merge([$newThreadId, $threadId], $replyIds));
    }

    $delSql = "DELETE FROM posts WHERE thread_id = ? AND id IN ($placeholders)";
    $pdo->prepare($delSql)->execute(array_merge([$threadId], $intIds));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'");
    $countStmt->execute([$threadId]);
    if (empty($countStmt->fetchColumn())) {
        $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
        redirect(url('home'));
    }
    redirect(url('thread', ['id' => $newThreadId, 'slug' => slugify($newTitle)]));
    return true;
}

function handle_merge_thread_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $threadId = (int)($_POST['thread_id'] ?? 0);
    $targetTitle = trim($_POST['target_title'] ?? '');
    if ($threadId <= 0 || $targetTitle === '') {
        die('Invalid input');
    }
    $userRole = $_SESSION['user_role'] ?? 'user';
    if ($userRole !== 'admin' && $userRole !== 'moderator') {
        die('Not authorized');
    }
    $targetThreadStmt = $pdo->prepare("SELECT * FROM threads WHERE title LIKE ? LIMIT 1");
    $targetThreadStmt->execute(["%$targetTitle%"]);
    $targetThread = $targetThreadStmt->fetch();
    if (!$targetThread) {
        die('Target thread not found');
    }
    $targetThreadId = (int)$targetThread['id'];
    if ($threadId === $targetThreadId) {
        die('Cannot merge a thread into itself');
    }
    $pdo->prepare("UPDATE posts SET thread_id = ? WHERE thread_id = ?")->execute([$targetThreadId, $threadId]);
    $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
    redirect(url('thread', ['id' => $targetThreadId, 'slug' => slugify($targetThread['title'] ?? '')]));
    return true;
}

function handle_admin_roles_get(): bool
{
    include __DIR__ . '/../../views/admin_roles.php';
    return true;
}

function handle_admin_roles_action_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
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
    redirect(url('admin_roles'));
    return true;
}

function handle_admin_users_get(): bool
{
    include __DIR__ . '/../../views/admin_users.php';
    return true;
}

function handle_admin_user_edit(string $method): bool
{
    global $pdo;

    if (!is_admin()) {
        die('Admin required');
    }
    $editUserId = (int)($_GET['id'] ?? 0);
    if ($editUserId <= 0) {
        redirect(url('admin_users'));
    }
    $editUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $editUser->execute([$editUserId]);
    $editUser = $editUser->fetch();
    if (!$editUser) {
        redirect(url('admin_users'));
    }
    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            die('CSRF token invalid');
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
        redirect(url('admin_user_edit', ['id' => $editUserId]));
    }
    include __DIR__ . '/../../views/admin_user_edit.php';
    return true;
}

function handle_admin_create_user_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }

    $username = validate_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = validate_input($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $status = $_POST['status'] ?? 'active';
    $emailVerified = isset($_POST['email_verified']) ? 1 : 0;

    if ($username === '' || $password === '') {
        die('Username and password are required');
    }

    $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $existsStmt->execute([$username]);
    $exists = $existsStmt->fetchColumn();
    if ($exists > 0) {
        die('Username already taken');
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

    redirect(url('admin_users'));
    return true;
}

function handle_admin_categories(string $method): bool
{
    global $pdo;

    if (!is_admin()) {
        die('Admin required');
    }
    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            die('CSRF token invalid');
        }
        $allowedRoles = $_POST['allowed_roles'] ?? 'all';
        $allowedRoles = in_array($allowedRoles, ['all', 'admin', 'moderator'], true) ? $allowedRoles : 'all';
        if (isset($_GET['id'])) {
            $catId = (int)$_GET['id'];
            $name = validate_input($_POST['name'] ?? '');
            $description = validate_input($_POST['description'] ?? '');
            if ($catId > 0 && $name !== '') {
                $pdo->prepare("UPDATE categories SET name = ?, description = ?, allowed_roles = ? WHERE id = ?")->execute([$name, $description, $allowedRoles, $catId]);
                log_admin_action('category_update', ['category_id' => $catId, 'name' => $name]);
            }
        } else {
            $name = validate_input($_POST['name'] ?? '');
            $description = validate_input($_POST['description'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO categories (name, description, allowed_roles) VALUES (?, ?, ?)")->execute([$name, $description, $allowedRoles]);
                log_admin_action('category_create', ['name' => $name]);
            }
        }
        redirect(url('admin_categories'));
    }
    include __DIR__ . '/../../views/admin_categories.php';
    return true;
}

function handle_delete_category_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $catId = (int)($_GET['id'] ?? 0);
    if ($catId > 0) {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);
        log_admin_action('category_delete', ['category_id' => $catId]);
    }
    redirect(url('admin_categories'));
    return true;
}

function handle_update_category_order_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
        exit;
    }
    $orderRaw = $_POST['order'] ?? '';
    $order = is_string($orderRaw) ? json_decode($orderRaw, true) : $orderRaw;
    if (!is_array($order)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid order data']);
        exit;
    }
    $position = 1;
    $stmt = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ?");
    foreach ($order as $catId) {
        $catId = (int)$catId;
        if ($catId > 0) {
            $stmt->execute([$position, $catId]);
            $position++;
        }
    }
    echo json_encode(['success' => true]);
    exit;
    return true;
}

function handle_admin_langs(string $method): bool
{
    global $config, $pdo;

    if (!is_admin()) {
        die('Admin required');
    }

    $langMetaPath = __DIR__ . '/../../data/lang-meta.json';
    $langMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
    $langsJsonUrl = $langMirrorBase . '/langs.json';

    if (!function_exists('loadLangMeta')) {
        function loadLangMeta(string $path): array {
            if (!file_exists($path)) {
                return [];
            }
            $data = json_decode(file_get_contents($path), true);
            return is_array($data) ? $data : [];
        }
    }
    if (!function_exists('saveLangMeta')) {
        function saveLangMeta(string $code, string $sha): void {
            global $langMetaPath;
            $path = $langMetaPath ?: __DIR__ . '/../../data/lang-meta.json';
            $meta = loadLangMeta($path);
            $meta[$code] = ['sha' => $sha, 'updated' => date('c')];
            @file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    $langMeta = loadLangMeta($langMetaPath);
    $remoteLangsRaw = @file_get_contents($langsJsonUrl);
    $remoteLangs = is_string($remoteLangsRaw) ? json_decode($remoteLangsRaw, true) : null;
    if (!is_array($remoteLangs)) {
        $remoteLangs = [];
    }

    $langSuccess = $_SESSION['lang_success'] ?? '';
    $langError = $_SESSION['lang_error'] ?? '';
    unset($_SESSION['lang_success'], $_SESSION['lang_error']);
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $langError = 'Invalid CSRF token';
        } else {
            if (isset($_POST['save_lang_settings'])) {
                $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
                $config['default_lang'] = $defaultLang;
                $installedLangs = [];
                foreach (glob(__DIR__ . '/../../lang/*.json') as $file) {
                    $installedLangs[] = basename($file, '.json');
                }
                $config['available_langs'] = array_values(array_unique($installedLangs));
                file_put_contents(__DIR__ . '/../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $_SESSION['lang_success'] = 'Language settings saved';
                redirect(url('admin_langs'));
            } elseif (isset($_POST['upload_lang']) && !empty($_FILES['lang_file']['tmp_name'])) {
                $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                if ($langCode === '') {
                    $langError = 'Invalid language code';
                } else {
                    $dest = __DIR__ . '/../../lang/'.$langCode.'.json';
                    if (file_exists($dest)) {
                        $langError = 'Language file already exists: '.escape($langCode);
                    } else {
                        // Translation files are JSON only: never execute uploaded
                        // content. Reject anything that is not a valid string=>string
                        // JSON array so a PHP upload cannot lead to RCE.
                        $raw = file_get_contents($_FILES['lang_file']['tmp_name']);
                        if ($raw === false) {
                            $langError = 'Failed to read uploaded file';
                        } else {
                            $decoded = json_decode($raw, true);
                            $valid = is_array($decoded);
                            if ($valid) {
                                foreach ($decoded as $k => $v) {
                                    if (!is_string($k) || !is_string($v)) {
                                        $valid = false;
                                        break;
                                    }
                                }
                            }
                            if (!$valid) {
                                $langError = 'Language file must be a JSON array of "key": "translation" strings';
                            } elseif (move_uploaded_file($_FILES['lang_file']['tmp_name'], $dest)) {
                                $_SESSION['lang_success'] = 'Language file uploaded: '.escape($langCode);
                                redirect(url('admin_langs'));
                            } else {
                                $langError = 'Failed to upload language file';
                            }
                        }
                    }
                }
            } elseif (isset($_POST['install_github_lang']) || isset($_POST['update_github_lang'])) {
                $isUpdate = isset($_POST['update_github_lang']);
                $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                $downloadUrl = trim($_POST['download_url'] ?? '');
                $remoteSha = $_POST['remote_sha'] ?? '';
                if ($langCode === '' || $downloadUrl === '') {
                    $langError = 'Invalid language code or download URL';
                } else {
                    ob_start();
                    $prevDisplayErrors = ini_get('display_errors');
                    @ini_set('display_errors', '0');
                    $parsed = parse_url($downloadUrl);
                    $allowed = false;
                    if (
                        $parsed
                        && ($parsed['scheme'] ?? '') === 'https'
                        && !str_contains($parsed['path'], '..')
                    ) {
                        // Official GitHub sources
                        if (
                            in_array($parsed['host'], ['github.com', 'raw.githubusercontent.com'], true)
                            && str_starts_with($parsed['path'], '/bulletinbored/langs/')
                        ) {
                            $allowed = true;
                        }
                        // Configured mirror (default: extend.bulletinbored.net)
                        $mirrorHost = parse_url($langMirrorBase, PHP_URL_HOST);
                        if ($mirrorHost && ($parsed['host'] ?? '') === $mirrorHost) {
                            $allowed = true;
                        }
                    }
                    if (!$allowed) {
                        $langError = 'Invalid download URL. Only URLs from the official language repository are allowed.';
                    } else {
                        $dest = __DIR__ . '/../../lang/'.$langCode.'.json';
                        if ($isUpdate && !file_exists($dest)) {
                            $langError = 'Language file not found: '.escape($langCode);
                        } elseif (!$isUpdate && file_exists($dest)) {
                            $langError = 'Language file already exists: '.escape($langCode);
                        } else {
                            // Prefer the JSON file from the official repo (no code
                            // execution at all). Fall back to the legacy PHP file
                            // ("return [...]") only from the allow-listed official
                            // host (supply-chain trust); it is evaluated once and
                            // stored as JSON so it is never executed on this server.
                            $candidateUrls = [$downloadUrl];
                            if (preg_match('#\.php$#i', $downloadUrl)) {
                                $candidateUrls[] = substr($downloadUrl, 0, -4) . '.json';
                            } else {
                                $candidateUrls[] = substr($downloadUrl, 0, -5) . '.php';
                            }

                            $data = null;
                            foreach ($candidateUrls as $tryUrl) {
                                $content = @file_get_contents($tryUrl);
                                if ($content === false) {
                                    continue;
                                }
                                if (str_ends_with($tryUrl, '.json')) {
                                    $decoded = json_decode($content, true);
                                    if (is_array($decoded)) {
                                        $data = $decoded;
                                        break;
                                    }
                                } else {
                                    $decoded = @eval('?>' . $content);
                                    if (is_array($decoded)) {
                                        $data = $decoded;
                                        break;
                                    }
                                }
                            }

                            if ($data === null) {
                                    $langError = 'Invalid language file from repository';
                                } elseif (!is_writable(dirname($dest))) {
                                    $langError = 'Language directory is not writable. Please check permissions.';
                                } else {
                                    $written = @file_put_contents($dest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                    if ($written === false) {
                                        $langError = 'Failed to save language file';
                                    } else {
                                        saveLangMeta($langCode, $remoteSha);
                                        $_SESSION['lang_success'] = ($isUpdate ? 'Language file updated: ' : 'Language file installed: ') . escape($langCode);
                                        ob_end_clean();
                                        @ini_set('display_errors', $prevDisplayErrors);
                                        redirect(url('admin_langs'));
                                    }
                                }
                        }
                    }
                }
                @ini_set('display_errors', $prevDisplayErrors);
                ob_end_clean();
            } elseif (isset($_POST['delete_lang'])) {
                $langCode = $_POST['lang_code'] ?? '';
                $langCode = preg_replace('/[^a-z_]/', '', strtolower($langCode));
                $dest = __DIR__ . '/../../lang/'.$langCode.'.json';
                if ($langCode === $config['default_lang']) {
                    $langError = 'Cannot delete the default language';
                } elseif (file_exists($dest)) {
                    @unlink($dest);
                    $_SESSION['lang_success'] = 'Language file deleted: '.escape($langCode);
                    redirect(url('admin_langs'));
                } else {
                    $langError = 'Language file not found';
                }
            }
        }
    }

    $langFiles = glob(__DIR__ . '/../../lang/*.json');
    $langOptions = [];
    foreach ($langFiles as $file) {
        $code = basename($file, '.json');
        $langOptions[] = $code;
    }
    include __DIR__ . '/../../views/admin_langs.php';
    return true;
}

function handle_admin_diagnostics_get(): bool
{
    global $config;

    $diag = [];

    $diag['php_version'] = PHP_VERSION;

    $diag['zip'] = extension_loaded('zip');
    $diag['curl'] = extension_loaded('curl');
    $diag['allow_url_fopen'] = (bool) ini_get('allow_url_fopen');
    $diag['exec'] = function_exists('exec');
    $diag['git'] = false;
    if (function_exists('exec')) {
        $out = @shell_exec('git --version 2>/dev/null');
        $diag['git'] = !empty($out);
    }

    $githubOk = false;
    $githubError = '';
    $testUrl = 'https://github.com/bulletinbored/editbored-plugin/archive/refs/heads/main.zip';
    if (extension_loaded('curl')) {
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ForumDiagnostics/1.0)',
        ]);
        $githubOk = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 500;
        if (!$githubOk) {
            $githubError = curl_error($ch) ?: ('HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
        }
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'method' => 'HEAD'], 'https' => ['timeout' => 15, 'method' => 'HEAD']]);
        $headers = @get_headers($testUrl, 0, $ctx);
        if ($headers && !str_contains(implode("\n", $headers), ' 5')) {
            $githubOk = true;
        } else {
            $githubError = 'Unable to reach GitHub via file_get_contents';
        }
    } else {
        $githubError = 'No outbound HTTP transport available (need curl or allow_url_fopen)';
    }
    $diag['github_reachable'] = $githubOk;
    $diag['github_error'] = $githubError;

    $diag['can_install'] = $diag['zip'] && ($diag['curl'] || $diag['allow_url_fopen']) && $githubOk;

    $recommendations = [];
    if (!$diag['zip']) {
        $recommendations[] = 'Enable the PHP <code>zip</code> extension so packages can be extracted.';
    }
    if (!$diag['curl'] && !$diag['allow_url_fopen']) {
        $recommendations[] = 'Enable <code>curl</code> or set <code>allow_url_fopen = On</code> so the server can download packages.';
    }
    if (!$githubOk) {
        $recommendations[] = 'The server cannot reach GitHub (' . escape($githubError) . '). Outbound HTTPS is required for one-click install.';
    }
    if ($diag['git']) {
        $recommendations[] = 'Git is available — installs will use it directly.';
    } elseif ($diag['can_install']) {
        $recommendations[] = t('all_requirements_met');
    }

    include __DIR__ . '/../../views/admin_diagnostics.php';
    return true;
}

function handle_admin_plugins(string $method): bool
{
    global $config, $pluginManager;

    $adminPluginError = '';
    $adminPluginSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $adminPluginError = 'Invalid CSRF token';
        } else {
            if (isset($_POST['save_plugin_settings'])) {
                $config['allow_catalog_only'] = !empty($_POST['allow_catalog_only']) ? 1 : 0;
                $config['plugin_verify_files'] = !empty($_POST['plugin_verify_files']) ? 1 : 0;

                file_put_contents(__DIR__ . '/../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $adminPluginSuccess = t('settings_saved');
            } elseif (isset($_POST['install_plugin']) && !empty($_FILES['plugin_zip']['tmp_name'])) {
                $tmpPath = $_FILES['plugin_zip']['tmp_name'];
                $result = $pluginManager->installFromZip($tmpPath);
                log_security_event('plugin_install', ['plugin' => $_POST['plugin_name'] ?? 'unknown', 'success' => (int)$result['success'], 'message' => $result['message']]);
                if ($result['success']) {
                    $adminPluginSuccess = $result['message'];
                } else {
                    $adminPluginError = $result['message'];
                }
            } elseif (isset($_POST['install_from_catalog'])) {
                $repo = $_POST['repo'] ?? '';
                $tag = $_POST['tag'] ?? null;
                $name = strtolower($_POST['plugin_name'] ?? '');
                if (!empty($config['allow_catalog_only'])) {
                    $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
                    $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
                    $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
                    $catalog = is_array($remoteCatalog) ? $remoteCatalog : (json_decode(file_get_contents(__DIR__ . '/../../data/catalog.json'), true) ?: []);
                    $catalogItem = array_filter($catalog, fn($i) => strtolower($i['name'] ?? '') === $name && strtolower($i['type'] ?? '') === 'plugin');
                    $catalogItem = array_values($catalogItem);
                    if (empty($catalogItem)) {
                        $adminPluginError = 'Catalog-only mode: this entry is not present in the catalog.';
                        goto skip_catalog_install;
                    }
                    if (($catalogItem[0]['author_type'] ?? '') === 'third_party') {
                        $adminPluginError = 'Catalog-only mode: third-party plugins cannot be installed. Only bulletinbored team plugins are allowed.';
                        goto skip_catalog_install;
                    }
                }
                skip_catalog_install:
                if ($repo === '' || $name === '') {
                    $adminPluginError = 'Invalid catalog item';
                } else {
                    $result = $pluginManager->installFromRepo($repo, $tag, $name);
                    if ($result['success']) {
                        $adminPluginSuccess = 'Installed from catalog';
                    } else {
                        $adminPluginError = $result['message'];
                    }
                }
            } elseif (isset($_POST['delete_plugin'])) {
                $pluginName = $_POST['plugin_name'] ?? '';
                $result = $pluginManager->delete($pluginName);
                if ($result['success']) {
                    $adminPluginSuccess = $result['message'];
                    log_admin_action('plugin_delete', ['plugin' => $pluginName]);
                } else {
                    $adminPluginError = $result['message'];
                }
            } elseif (isset($_POST['action'])) {
                $pluginName = $_POST['plugin_name'] ?? '';
                if ($_POST['action'] === 'enable') {
                    if ($pluginManager->enable($pluginName)) {
                        $adminPluginSuccess = 'Plugin enabled';
                        log_admin_action('plugin_enable', ['plugin' => $pluginName]);
                    } else {
                        $adminPluginError = 'Plugin not found';
                    }
                } elseif ($_POST['action'] === 'disable') {
                    if ($pluginManager->disable($pluginName)) {
                        $adminPluginSuccess = 'Plugin disabled';
                        log_admin_action('plugin_disable', ['plugin' => $pluginName]);
                    } else {
                        $adminPluginError = 'Plugin not found';
                    }
                }
            }
        }
    }

    $allPlugins = $pluginManager->getAll();
    $missingPlugins = $pluginManager->removeMissing();
    if (!empty($missingPlugins)) {
        $installedPath = __DIR__.'/../../data/installed.json';
        $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
        foreach ($missingPlugins as $removed) {
            $key = strtolower($removed);
            unset($installed['plugins'][$key]);
        }
        file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    include __DIR__ . '/../../views/admin_plugins.php';
    return true;
}

function handle_admin_themes(string $method): bool
{
    global $config, $themeManager;

    $adminThemeError = '';
    $adminThemeSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $adminThemeError = 'Invalid CSRF token';
        } else {
            if (isset($_POST['install_theme']) && !empty($_FILES['theme_zip']['tmp_name'])) {
                $tmpPath = $_FILES['theme_zip']['tmp_name'];
                $result = $themeManager->installFromZip($tmpPath);
                if ($result['success']) {
                    $adminThemeSuccess = $result['message'];
                } else {
                    $adminThemeError = $result['message'];
                }
            } elseif (isset($_POST['install_from_catalog'])) {
                $repo = $_POST['repo'] ?? '';
                $tag = $_POST['tag'] ?? null;
                $name = strtolower($_POST['theme_name'] ?? '');
                if (!empty($config['allow_catalog_only'])) {
                    $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
                    $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
                    $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
                    $catalog = is_array($remoteCatalog) ? $remoteCatalog : (json_decode(file_get_contents(__DIR__ . '/../../data/catalog.json'), true) ?: []);
                    $catalogItem = array_filter($catalog, fn($i) => strtolower($i['name'] ?? '') === $name && strtolower($i['type'] ?? '') === 'theme');
                    $catalogItem = array_values($catalogItem);
                    if (empty($catalogItem)) {
                        $adminThemeError = 'Catalog-only mode: this entry is not present in the catalog.';
                        goto skip_catalog_install_theme;
                    }
                    if (($catalogItem[0]['author_type'] ?? '') === 'third_party') {
                        $adminThemeError = 'Catalog-only mode: third-party themes cannot be installed. Only bulletinbored team themes are allowed.';
                        goto skip_catalog_install_theme;
                    }
                }
                skip_catalog_install_theme:
                if ($repo === '' || $name === '') {
                    $adminThemeError = 'Invalid catalog item';
                } else {
                    $result = $themeManager->installFromRepo($repo, $tag, $name);
                    if ($result['success']) {
                        $adminThemeSuccess = 'Installed from catalog';
                    } else {
                        $adminThemeError = $result['message'];
                    }
                }
            } elseif (isset($_POST['activate_theme'])) {
                $themeName = $_POST['theme_name'] ?? '';
                if ($themeManager->activate($themeName)) {
                    $adminThemeSuccess = 'Theme activated';
                    log_admin_action('theme_activate', ['theme' => $themeName]);
                } else {
                    $adminThemeError = 'Theme not found';
                }
            } elseif (isset($_POST['delete_theme'])) {
                $themeName = $_POST['theme_name'] ?? '';
                $result = $themeManager->delete($themeName);
                if ($result['success']) {
                    $adminThemeSuccess = $result['message'];
                    log_admin_action('theme_delete', ['theme' => $themeName]);
                } else {
                    $adminThemeError = $result['message'];
                }
            }
        }
    }

    $allThemes = $themeManager->getAll();
    $missingThemes = $themeManager->removeMissing();
    if (!empty($missingThemes)) {
        $installedPath = __DIR__.'/../../data/installed.json';
        $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
        foreach ($missingThemes as $removed) {
            $key = strtolower($removed);
            unset($installed['themes'][$key]);
        }
        file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    include __DIR__ . '/../../views/admin_themes.php';
    return true;
}

function handle_admin_catalog(string $method): bool
{
    global $config, $updateManager;

    if (!is_admin()) {
        die('Admin required');
    }

    $adminCatalogError = '';
    $adminCatalogSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $adminCatalogError = 'Invalid CSRF token';
        } elseif (isset($_POST['uninstall_from_catalog'])) {
            $name = strtolower(trim($_POST['name'] ?? ''));
            $type = strtolower(trim($_POST['type'] ?? ''));
            if ($name === '' || !in_array($type, ['plugin', 'theme'])) {
                $adminCatalogError = 'Invalid request';
            } else {
                $baseDir = $type === 'plugin' ? __DIR__ . '/../../plugins' : __DIR__ . '/../../themes';
                $target = $baseDir.'/'.$name;
                if (is_dir($target)) {
                    require_once __DIR__ . '/../../lib/PluginManager.php';
                    require_once __DIR__ . '/../../lib/ThemeManager.php';
                    if ($type === 'plugin') {
                        $pm = new PluginManager(__DIR__ . '/../../plugins', __DIR__ . '/../../data/plugins.json');
                        $pm->delete($name);
                    } else {
                        $tm = new ThemeManager(__DIR__ . '/../../themes', __DIR__ . '/../../data/themes.json', 'freshbored');
                        $tm->delete($name);
                    }
                }
                $installedPath = __DIR__.'/../../data/installed.json';
                $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
                unset($installed[$type.'s'][$name]);
                file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $adminCatalogSuccess = 'Uninstalled successfully';
            }
        } elseif (isset($_POST['install_from_catalog'])) {
            $name = strtolower(trim($_POST['name'] ?? ''));
            $type = strtolower(trim($_POST['type'] ?? ''));
            $repo = trim($_POST['repo'] ?? '');
            $tag = $_POST['tag'] ?? null;
            if ($repo === '' || $name === '' || !in_array($type, ['plugin', 'theme'])) {
                $adminCatalogError = 'Invalid request';
            } else {
                if ($type === 'plugin') {
                    $pluginManager = new PluginManager(__DIR__ . '/../../plugins', __DIR__ . '/../../data/plugins.json');
                    $result = $pluginManager->installFromRepo($repo, $tag, $name);
                } else {
                    $themeManager = new ThemeManager(__DIR__ . '/../../themes', __DIR__ . '/../../data/themes.json', 'freshbored');
                    $result = $themeManager->installFromRepo($repo, $tag, $name);
                }
                if ($result['success']) {
                    $installedPath = __DIR__.'/../../data/installed.json';
                    $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
                    if (!isset($installed[$type.'s'])) {
                        $installed[$type.'s'] = [];
                    }
                    $installed[$type.'s'][$name] = [
                        'name' => $name,
                        'repo' => $repo,
                        'version' => $result['manifest']['version'] ?? '1.0.0',
                        'installed_at' => date('c')
                    ];
                    file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $adminCatalogSuccess = 'Installed successfully';
                } else {
                    $adminCatalogError = $result['message'];
                }
            }
        }
    }

    $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
    $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
    $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
    if (is_array($remoteCatalog)) {
        $catalog = $remoteCatalog;
    } else {
        $catalog = json_decode(file_get_contents(__DIR__.'/../../data/catalog.json'), true) ?: [];
    }
    $installed = json_decode(file_get_contents(__DIR__.'/../../data/installed.json'), true) ?: ['plugins'=>[], 'themes'=>[]];
    $search = strtolower(trim($_GET['q'] ?? ''));
    if ($search !== '') {
        $catalog = array_filter($catalog, function($item) use ($search) {
            return str_contains(strtolower($item['name'] ?? ''), $search) || str_contains(strtolower($item['description'] ?? ''), $search);
        });
    }
    $typeFilter = strtolower(trim($_GET['type'] ?? ''));
    if ($typeFilter !== '' && $typeFilter !== 'all') {
        $catalog = array_filter($catalog, fn($item) => strtolower($item['type'] ?? '') === $typeFilter);
    }

    foreach ($catalog as $item) {
        $name = strtolower($item['name'] ?? '');
        $type = strtolower($item['type'] ?? '');
        $baseDir = $type === 'plugin' ? __DIR__ . '/../../plugins' : __DIR__ . '/../../themes';
        $requiredFile = $type === 'plugin' ? '/manifest.json' : '/style.css';
        $hasFiles = is_dir($baseDir.'/'.$name) && file_exists($baseDir.'/'.$name.$requiredFile);
        if ($hasFiles && !isset($installed[$type.'s'][$name])) {
            $manifestPath = $baseDir.'/'.$name.'/manifest.json';
            $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
            $installed[$type.'s'][$name] = [
                'name' => $name,
                'repo' => $item['repo'] ?? '',
                'version' => $manifest['version'] ?? '1.0.0',
                'installed_at' => date('c')
            ];
        }
    }

    foreach (['plugins','themes'] as $group) {
        foreach ($installed[$group] as $name => $data) {
            $baseDir = $group === 'plugins' ? __DIR__ . '/../../plugins' : __DIR__ . '/../../themes';
            $requiredFile = $group === 'plugins' ? '/manifest.json' : '/style.css';
            if (!is_dir($baseDir.'/'.$name) || !file_exists($baseDir.'/'.$name.$requiredFile)) {
                unset($installed[$group][$name]);
            }
        }
    }
    file_put_contents(__DIR__.'/../../data/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $catalogRemoteVersions = [];
    foreach ($catalog as $item) {
        $name = strtolower($item['name'] ?? '');
        $type = strtolower($item['type'] ?? '');
        $repo = $item['repo'] ?? '';
        $catalogRemoteVersions[$name] = $updateManager->getRemoteVersion($type, $name, $repo);
    }

    include __DIR__ . '/../../views/admin_catalog.php';
    return true;
}

function handle_admin_updates(string $method): bool
{
    global $config, $pluginManager, $themeManager, $updateManager;

    $updateResults = null;
    $updateError = '';
    $updateSuccess = '';

    if ($method === 'POST' && isset($_POST['check_updates'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } else {
            $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
            $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
            $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
            $catalog = is_array($remoteCatalog)
                ? $remoteCatalog
                : (file_exists(__DIR__.'/../../data/catalog.json') ? json_decode(file_get_contents(__DIR__.'/../../data/catalog.json'), true) : []);
            $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager, $catalog);
        }
    }

    if ($method === 'POST' && isset($_POST['apply_update'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } else {
            $type = $_POST['type'] ?? '';
            $name = $_POST['name'] ?? '';

            if ($type === 'core' && !empty($_POST['core_tag'])) {
                $tag = ltrim($_POST['core_tag'], 'v');
                if (version_compare($tag, $config['version'] ?? '1.0.0', '<=')) {
                    $updateError = 'No newer version available';
                } elseif ($updateManager->applyCoreUpdate($tag)) {
                    $updateSuccess = 'Core updated to v' . escape($tag);
                    log_security_event('core_update', ['tag' => $tag]);
                    clearstatcache();
                    $versionFile = __DIR__ . '/../../VERSION';
                    if (file_exists($versionFile)) {
                        $config['version'] = trim(@file_get_contents($versionFile));
                    }
                } else {
                    $updateError = 'Failed to update core';
                    log_security_event('core_update_failed', ['tag' => $tag]);
                }
            } elseif (($type === 'plugins' || $type === 'themes') && !empty($_POST['ext_tag'])) {
                $tag = ltrim($_POST['ext_tag'], 'v');
                $extName = $name ?? '';
                $installedVersion = '1.0.0';
                if ($type === 'plugins' && $pluginManager) {
                    $plugin = $pluginManager->getAll();
                    $plugin = $plugin[$extName] ?? null;
                    $installedVersion = $plugin['version'] ?? '1.0.0';
                } elseif ($type === 'themes' && $themeManager) {
                    $theme = $themeManager->getAll();
                    $theme = $theme[$extName] ?? null;
                    $installedVersion = $theme['version'] ?? '1.0.0';
                }
                if (version_compare($tag, $installedVersion, '<=')) {
                    $updateError = 'No newer version available';
                } elseif ($updateManager->applyExtensionUpdate($type === 'plugins' ? 'plugin' : 'theme', $extName, $tag)) {
                    $updateSuccess = 'Extension updated to v' . escape($tag);
                    log_security_event('extension_update', ['type' => $type, 'name' => $extName, 'tag' => $tag]);
                    if ($type === 'plugins' && $pluginManager) {
                        $pluginManager->discover();
                    } elseif ($type === 'themes' && $themeManager) {
                        $themeManager->discover();
                    }
                    $installedPath = __DIR__ . '/../../data/installed.json';
                    $installedData = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins' => [], 'themes' => []];
                    if (!is_array($installedData)) {
                        $installedData = ['plugins' => [], 'themes' => []];
                    }
                    $group = $type === 'plugins' ? 'plugins' : 'themes';
                    if (isset($installedData[$group][$extName])) {
                        $installedData[$group][$extName]['version'] = $tag;
                    }
                    file_put_contents($installedPath, json_encode($installedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $updateError = 'Failed to update extension';
                    log_security_event('extension_update_failed', ['type' => $type, 'name' => $extName, 'tag' => $tag]);
                }
            } elseif (!empty($_FILES['update_package']['tmp_name'])) {
                $tmpPath = $_FILES['update_package']['tmp_name'];
                $result = $updateManager->applyUpdate($type, $name, $tmpPath);
                if ($result) {
                    $updateSuccess = 'Update applied successfully';
                } else {
                    $updateError = 'Failed to apply update';
                }
            } else {
                $updateError = 'No update package uploaded';
            }

            $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager);
        }
    }

    $updateStatus = $updateResults ?? null;
    include __DIR__ . '/../../views/admin_updates.php';
    return true;
}

function handle_delete_user_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
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
    redirect(url('admin_users'));
    return true;
}

function handle_unban_user_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$userId]);
        log_admin_action('user_unban', ['target_id' => $userId]);
    }
    redirect(url('admin_users'));
    return true;
}

function handle_ban_user_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role <> 'admin'")->execute([$userId]);
        log_admin_action('user_ban', ['target_id' => $userId]);
    }
    redirect(url('admin_users'));
    return true;
}

function handle_suspend_user_post(): bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        die('CSRF token invalid');
    }
    $userId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $days = max(1, (int)($_POST['days'] ?? 1));
    $suspensionTime = time() + ($days * 86400);
    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET status = 'suspended', suspension_time = ? WHERE id = ?")->execute([$suspensionTime, $userId]);
        log_admin_action('user_suspend', ['target_id' => $userId, 'days' => $days]);
    }
    redirect(url('admin_users'));
    return true;
}
