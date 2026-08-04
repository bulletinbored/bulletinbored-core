<?php
session_start();

function escape($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['install_db_driver'])) {
    header('Location: install.php');
    exit;
}

$error = '';
$success = '';
$installed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName = trim($_POST['site_name'] ?? '');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';
    $adminEmail = trim($_POST['admin_email'] ?? '');

    if (empty($siteName) || empty($adminUser) || empty($adminPass) || empty($adminEmail)) {
        $error = 'All fields are required.';
    } elseif ($adminPass !== $adminPassConfirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($adminPass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (file_exists(__DIR__ . '/config.php')) {
        $error = 'Configuration file already exists. Remove it to reinstall.';
    } else {
        $dbDriver = $_SESSION['install_db_driver'];
        $dbHost = $_SESSION['install_db_host'] ?? 'localhost';
        $dbName = $_SESSION['install_db_name'] ?? 'forum';
        $dbUser = $_SESSION['install_db_user'] ?? 'root';
        $dbPass = $_SESSION['install_db_pass'] ?? '';
        $dbPath = $_SESSION['install_db_path'] ?? __DIR__ . '/data/database.sqlite';

        try {
            if ($dbDriver === 'mysql') {
                $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            } else {
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            if ($dbDriver === 'mysql') {
                $tables = [
                    "users" => "id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255), role VARCHAR(50) DEFAULT 'user', avatar VARCHAR(255), status VARCHAR(50) DEFAULT 'active', email_verified INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "categories" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, position INT DEFAULT 0",
                    "threads" => "id INT AUTO_INCREMENT PRIMARY KEY, category_id INT, user_id INT, title TEXT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "posts" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, content TEXT, status VARCHAR(50) DEFAULT 'visible', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "uploads" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, post_id INT, user_id INT, filename VARCHAR(255), original_name VARCHAR(255), size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "thread_watchers" => "id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_watch (thread_id, user_id)",
                    "notifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) DEFAULT 'info', title TEXT NOT NULL, message TEXT, link TEXT, read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "private_messages" => "id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL, subject TEXT DEFAULT '', content TEXT NOT NULL, read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "roles" => "id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, permissions TEXT DEFAULT '[]', created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "email_verifications" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token TEXT NOT NULL, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
                    "password_resets" => "id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token TEXT NOT NULL, expires_at DATETIME NOT NULL, used INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP"
                ];

                foreach ($tables as $name => $schema) {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($schema)");
                }

                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'");
                if ($stmt->fetchColumn() == 0) {
                    $pdo->prepare("INSERT INTO users (username, password, role, email) VALUES (?, ?, 'admin', ?)")
                        ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail]);
                }

                $defaultRoles = [
                    ['admin', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads', 'can_ban_users', 'can_manage_roles'])],
                    ['moderator', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads'])],
                    ['user', json_encode(['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts'])],
                ];
                foreach ($defaultRoles as $role) {
                    $pdo->prepare("INSERT IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute($role);
                }

                $pdo->prepare("INSERT IGNORE INTO categories (name, description, position) VALUES ('General', 'General discussion', 1)")->execute();
            } else {
                $pdo->exec("
                    CREATE TABLE users (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        username TEXT UNIQUE NOT NULL,
                        password TEXT NOT NULL,
                        email TEXT,
                        role TEXT DEFAULT 'user',
                        avatar TEXT,
                        status TEXT DEFAULT 'active',
                        email_verified INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE categories (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        description TEXT,
                        position INTEGER DEFAULT 0
                    );
                    CREATE TABLE threads (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        category_id INTEGER,
                        user_id INTEGER,
                        title TEXT NOT NULL,
                        content TEXT,
                        status TEXT DEFAULT 'visible',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE posts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        thread_id INTEGER,
                        user_id INTEGER,
                        content TEXT NOT NULL,
                        status TEXT DEFAULT 'visible',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE uploads (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        thread_id INTEGER,
                        post_id INTEGER,
                        user_id INTEGER,
                        filename TEXT,
                        original_name TEXT,
                        size INTEGER,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE thread_watchers (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        thread_id INTEGER NOT NULL,
                        user_id INTEGER NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE(thread_id, user_id)
                    );
                    CREATE TABLE notifications (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        type VARCHAR(50) DEFAULT 'info',
                        title TEXT NOT NULL,
                        message TEXT,
                        link TEXT,
                        read INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE private_messages (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        sender_id INTEGER NOT NULL,
                        recipient_id INTEGER NOT NULL,
                        subject TEXT DEFAULT '',
                        content TEXT NOT NULL,
                        read INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE roles (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL UNIQUE,
                        permissions TEXT DEFAULT '[]',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE email_verifications (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        token TEXT NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE password_resets (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        token TEXT NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                ");

                $pdo->prepare("INSERT INTO users (username, password, role, email) VALUES (?, ?, 'admin', ?)")
                    ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail]);

                $defaultRoles = [
                    ['admin', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads', 'can_ban_users', 'can_manage_roles'])],
                    ['moderator', json_encode(['can_approve_threads', 'can_delete_threads', 'can_delete_posts', 'can_lock_threads', 'can_sticky_threads', 'can_edit_posts', 'can_edit_threads'])],
                    ['user', json_encode(['can_create_threads', 'can_create_posts', 'can_edit_own_posts', 'can_delete_own_posts'])],
                ];
                foreach ($defaultRoles as $role) {
                    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute($role);
                }

                $pdo->prepare("INSERT OR IGNORE INTO categories (name, description, position) VALUES ('General', 'General discussion', 1)")->execute();
            }

            $version = trim(file_get_contents(__DIR__ . '/VERSION'));

            foreach (['data', 'plugins', 'uploads', 'uploads/avatars'] as $d) {
                $dir = __DIR__ . '/' . $d;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            $configContent = "<?php\n";
            $configContent .= '$config[\'db_driver\'] = \'' . $dbDriver . "';\n";
            if ($dbDriver === 'sqlite') {
                $dbPath = $_SESSION['install_db_path'];
                $normalizedDir = str_replace('\\', '/', __DIR__);
                $normalizedPath = str_replace('\\', '/', $dbPath);
                if (!str_starts_with($normalizedPath, $normalizedDir . '/')) {
                    if (!preg_match('~^[a-zA-Z]:/|^/~', $normalizedPath)) {
                        $dbPath = __DIR__ . '/' . ltrim($dbPath, '/\\');
                    }
                }
                $relPath = str_replace(__DIR__, '', $dbPath);
                $configContent .= '$config[\'db_path\'] = __DIR__ . \'' . $relPath . "';\n";
            } else {
                $configContent .= '$config[\'db_path\'] = __DIR__ . \'/data/database.sqlite\';\n';
            }
            $configContent .= '$config[\'db_host\'] = \'' . str_replace("'", "\\'", $dbHost) . "';\n";
            $configContent .= '$config[\'db_name\'] = \'' . str_replace("'", "\\'", $dbName) . "';\n";
            $configContent .= '$config[\'db_user\'] = \'' . str_replace("'", "\\'", $dbUser) . "';\n";
            $configContent .= '$config[\'db_pass\'] = \'' . str_replace("'", "\\'", $dbPass) . "';\n";
            $configContent .= '$config[\'site_name\'] = \'' . str_replace("'", "\\'", $siteName) . "';\n";
            $configContent .= '$config[\'admin_user\'] = \'' . str_replace("'", "\\'", $adminUser) . "';\n";
            $configContent .= '$config[\'admin_pass\'] = \'' . str_replace("'", "\\'", $adminPass) . "';\n";
            $configContent .= '$config[\'mail_from\'] = \'' . str_replace("'", "\\'", $adminEmail) . "';\n";
            $configContent .= '$config[\'mail_from_name\'] = \'' . str_replace("'", "\\'", $siteName) . "';\n";
            $configContent .= '$config[\'mail_method\'] = \'mail\';\n';
            $configContent .= '$config[\'theme\'] = \'freshbored\';\n';
            $configContent .= '$config[\'default_lang\'] = \'en\';\n';
            $configContent .= '$config[\'available_langs\'] = array (\n  0 => \'en\',\n);\n';
            $configContent .= '$config[\'avatar_max_size\'] = 2097152;\n';
            $configContent .= '$config[\'avatar_allowed_types\'] = array (\n  0 => \'image/jpeg\',\n  1 => \'image/png\',\n  2 => \'image/gif\',\n  3 => \'image/webp\',\n);\n';
            $configContent .= '$config[\'base_url\'] = \'\';\n';
            $configContent .= '$config[\'allow_registration\'] = 0;\n';
            $configContent .= '$config[\'maintenance_mode\'] = 0;\n';
            $configContent .= '$config[\'site_tagline\'] = \'\';\n';
            $configContent .= '$config[\'site_icon\'] = \'\';\n';
            $configContent .= '$config[\'timezone\'] = \'UTC\';\n';
            $configContent .= '$config[\'date_format\'] = \'Y-m-d\';\n';
            $configContent .= '$config[\'time_format\'] = \'H:i\';\n';
            $configContent .= '$config[\'version\'] = trim(file_get_contents(__DIR__.\'/VERSION\'));\n';
            $configContent .= '$config[\'plugin_manifest\'] = __DIR__.\'/data/plugins.json\';\n';
            $configContent .= '$config[\'theme_manifest\'] = __DIR__.\'/data/themes.json\';\n';
            $configContent .= '$config[\'update_manifest\'] = __DIR__.\'/data/updates.json\';\n';
            $configContent .= '$config[\'update_server\'] = \'\';\n';

            file_put_contents(__DIR__ . '/config.php', $configContent, LOCK_EX);

            $installed = true;
            $success = 'Installation completed successfully! Your forum is ready.';
            session_destroy();
        } catch (PDOException $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - bulletinbored</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --fb-brand: #3d5afe;
            --fb-brand-dark: #2f46d1;
            --fb-brand-soft: #eef1ff;
            --fb-bg: #f6f7f9;
            --fb-surface: #ffffff;
            --fb-border: #e6e8ec;
            --fb-border-soft: #f0f1f4;
            --fb-text: #1f2430;
            --fb-muted: #6b7280;
            --fb-muted-soft: #9aa1ad;
            --fb-radius: 12px;
            --fb-radius-sm: 8px;
            --fb-shadow: 0 1px 2px rgba(16, 24, 40, .05);
            --fb-shadow-lg: 0 8px 24px rgba(16, 24, 40, .08);
        }

        body {
            background-color: var(--fb-bg);
            color: var(--fb-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .installer-wrap {
            width: 100%;
            max-width: 480px;
        }

        .installer-logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .installer-logo i {
            font-size: 2.5rem;
            color: var(--fb-brand);
            background: var(--fb-brand-soft);
            padding: 1.1rem;
            border-radius: 18px;
        }

        .installer-logo span {
            display: block;
            margin-top: 0.75rem;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--fb-text);
            letter-spacing: -0.01em;
        }

        .installer-card {
            background: var(--fb-surface);
            border: 1px solid var(--fb-border);
            border-radius: var(--fb-radius);
            padding: 2rem;
            box-shadow: var(--fb-shadow-lg);
        }

        .installer-steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1.75rem;
        }

        .step {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 700;
            background: var(--fb-border-soft);
            color: var(--fb-muted);
        }

        .step.active {
            background: var(--fb-brand);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(61, 90, 254, .15);
        }

        .step.completed {
            background: #22c55e;
            color: #fff;
        }

        .step-line {
            flex: 1;
            height: 2px;
            background: var(--fb-border);
            max-width: 80px;
        }

        .installer-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.35rem;
            text-align: center;
        }

        .installer-subtitle {
            color: var(--fb-muted);
            font-size: 0.95rem;
            margin-bottom: 1.75rem;
            text-align: center;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--fb-text);
            margin-bottom: 0.4rem;
        }

        .form-control {
            border: 1px solid var(--fb-border);
            border-radius: var(--fb-radius-sm);
            padding: 0.6rem 0.85rem;
            font-size: 0.95rem;
            color: var(--fb-text);
            background: var(--fb-surface);
        }

        .form-control:focus {
            border-color: var(--fb-brand);
            box-shadow: 0 0 0 3px rgba(61, 90, 254, .1);
            background: var(--fb-surface);
        }

        .btn-brand {
            background-color: var(--fb-brand);
            border: 1px solid var(--fb-brand);
            color: #fff;
            font-weight: 600;
            border-radius: var(--fb-radius-sm);
            padding: 0.65rem 1.5rem;
        }

        .btn-brand:hover, .btn-brand:focus {
            background-color: var(--fb-brand-dark);
            border-color: var(--fb-brand-dark);
            color: #fff;
        }

        .btn-outline-soft {
            background-color: var(--fb-surface);
            border: 1px solid var(--fb-border);
            color: var(--fb-text);
            font-weight: 600;
            border-radius: var(--fb-radius-sm);
            padding: 0.65rem 1.5rem;
            text-decoration: none;
        }

        .btn-outline-soft:hover {
            background-color: var(--fb-brand-soft);
            border-color: var(--fb-brand);
            color: var(--fb-brand-dark);
        }

        .installer-foot {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--fb-border-soft);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert {
            border-radius: var(--fb-radius-sm);
            font-size: 0.9rem;
            border: none;
            margin-bottom: 1.25rem;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
        }

        .success-icon {
            text-align: center;
            font-size: 3.5rem;
            color: #22c55e;
            margin-bottom: 1rem;
        }

        .success-title {
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .success-text {
            color: var(--fb-muted);
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .text-muted small {
            color: var(--fb-muted-soft);
        }
    </style>
</head>
<body>
    <div class="installer-wrap">
        <div class="installer-logo">
            <i class="fas fa-comments"></i>
            <span>bulletinbored</span>
        </div>

        <div class="installer-card">
            <?php if (!$installed): ?>
                <div class="installer-steps">
                    <div class="step completed">1</div>
                    <div class="step-line"></div>
                    <div class="step active">2</div>
                </div>

                <h1 class="installer-title">Site Configuration</h1>
                <p class="installer-subtitle">Set up your forum and administrator account.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label class="form-label" for="site_name">Site Name</label>
                        <input type="text" class="form-control" id="site_name" name="site_name" value="<?= escape($_POST['site_name'] ?? 'My Forum') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_user">Admin Username</label>
                        <input type="text" class="form-control" id="admin_user" name="admin_user" value="<?= escape($_POST['admin_user'] ?? 'admin') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_pass">Admin Password</label>
                        <input type="password" class="form-control" id="admin_pass" name="admin_pass" required>
                        <div class="text-muted small mt-1">Minimum 6 characters.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_pass_confirm">Confirm Password</label>
                        <input type="password" class="form-control" id="admin_pass_confirm" name="admin_pass_confirm" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_email">Admin Email</label>
                        <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?= escape($_POST['admin_email'] ?? '') ?>" required>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <a href="install.php" class="btn btn-outline-soft">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <button type="submit" class="btn btn-brand">
                            <i class="fas fa-rocket me-2"></i>Install Now
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1 class="success-title">Installation Complete</h1>
                <p class="success-text">Your forum is ready. Log in with the administrator account you just created.</p>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape($success) ?></div>
                <a href="<?= htmlspecialchars(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-brand w-100">
                    Go to your forum<i class="fas fa-arrow-right ms-2"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
