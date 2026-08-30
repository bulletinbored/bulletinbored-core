<?php 
global $pdo;
$categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
$allRoles = $pdo->query("SELECT name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$csrf = generate_csrf_token();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header(t('categories')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('categories_management') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('categories') ?></p>
        </div>
        <button id="save-order-btn" class="btn btn-outline-primary" disabled>
            <i class="fas fa-save me-1"></i><?= t('save_order') ?>
        </button>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-folder me-2"></i><?= t('forum_categories') ?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                          <tr>
                              <th style="width:40px"></th>
                              <th><?= t('name') ?></th>
                              <th><?= t('description') ?></th>
                              <th><?= t('position') ?></th>
                              <th><?= t('allowed_roles') ?></th>
                              <th class="text-end"><?= t('actions') ?></th>
                          </tr>
                    </thead>
                    <tbody id="categories-sortable">
                        <?php foreach ($categories as $cat): ?>
                        <tr data-id="<?= $cat['id'] ?>">
                            <td><i class="fas fa-grip-vertical text-muted"></i></td>
                            <td><?= escape($cat['name']) ?></td>
                            <td><?= escape($cat['description'] ?? '') ?></td>
                            <td class="position-display"><?= escape($cat['position']) ?></td>
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
                                <button class="btn btn-sm btn-warning"
                                    data-toggle-target="edit-form-<?= $cat['id'] ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form method="POST" action="<?= url('delete_category', ['id' => $cat['id']]) ?>" class="d-inline" data-confirm="<?= t('delete_confirm') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr id="edit-form-<?= $cat['id'] ?>" class="d-none">
                            <td colspan="6">
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
                     <label class="form-label small fw-bold d-flex align-items-center">
                         <?= t('allowed_roles') ?>
                         <i class="fas fa-question-circle ms-1 text-muted small" title="<?= t('allowed_roles_hint') ?>" style="cursor:help;"></i>
                     </label>
                     <select name="allowed_roles" class="form-select">
                         <option value="all" <?= ($cat['allowed_roles'] ?? 'all') === 'all' ? 'selected' : '' ?>><?= t('allowed_roles_everybody') ?></option>
                         <option value="admin" <?= ($cat['allowed_roles'] ?? '') === 'admin' ? 'selected' : '' ?>><?= t('allowed_roles_admin') ?></option>
                         <option value="moderator" <?= ($cat['allowed_roles'] ?? '') === 'moderator' ? 'selected' : '' ?>><?= t('allowed_roles_moderator') ?></option>
                     </select>
                 </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <div class="w-100">
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
                                            <button type="button" class="btn btn-sm btn-secondary" data-close-target="edit-form-<?= $cat['id'] ?>"><?= t('cancel') ?></button>
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

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-plus me-2"></i><?= t('add_new_category') ?></h5>
        </div>
        <div class="card-body">
             <form method="POST" action="<?= url('admin_categories') ?>" class="row g-3 align-items-end">
                 <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                 <div class="col-md-3">
                     <label class="form-label"><?= t('name') ?></label>
                     <input type="text" name="name" class="form-control" required>
                 </div>
                 <div class="col-md-3">
                     <label class="form-label"><?= t('description') ?></label>
                     <input type="text" name="description" class="form-control">
                 </div>
                 <div class="col-md-3">
                     <label class="form-label d-flex align-items-center">
                         <?= t('allowed_roles') ?>
                         <i class="fas fa-question-circle ms-1 text-muted small" title="<?= t('allowed_roles_hint') ?>" style="cursor:help;"></i>
                     </label>
                     <select name="allowed_roles" class="form-select">
                         <option value="all"><?= t('allowed_roles_everybody') ?></option>
                         <option value="admin"><?= t('allowed_roles_admin') ?></option>
                         <option value="moderator"><?= t('allowed_roles_moderator') ?></option>
                     </select>
                 </div>
                 <div class="col-md-3">
                     <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i><?= t('add_category') ?></button>
                 </div>
             </form>
         </div>
     </div>
</div>

<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
var UPDATE_ORDER_URL = '<?= url('update_category_order') ?>';
var SAVE_TEXT = '<?= t('save') ?>';
var ERROR_TEXT = '<?= t('error_occurred') ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="<?= htmlspecialchars(base_url() . '/assets/js/admin-categories.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include __DIR__.'/admin_footer.php'; ?>