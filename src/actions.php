<?php
// Routing
$action = $_GET['action'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

// Redirect banned users to home
if (is_logged_in() && (is_banned() || is_suspended())) {
    session_destroy();
    redirect(url('home'));
}

try {
    if ($action === 'home' || $action === '') {
        // All discussions listing
        $page = max(1, (int)($_GET['page'] ?? 1));
        $sort = $_GET['sort'] ?? 'latest';

        $listing     = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'sticky_first' => false]);
        $threads     = $listing['threads'];
        $total       = $listing['total'];
        $totalPages  = $listing['pages'];
        $page        = $listing['page'];
        $sort        = $listing['sort'];
        $listContext = 'home';

        $categories = sidebar_categories();
        include __DIR__ . '/../views/home.php';
    } 
    elseif ($action === 'thread' && isset($_GET['id'])) {
        // View single thread
        $threadId = (int)$_GET['id'];
        $stmt = $pdo->prepare("
            SELECT t.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
                   u.created_at as author_joined, u.id as author_id,
                   c.name as category_name,
                   COALESCE(t.views, 0) as view_count,
                   (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') as reply_count,
                   (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = t.user_id AND p2.status = 'visible') as author_posts
            FROM threads t 
            LEFT JOIN users u ON t.user_id = u.id 
            LEFT JOIN categories c ON t.category_id = c.id 
            WHERE t.id = ?
        ");
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch();
        
        if (!$thread) {
            die('Thread not found');
        }

        // Count one view per visitor session
        $seenThreads = $_SESSION['viewed_threads'] ?? [];
        if (!in_array($threadId, $seenThreads, true)) {
            try {
                $pdo->prepare("UPDATE threads SET views = COALESCE(views, 0) + 1 WHERE id = ?")->execute([$threadId]);
                $thread['view_count'] = (int)$thread['view_count'] + 1;
            } catch (PDOException $e) {}
            $seenThreads[] = $threadId;
            $_SESSION['viewed_threads'] = array_slice($seenThreads, -200);
        }
        
        // Get posts for this thread with pagination
        $postPage = max(1, (int)($_GET['post_page'] ?? 1));
        $perPage = 15;
        $offset = ($postPage - 1) * $perPage;
        
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM posts 
            WHERE thread_id = ? AND status = 'visible'
        ");
        $totalStmt->execute([$threadId]);
        $totalPosts = $totalStmt->fetchColumn();
        
        $totalPages = max(1, (int)ceil($totalPosts / $perPage));
        
        $postsStmt = $pdo->prepare("
            SELECT p.*, u.username as author, u.avatar as author_avatar, u.role as author_role,
                   u.created_at as author_joined,
                   (SELECT COUNT(*) FROM posts p2 WHERE p2.user_id = p.user_id AND p2.status = 'visible') as author_posts
            FROM posts p 
            LEFT JOIN users u ON p.user_id = u.id 
            WHERE p.thread_id = ? AND p.status = 'visible' 
            ORDER BY p.created_at ASC, p.id ASC
            LIMIT ".(int)$perPage." OFFSET ".(int)$offset."
        ");
        $postsStmt->execute([$threadId]);
        $posts = $postsStmt->fetchAll();
        
        include __DIR__ . '/../views/thread.php';
    }
    elseif ($action === 'new_thread') {
        // Show new thread form / handle submission
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }

            // Rate limit: 20 new threads / hour per user.
            if (!rate_limit('new_thread', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
                http_response_code(429);
                die('You are posting too fast. Please try again later.');
            }

            $title = validate_input($_POST['title'] ?? '');
            $content = validate_input($_POST['content'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 1);
            
            $catStmt = $pdo->prepare("SELECT allowed_roles FROM categories WHERE id = ?");
            $catStmt->execute([$categoryId]);
            $allowedRoles = $catStmt->fetchColumn();
            if ($allowedRoles && $allowedRoles !== 'all' && !is_admin()) {
                if ($allowedRoles === 'moderator' && !in_array($_SESSION['user_role'] ?? 'user', ['admin', 'moderator'], true)) {
                    die(t('not_authorized_category'));
                } elseif ($allowedRoles === 'admin' && ($_SESSION['user_role'] ?? 'user') !== 'admin') {
                    die(t('not_authorized_category'));
                } else {
                    $allowed = json_decode($allowedRoles, true);
                    if ($allowed && is_array($allowed) && !in_array($_SESSION['user_role'] ?? 'user', $allowed)) {
                        die(t('not_authorized_category'));
                    }
                }
             }
             
             if ($title === '' || $content === '') {
                die('Title and content are required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO threads (category_id, user_id, title, content) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$categoryId, $_SESSION['user_id'], $title, $content]);
            $threadId = $pdo->lastInsertId();
            
            // Handle file uploads
            if (!empty($config['attachments_enabled']) && !empty($_FILES['attachments']['name'][0])) {
                foreach ($_FILES['attachments']['name'] as $index => $originalName) {
                    if ($_FILES['attachments']['error'][$index] === UPLOAD_ERR_OK) {
                        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        $safeName = uniqid().'.'.$extension;
                        $uploadPath = __DIR__ . '/../uploads/'.$safeName;
                        
                        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$index], $uploadPath)) {
                            $pdo->prepare("
                                INSERT INTO uploads (thread_id, user_id, filename, original_name, size) 
                                VALUES (?, ?, ?, ?, ?)
                            ")->execute([
                                $threadId,
                                $_SESSION['user_id'],
                                $safeName,
                                basename($originalName),
                                $_FILES['attachments']['size'][$index]
                            ]);
                        }
                    }
                }
            }
            
            $pluginManager->runHook('after_thread', $threadId);
            
            redirect(url('thread', ['id' => $threadId, 'slug' => slugify($_POST['title'] ?? '')]));
        }
        $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
        include __DIR__ . '/../views/new_thread.php';
    }
    elseif ($action === 'reply' && $method === 'POST') {
        // Handle reply submission
        if (!is_logged_in()) {
            die('Login required');
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }

        // Rate limit: 30 replies / hour per user.
        if (!rate_limit('reply', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            http_response_code(429);
            die('You are posting too fast. Please try again later.');
        }

        $threadId = (int)($_POST['thread_id'] ?? 0);
        $content = validate_input($_POST['content'] ?? '');
        
        if ($threadId <= 0 || $content === '') {
            die('Invalid thread or empty content');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO posts (thread_id, user_id, content) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$threadId, $_SESSION['user_id'], $content]);
        
        $pluginManager->runHook('after_post', $threadId, $pdo->lastInsertId());
        
        // Notify watchers
        $watchersStmt = $pdo->prepare("
            SELECT u.email, u.username 
            FROM thread_watchers w 
            JOIN users u ON w.user_id = u.id 
            WHERE w.thread_id = ? AND w.user_id <> ?
        ");
        $watchersStmt->execute([$threadId, $_SESSION['user_id']]);
        $watchers = $watchersStmt->fetchAll();
        
        $threadTitleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
        $threadTitleStmt->execute([$threadId]);
        $threadTitle = $threadTitleStmt->fetchColumn();
        
        foreach ($watchers as $watcher) {
            if (!empty($watcher['email'])) {
                $subject = t('reply_notification_subject', ['title' => $threadTitle]);
                $replyLink = url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)], true);
                $body = t('reply_notification_body', [
                    'username' => escape($watcher['username']),
                    'title' => escape($threadTitle),
                    'author' => escape($_SESSION['username'] ?? 'Someone'),
                    'link' => $replyLink
                ]);
                send_email($watcher['email'], $subject, $body);
            }
        }
        
        redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)]));
    }
    elseif ($action === 'edit_post' && isset($_GET['id'])) {
        if (!is_logged_in()) {
            die('Login required');
        }
        
        $postId = (int)$_GET['id'];
        $source = 'post';
        
        $postStmt = $pdo->prepare("
            SELECT p.*, t.title as thread_title, t.id as thread_id 
            FROM posts p 
            JOIN threads t ON p.thread_id = t.id 
            WHERE p.id = ?
        ");
        $postStmt->execute([$postId]);
        $post = $postStmt->fetch();
        
        if (!$post) {
            $source = 'thread';
            $threadStmt = $pdo->prepare("
                SELECT t.*, t.title as thread_title, t.id as thread_id 
                FROM threads t 
                WHERE t.id = ?
            ");
            $threadStmt->execute([$postId]);
            $post = $threadStmt->fetch();
        }
        
        if (!$post) {
            die('Post not found');
        }
        
        // Check permissions
        if ($post['user_id'] !== $_SESSION['user_id'] && !is_admin()) {
            die('Not authorized');
        }
        
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            
            $postId = (int)($_POST['post_id'] ?? 0);
            $rawContent = $_POST['content'] ?? '';
            $title = '';
            
            if ($postId <= 0 || $rawContent === '') {
                die('Invalid post or empty content');
            }
            
            // Re-check permissions for the target record
            $postSource = 'post';
            $postStmt2 = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
            $postStmt2->execute([$postId]);
            $postCheck = $postStmt2->fetch();
            
            if (!$postCheck) {
                $postSource = 'thread';
                $postStmt2 = $pdo->prepare("SELECT user_id FROM threads WHERE id = ?");
                $postStmt2->execute([$postId]);
                $postCheck = $postStmt2->fetch();
            }
            
            $canEdit = is_admin()
                || (($postCheck['user_id'] ?? null) == ($_SESSION['user_id'] ?? 0))
                || (function_exists('user_has_permission')
                    && (($postSource === 'thread' && user_has_permission('can_edit_threads'))
                        || ($postSource === 'post' && user_has_permission('can_edit_posts'))));
            
            if (!$postCheck || !$canEdit) {
                die('Not authorized');
            }
            
            // editbored stores ready HTML; do not re-encode it with htmlspecialchars.
            // Plain markdown input (no HTML tags) still gets escaped for safety.
            $content = (str_contains($rawContent, '<') && str_contains($rawContent, '>'))
                ? $rawContent
                : validate_input($rawContent);
            
            if ($postSource === 'thread') {
                $title = validate_input($_POST['title'] ?? '');
                if ($title === '') {
                    die('Invalid title');
                }
                $pdo->prepare("UPDATE threads SET title = ?, content = ? WHERE id = ?")
                    ->execute([$title, $content, $postId]);
            } else {
                $pdo->prepare("UPDATE posts SET content = ? WHERE id = ?")
                    ->execute([$content, $postId]);
            }
            
            // Get thread ID for redirect
            if ($postSource === 'thread') {
                $threadId = $postId;
            } else {
                $tidStmt = $pdo->prepare("SELECT thread_id FROM posts WHERE id = ?");
                $tidStmt->execute([$postId]);
                $threadId = $tidStmt->fetchColumn();
            }
            $titleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
            $titleStmt->execute([$threadId]);
            $threadTitle = $titleStmt->fetchColumn();
            redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)]));
        }
        
        $editThreadTitle = ($source === 'thread');
        include __DIR__ . '/../views/edit_post.php';
    }
    elseif ($action === 'delete_post' && isset($_GET['id']) && $method === 'POST') {
        // Handle post deletion
        if (!is_logged_in()) {
            die('Login required');
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        
        $postId = (int)($_GET['id'] ?? 0);
        $postStmt3 = $pdo->prepare("SELECT user_id, thread_id FROM posts WHERE id = ?");
        $postStmt3->execute([$postId]);
        $post = $postStmt3->fetch();
        
        if (!$post || ($post['user_id'] !== $_SESSION['user_id'] && !is_admin())) {
            die('Not authorized');
        }
        
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
        redirect(url('thread', ['id' => $post['thread_id']]));
    }
    elseif ($action === 'watch' && is_logged_in()) {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        if ($threadId > 0) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
            $checkStmt->execute([$threadId, $_SESSION['user_id']]);
            if ($checkStmt->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")
                    ->execute([$threadId, $_SESSION['user_id']]);
            }
        }
        redirect($_SERVER['HTTP_REFERER'] ?? url('home'));
    }
    elseif ($action === 'unwatch' && is_logged_in()) {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        if ($threadId > 0) {
            $pdo->prepare("DELETE FROM thread_watchers WHERE thread_id = ? AND user_id = ?")
                ->execute([$threadId, $_SESSION['user_id']]);
        }
        redirect($_SERVER['HTTP_REFERER'] ?? url('home'));
    }
    elseif ($action === 'login') {
        // Show login form / handle login submission
        if (is_logged_in()) {
            redirect(url('home'));
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }

            // Rate limit: 5 attempts / 15 min per IP+username.
            $rlKey = ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '|' . ($_POST['username'] ?? '');
            if (!rate_limit('login', 5, 900, $rlKey)) {
                http_response_code(429);
                die('Too many login attempts. Please try again later.');
            }

            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
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
                    redirect(url('home'));
                }
            } else {
                $error = 'Invalid credentials';
            }
        }
        include __DIR__ . '/../views/login.php';
    }
    elseif ($action === 'register') {
        // Show registration form / handle registration
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }

            // Rate limit: 5 registrations / hour per IP.
            if (!rate_limit('register', 5, 3600)) {
                http_response_code(429);
                die('Too many registration attempts. Please try again later.');
            }

            $username = validate_input($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if ($username === '' || $password === '') {
                die('Username and password are required');
            }
            
            // Check if username exists
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $existsStmt->execute([$username]);
            $exists = $existsStmt->fetchColumn();
            if ($exists > 0) {
                die('Username already taken');
            }
            
            $email = validate_input($_POST['email'] ?? '');
            
            $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified) VALUES (?, ?, ?, 'user', 0)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
            
            $userId = $pdo->lastInsertId();
            $pluginManager->runHook('user_registered', $userId, $username);
            
            if (!empty($email)) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $pdo->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)")
                    ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $expires]);
                
                $verifyLink = url('verify_email', ['token' => $token], true);
                $subject = 'Confirm your email';
                $body = '<p>Hello '.escape($username).',</p>
                        <p>Thank you for registering! Please click the button below to confirm your email address:</p>
                        <p style="text-align:center;"><a class="btn" href="'.$verifyLink.'">Verify Email</a></p>
                        <p>Or copy this link: <br><code>'.$verifyLink.'</code></p>
                        <p>This link expires in 24 hours.</p>';
                send_email($email, $subject, $body);
            }
            
            redirect(url('login', ['registered' => 1]));
        }
        include __DIR__ . '/../views/register.php';
    }
    elseif ($action === 'logout') {
        // Handle logout
        session_destroy();
        redirect(url('home'));
    }
    elseif ($action === 'verify_email') {
        $token = $_GET['token'] ?? '';
        
        if (empty($token)) {
            $error = 'verify_email_invalid';
            include __DIR__ . '/../views/verify_email.php';
            exit;
        }
        
        $tokensStmt = $pdo->prepare("SELECT * FROM email_verifications WHERE used = 0 AND expires_at > CURRENT_TIMESTAMP ORDER BY created_at DESC");
        $tokensStmt->execute();
        $validToken = null;
        foreach ($tokensStmt->fetchAll() as $row) {
            if (password_verify($token, $row['token'])) {
                $validToken = $row;
                break;
            }
        }
        
        if (!$validToken) {
            $error = 'verify_email_invalid';
            include __DIR__ . '/../views/verify_email.php';
            exit;
        }
        
        $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$validToken['user_id']]);
        $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?")->execute([$validToken['id']]);
        
        $success = 'verify_email_success';
        include __DIR__ . '/../views/verify_email.php';
        exit;
    }
    elseif ($action === 'profile' && isset($_GET['user'])) {
        // View user profile
        $username = $_GET['user'];
        $profileStmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $profileStmt->execute([$username]);
        $profileUser = $profileStmt->fetch();
        
        if (!$profileUser) {
            die('User not found');
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
        
        include __DIR__ . '/../views/profile.php';
    }
    elseif ($action === 'edit_profile') {
        // Show edit profile form / handle profile update / upload avatar
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }
            if (!empty($_FILES['avatar']['name'])) {
                $avatarDir = __DIR__ . '/../uploads/avatars/';
                if (!is_dir($avatarDir)) {
                    @mkdir($avatarDir, 0777, true);
                }

                if (empty($_FILES['avatar']['name']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    $_SESSION['avatar_upload_error'] = 'No file uploaded or upload error occurred.';
                    redirect(url('edit_profile'));
                }

                $allowed = $config['avatar_allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $maxSize = $config['avatar_max_size'] ?? 2*1024*1024;

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['avatar']['tmp_name']);

                if (!in_array($mime, $allowed)) {
                    $_SESSION['avatar_upload_error'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.';
                    redirect(url('edit_profile'));
                }

                if ($_FILES['avatar']['size'] > $maxSize) {
                    $_SESSION['avatar_upload_error'] = 'File is too large. Max 2MB.';
                    redirect(url('edit_profile'));
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

                redirect(url('edit_profile'));
            } else {
                $updates = [];
                $params = [];
                
                if (!empty($_POST['username'])) {
                    $newUsername = validate_input($_POST['username']);
                    $existingStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
                    $existingStmt->execute([$newUsername, $_SESSION['user_id']]);
                    $existing = $existingStmt->fetchColumn();
                    if ($existing) {
                        die('Username already taken');
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
                
                redirect(url('profile', ['user' => $_SESSION['username']]));
            }
        }
        include __DIR__ . '/../views/edit_profile.php';
    }
    elseif ($action === 'search') {
        // Search functionality - rendered with the standard discussion listing
        $query = $_GET['q'] ?? '';
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $sort  = $_GET['sort'] ?? 'latest';

        $listing     = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'search' => $query, 'sticky_first' => false]);
        $threads     = $listing['threads'];
        $total       = $listing['total'];
        $totalPages  = $listing['pages'];
        $page        = $listing['page'];
        $sort        = $listing['sort'];
        $listContext = 'search';

        $categories = sidebar_categories();
        include __DIR__ . '/../views/home.php';
    }
    elseif ($action === 'category' && isset($_GET['id'])) {
        // View category
        $categoryId = (int)$_GET['id'];
        $catStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $category = $catStmt->fetch();
        
        if (!$category) {
            die('Category not found');
        }
        
        $page = max(1, (int)($_GET['page'] ?? 1));
        $sort = $_GET['sort'] ?? 'latest';

        $listing     = fetch_threads(['page' => $page, 'sort' => $sort, 'per_page' => 15, 'category_id' => $categoryId]);
        $threads     = $listing['threads'];
        $total       = $listing['total'];
        $totalPages  = $listing['pages'];
        $page        = $listing['page'];
        $sort        = $listing['sort'];
        $listContext = 'category';

        include __DIR__ . '/../views/category.php';
    }
    elseif ($action === 'admin') {
        // Admin panel
        if (!is_admin()) {
            die('Admin required');
        }
        
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
        
        // Admin settings
        $adminError = '';
        $adminSuccess = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $adminError = 'Invalid CSRF token';
            } else {
                $siteName = trim($_POST['site_name'] ?? $config['site_name']);
                $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
                $availableLangs = array_filter(array_map('trim', explode(',', $_POST['available_langs'] ?? implode(',', $config['available_langs'] ?? ['en']))));
                
                $config['site_name'] = $siteName;
                $config['default_lang'] = $defaultLang;
                $config['available_langs'] = array_values($availableLangs);
                
                $configContent = "<?php\n";
                foreach ($config as $key => $value) {
                    if (is_string($value)) {
                        $configContent .= "\$config['$key'] = '" . addslashes($value) . "';\n";
                    } elseif (is_array($value)) {
                        $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
                    } else {
                        $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
                    }
                }
                
            if (file_put_contents(__DIR__ . '/../config.php', $configContent) !== false) {
                $adminSuccess = 'Settings saved successfully';
            } else {
                $adminError = 'Failed to save settings';
            }
        }
        
        redirect(url('admin_settings'));
    }
    
    include __DIR__ . '/../views/admin.php';
}
elseif ($action === 'moderate' && $method === 'POST' && is_admin()) {
         // Handle moderation actions
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         
         $threadId = (int)($_POST['id'] ?? 0);
         $action = $_POST['do'] ?? '';
         
         if ($threadId <= 0) {
             die('Invalid thread ID');
         }
         
         if ($action === 'approve') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'delete') {
             $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'lock') {
             $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'unlock') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'sticky') {
             $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'unsticky') {
             $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
         } elseif ($action === 'hide') {
             $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
         }
         
