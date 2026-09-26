<?php
session_name('BBSESSID');
$sessionDir = realpath(__DIR__ . '/data/sessions') ?: (__DIR__ . '/data/sessions');
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
session_start();

date_default_timezone_set('UTC');

require_once __DIR__ . '/src/csp.php';
require_once __DIR__ . '/src/Security.php';
$cspNonce = generate_csp_nonce();
send_security_headers($cspNonce);
$installerCsrf = generate_csrf_token();

function is_installed() {
    $configPath = __DIR__ . '/config.json';
    $legacyPath = __DIR__ . '/config.php';
    if (!file_exists($configPath) && !file_exists($legacyPath)) {
        return false;
    }
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
        if (!is_array($config)) { $config = []; }
    } else {
        @include $legacyPath;
        if (!is_array($config)) { $config = []; }
    }
    // A config file with a database driver means the installation completed.
    // Fail closed: never allow a re-install just because the database is
    // temporarily unreachable or the users table is missing.
    return !empty($config['db_driver']);
}

function escape($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Resolve a user-supplied SQLite path and confine it to the application
 * directory. Prevents the (pre-auth) installer from being used to create
 * files anywhere the web user can write.
 *
 * This normalises the nominal path (resolving "." / "..") and additionally
 * resolves the nearest existing ancestor so a symlinked directory that points
 * outside the application tree is rejected. The check is best-effort: it can
 * only resolve ancestors that already exist on disk.
 */
function resolve_sqlite_path(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '' || strpos($path, "\0") !== false) {
        return null;
    }
    $path = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
    $isAbsolute = ($path[0] === '/') || (bool)preg_match('#^[A-Za-z]:/#', $path);
    if (!$isAbsolute) {
        $path = $root . '/' . ltrim($path, '/');
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $normalized = implode('/', $segments);
    $rootNorm = implode('/', array_filter(explode('/', $root), fn($s) => $s !== ''));
    if ($normalized !== $rootNorm && strncmp($normalized, $rootNorm . '/', strlen($rootNorm) + 1) !== 0) {
        return null;
    }

    // Symlink guard: resolve the nearest existing ancestor and make sure it
    // still lives inside the application root.
    $existing = $normalized;
    while ($existing !== '' && !file_exists($existing)) {
        $parent = dirname($existing);
        if ($parent === $existing) {
            break;
        }
        $existing = $parent;
    }
    $realRoot = realpath(__DIR__);
    $realExisting = $existing !== '' ? realpath($existing) : false;
    if ($realRoot !== false && $realExisting !== false) {
        $realRoot = str_replace('\\', '/', $realRoot);
        $realExisting = str_replace('\\', '/', $realExisting);
        if ($realExisting !== $realRoot && strpos($realExisting, $realRoot . '/') !== 0) {
            return null;
        }
    }

    return $normalized;
}

if (is_installed()) {
    log_security_event('installer_access_denied', ['script' => 'install.php']);
    http_response_code(403);
    die('<h1>Already Installed</h1><p>bulletinbored is already installed. Delete <code>config.json</code> to reinstall.</p>');
}

$error = '';
$success = '';
$dbDriver = $_POST['db_driver'] ?? 'sqlite';
$dbHost = $_POST['db_host'] ?? 'localhost';
$dbName = $_POST['db_name'] ?? 'forum';
$dbUser = $_POST['db_user'] ?? 'root';
$dbPass = $_POST['db_pass'] ?? '';
$dbPath = $_POST['db_path'] ?? __DIR__ . '/data/database.sqlite';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid security token. Please reload the page and try again.';
    }

    if ($error === '' && $dbDriver !== 'mysql') {
        $resolvedPath = resolve_sqlite_path($dbPath);
        if ($resolvedPath === null) {
            $error = 'Invalid database path: it must point inside the application directory.';
        } else {
            $dbPath = $resolvedPath;
        }
    }

    if ($error === '' && isset($_POST['test_connection'])) {
        try {
            if ($dbDriver === 'mysql') {
                $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                $success = 'Connection successful!';
            } else {
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $success = 'Connection successful!';
            }
        } catch (PDOException $e) {
            $error = 'Connection failed. Please check the database settings and try again.';
        }
    }

    if ($error === '' && isset($_POST['next'])) {
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

            $_SESSION['install_db_driver'] = $dbDriver;
            $_SESSION['install_db_host'] = $dbHost;
            $_SESSION['install_db_name'] = $dbName;
            $_SESSION['install_db_user'] = $dbUser;
            $_SESSION['install_db_pass'] = $dbPass;
            $_SESSION['install_db_path'] = $dbPath;

            session_regenerate_id(true);
            header('Location: install2.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Connection failed. Please check the database settings and try again.';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/favicon.svg', ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
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
            max-width: 560px;
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

        .driver-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .driver-card {
            cursor: pointer;
        }

        .driver-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .driver-card-content {
            border: 2px solid var(--fb-border);
            border-radius: var(--fb-radius);
            padding: 1.5rem 1rem;
            text-align: center;
            transition: all 0.2s ease;
            height: 100%;
        }

        .driver-card input:checked + .driver-card-content {
            border-color: #550296;
            background: #f3e5f5;
            box-shadow: 0 0 0 4px rgba(85, 2, 150, .08);
        }

        .driver-card-content i {
            font-size: 1.75rem;
            color: #550296;
            margin-bottom: 0.6rem;
            display: block;
        }

        .driver-card-content strong {
            display: block;
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 0.35rem;
            color: var(--fb-text);
        }

        .driver-card-content span {
            font-size: 0.8rem;
            color: var(--fb-muted);
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
            padding: 0.6rem 1.25rem;
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

        .installer-foot a {
            color: var(--fb-muted);
            font-size: 0.85rem;
            text-decoration: none;
        }

        .installer-foot a:hover {
            color: var(--fb-brand);
        }

        .alert {
            border-radius: var(--fb-radius-sm);
            font-size: 0.9rem;
            border: none;
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
                <div class="step active">1</div>
                <div class="step-line"></div>
                <div class="step">2</div>
                <div class="step-line"></div>
                <div class="step">3</div>
            </div>

            <h1 class="installer-title">Database Setup</h1>
            <p class="installer-subtitle">Choose how you want to store your data.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape($success) ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escape($installerCsrf) ?>">
                <div class="driver-cards">
                    <label class="driver-card">
                        <input type="radio" name="db_driver" value="sqlite" <?= $dbDriver === 'sqlite' ? 'checked' : '' ?>>
                        <div class="driver-card-content">
                            <i class="fas fa-file-code"></i>
                            <strong>SQLite</strong>
                            <span>File-based, no setup required</span>
                        </div>
                    </label>
                    <label class="driver-card">
                        <input type="radio" name="db_driver" value="mysql" <?= $dbDriver === 'mysql' ? 'checked' : '' ?>>
                        <div class="driver-card-content">
                            <i class="fas fa-database"></i>
                            <strong>MySQL</strong>
                            <span>Remote database server</span>
                        </div>
                    </label>
                </div>

                <div id="sqlite-fields">
                    <div class="mb-3">
                        <label class="form-label" for="db_path">Database Path</label>
                        <input type="text" class="form-control" id="db_path" name="db_path" value="<?= escape($dbPath) ?>">
                        <div class="text-muted small mt-1">Path to the SQLite database file. The <code>data/</code> directory will be created if it doesn't exist.</div>
                    </div>
                </div>

                <div id="mysql-fields" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label" for="db_host">Database Host</label>
                        <input type="text" class="form-control" id="db_host" name="db_host" value="<?= escape($dbHost) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="db_name">Database Name</label>
                        <input type="text" class="form-control" id="db_name" name="db_name" value="<?= escape($dbName) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="db_user">Username</label>
                        <input type="text" class="form-control" id="db_user" name="db_user" value="<?= escape($dbUser) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="db_pass">Password</label>
                        <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?= escape($dbPass) ?>">
                    </div>
                </div>

                <div class="installer-foot">
                    <div></div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="test_connection" class="btn btn-outline-soft">
                            <i class="fas fa-plug me-2"></i>Test Connection
                        </button>
                        <button type="submit" name="next" class="btn btn-brand">
                            Next<i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
        const driverInputs = document.querySelectorAll('input[name="db_driver"]');
        const sqliteFields = document.getElementById('sqlite-fields');
        const mysqlFields = document.getElementById('mysql-fields');

        function toggleFields() {
            const driver = document.querySelector('input[name="db_driver"]:checked').value;
            if (driver === 'mysql') {
                sqliteFields.style.display = 'none';
                mysqlFields.style.display = 'block';
            } else {
                sqliteFields.style.display = 'block';
                mysqlFields.style.display = 'none';
            }
        }

        driverInputs.forEach(input => input.addEventListener('change', toggleFields));
        toggleFields();
    </script>
</body>
</html>
