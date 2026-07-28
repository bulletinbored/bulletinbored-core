<?php 
global $pdo;
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header('Users'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Users Management</h2>
        <a href="<?= url('admin') ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
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
                            <th>Registered</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= escape($u['username']) ?></td>
                            <td><?= escape($u['email'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'bg-warning' : 'bg-info' ?>">
                                    <?= escape(ucfirst($u['role'])) ?>
                                </span>
                            </td>
                            <td><?= escape($u['created_at'] ?? 'N/A') ?></td>
                            <td class="text-end">
                                <?php if ($u['role'] !== 'admin'): ?>
                                <form method="POST" action="<?= url('delete_user', ['id' => $u['id']]) ?>" class="d-inline" onsubmit="return confirm('Delete this user?')">
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