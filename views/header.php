<?php
// Shared frontend header - clean Bootstrap 5 theme
function render_header($title = 'bulletinbored') {
    global $config, $lang, $pluginHeadAssets;
    $siteName = $config['site_name'] ?? 'bulletinbored';
    $themeName = $config['theme'] ?? 'freshbored';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title) ?> - <?= escape($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <?php global $themeCssUrl, $themeManager;
    if (empty($themeCssUrl) && isset($themeManager) && method_exists($themeManager, 'getCssUrl')) {
        $themeCssUrl = $themeManager->getCssUrl();
    }
    ?>
    <link href="<?= htmlspecialchars($themeCssUrl ?? base_url().'/themes/freshbored/style.css', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php echo $pluginHeadAssets ?? ''; ?>
</head>
<body>
    <!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-forum fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?= url('home') ?>">
                <i class="fas fa-comments me-2"></i><?= escape($siteName) ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('home') ?>"><i class="fas fa-home me-1"></i><?= t('home') ?></a>
                    </li>
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= url('new_thread') ?>"><i class="fas fa-plus me-1"></i><?= t('new_thread') ?></a>
                        </li>
                    <?php endif; ?>
                </ul>
                <form class="d-flex me-2" method="GET" action="<?= url('search') ?>">
                    <div class="input-group input-group-sm">
                        <input type="text" name="q" class="form-control" placeholder="<?= t('search') ?>..." required>
                        <button class="btn btn-outline-light" type="submit"><i class="fas fa-search"></i></button>
                    </div>
                </form>
                <ul class="navbar-nav">
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-1"></i><?= escape($_SESSION['username'] ?? '') ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>"><i class="fas fa-id-card me-2"></i><?= t('profile') ?></a></li>
                                <li><a class="dropdown-item" href="<?= url('messages') ?>"><i class="fas fa-envelope me-2"></i>New message</a></li>
                                <?php if (function_exists('is_admin') && is_admin()): ?>
                                    <li><a class="dropdown-item" href="<?= url('admin') ?>"><i class="fas fa-cog me-2"></i><?= t('admin_panel') ?></a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= url('logout') ?>"><i class="fas fa-sign-out-alt me-2"></i><?= t('logout') ?></a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= url('login') ?>"><i class="fas fa-sign-in-alt me-1"></i><?= t('login') ?></a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= url('register') ?>"><i class="fas fa-user-plus me-1"></i><?= t('register') ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
<?php
}

function render_footer() {
    if (!empty($GLOBALS['pluginFooterAssets'] ?? '')) {
        echo $GLOBALS['pluginFooterAssets'];
    }
    if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'runHook')) {
        $GLOBALS['pluginManager']->runHook('footer_before_render');
    }
?>
    </div><!-- /.container -->

    <footer class="footer">
        <div class="container text-center">
            <p class="mb-0 small">
                <i class="fas fa-comments me-1"></i><?= $GLOBALS['config']['site_name'] ?? 'bulletinbored' ?> &mdash;
                Powered by PHP & Bootstrap 5
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
?>