<?php
// Shared frontend chrome: top bar, left sidebar and page shell (Bootstrap 5).
//
// render_header($title, $options)
//   sidebar  bool  show the left sidebar column (default true)
//   wide     bool  full width main column, used by auth/form pages
//   info     array extra "discussion info" rows shown on top of the sidebar
function render_header($title = 'bulletinbored', $options = []) {
    global $config, $lang, $pdo;

    $siteName = $config['site_name'] ?? 'bulletinbored';
    $options  = is_array($options) ? $options : [];
    $showSidebar = $options['sidebar'] ?? true;

    $GLOBALS['layoutOptions'] = $options;
?>
<!DOCTYPE html>
<html lang="<?= escape($lang ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title) ?> - <?= escape($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="<?= base_url() ?>/favicon.svg">
    <?php global $themeCssUrl, $themeManager;
    if (empty($themeCssUrl) && isset($themeManager) && method_exists($themeManager, 'getCssUrl')) {
        $themeCssUrl = $themeManager->getCssUrl();
    }
    ?>
    <link href="<?= htmlspecialchars($themeCssUrl ?? base_url().'/themes/freshbored/style.css', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php
    if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
        $GLOBALS['pluginManager']->runHook('before_render');
        $GLOBALS['pluginManager']->runHook('frontend_before_render');
        if (is_admin()) {
            $GLOBALS['pluginManager']->runHook('admin_before_render');
        }
    }
    ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-forum fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?= url('home') ?>">
                <span class="brand-mark">▦</span>
                <span class="brand-text">bulletin<b>bored</b></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav topbar-links me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('home') ?>"><?= t('all_discussions') ?></a>
                    </li>
                </ul>

                <form class="topbar-search me-lg-3" method="GET" action="<?= url('search') ?>" role="search">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" value="<?= escape($_GET['q'] ?? '') ?>" placeholder="<?= t('search') ?>…" aria-label="<?= t('search') ?>" required>
                </form>

                <?php // Keep this list the LAST child: plugins append their items here. ?>
                <ul class="navbar-nav topbar-user">
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <?php
                        $navUnreadMsg = 0;
                        $navUnreadNotif = 0;
                        if (!empty($pdo)) {
                            try {
                                $me = (int)($_SESSION['user_id'] ?? 0);
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE recipient_id = ? AND is_read = 0");
                                $stmt->execute([$me]);
                                $navUnreadMsg = (int)$stmt->fetchColumn();
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                                $stmt->execute([$me]);
                                $navUnreadNotif = (int)$stmt->fetchColumn();
                            } catch (PDOException $e) {}
                        }
                        ?>
                        <li class="nav-item">
                            <a class="nav-link nav-icon position-relative" href="<?= url('messages') ?>" title="<?= t('messages') ?>">
                                <i class="fas fa-envelope"></i>
                                <?php if ($navUnreadMsg > 0): ?><span class="nav-badge"><?= $navUnreadMsg > 99 ? '99+' : $navUnreadMsg ?></span><?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link nav-icon position-relative" href="<?= url('notifications') ?>" title="<?= t('notifications') ?>">
                                <i class="fas fa-bell"></i>
                                <?php if ($navUnreadNotif > 0): ?><span class="nav-badge"><?= $navUnreadNotif > 99 ? '99+' : $navUnreadNotif ?></span><?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item dropdown d-none d-lg-block">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
                                <?= render_avatar($_SESSION['username'] ?? '', $_SESSION['avatar'] ?? '', 28) ?>
                                <span class="d-none d-lg-inline"><?= escape($_SESSION['username'] ?? '') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>"><i class="fas fa-id-card me-2"></i><?= t('profile') ?></a></li>
                                <li><a class="dropdown-item" href="<?= url('edit_profile') ?>"><i class="fas fa-sliders-h me-2"></i><?= t('edit_profile') ?></a></li>
                                <li><a class="dropdown-item" href="<?= url('messages') ?>"><i class="fas fa-envelope me-2"></i><?= t('messages') ?></a></li>
                                <?php if (function_exists('is_admin') && is_admin()): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?= url('admin') ?>"><i class="fas fa-cog me-2"></i><?= t('admin_panel') ?></a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= url('logout') ?>"><i class="fas fa-sign-out-alt me-2"></i><?= t('logout') ?></a></li>
                            </ul>
                        </li>
                        <li class="nav-item d-lg-none">
                            <a class="nav-link" href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>" title="<?= t('profile') ?>">
                                <?= render_avatar($_SESSION['username'] ?? '', $_SESSION['avatar'] ?? '', 28) ?>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= url('login') ?>"><?= t('login') ?></a></li>
                        <li class="nav-item"><a class="btn btn-brand btn-sm ms-lg-2" href="<?= url('register') ?>"><?= t('register') ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="page-shell">
        <div class="container">
            <div class="row g-4">
<?php if ($showSidebar): ?>
                <div class="col-lg-3 order-lg-1 sidebar-col">
                    <?php include __DIR__.'/partials/sidebar.php'; ?>
                </div>
                <div class="col-lg-9 order-lg-2 content-col">
<?php else: ?>
                <div class="col-12 content-col content-col-narrow">
<?php endif; ?>
<?php
}

function render_footer() {
    if (!empty($GLOBALS['pluginFooterAssets'] ?? '')) {
        echo $GLOBALS['pluginFooterAssets'];
    }
    if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
        $GLOBALS['pluginManager']->runHook('footer_before_render');
    }
    $siteName = $GLOBALS['config']['site_name'] ?? 'bulletinbored';
?>
                </div><!-- /.content-col -->
            </div><!-- /.row -->
        </div><!-- /.container -->
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="footer-brand">
                        <span class="brand-mark">▦</span>
                        <span class="brand-text">bulletin<b>bored</b></span>
                    </div>
                    <p class="footer-text mb-0"><?= t('footer_tagline') ?></p>
                </div>
                <div class="col-6 col-md-3">
                    <h6 class="footer-title"><?= t('quick_links') ?></h6>
                    <ul class="footer-list">
                        <li><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
                        <li><a href="<?= url('search') ?>"><?= t('search') ?></a></li>
                        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                            <li><a href="<?= url('new_thread') ?>"><?= t('new_thread') ?></a></li>
                        <?php else: ?>
                            <li><a href="<?= url('register') ?>"><?= t('register') ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="col-6 col-md-4">
                    <h6 class="footer-title"><?= t('categories') ?></h6>
                    <ul class="footer-list">
                        <?php foreach (array_slice(sidebar_categories(), 0, 5) as $fcat): ?>
                            <li><a href="<?= url('category', ['id' => $fcat['id'], 'slug' => slugify($fcat['name'] ?? '')]) ?>"><?= escape($fcat['name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                &copy; <?= date('Y') ?> <?= escape($siteName) ?> &mdash; <?= t('footer_powered') ?>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/navbar.js', ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
<?php
}
?>
