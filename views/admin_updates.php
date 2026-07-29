<?php include __DIR__.'/admin_header.php'; render_admin_header('Updates'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Update Manager</h2>
        <a href="<?= url('admin') ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
    </div>

    <?php if ($updateSuccess): ?>
        <div class="alert alert-success"><?= escape($updateSuccess) ?></div>
    <?php endif; ?>
    <?php if ($updateError): ?>
        <div class="alert alert-danger"><?= escape($updateError) ?></div>
    <?php endif; ?>

    <form method="POST" class="mb-4">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <button type="submit" name="check_updates" value="1" class="btn btn-primary"><i class="fas fa-sync me-1"></i>Check for Updates</button>
    </form>

    <?php if ($updateStatus !== null): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Update Status</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card border-left-<?= ($updateStatus['core']['update_available'] ?? false) ? 'warning' : 'success' ?>">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-uppercase text-muted mb-1">Core</div>
                                <div class="h5 mb-0">v<?= escape($updateStatus['core']['installed']) ?></div>
                                <?php if ($updateStatus['core']['remote'] ?? null): ?>
                                    <small class="text-muted">Remote: v<?= escape($updateStatus['core']['remote']) ?></small>
                                <?php endif; ?>
                                <?php if ($updateStatus['core']['update_available'] ?? false): ?>
                                    <br><span class="badge bg-warning">Update Available</span>
                                <?php else: ?>
                                    <br><span class="badge bg-success">Up to date</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border-left-info">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-uppercase text-muted mb-1">Plugins</div>
                                <div class="h5 mb-0"><?= count($updateStatus['plugins'] ?? []) ?></div>
                                <small class="text-muted">Tracked</small>
                                <?php $pluginUpdates = array_filter($updateStatus['plugins'] ?? [], fn($i) => $i['update_available'] ?? false); ?>
                                <?php if ($pluginUpdates): ?>
                                    <br><span class="badge bg-warning"><?= count($pluginUpdates) ?> Update(s)</span>
                                <?php else: ?>
                                    <br><span class="badge bg-success">All up to date</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border-left-primary">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-uppercase text-muted mb-1">Themes</div>
                                <div class="h5 mb-0"><?= count($updateStatus['themes'] ?? []) ?></div>
                                <small class="text-muted">Tracked</small>
                                <?php $themeUpdates = array_filter($updateStatus['themes'] ?? [], fn($i) => $i['update_available'] ?? false); ?>
                                <?php if ($themeUpdates): ?>
                                    <br><span class="badge bg-warning"><?= count($themeUpdates) ?> Update(s)</span>
                                <?php else: ?>
                                    <br><span class="badge bg-success">All up to date</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($pluginUpdates) || !empty($themeUpdates) || ($updateStatus['core']['update_available'] ?? false)): ?>
                    <div class="mt-4">
                        <h6>Available Updates</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr><th>Component</th><th>Type</th><th>Installed</th><th>Remote</th><th>Apply Update</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($updateStatus['core']['update_available'] ?? false): ?>
                                        <tr>
                                            <td>Core</td>
                                            <td><span class="badge bg-warning text-dark">Core</span></td>
                                            <td>v<?= escape($updateStatus['core']['installed']) ?></td>
                                            <td>v<?= escape($updateStatus['core']['remote']) ?></td>
                                            <td>
                                                <form method="POST" enctype="multipart/form-data" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                    <input type="hidden" name="type" value="core">
                                                    <input type="hidden" name="name" value="core">
                                                    <input type="file" name="update_package" accept=".zip" required class="form-control form-control-sm d-inline-block" style="width: auto;">
                                                    <button type="submit" name="apply_update" value="1" class="btn btn-sm btn-success">Apply</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($pluginUpdates as $name => $info): ?>
                                        <tr>
                                            <td><?= escape($name) ?></td>
                                            <td><span class="badge bg-info">Plugin</span></td>
                                            <td>v<?= escape($info['installed']) ?></td>
                                            <td>v<?= escape($info['remote']) ?></td>
                                            <td>
                                                <form method="POST" enctype="multipart/form-data" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                    <input type="hidden" name="type" value="plugins">
                                                    <input type="hidden" name="name" value="<?= escape($name) ?>">
                                                    <input type="file" name="update_package" accept=".zip" required class="form-control form-control-sm d-inline-block" style="width: auto;">
                                                    <button type="submit" name="apply_update" value="1" class="btn btn-sm btn-success">Apply</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($themeUpdates as $name => $info): ?>
                                        <tr>
                                            <td><?= escape($name) ?></td>
                                            <td><span class="badge bg-primary">Theme</span></td>
                                            <td>v<?= escape($info['installed']) ?></td>
                                            <td>v<?= escape($info['remote']) ?></td>
                                            <td>
                                                <form method="POST" enctype="multipart/form-data" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                    <input type="hidden" name="type" value="themes">
                                                    <input type="hidden" name="name" value="<?= escape($name) ?>">
                                                    <input type="file" name="update_package" accept=".zip" required class="form-control form-control-sm d-inline-block" style="width: auto;">
                                                    <button type="submit" name="apply_update" value="1" class="btn btn-sm btn-success">Apply</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mt-4 alert alert-success">
                        <i class="fas fa-check-circle me-1"></i>All components are up to date.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow">
            <div class="card-body text-center py-5">
                <i class="fas fa-arrow-up fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No update information yet</h5>
                <p class="text-muted">Click "Check for Updates" to check for available updates for core, plugins, and themes.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
