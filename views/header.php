<?php
// Shared frontend header - clean Bootstrap 5 theme
function render_header($title = 'Forum Nuovo') {
    global $config;
    $siteName = $config['site_name'] ?? 'Forum Nuovo';
    $themeName = $config['theme'] ?? 'default';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title) ?> - <?= escape($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <?php
    $themeCssUrl = base_url() . '/themes/' . $themeName . '/style.css';
    if (!file_exists(__DIR__ . '/../themes/' . $themeName . '/style.css')) {
        $themeCssUrl = base_url() . '/themes/default/style.css';
    }
    ?>
    <link href="<?= htmlspecialchars($themeCssUrl, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-forum fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?= base_url() ?>/?action=home">
                <i class="fas fa-comments me-2"></i><?= escape($siteName) ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_url() ?>/?action=home"><i class="fas fa-home me-1"></i>Home</a>
                    </li>
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= base_url() ?>/?action=new_thread"><i class="fas fa-plus me-1"></i>New Thread</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <form class="d-flex me-2" method="GET" action="<?= base_url() ?>/?action=search">
                    <div class="input-group input-group-sm">
                        <input type="text" name="q" class="form-control" placeholder="Search..." required>
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
                                <li><a class="dropdown-item" href="<?= base_url() ?>/?action=profile&user=<?= escape($_SESSION['username'] ?? '') ?>"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                                <?php if (function_exists('is_admin') && is_admin()): ?>
                                    <li><a class="dropdown-item" href="<?= base_url() ?>/?action=admin"><i class="fas fa-cog me-2"></i>Admin Panel</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= base_url() ?>/?action=logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url() ?>/?action=login"><i class="fas fa-sign-in-alt me-1"></i>Login</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url() ?>/?action=register"><i class="fas fa-user-plus me-1"></i>Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
<?php
}

function render_footer() {
?>
    </div><!-- /.container -->

    <footer class="footer">
        <div class="container text-center">
            <p class="mb-0 small">
                <i class="fas fa-comments me-1"></i><?= $GLOBALS['config']['site_name'] ?? 'Forum Nuovo' ?> &mdash;
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title) ?> - <?= escape($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <?php if ($admin): ?>
    <link href="<?= base_url() ?>/assets/admin.css" rel="stylesheet">
    <?php else: ?>
    <?php
    $themeCssUrl = base_url() . '/themes/' . $themeName . '/style.css';
    if (!file_exists(__DIR__ . '/../themes/' . $themeName . '/style.css')) {
        $themeCssUrl = base_url() . '/themes/default/style.css';
    }
    ?>
    <link href="<?= htmlspecialchars($themeCssUrl, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endif; ?>
</head>
<body class="<?= $admin ? 'admin-layout' : '' ?>">
    <?php if ($admin): ?>
    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <nav class="sidebar">
            <!-- Sidebar Brand -->
            <a class="sidebar-brand" href="<?= base_url() ?>/?action=admin">
                <i class="fas fa-cog fa-spin me-2"></i>
                <span>Admin Panel</span>
            </a>
            <hr class="sidebar-divider my-0">

            <!-- Dashboard -->
            <ul class="sidebar-nav">
                <li>
                    <a href="<?= base_url() ?>/?action=admin" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            </ul>
            <hr class="sidebar-divider">

            <!-- Moderation -->
            <div class="sidebar-heading">Moderation</div>
            <ul class="sidebar-nav">
                <li><a href="#"><i class="fas fa-clock"></i> <span>Pending Threads</span></a></li>
                <li><a href="#"><i class="fas fa-exclamation-triangle"></i> <span>Reports</span></a></li>
                <li><a href="#"><i class="fas fa-trash-alt"></i> <span>Deleted Content</span></a></li>
            </ul>
            <hr class="sidebar-divider">

            <!-- Management -->
            <div class="sidebar-heading">Management</div>
            <ul class="sidebar-nav">
                <li><a href="#"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="#"><i class="fas fa-folder"></i> <span>Categories</span></a></li>
                <li><a href="#"><i class="fas fa-comments"></i> <span>Threads</span></a></li>
                <li><a href="#"><i class="fas fa-reply"></i> <span>Posts</span></a></li>
            </ul>
            <hr class="sidebar-divider">

            <!-- System -->
            <div class="sidebar-heading">System</div>
            <ul class="sidebar-nav">
                <li><a href="#"><i class="fas fa-cogs"></i> <span>Settings</span></a></li>
                <li><a href="#"><i class="fas fa-file-alt"></i> <span>Logs</span></a></li>
                <li><a href="<?= base_url() ?>/?action=home"><i class="fas fa-arrow-left"></i> <span>Back to Forum</span></a></li>
            </ul>
        </nav>
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <!-- Sidebar Toggle (Topbar) -->
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle me-3">
                        <i class="fas fa-bars"></i>
                    </button>

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ms-auto">
                        <div class="topbar-divider d-none d-sm-block"></div>

                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="me-2 d-none d-lg-inline text-gray-600 small"><?= escape($_SESSION['username'] ?? '') ?></span>
                                <i class="fas fa-user-circle fa-fw"></i>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end shadow animated--fade-in" aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="<?= base_url() ?>/?action=profile&user=<?= escape($_SESSION['username'] ?? '') ?>">
                                    <i class="fas fa-user fa-sm fa-fw me-2 text-gray-400"></i>Profile
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="<?= base_url() ?>/?action=logout">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw me-2 text-gray-400"></i>Logout
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

    <?php else: ?>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-forum fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?= base_url() ?>/?action=home">
                <i class="fas fa-comments me-2"></i><?= escape($siteName) ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_url() ?>/?action=home"><i class="fas fa-home me-1"></i>Home</a>
                    </li>
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= base_url() ?>/?action=new_thread"><i class="fas fa-plus me-1"></i>New Thread</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <form class="d-flex me-2" method="GET" action="<?= base_url() ?>/?action=search">
                    <div class="input-group input-group-sm">
                        <input type="text" name="q" class="form-control" placeholder="Search..." required>
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
                                <li><a class="dropdown-item" href="<?= base_url() ?>/?action=profile&user=<?= escape($_SESSION['username'] ?? '') ?>"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                                <?php if (function_exists('is_admin') && is_admin()): ?>
                                    <li><a class="dropdown-item" href="<?= base_url() ?>/?action=admin"><i class="fas fa-cog me-2"></i>Admin Panel</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= base_url() ?>/?action=logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url() ?>/?action=login"><i class="fas fa-sign-in-alt me-1"></i>Login</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= base_url() ?>/?action=register"><i class="fas fa-user-plus me-1"></i>Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
    <?php endif; ?>
<?php
}

function render_footer() {
    global $adminLayout;
?>
    <?php if ($adminLayout): ?>
                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="copyright text-center my-auto">
                    <span>Copyright &copy; Forum Nuovo <?= date('Y') ?></span>
                </div>
            </footer>
            <!-- End of Footer -->

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <?php else: ?>
    </div><!-- /.container -->

    <footer class="footer">
        <div class="container text-center">
            <p class="mb-0 small">
                <i class="fas fa-comments me-1"></i><?= $GLOBALS['config']['site_name'] ?? 'Forum Nuovo' ?> &mdash;
                Powered by PHP & Bootstrap 5
            </p>
        </div>
    </footer>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($adminLayout): ?>
    <script>
        document.getElementById('sidebarToggleTop').addEventListener('click', function() {
            document.body.classList.toggle('sidebar-toggled');
            document.querySelector('.sidebar').classList.toggle('toggled');
        });
    </script>
    <?php endif; ?>
</body>
</html>
<?php
}
