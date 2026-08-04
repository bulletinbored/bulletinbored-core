<?php include __DIR__.'/admin_header.php'; render_admin_header('Edit User: ' . escape($editUser['username'])); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit User: <?= escape($editUser['username']) ?></h2>
        <a href="<?= url('admin_users') ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Users</a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= escape($editUser['username']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= escape($editUser['email'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select">
                        <option value="user" <?= $editUser['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="moderator" <?= $editUser['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $editUser['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        <option value="banned" <?= $editUser['status'] === 'banned' ? 'selected' : '' ?>>Banned</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                <a href="<?= url('admin_users') ?>" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>