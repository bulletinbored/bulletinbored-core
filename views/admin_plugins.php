<?php include __DIR__.'/admin_header.php'; render_admin_header('Plugins'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Plugin Manager</h2>
    </div>

    <?php if ($adminPluginSuccess): ?>
        <div class="alert alert-success"><?= escape($adminPluginSuccess) ?></div>
    <?php endif; ?>
    <?php if ($adminPluginError): ?>
        <div class="alert alert-danger"><?= escape($adminPluginError) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Install Plugin</h6>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label">ZIP Package</label>
                            <input type="file" name="plugin_zip" accept=".zip" required class="form-control">
                        </div>
                        <button type="submit" name="install_plugin" value="1" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Install</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Installed Plugins</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($allPlugins)): ?>
                        <p class="text-muted">No plugins installed. Place PHP plugin files in the <code>plugins/</code> directory or upload a ZIP.</p>
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
                                    <?php foreach ($allPlugins as $plugin): ?>
                                        <tr>
                                            <td><?= escape($plugin['name']) ?></td>
                                            <td><?= escape($plugin['description']) ?></td>
                                            <td><?= escape($plugin['version']) ?></td>
                                            <td>
                                                <?php if ($plugin['enabled']): ?>
                                                    <span class="badge bg-success">Enabled</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Disabled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-inline-flex gap-2">
                                                    <form method="POST" onsubmit="return confirm('<?= $plugin['enabled'] ? 'Disable' : 'Enable' ?> plugin <?= escape($plugin['name']) ?>?')">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                                        <?php if ($plugin['enabled']): ?>
                                                            <button type="submit" name="action" value="disable" class="btn btn-sm btn-outline-warning" title="Disable">
                                                                <i class="fas fa-toggle-on"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="submit" name="action" value="enable" class="btn btn-sm btn-outline-success" title="Enable">
                                                                <i class="fas fa-toggle-off"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Delete plugin <?= escape($plugin['name']) ?>?');">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                                        <button type="submit" name="delete_plugin" value="1" class="btn btn-sm btn-outline-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
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
