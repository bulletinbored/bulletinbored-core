<?php
session_name('BBSESSID');
$sessionDir = realpath(__DIR__ . '/data/sessions') ?: (__DIR__ . '/data/sessions');
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
session_start();

require_once __DIR__ . '/src/csp.php';
$cspNonce = generate_csp_nonce();
send_security_headers($cspNonce);

function is_installed() {
    $configPath = __DIR__ . '/config.json';
    $legacyPath = __DIR__ . '/config.php';
    if (!file_exists($configPath) && !file_exists($legacyPath)) {
        return false;
    }
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
    } else {
        @include $legacyPath;
    }
    if (empty($config['db_driver'] ?? '')) {
        return false;
    }
    try {
        if (($config['db_driver'] ?? 'sqlite') === 'mysql') {
            $pdo = new PDO(
                "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
                $config['db_user'],
                $config['db_pass']
            );
        } else {
            $pdo = new PDO('sqlite:' . ($config['db_path'] ?? __DIR__ . '/data/database.sqlite'));
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

if (is_installed()) {
    log_security_event('installer_access_denied', ['script' => 'install2.php']);
    http_response_code(403);
    die('<h1>Already Installed</h1><p>bulletinbored is already installed. Delete <code>config.json</code> to reinstall.</p>');
}

if (empty($_SESSION['install_db_driver'])) {
    header('Location: install.php');
    exit;
}

function escape($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$error = '';
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
    } elseif (strlen($adminPass) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (file_exists(__DIR__ . '/config.json')) {
        $error = 'Configuration file already exists. Remove it to reinstall.';
    } else {
        $_SESSION['install_site_name'] = $siteName;
        $_SESSION['install_admin_user'] = $adminUser;
        $_SESSION['install_admin_pass'] = $adminPass;
        $_SESSION['install_admin_email'] = $adminEmail;

        session_regenerate_id(true);
        header('Location: install3.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - bulletinbored</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/favicon.svg', ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --fb-brand: #550296;
            --fb-brand-dark: #7a04d4;
            --fb-brand-soft: #f3e5f5;
            --fb-bg: #f6f7f9;
            --fb-surface: #ffffff;
            --fb-border: #e2e2f0;
            --fb-border-soft: #f0f1f4;
            --fb-text: #1a1a2e;
            --fb-muted: #6b6b8a;
            --fb-muted-soft: #9b9bb8;
            --fb-radius: 12px;
            --fb-radius-sm: 8px;
            --fb-shadow: 0 1px 2px rgba(16, 24, 40, .05);
            --fb-shadow-lg: 0 8px 24px rgba(16, 24, 40, .08);
        }

        body {
            background-color: var(--fb-bg);
            color: var(--fb-text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
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
            display: none;
        }

        .installer-logo::before {
            content: '▦';
            font-size: 2.5rem;
            color: var(--fb-brand);
            line-height: 1;
        }

        .installer-logo span {
            display: block;
            margin-top: 0.75rem;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--fb-text);
            letter-spacing: -0.02em;
        }

        .installer-logo span b {
            color: var(--fb-brand);
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
            font-weight: 800;
            margin-bottom: 0.35rem;
            text-align: center;
            letter-spacing: -0.02em;
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
            border-color: #550296;
            box-shadow: 0 0 0 3px rgba(85, 2, 150, .1);
            background: var(--fb-surface);
        }

        .btn-brand {
            background: linear-gradient(135deg, var(--fb-brand), var(--fb-brand-dark));
            border: 1px solid transparent;
            color: #fff;
            font-weight: 600;
            border-radius: var(--fb-radius);
            padding: 0.65rem 1.5rem;
            box-shadow: 0 4px 16px rgba(85, 2, 150, 0.3);
        }

        .btn-brand:hover, .btn-brand:focus {
            background: linear-gradient(135deg, var(--fb-brand), #9a3bf6);
            border-color: transparent;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(85, 2, 150, 0.4);
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
            background-color: transparent;
            border-color: #550296;
            color: #550296;
            transform: translateY(-2px);
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

        .text-muted small {
            color: var(--fb-muted-soft);
        }
    </style>
</head>
<body>
    <div class="installer-wrap">
            <div class="installer-logo">
                <span>bulletin<b>bored</b></span>
            </div>

        <div class="installer-card">
            <div class="installer-steps">
                <div class="step completed">1</div>
                <div class="step-line"></div>
                <div class="step active">2</div>
                <div class="step-line"></div>
                <div class="step">3</div>
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
                     <div class="text-muted small mt-1">Minimum 12 characters. A passphrase is recommended.</div>
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
                        Next<i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