redirect(url('admin_moderation'));
      }
      elseif ($action === 'frontend_moderate' && $method === 'POST' && is_logged_in()) {
          // Frontend moderation actions (from thread view)
          if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
              die('CSRF token invalid');
          }
          $threadId = (int)($_POST['id'] ?? 0);
          $modAction = $_POST['do'] ?? '';
          if ($threadId <= 0) {
              die('Invalid thread ID');
          }
          // Check if user is admin or has moderation permissions
          $userRole = $_SESSION['user_role'] ?? 'user';
          if ($userRole !== 'admin' && $userRole !== 'moderator') {
              die('Not authorized');
          }
          if ($modAction === 'lock') {
              $pdo->prepare("UPDATE threads SET status = 'locked' WHERE id = ?")->execute([$threadId]);
          } elseif ($modAction === 'unlock') {
              $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
          } elseif ($modAction === 'sticky') {
              $pdo->prepare("UPDATE threads SET status = 'sticky' WHERE id = ?")->execute([$threadId]);
          } elseif ($modAction === 'unsticky') {
              $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
          } elseif ($modAction === 'hide') {
              $pdo->prepare("UPDATE threads SET status = 'hidden' WHERE id = ?")->execute([$threadId]);
          } elseif ($modAction === 'delete') {
              $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
              redirect(url('admin_moderation'));
          } elseif ($modAction === 'approve') {
              $pdo->prepare("UPDATE threads SET status = 'visible' WHERE id = ?")->execute([$threadId]);
          } elseif ($modAction === 'move') {
              $targetCat = (int)($_POST['category_id'] ?? 0);
              if ($targetCat > 0) {
                  $pdo->prepare("UPDATE threads SET category_id = ? WHERE id = ?")->execute([$targetCat, $threadId]);
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
                  }
              }
          }
          $threadTitleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
          $threadTitleStmt->execute([$threadId]);
          $threadTitle = $threadTitleStmt->fetchColumn();
          redirect(url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle ?? '')]));
      }
      elseif ($action === 'split_thread' && $method === 'POST' && is_logged_in()) {
          if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
          $ins = $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status, created_at) VALUES (?, ?, ?, ?, 'visible', ?)");
          $ins->execute([$srcThread['category_id'], $srcThread['user_id'], $newTitle, $srcThread['content'], $srcThread['created_at']]);
          $newThreadId = (int)$pdo->lastInsertId();
          $postIns = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status, created_at) SELECT ?, user_id, content, status, created_at FROM posts WHERE id IN (" . implode(',', array_fill(0, count($postIds), '?')) . ")");
          $params = array_merge([$newThreadId], array_map('intval', $postIds));
          $postIns->execute($params);
          $delParams = array_merge([$threadId], array_map('intval', $postIds));
          $delSql = "DELETE FROM posts WHERE thread_id = ? AND id IN (" . implode(',', array_fill(0, count($postIds), '?')) . ")";
          $pdo->prepare($delSql)->execute($delParams);
          $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible'");
          $countStmt->execute([$threadId]);
          if (empty($countStmt->fetchColumn())) {
              $pdo->prepare("DELETE FROM threads WHERE id = ?")->execute([$threadId]);
              redirect(url('home'));
          }
          redirect(url('thread', ['id' => $newThreadId, 'slug' => slugify($newTitle)]));
      }
      elseif ($action === 'merge_thread' && $method === 'POST' && is_logged_in()) {
          if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
      }
     elseif ($action === 'admin_categories') {
        // Show categories management page / handle create & edit
        if (!is_admin()) {
            die('Admin required');
        }
         if ($method === 'POST') {
             if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
                }
            } else {
                $name = validate_input($_POST['name'] ?? '');
                $description = validate_input($_POST['description'] ?? '');
                if ($name !== '') {
                    $pdo->prepare("INSERT INTO categories (name, description, allowed_roles) VALUES (?, ?, ?)")->execute([$name, $description, $allowedRoles]);
                }
            }
             redirect(url('admin_categories'));
         }
        include __DIR__ . '/../views/admin_categories.php';
    }
    elseif ($action === 'delete_category' && $method === 'POST' && is_admin()) {
        // Delete category
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $catId = (int)($_GET['id'] ?? 0);
        if ($catId > 0) {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);
        }
        redirect(url('admin_categories'));
    }
    elseif ($action === 'update_category_order' && $method === 'POST' && is_admin()) {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
    }
