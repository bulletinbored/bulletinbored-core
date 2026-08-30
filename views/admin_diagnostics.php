<?php include __DIR__.'/admin_header.php'; render_admin_header(t('diagnostics')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('system_diagnostics') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('diagnostics') ?></p>
        </div>
    </div>

    <?php if ($diag['can_install']): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?= t('one_click_install_supported') ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= t('one_click_install_may_not_work') ?>
        </div>
    <?php endif; ?>

    <div class="card shadow mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-check-circle me-2"></i><?= t('requirements') ?></h5>
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr><th><?= t('check') ?></th><th><?= t('status') ?></th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= t('php_version') ?></td>
                        <td><?= escape($diag['php_version']) ?></td>
                    </tr>
                    <tr>
                        <td><?= t('zip_extension') ?></td>
                        <td><?= $diag['zip'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Missing</span>' ?></td>
                    </tr>
                    <tr>
                        <td><?= t('curl_extension') ?></td>
                        <td><?= $diag['curl'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                    </tr>
                    <tr>
                        <td><?= t('allow_url_fopen_setting') ?></td>
                        <td><?= $diag['allow_url_fopen'] ? '<span class="badge bg-success">On</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
                    </tr>
                    <tr>
                        <td><?= t('git_optional') ?></td>
                        <td><?= $diag['git'] ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-secondary">' . t('git_not_available') . '</span>' ?></td>
                    </tr>
                    <tr>
                        <td><?= t('reach_github_https') ?></td>
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
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-clipboard-list me-2"></i><?= t('recommendations') ?></h5>
        </div>
        <div class="card-body">
            <?php if (empty($recommendations)): ?>
                <p class="text-muted mb-0"><?= t('nothing_to_report') ?></p>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach ($recommendations as $rec): ?>
                        <li><?= $rec ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
