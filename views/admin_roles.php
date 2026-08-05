<?php 
global $pdo;
$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$allPermissions = [
    'can_approve_threads' => 'Approve threads',
    'can_delete_threads' => 'Delete threads',
    'can_delete_posts' => 'Delete posts',
    'can_lock_threads' => 'Lock threads',
    'can_sticky_threads' => 'Sticky threads',
    'can_edit_posts' => 'Edit posts',
    'can_edit_threads' => 'Edit threads',
    'can_ban_users' => 'Ban users',
    'can_manage_roles' => 'Manage roles',
    'can_create_threads' => 'Create threads',
    'can_create_posts' => 'Create posts',
    'can_edit_own_posts' => 'Edit own posts',
    'can_delete_own_posts' => 'Delete own posts',
];
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header('Roles & Permissions'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Roles & Permissions</h2>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h5 class="card-title mb-0"><i class="fas fa-shield-halved me-2"></i>Roles</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Permissions</th>
                                    <th class="text-end">Actions</th>
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
                                                <span class="badge bg-light text-dark" style="font-size:0.7rem"><?= escape(str_replace('can_', '', $perm)) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (empty($perms)): ?>
                                                <span class="text-muted small">No permissions</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="document.getElementById('edit-form-<?= $role['id'] ?>).classList.toggle('d-none')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($role['name'] !== 'admin'): ?>
                                            <form method="POST" action="<?= url('admin_roles_action') ?>" onsubmit="return confirm('Delete this role?')">
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
                                                <label class="form-label small fw-bold">Permissions for <?= escape(ucfirst($role['name'])) ?></label>
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
                                                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save me-1"></i>Save Permissions</button>
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('edit-form-<?= $role['id'] ?>').classList.add('d-none')">Cancel</button>
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
                    <h5 class="card-title mb-0"><i class="fas fa-plus-circle me-2"></i>Create Role</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= url('admin_roles_action') ?>">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="do" value="create">
                        <div class="mb-3">
                            <label class="form-label">Role Name</label>
                            <input type="text" name="role_name" class="form-control" required placeholder="e.g. editor">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Permissions</label>
                            <div class="form-check">
                                <?php foreach ($allPermissions as $permKey => $permLabel): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $permKey ?>" id="new_perm_<?= $permKey ?>">
                                        <label class="form-check-label small" for="new_perm_<?= $permKey ?>"><?= escape($permLabel) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i>Create Role</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>