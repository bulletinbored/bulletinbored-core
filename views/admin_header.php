<?php
// Admin header - completely separate from frontend theme.
//
// Helpers below are guarded so the file stays safe if it is included twice.
if (!function_exists('admin_current_section')) {
    /**
     * Resolve the active admin section from the current URL.
     *
     * current_route_action() intentionally returns only the first path segment
     * ("admin" for every admin page), so the sidebar needs its own resolver.
     */
    function admin_current_section(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = ltrim((string)$path, '/');
        $base = parse_url(base_url(), PHP_URL_PATH) ?: '';
        $base = trim((string)$base, '/');
        if ($base !== '') {
            if ($path === $base) {
                $path = '';
            } elseif (str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base) + 1);
            }
        }

        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        if (($segments[0] ?? '') !== 'admin') {
            return '';
        }

        $sub = $segments[1] ?? '';
        $map = [
            ''                   => 'dashboard',
            'moderation'         => 'moderation',
            'users'              => 'users',
            'create-user'        => 'users',
            'roles'              => 'roles',
            'roles-action'       => 'roles',
            'categories'         => 'categories',
            'delete-category'    => 'categories',
            'update-category-order' => 'categories',
            'plugins'            => 'plugins',
            'themes'             => 'themes',
            'langs'              => 'langs',
            'settings'           => 'settings',
            'upload-site-image'  => 'settings',
            'get-images'         => 'settings',
            'smtp'               => 'smtp',
            'updates'            => 'updates',
            'diagnostics'        => 'diagnostics',
            'catalog'            => 'catalog',
        ];

        return $map[$sub] ?? '';
    }
}

