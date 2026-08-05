<?php include __DIR__.'/admin_header.php'; render_admin_header('Themes'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Theme Manager</h2>
    </div>

    <?php if ($adminThemeSuccess): ?>
        <div class="alert alert-success"><?= escape($adminThemeSuccess) ?></div>
    <?php endif; ?>
    <?php if ($adminThemeError): ?>
        <div class="alert alert-danger"><?= escape($adminThemeError) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Select Active Theme</h6>
                </div>
                <div class="card-body">
                    <form method="POST" class="d-flex align-items-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="flex-grow-1">
                            <label class="form-label">Active Theme</label>
                            <select name="theme_name" class="form-select">
                                <?php foreach ($allThemes as $theme): ?>
                                    <option value="<?= escape($theme['name']) ?>" <?= $theme['active'] ? 'selected' : '' ?>><?= escape($theme['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="activate_theme" value="1" class="btn btn-primary"><i class="fas fa-check me-1"></i>Activate</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Install Theme</h6>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label">ZIP Package</label>
                            <input type="file" name="theme_zip" accept=".zip" required class="form-control">
                            <div class="form-text">ZIP must contain a folder with <code>style.css</code> and optional <code>manifest.json</code>.</div>
                        </div>
                        <button type="submit" name="install_theme" value="1" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Install</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Available Themes</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($allThemes)): ?>
                        <p class="text-muted">No themes found. Place theme folders in the <code>themes/</code> directory or upload a ZIP.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Version</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allThemes as $theme): ?>
                                        <tr>
                                            <td><?= escape($theme['name']) ?></td>
                                            <td><?= escape($theme['description']) ?></td>
                                            <td><?= escape($theme['version']) ?></td>
                                            <td>
                                                <?php if ($theme['active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$theme['active']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="theme_name" value="<?= escape($theme['name']) ?>">
                                                        <button type="submit" name="activate_theme" value="1" class="btn btn-sm btn-primary">Activate</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($theme['name'] !== 'freshbored'): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete theme <?= escape($theme['name']) ?>?');">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="theme_name" value="<?= escape($theme['name']) ?>">
                                                        <button type="submit" name="delete_theme" value="1" class="btn btn-sm btn-danger">Delete</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted">Default</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