elseif ($action === 'delete_user' && $method === 'POST' && is_admin()) {
         // Delete user
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         $userId = (int)($_GET['id'] ?? 0);
         if ($userId > 0) {
             $pdo->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'")->execute([$userId]);
         }
         redirect(url('admin_users'));
     }
elseif ($action === 'ban_user' && $method === 'POST' && is_admin()) {
          // Ban a user
          if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
              die('CSRF token invalid');
          }
          $userId = (int)($_GET['id'] ?? 0);
          $username = '';
          if ($userId > 0) {
              $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
              $userStmt->execute([$userId]);
              $username = $userStmt->fetchColumn() ?? '';
              $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role <> 'admin'")->execute([$userId]);
          }
          $redirect = $_POST['redirect'] ?? '';
          if ($redirect !== '' && str_starts_with($redirect, '/')) {
              $redirect = base_url() . $redirect;
          }
          if ($redirect && $username) {
              redirect($redirect);
          } elseif ($username) {
              redirect(url('profile', ['user' => $username]));
          } else {
              redirect(url('admin_users'));
          }
      }
elseif ($action === 'unban_user' && $method === 'POST' && is_admin()) {
        // Unban a user
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $userId = (int)($_GET['id'] ?? 0);
        $username = '';
        if ($userId > 0) {
            $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $username = $userStmt->fetchColumn() ?? '';
            $pdo->prepare("UPDATE users SET status = 'active', suspension_time = 0 WHERE id = ?")->execute([$userId]);
          }
          $redirect = $_POST['redirect'] ?? '';
          if ($redirect !== '' && str_starts_with($redirect, '/')) {
              $redirect = base_url() . $redirect;
          }
          if ($redirect && $username) {
            redirect($redirect);
        } elseif ($username) {
            redirect(url('profile', ['user' => $username]));
        } else {
            redirect(url('admin_users'));
        }
    }
    elseif ($action === 'suspend_user' && $method === 'POST' && is_admin()) {
        // Suspend a user for specified days
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $userId = (int)($_GET['id'] ?? 0);
        $days = max(1, (int)($_POST['days'] ?? 1));
        $suspensionTime = time() + ($days * 86400);
        $username = '';
        if ($userId > 0) {
            $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $username = $userStmt->fetchColumn() ?? '';
            $pdo->prepare("UPDATE users SET status = 'suspended', suspension_time = ? WHERE id = ?")->execute([$suspensionTime, $userId]);
          }
          $redirect = $_POST['redirect'] ?? '';
          if ($redirect !== '' && str_starts_with($redirect, '/')) {
              $redirect = base_url() . $redirect;
          }
          if ($redirect && $username) {
            redirect($redirect);
        } elseif ($username) {
            redirect(url('profile', ['user' => $username]));
        } else {
            redirect(url('admin_users'));
        }
    }
    elseif ($action === 'admin_settings' && $method === 'POST' && is_admin()) {
        // Save admin settings
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            die('CSRF token invalid');
        }
        $siteName = trim($_POST['site_name'] ?? $config['site_name']);
        $siteTagline = trim($_POST['site_tagline'] ?? $config['site_tagline']);
        $siteIcon = trim($_POST['site_icon'] ?? $config['site_icon']);
        $timezone = trim($_POST['timezone'] ?? $config['timezone']);
        $dateFormat = trim($_POST['date_format'] ?? $config['date_format']);
        $timeFormat = trim($_POST['time_format'] ?? $config['time_format']);
        $mailFrom = trim($_POST['mail_from'] ?? $config['mail_from'] ?? '');
        $mailFromName = trim($_POST['mail_from_name'] ?? $config['mail_from_name'] ?? '');
        $attachmentsEnabled = !empty($_POST['attachments_enabled']) ? 1 : 0;

        $config['site_name'] = $siteName;
        $config['site_tagline'] = $siteTagline;
        $config['site_icon'] = $siteIcon;
        $config['timezone'] = $timezone;
        $config['date_format'] = $dateFormat;
        $config['time_format'] = $timeFormat;
        $config['mail_from'] = $mailFrom;
        $config['mail_from_name'] = $mailFromName;
        $config['attachments_enabled'] = $attachmentsEnabled;

        $configContent = "<?php\n";
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $configContent .= "\$config['$key'] = '" . addslashes($value) . "';\n";
            } else {
                $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
            }
        }

        file_put_contents(__DIR__ . '/../config.php', $configContent);
        $_SESSION['settings_saved'] = true;
        redirect(url('admin_settings'));
    }
