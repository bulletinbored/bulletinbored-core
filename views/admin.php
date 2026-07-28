<?php include __DIR__.'/admin_header.php'; render_admin_header('Dashboard'); ?>
    <!-- Begin Page Content -->

        <!-- Page Heading -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
            <a href="<?= base_url() ?>/?action=home" class="d-none d-sm-inline-block btn btn-sm btn-secondary shadow-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Forum
            </a>
        </div>

        <!-- Content Row -->
        <div class="row">

            <!-- Users Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Users</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-users fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Threads Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Threads</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-comments fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Posts Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Posts</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-reply fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Pending</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM threads WHERE status = 'pending'")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($adminError): ?>
        <div class="alert alert-danger"><?= escape($adminError) ?></div>
        <?php endif; ?>
        <?php if ($adminSuccess): ?>
        <div class="alert alert-success"><?= escape($adminSuccess) ?></div>
        <?php endif; ?>

        <!-- Moderation & Categories Row -->
        <div class="row">

            <!-- Categories Section -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-folder me-2"></i>Categories
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><?= escape($cat['name']) ?></td>
                                        <td><?= escape($cat['description'] ?? '') ?></td>
                                        <td class="text-end">
                                            <form method="POST" action="<?= base_url() ?>/?action=edit_category&id=<?= $cat['id'] ?>" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="text" name="name" value="<?= escape($cat['name']) ?>" class="form-control form-control-sm d-inline" style="width:100px" required>
                                                <input type="text" name="description" value="<?= escape($cat['description'] ?? '') ?>" class="form-control form-control-sm d-inline" style="width:120px">
                                                <button class="btn btn-success btn-sm"><i class="fas fa-save"></i></button>
                                            </form>
                                            <form method="POST" action="<?= base_url() ?>/?action=delete_category&id=<?= $cat['id'] ?>" class="d-inline" onsubmit="return confirm('Delete?')">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <hr>
                        <form method="POST" action="<?= base_url() ?>/?action=create_category" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="col-auto">
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="Name" required>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Description">
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Moderation Section -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-shield-alt me-2"></i>Moderation Queue
                        </h6>
                        <span class="badge badge-warning"><?= count($pendingThreads) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pendingThreads)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0">No pending threads.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingThreads as $thread): ?>
                                    <tr>
                                        <td><?= escape($thread['title']) ?></td>
                                        <td><?= escape($thread['author'] ?? 'Unknown') ?></td>
                                        <td class="text-end">
                                            <form method="POST" action="<?= base_url() ?>/?action=moderate" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="do" value="approve">
                                                <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                                                <button class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
                                            </form>
                                            <form method="POST" action="<?= base_url() ?>/?action=moderate" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="do" value="delete">
                                                <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash me-1"></i>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users & Settings Row -->
        <div class="row">
            <!-- Users Section -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-users me-2"></i>Users
                        </h6>
                        <a href="#" class="btn btn-primary btn-sm">Manage</a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Registered</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $allUsers = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
                                    foreach ($allUsers as $u): ?>
                                    <tr>
                                        <td><?= $u['id'] ?></td>
                                        <td><?= escape($u['username']) ?></td>
                                        <td><?= escape($u['email'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="badge <?= $u['role'] === 'admin' ? 'bg-warning' : 'bg-info' ?>">
                                                <?= escape(ucfirst($u['role'] ?? 'user')) ?>
                                            </span>
                                        </td>
                                        <td><?= escape($u['created_at'] ?? 'N/A') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings Section -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-cogs me-2"></i>Settings
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="save_settings" value="1">
                            
                            <div class="mb-3">
                                <label class="form-label">Site Name</label>
                                <input type="text" name="site_name" class="form-control" value="<?= escape($config['site_name'] ?? 'Forum Nuovo') ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Theme</label>
                                <select name="theme" class="form-control">
                                    <option value="default" <?= ($config['theme'] ?? 'default') === 'default' ? 'selected' : '' ?>>Default</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="allow_registration" id="allowRegistration" <?= !empty($config['allow_registration']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="allowRegistration">Allow Registration</label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenanceMode" <?= !empty($config['maintenance_mode']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="maintenanceMode">Maintenance Mode</label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Save Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- /.container-fluid -->
<?php include __DIR__.'/admin_footer.php'; ?>

        <!-- Page Heading -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
            <a href="#" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                <i class="fas fa-download fa-sm text-white-50 me-1"></i> Generate Report
            </a>
        </div>

        <!-- Content Row -->
        <div class="row">

            <!-- Users Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Users</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-users fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Threads Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Threads</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-comments fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Posts Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Posts</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-reply fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Card -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col me-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Pending</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= $pdo->query("SELECT COUNT(*) FROM threads WHERE status = 'pending'")->fetchColumn() ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Row -->
        <div class="row">

            <!-- Categories Section -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-folder me-2"></i>Categories
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><?= escape($cat['name']) ?></td>
                                        <td><?= escape($cat['description'] ?? '') ?></td>
                                        <td class="text-end">
                                            <form method="POST" action="<?= base_url() ?>/?action=edit_category&id=<?= $cat['id'] ?>" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="text" name="name" value="<?= escape($cat['name']) ?>" class="form-control form-control-sm d-inline" style="width:100px" required>
                                                <input type="text" name="description" value="<?= escape($cat['description'] ?? '') ?>" class="form-control form-control-sm d-inline" style="width:120px">
                                                <button class="btn btn-success btn-sm"><i class="fas fa-save"></i></button>
                                            </form>
                                            <form method="POST" action="<?= base_url() ?>/?action=delete_category&id=<?= $cat['id'] ?>" class="d-inline" onsubmit="return confirm('Delete?')">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <hr>
                        <form method="POST" action="<?= base_url() ?>/?action=create_category" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="col-auto">
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="Name" required>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Description">
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Moderation Section -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-shield-alt me-2"></i>Moderation
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pendingThreads)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0">No pending threads.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingThreads as $thread): ?>
                                    <tr>
                                        <td><?= escape($thread['title']) ?></td>
                                        <td><?= escape($thread['author'] ?? 'Unknown') ?></td>
                                        <td class="text-end">
                                            <form method="POST" action="<?= base_url() ?>/?action=moderate" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="do" value="approve">
                                                <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                                                <button class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
                                            </form>
                                            <form method="POST" action="<?= base_url() ?>/?action=moderate" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="do" value="delete">
                                                <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash me-1"></i>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users Section -->
        <div class="row">
            <div class="col-12">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-users me-2"></i>Users
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Registered</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $allUsers = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
                                    foreach ($allUsers as $u): ?>
                                    <tr>
                                        <td><?= $u['id'] ?></td>
                                        <td><?= escape($u['username']) ?></td>
                                        <td><?= escape($u['email'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="badge <?= $u['role'] === 'admin' ? 'bg-warning' : 'bg-info' ?>">
                                                <?= escape(ucfirst($u['role'] ?? 'user')) ?>
                                            </span>
                                        </td>
                                        <td><?= escape($u['created_at'] ?? 'N/A') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- /.container-fluid -->
<?php include __DIR__.'/admin_footer.php'; ?>
