<?php include __DIR__.'/admin_header.php'; render_admin_header(t('edit_user') . ' ' . escape($editUser['username'])); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><?= t('edit_user') ?>: <?= escape($editUser['username']) ?></h2>
        <a href="<?= url('admin_users') ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i><?= t('back_to_users') ?></a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                <div class="mb-3">
                    <label class="form-label"><?= t('username') ?></label>
                    <input type="text" name="username" class="form-control" value="<?= escape($editUser['username']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-control" value="<?= escape($editUser['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('role') ?></label>
                    <select name="role" class="form-select">
                        <option value="user" <?= $editUser['role'] === 'user' ? 'selected' : '' ?>><?= t('role_user') ?></option>
                        <option value="moderator" <?= $editUser['role'] === 'moderator' ? 'selected' : '' ?>><?= t('role_moderator') ?></option>
                        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>><?= t('role_admin') ?></option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('status') ?></label>
                    <select name="status" class="form-select">
                        <option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>><?= t('active') ?></option>
                        <option value="suspended" <?= $editUser['status'] === 'suspended' ? 'selected' : '' ?>><?= t('suspended') ?></option>
                        <option value="banned" <?= $editUser['status'] === 'banned' ? 'selected' : '' ?>><?= t('banned') ?></option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= t('save_changes') ?></button>
                <a href="<?= url('admin_users') ?>" class="btn btn-secondary"><?= t('cancel') ?></a>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>