elseif ($action === 'admin_moderation') {
         // Show moderation page
         if (!is_admin()) {
             die('Admin required');
         }
         include __DIR__ . '/../views/admin_moderation.php';
     }
     elseif ($action === 'admin_roles') {
         // Show roles/permissions management page
         if (!is_admin()) {
             die('Admin required');
         }
         include __DIR__ . '/../views/admin_roles.php';
     }
     elseif ($action === 'admin_roles_action' && $method === 'POST' && is_admin()) {
         // Handle roles/permissions actions
         if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
             die('CSRF token invalid');
         }
         $roleAction = $_POST['do'] ?? '';
         if ($roleAction === 'create') {
             $roleName = validate_input($_POST['role_name'] ?? '');
             $permissions = $_POST['permissions'] ?? [];
             if ($roleName !== '') {
                 $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
                     ->execute([$roleName, json_encode($permissions)]);
             }
         } elseif ($roleAction === 'update') {
             $roleId = (int)($_POST['role_id'] ?? 0);
             $permissions = $_POST['permissions'] ?? [];
             if ($roleId > 0) {
                 $pdo->prepare("UPDATE roles SET permissions = ? WHERE id = ?")
                     ->execute([json_encode($permissions), $roleId]);
             }
         } elseif ($roleAction === 'delete') {
             $roleId = (int)($_POST['role_id'] ?? 0);
             if ($roleId > 0) {
                 $pdo->prepare("DELETE FROM roles WHERE id = ? AND name <> 'admin'")->execute([$roleId]);
             }
         }
         redirect(url('admin_roles'));
     }
