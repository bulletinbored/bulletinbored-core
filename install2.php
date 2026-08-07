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
        $_SESSION['install_site_name'] = $siteName;
        $_SESSION['install_admin_user'] = $adminUser;
        $_SESSION['install_admin_pass'] = $adminPass;
        $_SESSION['install_admin_email'] = $adminEmail;

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --fb-brand: #550296;
            --fb-brand-dark: #3d046f;
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
                        Next<i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
