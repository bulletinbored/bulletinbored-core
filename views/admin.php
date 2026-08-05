<?php
global $pdo;
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalThreads = $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn();
$totalPosts = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$pendingCount = $pdo->query("SELECT COUNT(*) FROM threads WHERE status = 'pending'")->fetchColumn();
$totalCategories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header(t('dashboard')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= t('dashboard') ?></h2>
    </div>

    <div class="row g-4">
        <div class="col-xl-4 col-md-6">
            <div class="card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1"><?= t('users') ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalUsers ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card border-left-success shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1"><?= t('discussions') ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalThreads ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-comments fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card border-left-info shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1"><?= t('posts') ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalPosts ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-reply fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-2">
        <div class="col-xl-4 col-md-6">
            <div class="card border-left-warning shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1"><?= t('pending_threads') ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $pendingCount ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card border-left-secondary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1"><?= t('roles') ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn() ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shield-halved fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1"><?= t('categories') ?></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalCategories ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-folder fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>