elseif ($action === 'admin_users') {
         // Show users management page
         if (!is_admin()) {
             die('Admin required');
         }
         include __DIR__ . '/../views/admin_users.php';
      }
      elseif ($action === 'admin_user_edit' && isset($_GET['id'])) {
          // Edit user page
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
              if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                  die('CSRF token invalid');
              }
              $newUsername = trim($_POST['username'] ?? '');
              $newEmail = trim($_POST['email'] ?? '');
              $newRole = $_POST['role'] ?? 'user';
              $newStatus = $_POST['status'] ?? 'active';
              if ($newUsername !== '') {
                  $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ?, status = ? WHERE id = ?")
                      ->execute([$newUsername, $newEmail, $newRole, $newStatus, $editUserId]);
              }
              redirect(url('admin_user_edit', ['id' => $editUserId]));
          }
          include __DIR__ . '/../views/admin_user_edit.php';
      }
      elseif ($action === 'admin_create_user' && $method === 'POST' && is_admin()) {
          if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
          
          redirect(url('admin_users'));
      }
      elseif ($action === 'admin_settings') {
        // Show settings page
        if (!is_admin()) {
            die('Admin required');
        }
        include __DIR__ . '/../views/admin_settings.php';
    }
    elseif ($action === 'admin_langs') {
        // Language file management
        if (!is_admin()) {
            die('Admin required');
        }

        $langMetaPath = __DIR__ . '/../data/lang-meta.json';
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
                $meta = loadLangMeta($langMetaPath);
                $meta[$code] = ['sha' => $sha, 'updated' => date('c')];
                file_put_contents($langMetaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $langError = 'Invalid CSRF token';
            } else {
                if (isset($_POST['save_lang_settings'])) {
                    $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
                    $config['default_lang'] = $defaultLang;
                    $installedLangs = [];
                    foreach (glob(__DIR__ . '/../lang/*.php') as $file) {
                        $installedLangs[] = basename($file, '.php');
                    }
                    $config['available_langs'] = array_values(array_unique($installedLangs));
                    $configContent = "<?php\n";
                    foreach ($config as $key => $value) {
                        if (is_string($value)) {
                            $configContent .= "\$config['$key'] = '" . addslashes($value) . "';\n";
                        } else {
                            $configContent .= "\$config['$key'] = " . var_export($value, true) . ";\n";
                        }
                    }
                    file_put_contents(__DIR__ . '/../config.php', $configContent);
                    $_SESSION['lang_success'] = 'Language settings saved';
                    redirect(url('admin_langs'));
                } elseif (isset($_POST['upload_lang']) && !empty($_FILES['lang_file']['tmp_name'])) {
                    $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                    if ($langCode === '') {
                        $langError = 'Invalid language code';
                    } else {
                        $dest = __DIR__ . '/../lang/'.$langCode.'.php';
                        if (file_exists($dest)) {
                            $langError = 'Language file already exists: '.escape($langCode);
                        } elseif (move_uploaded_file($_FILES['lang_file']['tmp_name'], $dest)) {
                            $_SESSION['lang_success'] = 'Language file uploaded: '.escape($langCode);
                            redirect(url('admin_langs'));
                        } else {
                            $langError = 'Failed to upload language file';
                        }
                    }
                } elseif (isset($_POST['install_github_lang']) || isset($_POST['update_github_lang'])) {
                    $isUpdate = isset($_POST['update_github_lang']);
                    $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                    $downloadUrl = $_POST['download_url'] ?? '';
                    $remoteSha = $_POST['remote_sha'] ?? '';
                    if ($langCode === '' || $downloadUrl === '') {
                        $langError = 'Invalid language code or download URL';
                    } else {
                        $dest = __DIR__ . '/../lang/'.$langCode.'.php';
                        if ($isUpdate && !file_exists($dest)) {
                            $langError = 'Language file not found: '.escape($langCode);
                        } elseif (!$isUpdate && file_exists($dest)) {
                            $langError = 'Language file already exists: '.escape($langCode);
                        } else {
                            $content = @file_get_contents($downloadUrl);
                            if ($content === false) {
                                $langError = 'Failed to download language file';
                            } elseif (file_put_contents($dest, $content) === false) {
                                $langError = 'Failed to save language file';
                            } else {
                                saveLangMeta($langCode, $remoteSha);
                                $_SESSION['lang_success'] = ($isUpdate ? 'Language file updated: ' : 'Language file installed: ') . escape($langCode);
                                redirect(url('admin_langs'));
                            }
                        }
                    }
                } elseif (isset($_POST['delete_lang'])) {
                    $langCode = $_POST['lang_code'] ?? '';
                    $langCode = preg_replace('/[^a-z_]/', '', strtolower($langCode));
                    $dest = __DIR__ . '/../lang/'.$langCode.'.php';
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

        $langFiles = glob(__DIR__ . '/../lang/*.php');
        $langOptions = [];
        foreach ($langFiles as $file) {
            $code = basename($file, '.php');
            $langOptions[] = $code;
        }
        include __DIR__ . '/../views/admin_langs.php';
    }
    elseif ($action === 'admin_diagnostics') {
        if (!is_admin()) {
            die('Admin required');
        }

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

        // Test outbound HTTPS to GitHub (same host used by plugin/theme install)
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

        include __DIR__ . '/../views/admin_diagnostics.php';
    }
    elseif ($action === 'admin_plugins') {
        // Plugin management
        if (!is_admin()) {
            die('Admin required');
        }

        $adminPluginError = '';
        $adminPluginSuccess = '';
        if ($method === 'POST' && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $adminPluginError = 'Invalid CSRF token';
            } else {
                if (isset($_POST['install_plugin']) && !empty($_FILES['plugin_zip']['tmp_name'])) {
                    $tmpPath = $_FILES['plugin_zip']['tmp_name'];
                    $result = $pluginManager->installFromZip($tmpPath);
                    if ($result['success']) {
                        $adminPluginSuccess = $result['message'];
                    } else {
                        $adminPluginError = $result['message'];
                    }
                } elseif (isset($_POST['install_from_catalog'])) {
                    $repo = $_POST['repo'] ?? '';
                    $tag = $_POST['tag'] ?? null;
                    $name = strtolower($_POST['plugin_name'] ?? '');
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
                    } else {
                        $adminPluginError = $result['message'];
                    }
                } elseif (isset($_POST['action'])) {
                    $pluginName = $_POST['plugin_name'] ?? '';
                    if ($_POST['action'] === 'enable') {
                        if ($pluginManager->enable($pluginName)) {
                            $adminPluginSuccess = 'Plugin enabled';
                        } else {
                            $adminPluginError = 'Plugin not found';
                        }
                    } elseif ($_POST['action'] === 'disable') {
                        if ($pluginManager->disable($pluginName)) {
                            $adminPluginSuccess = 'Plugin disabled';
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
            $installedPath = __DIR__.'/../data/installed.json';
            $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
            foreach ($missingPlugins as $removed) {
                $key = strtolower($removed);
                unset($installed['plugins'][$key]);
            }
            file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        include __DIR__ . '/../views/admin_plugins.php';
    }
    elseif ($action === 'admin_themes') {
        // Theme management
        if (!is_admin()) {
            die('Admin required');
        }

        $adminThemeError = '';
        $adminThemeSuccess = '';
        if ($method === 'POST' && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
                    } else {
                        $adminThemeError = 'Theme not found';
                    }
                } elseif (isset($_POST['delete_theme'])) {
                    $themeName = $_POST['theme_name'] ?? '';
                    $result = $themeManager->delete($themeName);
                    if ($result['success']) {
                        $adminThemeSuccess = $result['message'];
                    } else {
                        $adminThemeError = $result['message'];
                    }
                }
            }
        }

        $allThemes = $themeManager->getAll();
        $missingThemes = $themeManager->removeMissing();
        if (!empty($missingThemes)) {
            $installedPath = __DIR__.'/../data/installed.json';
            $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
            foreach ($missingThemes as $removed) {
                $key = strtolower($removed);
                unset($installed['themes'][$key]);
            }
            file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        include __DIR__ . '/../views/admin_themes.php';
    }
    elseif ($action === 'admin_catalog') {
        if (!is_admin()) {
            die('Admin required');
        }

        $adminCatalogError = '';
        $adminCatalogSuccess = '';
        if ($method === 'POST' && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $adminCatalogError = 'Invalid CSRF token';
            } elseif (isset($_POST['uninstall_from_catalog'])) {
                $name = strtolower(trim($_POST['name'] ?? ''));
                $type = strtolower(trim($_POST['type'] ?? ''));
                if ($name === '' || !in_array($type, ['plugin', 'theme'])) {
                    $adminCatalogError = 'Invalid request';
            } else {
                $baseDir = $type === 'plugin' ? __DIR__ . '/../plugins' : __DIR__ . '/../themes';
                $target = $baseDir.'/'.$name;
                if (is_dir($target)) {
                    require_once __DIR__.'/lib/PluginManager.php';
                    require_once __DIR__.'/lib/ThemeManager.php';
                    if ($type === 'plugin') {
                        $pm = new PluginManager(__DIR__ . '/../plugins', __DIR__ . '/../data/plugins.json');
                        $pm->delete($name);
                    } else {
                        $tm = new ThemeManager(__DIR__ . '/../themes', __DIR__ . '/../data/themes.json', 'freshbored');
                        $tm->delete($name);
                    }
                }
                $installedPath = __DIR__.'/../data/installed.json';
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
                        $pluginManager = new PluginManager(__DIR__ . '/../plugins', __DIR__ . '/../data/plugins.json');
                        $result = $pluginManager->installFromRepo($repo, $tag, $name);
                    } else {
                        $themeManager = new ThemeManager(__DIR__ . '/../themes', __DIR__ . '/../data/themes.json', 'freshbored');
                        $result = $themeManager->installFromRepo($repo, $tag, $name);
                    }
                    if ($result['success']) {
                        $installedPath = __DIR__.'/../data/installed.json';
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

        $catalog = json_decode(file_get_contents(__DIR__.'/../data/catalog.json'), true) ?: [];
        $installed = json_decode(file_get_contents(__DIR__.'/../data/installed.json'), true) ?: ['plugins'=>[], 'themes'=>[]];
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
            $baseDir = $type === 'plugin' ? __DIR__ . '/../plugins' : __DIR__ . '/../themes';
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
                $baseDir = $group === 'plugins' ? __DIR__ . '/../plugins' : __DIR__ . '/../themes';
                $requiredFile = $group === 'plugins' ? '/manifest.json' : '/style.css';
                if (!is_dir($baseDir.'/'.$name) || !file_exists($baseDir.'/'.$name.$requiredFile)) {
                    unset($installed[$group][$name]);
                }
            }
        }
        file_put_contents(__DIR__.'/../data/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $catalogRemoteVersions = [];
        foreach ($catalog as $item) {
            $name = strtolower($item['name'] ?? '');
            $type = strtolower($item['type'] ?? '');
            $repo = $item['repo'] ?? '';
            $catalogRemoteVersions[$name] = $updateManager->getRemoteVersion($type, $name, $repo);
        }

        include __DIR__ . '/../views/admin_catalog.php';
    }
    elseif ($action === 'admin_updates') {
        // Update management
        if (!is_admin()) {
            die('Admin required');
        }

        $updateResults = null;
        $updateError = '';
        $updateSuccess = '';

        if ($method === 'POST' && isset($_POST['check_updates'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                $updateError = 'Invalid CSRF token';
            } else {
                $catalogPath = __DIR__.'/../data/catalog.json';
                $catalog = file_exists($catalogPath) ? json_decode(file_get_contents($catalogPath), true) : [];
                $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager, $catalog);
            }
        }

        if ($method === 'POST' && isset($_POST['apply_update'])) {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
                    } else {
                        $updateError = 'Failed to update core';
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
                        if ($type === 'plugins' && $pluginManager) {
                            $pluginManager->discover();
                        } elseif ($type === 'themes' && $themeManager) {
                            $themeManager->discover();
                        }
                    } else {
                        $updateError = 'Failed to update extension';
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
        include __DIR__ . '/../views/admin_updates.php';
    }
    elseif ($action === 'forgot_password') {
        // Show forgot password form / handle request
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }

            // Rate limit: 5 requests / hour per IP (prevents reset-email bombing).
            if (!rate_limit('forgot_password', 5, 3600)) {
                http_response_code(429);
                die('Too many requests. Please try again later.');
            }

            $email = validate_input($_POST['email'] ?? '');
            $userStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $userStmt->execute([$email]);
            $user = $userStmt->fetch();
            
            if ($user) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
                    ->execute([$user['id'], password_hash($token, PASSWORD_DEFAULT), $expires]);
                
                // Send email
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
            
            // Always show success (don't reveal if email exists)
            $success = 'If an account with that email exists, a password reset link has been sent.';
        }
        include __DIR__ . '/../views/forgot_password.php';
    }
    elseif ($action === 'reset_password') {
        // Show reset password form / handle reset
        if ($method === 'POST') {
            if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
                die('CSRF token invalid');
            }

            // Rate limit: 10 reset attempts / hour per IP (token brute-force protection).
            if (!rate_limit('reset_password', 10, 3600)) {
                http_response_code(429);
                die('Too many attempts. Please try again later.');
            }

            $token = $_POST['token'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            
            if ($password !== $confirm) {
                $error = 'Passwords do not match.';
                include __DIR__ . '/../views/reset_password.php';
                exit;
            }
            if (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
                include __DIR__ . '/../views/reset_password.php';
                exit;
            }
            
            // Find valid token
            $tokensStmt = $pdo->prepare("SELECT * FROM password_resets WHERE used = 0 AND expires_at > CURRENT_TIMESTAMP ORDER BY created_at DESC");
            $tokensStmt->execute();
            $validToken = null;
            foreach ($tokensStmt->fetchAll() as $row) {
                if (password_verify($token, $row['token'])) {
                    $validToken = $row;
                    break;
                }
            }
            
            if (!$validToken) {
                $error = 'Invalid or expired reset token.';
                include __DIR__ . '/../views/reset_password.php';
                exit;
            }
            
            // Update password
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $validToken['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$validToken['id']]);
            
            // Send confirmation email
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
            
            redirect(url('login'));
        }
        if (!isset($_GET['token'])) {
            http_response_code(404);
            echo 'Page not found';
            exit;
        }
        include __DIR__ . '/../views/reset_password.php';
    }
    elseif ($action === 'editbored_upload' && $method === 'POST') {
        // Image upload for editbored editor
        if (!is_logged_in()) {
            http_response_code(403);
            echo json_encode(['error' => 'Login required']);
            exit;
        }
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
        if (empty($_FILES['editbored_image']['tmp_name']) || $_FILES['editbored_image']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No image uploaded']);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['editbored_image']['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type']);
            exit;
        }
        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $safeName = 'editbored_' . $_SESSION['user_id'] . '_' . uniqid() . '.' . $ext;
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        if (move_uploaded_file($_FILES['editbored_image']['tmp_name'], $uploadDir . $safeName)) {
            $url = base_url() . '/uploads/' . rawurlencode($safeName);
            echo json_encode(['url' => $url, 'filename' => $safeName]);
            exit;
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }
    elseif ($action === 'notifications') {
        // Notification center
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST' && validate_csrf_token($_POST['csrf_token'] ?? '')) {
            if (isset($_POST['do']) && $_POST['do'] === 'mark_read' && isset($_GET['id'])) {
                $id = (int)$_GET['id'];
                if ($id > 0) {
                    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                        ->execute([$id, $_SESSION['user_id']]);
                }
            }
            if (isset($_POST['do']) && $_POST['do'] === 'mark_all_read') {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
                    ->execute([$_SESSION['user_id']]);
            }
            redirect(url('notifications'));
        }
        $notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
        $notifications->execute([$_SESSION['user_id']]);
        $notifications = $notifications->fetchAll();
        include __DIR__ . '/../views/notifications.php';
    }
    elseif ($action === 'messages') {
        // Private messages center
        if (!is_logged_in()) {
            die('Login required');
        }
        if ($method === 'POST' && isset($_POST['content']) && validate_csrf_token($_POST['csrf_token'] ?? '')) {
            $recipientId = (int)($_GET['conversation'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            if ($recipientId > 0 && $content !== '') {
                $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (?, ?, '', ?)");
                $stmt->execute([$_SESSION['user_id'], $recipientId, $content]);
            }
            redirect(url('messages', ['conversation' => $recipientId]));
        }
        $conversationUserId = (int)($_GET['conversation'] ?? 0);
        if ($conversationUserId > 0) {
            $messages = $pdo->prepare("
                SELECT pm.*, u.username as sender_name
                FROM private_messages pm
                JOIN users u ON pm.sender_id = u.id
                WHERE (pm.sender_id = :me AND pm.recipient_id = :other) OR (pm.sender_id = :other AND pm.recipient_id = :me)
                ORDER BY pm.created_at ASC
            ");
            $messages->execute(['me' => $_SESSION['user_id'], 'other' => $conversationUserId]);
            $messages = $messages->fetchAll();

            $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE recipient_id = :me AND sender_id = :other AND is_read = 0")
                ->execute(['me' => $_SESSION['user_id'], 'other' => $conversationUserId]);

            $otherUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $otherUser->execute([$conversationUserId]);
            $otherUsername = $otherUser->fetchColumn();

            include __DIR__ . '/../views/messages.php';
        } else {
            $conversations = $pdo->prepare("
                SELECT
                    CASE WHEN sender_id = :uid THEN recipient_id ELSE sender_id END as other_user_id,
                    MAX(created_at) as last_message_at,
                    MAX(is_read) as last_read,
                    (SELECT content FROM private_messages pm2
                     WHERE ((pm2.sender_id = :uid AND pm2.recipient_id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END)
                         OR (pm2.recipient_id = :uid AND pm2.sender_id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END))
                     ORDER BY pm2.created_at DESC LIMIT 1) as last_message,
                    (SELECT username FROM users u WHERE u.id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END) as other_username,
                    SUM(CASE WHEN recipient_id = :uid AND is_read = 0 THEN 1 ELSE 0 END) as unread_count
                FROM private_messages pm
                WHERE sender_id = :uid OR recipient_id = :uid
                GROUP BY other_user_id
                ORDER BY last_message_at DESC
            ");
            $conversations->execute(['uid' => $_SESSION['user_id']]);
            $conversations = $conversations->fetchAll();
            include __DIR__ . '/../views/messages.php';
        }
    }
    else {
        // Not found
        http_response_code(404);
        echo 'Page not found';
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo 'An error occurred. Please try again later.';
}
?>
