<?php 
global $pdo;
$categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
$allRoles = $pdo->query("SELECT name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header(t('categories')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><?= t('categories_management') ?></h2>
    </div>

    <div class="row mb-4">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h5 class="card-title mb-0"><i class="fas fa-folder me-2"></i><?= t('forum_categories') ?></h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                 <tr>
                                     <th><?= t('name') ?></th>
                                     <th><?= t('description') ?></th>
                                     <th><?= t('position') ?></th>
                                     <th><?= t('allowed_roles') ?></th>
                                     <th class="text-end"><?= t('actions') ?></th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?= escape($cat['name']) ?></td>
                                    <td><?= escape($cat['description'] ?? '') ?></td>
                                    <td><?= escape($cat['position']) ?></td>
                                    <td><?php
                                        $allowed = $cat['allowed_roles'] ?? null;
                                        if ($allowed === null || $allowed === '' || $allowed === 'all') {
                                            echo '<span class="text-muted">' . t('allowed_roles_everybody') . '</span>';
                                        } elseif ($allowed === 'admin') {
                                            echo t('allowed_roles_admin');
                                        } elseif ($allowed === 'moderator') {
                                            echo t('allowed_roles_moderator');
                                        } else {
                                            $decoded = json_decode($allowed, true);
                                            if ($decoded && is_array($decoded)) {
                                                echo escape(implode(', ', $decoded));
                                            } else {
                                                echo escape($allowed);
                                            }
                                        }
                                    ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-warning" onclick="document.getElementById('edit-form-<?= $cat['id'] ?>').classList.toggle('d-none')">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <form method="POST" action="<?= url('delete_category', ['id' => $cat['id']]) ?>" class="d-inline" onsubmit="return confirm('<?= t('delete_confirm') ?>')">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="edit-form-<?= $cat['id'] ?>" class="d-none">
                                    <td colspan="5">
                                        <form method="POST" action="<?= url('admin_categories', ['id' => $cat['id']]) ?>" class="row g-2 p-3 bg-light rounded">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold"><?= t('name') ?></label>
                                                <input type="text" name="name" class="form-control" value="<?= escape($cat['name']) ?>" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold"><?= t('description') ?></label>
                                                <input type="text" name="description" class="form-control" value="<?= escape($cat['description'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold"><?= t('allowed_roles') ?></label>
                                                <select name="allowed_roles" class="form-select">
                                                    <option value="all" <?= ($cat['allowed_roles'] ?? 'all') === 'all' ? 'selected' : '' ?>><?= t('allowed_roles_everybody') ?></option>
                                                    <option value="admin" <?= ($cat['allowed_roles'] ?? '') === 'admin' ? 'selected' : '' ?>><?= t('allowed_roles_admin') ?></option>
                                                    <option value="moderator" <?= ($cat['allowed_roles'] ?? '') === 'moderator' ? 'selected' : '' ?>><?= t('allowed_roles_moderator') ?></option>
                                                </select>
                                                <div class="form-text"><?= t('allowed_roles_hint') ?></div>
                                            </div>
                                            <div class="col-md-3 d-flex align-items-end">
                                                <div class="w-100">
                                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
                                                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('edit-form-<?= $cat['id'] ?>').classList.add('d-none')"><?= t('cancel') ?></button>
                                                </div>
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
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-plus me-2"></i><?= t('add_new_category') ?></h5>
                </div>
                <div class="card-body">
                     <form method="POST" action="<?= url('admin_categories') ?>" class="row g-3">
                         <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                         <div class="col-md-4">
                             <label class="form-label"><?= t('name') ?></label>
                             <input type="text" name="name" class="form-control" required>
                         </div>
                         <div class="col-md-4">
                             <label class="form-label"><?= t('description') ?></label>
                             <input type="text" name="description" class="form-control">
                         </div>
                           <div class="col-md-4">
                               <label class="form-label"><?= t('allowed_roles') ?></label>
                               <select name="allowed_roles" class="form-select">
                                   <option value="all"><?= t('allowed_roles_everybody') ?></option>
                                   <option value="admin"><?= t('allowed_roles_admin') ?></option>
                                   <option value="moderator"><?= t('allowed_roles_moderator') ?></option>
                               </select>
                               <div class="form-text"><?= t('allowed_roles_hint') ?></div>
                           </div>
                         <div class="col-md-12">
                             <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i><?= t('add_category') ?></button>
                         </div>
                     </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>