if (!function_exists('admin_pending_threads_count')) {
    function admin_pending_threads_count(): int
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM threads WHERE status = 'pending'")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

function render_admin_header($title = 'Admin Panel') {
    global $config, $lang;
    $siteName = $config['site_name'] ?? 'bulletinbored';
    $siteNamePlain = strip_tags($siteName);
    $section = admin_current_section();
    $pendingCount = admin_pending_threads_count();
    $roleKey = 'role_' . ($_SESSION['role'] ?? 'admin');
    $roleLabel = t($roleKey);
    if ($roleLabel === $roleKey) {
        $roleLabel = t('admin');
    }
    $isActive = function (string $key) use ($section): string {
        return $section === $key ? ' active' : '';
    };
    $ariaCurrent = function (string $key) use ($section): string {
        return $section === $key ? ' aria-current="page"' : '';
    };
?>
<!DOCTYPE html>
<html lang="<?= escape($lang ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title) ?> - <?= escape($siteNamePlain) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= base_url() ?>/assets/admin.css" rel="stylesheet">
</head>
<body class="admin-layout">
    <a class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white" href="#main-content">Skip to content</a>
    <div id="wrapper">

        <!-- Sidebar -->
        <div class="sidebar-overlay d-md-none"></div>
        <nav class="sidebar" id="adminSidebar" aria-label="Admin navigation">
            <a class="sidebar-brand" href="<?= url('admin') ?>">
                <span class="brand-icon"><i class="fas fa-cog"></i></span>
                <span class="brand-copy">
                    <span class="brand-name"><?= escape($siteNamePlain) ?></span>
                    <span class="brand-tag"><?= t('admin_panel') ?></span>
                </span>
            </a>
            <button class="sidebar-close d-md-none" type="button" aria-label="<?= t('close') ?>">
                <i class="fas fa-times"></i>
            </button>

            <div class="sidebar-scroll">
                <div class="sidebar-heading"><?= t('overview') ?></div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="<?= url('admin') ?>" class="<?= trim($isActive('dashboard')) ?>"<?= $ariaCurrent('dashboard') ?>>
                            <i class="fas fa-gauge-high"></i>
                            <span><?= t('dashboard') ?></span>
                        </a>
                    </li>
                </ul>

                <div class="sidebar-heading"><?= t('moderation') ?></div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="<?= url('admin_moderation') ?>" class="<?= trim($isActive('moderation')) ?>"<?= $ariaCurrent('moderation') ?>>
                            <i class="fas fa-clock"></i>
                            <span><?= t('pending_threads') ?></span>
                            <?php if ($pendingCount > 0): ?>
                                <span class="sidebar-badge" aria-label="<?= $pendingCount ?>"><?= $pendingCount > 99 ? '99+' : $pendingCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>

                <div class="sidebar-heading"><?= t('community') ?></div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="<?= url('admin_users') ?>" class="<?= trim($isActive('users')) ?>"<?= $ariaCurrent('users') ?>>
                            <i class="fas fa-users"></i>
                            <span><?= t('users') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_roles') ?>" class="<?= trim($isActive('roles')) ?>"<?= $ariaCurrent('roles') ?>>
                            <i class="fas fa-shield-halved"></i>
                            <span><?= t('roles_permissions') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_categories') ?>" class="<?= trim($isActive('categories')) ?>"<?= $ariaCurrent('categories') ?>>
                            <i class="fas fa-folder"></i>
                            <span><?= t('categories') ?></span>
                        </a>
                    </li>
                </ul>

                <div class="sidebar-heading"><?= t('extensions') ?></div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="<?= url('admin_plugins') ?>" class="<?= trim($isActive('plugins')) ?>"<?= $ariaCurrent('plugins') ?>>
                            <i class="fas fa-puzzle-piece"></i>
                            <span><?= t('plugins') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_themes') ?>" class="<?= trim($isActive('themes')) ?>"<?= $ariaCurrent('themes') ?>>
                            <i class="fas fa-palette"></i>
                            <span><?= t('themes') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_langs') ?>" class="<?= trim($isActive('langs')) ?>"<?= $ariaCurrent('langs') ?>>
                            <i class="fas fa-language"></i>
                            <span><?= t('languages') ?></span>
                        </a>
                    </li>
                    <?php
                    if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
                        $GLOBALS['pluginManager']->runHook('admin_sidebar_items');
                    }
                    ?>
                </ul>

                <div class="sidebar-heading"><?= t('system') ?></div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="<?= url('admin_settings') ?>" class="<?= trim($isActive('settings')) ?>"<?= $ariaCurrent('settings') ?>>
                            <i class="fas fa-sliders"></i>
                            <span><?= t('settings') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_smtp') ?>" class="<?= trim($isActive('smtp')) ?>"<?= $ariaCurrent('smtp') ?>>
                            <i class="fas fa-paper-plane"></i>
                            <span><?= t('smtp_settings') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_updates') ?>" class="<?= trim($isActive('updates')) ?>"<?= $ariaCurrent('updates') ?>>
                            <i class="fas fa-arrow-up-from-bracket"></i>
                            <span><?= t('updates') ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('admin_diagnostics') ?>" class="<?= trim($isActive('diagnostics')) ?>"<?= $ariaCurrent('diagnostics') ?>>
                            <i class="fas fa-stethoscope"></i>
                            <span><?= t('diagnostics') ?></span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-foot">
                <a href="<?= url('home') ?>">
                    <i class="fas fa-arrow-left"></i>
                    <span><?= t('back_to_forum') ?></span>
                </a>
            </div>
        </nav>
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <nav class="navbar topbar" aria-label="Admin toolbar">
                    <div class="topbar-inner">
                        <button id="sidebarToggleTop" class="topbar-toggle d-md-none" type="button" aria-label="<?= t('toggle_navigation') ?>" aria-controls="adminSidebar" aria-expanded="false">
                            <i class="fas fa-bars"></i>
                        </button>

                        <div class="topbar-crumbs">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="<?= url('admin') ?>"><?= t('admin_panel') ?></a></li>
                                <?php if ($section !== 'dashboard'): ?>
                                    <li class="breadcrumb-item active" aria-current="page"><?= escape($title) ?></li>
                                <?php else: ?>
                                    <li class="breadcrumb-item active" aria-current="page"><?= t('dashboard') ?></li>
                                <?php endif; ?>
                            </ol>
                        </div>

                        <div class="topbar-actions">
                            <a class="topbar-link" href="<?= url('home') ?>">
                                <i class="fas fa-arrow-up-right-from-square"></i>
                                <span><?= t('back_to_forum') ?></span>
                            </a>

                            <div class="topbar-divider d-none d-sm-block"></div>

                            <div class="nav-item dropdown no-arrow user-menu">
                                <input type="checkbox" id="userMenuToggle" class="d-none">
                                <label for="userMenuToggle" class="user-menu-backdrop" aria-hidden="true"></label>
                                <label for="userMenuToggle" class="nav-link dropdown-toggle" tabindex="0" aria-haspopup="true" aria-label="<?= t('account') ?>">
                                    <?= render_avatar($_SESSION['username'] ?? '', $_SESSION['avatar'] ?? '', 32) ?>
                                    <span class="user-meta">
                                        <span class="user-name"><?= escape($_SESSION['username'] ?? '') ?></span>
                                        <span class="user-role"><?= escape($roleLabel) ?></span>
                                    </span>
                                    <i class="fas fa-chevron-down chevron"></i>
                                </label>
                                <div class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenuToggle">
                                    <a class="dropdown-item" href="<?= url('home') ?>">
                                        <i class="fas fa-house"></i><?= t('back_to_forum') ?>
                                    </a>
                                    <a class="dropdown-item" href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>">
                                        <i class="fas fa-id-card"></i><?= t('profile') ?>
                                    </a>
                                    <a class="dropdown-item" href="<?= url('edit_profile') ?>">
                                        <i class="fas fa-user-gear"></i><?= t('edit_profile') ?>
                                    </a>
                                    <hr class="dropdown-divider">
                                    <form method="POST" action="<?= url('logout') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <button type="submit" class="dropdown-item">
                                            <i class="fas fa-right-from-bracket"></i><?= t('logout') ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </nav>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid" id="main-content">

                    <?php
                    }
                    ?>
