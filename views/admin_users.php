<?php 
global $pdo;
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header('Users'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Users Management</h2>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h5 class="card-title mb-0"><i class="fas fa-user-plus me-2"></i>Create User</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('admin_create_user') ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Username</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Role</label>
                    <select name="role" class="form-select">
                        <option value="user">User</option>
                        <option value="moderator">Moderator</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="banned">Banned</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="email_verified" id="email_verified" checked>
                        <label class="form-check-label small" for="email_verified">Verified</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><a href="<?= url('admin_user_edit', ['id' => $u['id']]) ?>" class="text-decoration-none"><?= escape($u['username']) ?></a></td>
                            <td><?= escape($u['email'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'bg-warning' : 'bg-info' ?>">
                                    <?= escape(ucfirst($u['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $status = $u['status'] ?? 'active';
                                $suspensionTime = $u['suspension_time'] ?? 0;
                                $now = time();
                                
                                if ($suspensionTime > $now) {
                                    $remaining = $suspensionTime - $now;
                                    $days = floor($remaining / 86400);
                                    $hours = floor(($remaining % 86400) / 3600);
                                    $minutes = floor(($remaining % 3600) / 60);
                                    $timeStr = '';
                                    if ($days > 0) $timeStr .= $days . 'd ';
                                    if ($hours > 0) $timeStr .= $hours . 'h ';
                                    if ($minutes > 0) $timeStr .= $minutes . 'm';
                                    echo '<span class="badge bg-warning">Suspended (' . trim($timeStr) . ' left)</span>';
                                } elseif ($status === 'banned') {
                                    echo '<span class="badge bg-danger">Banned</span>';
                                } elseif ($status === 'active') {
                                    echo '<span class="badge bg-success">Active</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">' . escape(ucfirst($status)) . '</span>';
                                }
                                ?>
                            </td>
                            <td><?= escape($u['created_at'] ?? 'N/A') ?></td>
                            <td class="text-end">
                                <?php if ($u['role'] !== 'admin'): ?>
                                    <?php if ($u['status'] === 'banned' || ($suspensionTime > $now && $now < $suspensionTime)): ?>
                                    <form method="POST" action="<?= url('unban_user', ['id' => $u['id']]) ?>" class="d-inline" onsubmit="return confirm('Unban this user?')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="redirect" value="/admin/users">
                                        <button class="btn btn-sm btn-success"><i class="fas fa-unlock"></i> Unban</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" action="<?= url('ban_user', ['id' => $u['id']]) ?>" class="d-inline" onsubmit="return confirm('Ban this user?')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="redirect" value="/admin/users">
                                        <button class="btn btn-sm btn-warning"><i class="fas fa-ban"></i> Ban</button>
                                    </form>
                                    <form method="POST" action="<?= url('suspend_user', ['id' => $u['id']]) ?>" class="d-inline ms-1" onsubmit="return confirm('Suspend this user?')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="number" name="days" min="1" max="30" placeholder="Days" class="form-control form-control-sm" style="width: auto;">
                                        <input type="hidden" name="redirect" value="/admin/users">
                                        <button class="btn btn-sm btn-info" title="Suspend temporarily"><i class="fas fa-lock"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" action="<?= url('delete_user', ['id' => $u['id']]) ?>" class="d-inline ms-1" onsubmit="return confirm('Delete this user?')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>