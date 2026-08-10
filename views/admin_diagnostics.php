<?php include __DIR__.'/admin_header.php'; render_admin_header('Diagnostics'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>System Diagnostics</h2>
    </div>

    <?php if ($diag['can_install']): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            One-click plugin/theme installation from the catalog is supported on this server.
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            One-click installation may not work. See details below.
        </div>
    <?php endif; ?>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Requirements</h6>
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr><th>Check</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>PHP version</td>
                        <td><?= escape($diag['php_version']) ?></td>
                    </tr>
                    <tr>
                        <td>PHP <code>zip</code> extension (extracts packages)</td>
                        <td><?= $diag['zip'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Missing</span>' ?></td>
                    </tr>
                    <tr>
                        <td>PHP <code>curl</code> extension (downloads packages)</td>
                        <td><?= $diag['curl'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                    </tr>
                    <tr>
                        <td><code>allow_url_fopen</code> (alternative download)</td>
                        <td><?= $diag['allow_url_fopen'] ? '<span class="badge bg-success">On</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
                    </tr>
                    <tr>
                        <td>Git (optional, used if present)</td>
                        <td><?= $diag['git'] ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-secondary">Not available</span>' ?></td>
                    </tr>
                    <tr>
                        <td>Reach GitHub over HTTPS</td>
                        <td>
                            <?= $diag['github_reachable'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Failed</span>' ?>
                            <?php if (!$diag['github_reachable'] && !empty($diag['github_error'])): ?>
                                <div class="small text-danger mt-1"><?= escape($diag['github_error']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Recommendations</h6>
        </div>
        <div class="card-body">
            <?php if (empty($recommendations)): ?>
                <p class="text-muted mb-0">Nothing to report.</p>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach ($recommendations as $rec): ?>
                        <li><?= $rec ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <a href="<?= url('admin_plugins') ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to plugins</a>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
