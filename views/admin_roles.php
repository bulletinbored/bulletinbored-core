<?php 
global $pdo;
$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$allPermissions = [
    'can_approve_threads' => t('can_approve_threads'),
    'can_delete_threads' => t('can_delete_threads'),
    'can_delete_posts' => t('can_delete_posts'),
    'can_lock_threads' => t('can_lock_threads'),
    'can_sticky_threads' => t('can_sticky_threads'),
    'can_edit_posts' => t('can_edit_posts'),
    'can_edit_threads' => t('can_edit_threads'),
    'can_ban_users' => t('can_ban_users'),
    'can_manage_roles' => t('can_manage_roles'),
    'can_create_threads' => t('can_create_threads'),
    'can_create_posts' => t('can_create_posts'),
    'can_edit_own_posts' => t('can_edit_own_posts'),
    'can_delete_own_posts' => t('can_delete_own_posts'),
];
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header(t('roles_permissions')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= t('roles_permissions') ?></h2>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h5 class="card-title mb-0"><i class="fas fa-shield-halved me-2"></i><?= t('roles') ?></h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?= t('role') ?></th>
                                    <th><?= t('permissions') ?></th>
                                    <th class="text-end"><?= t('actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roles as $role): 
                                    $perms = json_decode($role['permissions'] ?? '[]', true) ?? [];
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge <?= $role['name'] === 'admin' ? 'bg-warning' : ($role['name'] === 'moderator' ? 'bg-info' : 'bg-secondary') ?>">
                                            <?= escape(ucfirst($role['name'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($perms as $perm): ?>
                                                <span class="badge bg-light text-dark" style="font-size:0.7rem"><?= t($perm) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (empty($perms)): ?>
                                                <span class="text-muted small"><?= t('no_permissions') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="document.getElementById('edit-form-<?= $role['id'] ?>').classList.toggle('d-none')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($role['name'] !== 'admin'): ?>
                                            <form method="POST" action="<?= url('admin_roles_action') ?>" onsubmit="return confirm('<?= t('delete_confirm') ?>')">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="do" value="delete">
                                                <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr id="edit-form-<?= $role['id'] ?>" class="d-none">
                                    <td colspan="3">
                                        <form method="POST" action="<?= url('admin_roles_action') ?>" class="row g-2 p-3 bg-light rounded">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="do" value="update">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                            <div class="col-12">
                                                <label class="form-label small fw-bold"><?= t('permissions_for') ?> <?= escape(ucfirst($role['name'])) ?></label>
                                            </div>
                                            <div class="col-md-6">
                                                <?php foreach ($allPermissions as $permKey => $permLabel): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $permKey ?>" id="perm_<?= $role['id'] ?>_<?= $permKey ?>" <?= in_array($permKey, $perms) ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="perm_<?= $role['id'] ?>_<?= $permKey ?>"><?= escape($permLabel) ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save me-1"></i><?= t('save_permissions') ?></button>
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('edit-form-<?= $role['id'] ?>').classList.add('d-none')"><?= t('cancel') ?></button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h5 class="card-title mb-0"><i class="fas fa-plus-circle me-2"></i><?= t('create_role') ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= url('admin_roles_action') ?>">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="do" value="create">
                        <div class="mb-3">
                            <label class="form-label"><?= t('role_name') ?></label>
                            <input type="text" name="role_name" class="form-control" required placeholder="<?= t('role_edit_placeholder') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('permissions') ?></label>
                            <div class="form-check">
                                <?php foreach ($allPermissions as $permKey => $permLabel): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $permKey ?>" id="new_perm_<?= $permKey ?>">
                                        <label class="form-check-label small" for="new_perm_<?= $permKey ?>"><?= escape($permLabel) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i><?= t('create_role') ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>