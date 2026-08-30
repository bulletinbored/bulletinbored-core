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
    $siteTagline = $config['site_tagline'] ?? '';
    $siteLogo = $config['site_logo'] ?? '';
    $siteFavicon = $config['site_favicon'] ?? '';
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
    <?php if ($siteFavicon): ?>
    <link rel="icon" type="<?= str_ends_with(strtolower($siteFavicon), '.svg') ? 'image/svg+xml' : (str_ends_with(strtolower($siteFavicon), '.ico') ? 'image/x-icon' : 'image/png') ?>" href="<?= escape($siteFavicon) ?>">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" href="<?= base_url() ?>/favicon.svg">
    <?php endif; ?>
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
<body class="<?= (function_exists('is_admin') && is_admin()) ? 'is-admin' : '' ?>">
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }
    </script>
    <nav class="navbar navbar-expand-lg navbar-forum fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?= url('home') ?>">
                <?php if ($siteLogo): ?>
                    <img src="<?= escape($siteLogo) ?>" alt="<?= escape($siteName) ?>" class="brand-logo" style="max-height:32px; width:auto;">
                <?php else: ?>
                    <span class="brand-mark">▦</span>
                <?php endif; ?>
                <span class="brand-text"><?= render_site_name($siteName) ?></span>
            </a>

            <?php // User icons: desktop dropdown / mobile opens the full-screen stack ?>
            <ul class="navbar-nav topbar-user">
                <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <?php
                        if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
                            $GLOBALS['pluginManager']->runHook('navbar_icons');
                        }
                        ?>
                        <li class="nav-item dropdown user-menu-dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
                            <?= render_avatar($_SESSION['username'] ?? '', $_SESSION['avatar'] ?? '', 28) ?>
                            <span class="d-none d-lg-inline"><?= escape($_SESSION['username'] ?? '') ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>"><i class="fas fa-id-card me-2"></i><?= t('profile') ?></a></li>
                            <li><a class="dropdown-item" href="<?= url('edit_profile') ?>"><i class="fas fa-sliders-h me-2"></i><?= t('edit_profile') ?></a></li>
                            <?php if (function_exists('is_admin') && is_admin()): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= url('admin') ?>"><i class="fas fa-cog me-2"></i><?= t('admin_panel') ?></a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= url('logout') ?>"><i class="fas fa-sign-out-alt me-2"></i><?= t('logout') ?></a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= url('login') ?>"><?= t('login') ?></a></li>
                    <li class="nav-item"><a class="btn btn-brand btn-sm ms-lg-2" href="<?= url('register') ?>"><?= t('register') ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <?php // Mobile tab bar: the 3 icons live BELOW the main navbar (mobile only).
          // Tapping one opens the full-screen stack. ?>
    <nav class="mobile-tabbar" aria-label="Mobile">
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="<?= url('new_thread') ?>" class="mobile-tab" title="<?= t('new_thread') ?>">
            <i class="fas fa-plus"></i>
        </a>
        <?php
        if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
            $GLOBALS['pluginManager']->runHook('mobile_tabbar_icons');
        }
        ?>
        <?php endif; ?>
        <a href="#" class="mobile-tab" data-mobile-tab="search" title="<?= t('search') ?>">
            <i class="fas fa-search"></i>
        </a>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="#" class="mobile-tab" data-mobile-tab="user" title="<?= t('account') ?>">
            <?= render_avatar($_SESSION['username'] ?? '', $_SESSION['avatar'] ?? '', 24) ?>
        </a>
        <?php else: ?>
        <a href="#" class="mobile-tab" data-mobile-tab="login" title="<?= t('login') ?>">
            <i class="fas fa-sign-in-alt"></i>
        </a>
        <?php endif; ?>
        <a href="#" class="mobile-tab" id="mobileMenuToggle" title="<?= t('browse') ?>">
            <i class="fas fa-bars"></i>
        </a>
    </nav>

    <?php // Mobile full-screen panel (Facebook-style) – only visible < 992px ?>
    <div class="mobile-stack" id="mobileStack" aria-hidden="true">
        <div class="mobile-stack-bar">
            <button type="button" class="mobile-stack-back" id="mobileStackBack" aria-label="Back to forum">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="mobile-stack-tabs" role="tablist">
                <?php
                if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
                    $GLOBALS['pluginManager']->runHook('mobile_stack_tabs');
                }
                ?>
                <button type="button" class="mobile-stack-tab<?= (function_exists('is_logged_in') && is_logged_in()) ? '' : ' active' ?>" data-tab="search" role="tab"><i class="fas fa-search"></i></button>
                <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                <button type="button" class="mobile-stack-tab" data-tab="user" role="tab"><i class="fas fa-user"></i></button>
                <?php else: ?>
                <button type="button" class="mobile-stack-tab" data-tab="login" role="tab"><i class="fas fa-sign-in-alt"></i></button>
                <?php endif; ?>
            </div>
            <span class="mobile-stack-title" id="mobileStackTitle"></span>
        </div>
        <div class="mobile-stack-body">
            <?php
            if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
                $GLOBALS['pluginManager']->runHook('mobile_stack_panes');
            }
            ?>
            <div class="mobile-stack-pane<?= (function_exists('is_logged_in') && is_logged_in()) ? '' : ' active' ?>" data-pane="search" id="paneSearch">
                <form class="mobile-stack-search" method="GET" action="<?= url('search') ?>" role="search">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" value="<?= escape($_GET['q'] ?? '') ?>" placeholder="<?= t('search') ?>…" aria-label="<?= t('search') ?>" required>
                    <button type="submit" class="btn btn-brand btn-sm"><?= t('search') ?></button>
                </form>
            </div>
            <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <div class="mobile-stack-pane" data-pane="user" id="paneUser">
                <a class="mobile-stack-row" href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>">
                    <i class="fas fa-id-card mobile-stack-row-icon"></i>
                    <div class="mobile-stack-row-main"><div class="mobile-stack-row-title"><?= t('profile') ?></div></div>
                </a>
                <a class="mobile-stack-row" href="<?= url('edit_profile') ?>">
                    <i class="fas fa-sliders-h mobile-stack-row-icon"></i>
                    <div class="mobile-stack-row-main"><div class="mobile-stack-row-title"><?= t('edit_profile') ?></div></div>
                </a>
                <?php if (function_exists('is_admin') && is_admin()): ?>
                <a class="mobile-stack-row" href="<?= url('admin') ?>">
                    <i class="fas fa-cog mobile-stack-row-icon"></i>
                    <div class="mobile-stack-row-main"><div class="mobile-stack-row-title"><?= t('admin_panel') ?></div></div>
                </a>
                <?php endif; ?>
                <a class="mobile-stack-row" href="<?= url('logout') ?>">
                    <i class="fas fa-sign-out-alt mobile-stack-row-icon"></i>
                    <div class="mobile-stack-row-main"><div class="mobile-stack-row-title"><?= t('logout') ?></div></div>
                </a>
            </div>
            <?php else: ?>
            <div class="mobile-stack-pane" data-pane="login" id="paneLogin">
                <a class="mobile-stack-row" href="<?= url('login') ?>">
                    <i class="fas fa-sign-in-alt mobile-stack-row-icon"></i>
                    <div class="mobile-stack-row-main"><div class="mobile-stack-row-title"><?= t('login') ?></div></div>
                </a>
                <a class="mobile-stack-row" href="<?= url('register') ?>">
                    <i class="fas fa-user-plus mobile-stack-row-icon"></i>
                    <div class="mobile-stack-row-main"><div class="mobile-stack-row-title"><?= t('register') ?></div></div>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

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
    $siteTagline = $GLOBALS['config']['site_tagline'] ?? '';
    $siteLogo = $GLOBALS['config']['site_logo'] ?? '';
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
                        <?php if (!empty($GLOBALS['config']['site_logo'])): ?>
                            <img src="<?= escape($GLOBALS['config']['site_logo']) ?>" alt="<?= escape($siteName) ?>" class="brand-logo me-2" style="max-height:28px; width:auto;">
                        <?php else: ?>
                            <span class="brand-mark">▦</span>
                        <?php endif; ?>
                <span class="brand-text"><?= render_site_name($siteName) ?></span>
                    </div>
                    <?php if ($siteTagline): ?>
                        <p class="footer-text mb-0"><?= escape($siteTagline) ?></p>
                    <?php else: ?>
                        <p class="footer-text mb-0"><?= t('footer_tagline') ?></p>
                    <?php endif; ?>
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
                &copy; <?= date('Y') ?> <?= escape($siteName) ?> - <?= t('footer_powered') ?>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/navbar.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/core-helpers.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/mobile-panel.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
<?php
}
?>
