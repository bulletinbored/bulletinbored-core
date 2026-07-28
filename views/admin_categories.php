<?php 
global $pdo;
$categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header('Categories'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Categories Management</h2>
    </div>

    <div class="row mb-4">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h5 class="card-title mb-0"><i class="fas fa-folder me-2"></i>Forum Categories</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Position</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?= escape($cat['name']) ?></td>
                                    <td><?= escape($cat['description'] ?? '') ?></td>
                                    <td><?= escape($cat['position']) ?></td>
                                    <td class="text-end">
                                        <form method="POST" action="<?= base_url() ?>/?action=edit_category&id=<?= $cat['id'] ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <button class="btn btn-sm btn-warning"><i class="fas fa-pen"></i></button>
                                        </form>
                                        <form method="POST" action="<?= base_url() ?>/?action=delete_category&id=<?= $cat['id'] ?>" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
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
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-plus me-2"></i>Add New Category</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= base_url() ?>/?action=create_category" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add Category</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>