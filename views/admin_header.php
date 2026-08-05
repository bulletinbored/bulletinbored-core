<?php
// Admin header - completely separate from frontend theme
function render_admin_header($title = 'Admin Panel') {
    global $config, $lang, $action;
    $siteName = $config['site_name'] ?? 'bulletinbored';
    $active = function($checks) use ($action) {
        foreach ((array)$checks as $c) {
            if ($action === $c) return 'active';
        }
        return '';
    };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title) ?> - <?= escape($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= base_url() ?>/assets/admin.css" rel="stylesheet">
</head>
<body class="admin-layout">
    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <nav class="sidebar">
            <!-- Sidebar Brand -->
            <a class="sidebar-brand" href="<?= url('admin') ?>">
                <i class="fas fa-cog me-2"></i>
                <span>Admin Panel</span>
            </a>
            <hr class="sidebar-divider my-0">

            <!-- Dashboard -->
            <ul class="sidebar-nav">
                <li>
                    <a href="<?= url('admin') ?>" class="<?= $active('admin') ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            </ul>
            <hr class="sidebar-divider">

            <!-- Moderation -->
            <div class="sidebar-heading">Moderation</div>
            <ul class="sidebar-nav">
                <li><a href="<?= url('admin_moderation') ?>" class="<?= $active('admin_moderation') ?>"><i class="fas fa-clock"></i> <span>Pending Threads</span></a></li>
            </ul>
            <hr class="sidebar-divider">

            <!-- Management -->
            <div class="sidebar-heading">Management</div>
            <ul class="sidebar-nav">
                <li><a href="<?= url('admin_categories') ?>" class="<?= $active('admin_categories') ?>"><i class="fas fa-folder"></i> <span>Categories</span></a></li>
                <li><a href="<?= url('admin_users') ?>" class="<?= $active(['admin_users','admin_user_edit','admin_create_user']) ?>"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="<?= url('admin_roles') ?>" class="<?= $active(['admin_roles','admin_roles_action']) ?>"><i class="fas fa-shield-halved"></i> <span>Roles &amp; Permissions</span></a></li>
                <li><a href="<?= url('admin_plugins') ?>" class="<?= $active('admin_plugins') ?>"><i class="fas fa-puzzle-piece"></i> <span>Plugins</span></a></li>
                <li><a href="<?= url('admin_themes') ?>" class="<?= $active('admin_themes') ?>"><i class="fas fa-palette"></i> <span>Themes</span></a></li>
            </ul>
            <hr class="sidebar-divider">

            <!-- Extensions -->
            <div class="sidebar-heading">Extensions</div>
            <ul class="sidebar-nav">
                <li><a href="<?= url('admin_updates') ?>" class="<?= $active('admin_updates') ?>"><i class="fas fa-arrow-up"></i> <span>Updates</span></a></li>
                <li><a href="<?= url('admin_langs') ?>" class="<?= $active('admin_langs') ?>"><i class="fas fa-language"></i> <span>Languages</span></a></li>
            </ul>
            <hr class="sidebar-divider">

            <!-- System -->
            <div class="sidebar-heading">System</div>
            <ul class="sidebar-nav">
                <li><a href="<?= url('admin_settings') ?>" class="<?= $active('admin_settings') ?>"><i class="fas fa-cogs"></i> <span>Settings</span></a></li>
                <li><a href="<?= url('home') ?>"><i class="fas fa-arrow-left"></i> <span>Back to Forum</span></a></li>
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
                        <li class="nav-item dropdown no-arrow user-menu">
                            <input type="checkbox" id="userMenuToggle" class="d-none">
                            <label for="userMenuToggle" class="user-menu-backdrop"></label>
                            <label for="userMenuToggle" class="nav-link dropdown-toggle" tabindex="0" aria-haspopup="true">
                                <span class="me-2 d-none d-lg-inline text-gray-600 small"><?= escape($_SESSION['username'] ?? '') ?></span>
                                <i class="fas fa-user-circle fa-fw"></i>
                            </label>
                            <div class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userMenuToggle">
                                <a class="dropdown-item" href="<?= url('logout') ?>"><i class="fas fa-sign-out-alt fa-sm fa-fw me-2 text-gray-400"></i>Logout</a>
                            </div>
                        </li>
                    </ul>
                </nav>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <?php
                    }
                    ?>