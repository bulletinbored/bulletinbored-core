<?php 
global $pdo;
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header(t('users')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= t('users_management') ?></h2>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h5 class="card-title mb-0"><i class="fas fa-user-plus me-2"></i><?= t('create_user') ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('admin_create_user') ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?= t('username') ?></label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold"><?= t('password') ?></label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold"><?= t('role') ?></label>
                    <select name="role" class="form-select">
                        <option value="user"><?= t('role_user') ?></option>
                        <option value="moderator"><?= t('role_moderator') ?></option>
                        <option value="admin"><?= t('role_admin') ?></option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-bold"><?= t('status') ?></label>
                    <select name="status" class="form-select">
                        <option value="active"><?= t('active') ?></option>
                        <option value="suspended"><?= t('suspended') ?></option>
                        <option value="banned"><?= t('banned') ?></option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="email_verified" id="email_verified" checked>
                        <label class="form-check-label small" for="email_verified"><?= t('verified') ?></label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i><?= t('add_user') ?></button>
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
                            <th><?= t('id') ?></th>
                            <th><?= t('username') ?></th>
                            <th><?= t('email') ?></th>
                            <th><?= t('role') ?></th>
                            <th><?= t('status') ?></th>
                            <th><?= t('registered') ?></th>
                            <th class="text-end"><?= t('actions') ?></th>
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
                                    echo '<span class="badge bg-warning">' . t('suspended') . ' (' . trim($timeStr) . ' ' . t('left') . ')</span>';
                                 } elseif ($status === 'banned') {
                                     echo '<span class="badge bg-danger">' . t('banned') . '</span>';
                                 } elseif ($status === 'active') {
                                     echo '<span class="badge bg-success">' . t('active') . '</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">' . escape(ucfirst($status)) . '</span>';
                                }
                                ?>
                            </td>
                            <td><?= escape($u['created_at'] ?? 'N/A') ?></td>
                            <td class="text-end">
                                <?php if ($u['role'] !== 'admin'): ?>
                                <div class="d-inline-flex align-items-stretch gap-1 flex-nowrap actions-cell">
                                    <?php if ($u['status'] === 'banned' || ($suspensionTime > $now && $now < $suspensionTime)): ?>
                                    <form method="POST" action="<?= url('unban_user', ['id' => $u['id']]) ?>" class="d-flex m-0" onsubmit="return confirm('<?= t('unban_user') ?>')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="redirect" value="/admin/users">
                                        <button type="submit" class="btn btn-sm btn-success w-100" title="<?= t('unban_user') ?>"><i class="fas fa-unlock"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" action="<?= url('ban_user', ['id' => $u['id']]) ?>" class="d-flex m-0" onsubmit="return confirm('<?= t('ban_user') ?>')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="redirect" value="/admin/users">
                                        <button type="submit" class="btn btn-sm btn-warning w-100" title="<?= t('ban_user') ?>"><i class="fas fa-ban"></i></button>
                                    </form>
                                    <form method="POST" action="<?= url('suspend_user', ['id' => $u['id']]) ?>" class="d-flex align-items-stretch gap-1 m-0" onsubmit="return confirm('<?= t('suspend') ?>')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="number" name="days" min="1" max="30" value="1" title="<?= t('days') ?>" class="form-control form-control-sm days-input">
                                        <input type="hidden" name="redirect" value="/admin/users">
                                        <button type="submit" class="btn btn-sm btn-info" title="<?= t('suspend') ?>"><i class="fas fa-lock"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" action="<?= url('delete_user', ['id' => $u['id']]) ?>" class="d-flex m-0" onsubmit="return confirm('<?= t('delete_confirm') ?>')">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <button type="submit" class="btn btn-sm btn-danger w-100" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="text-muted"><i class="fas fa-shield-alt" title="<?= t('admin') ?>"></i></span>
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