<?php include __DIR__.'/admin_header.php'; render_admin_header(t('plugins')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('plugin_manager') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('plugins') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('admin_catalog') ?>?type=plugin" class="btn btn-outline-primary">
                <i class="fas fa-store me-1"></i> <?= t('browse_catalog') ?>
            </a>
        </div>
    </div>

    <?php if ($adminPluginSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= escape($adminPluginSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($adminPluginError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?= escape($adminPluginError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Install Section -->
    <div class="row mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-upload me-2"></i><?= t('install_plugin') ?></h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 small mb-3">
                        <i class="fas fa-exclamation-triangle mt-05"></i>
                        <span><?= t('plugin_warning_text') ?></span>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('zip_package') ?></label>
                            <input type="file" name="plugin_zip" accept=".zip" required class="form-control">
                            <div class="form-text"><?= t('plugin_zip_requirement') ?></div>
                        </div>
                        <button type="submit" name="install_plugin" value="1" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i> <?= t('install') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i><?= t('plugin_how_it_works') ?></h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                                <i class="fas fa-store text-primary"></i>
                            </div>
                            <p class="small text-gray-600 mb-0"><?= t('plugin_step_browse') ?></p>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                                <i class="fas fa-download text-primary"></i>
                            </div>
                            <p class="small text-gray-600 mb-0"><?= t('plugin_step_install') ?></p>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                                <i class="fas fa-toggle-on text-primary"></i>
                            </div>
                            <p class="small text-gray-600 mb-0"><?= t('plugin_step_enable') ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Installed Plugins -->
    <h5 class="mb-3"><i class="fas fa-puzzle-piece me-2"></i><?= t('installed_plugins') ?></h5>

    <?php if (empty($allPlugins)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-puzzle-piece fa-3x text-gray-400 mb-3"></i>
                <h5 class="text-gray-600"><?= t('no_plugins_installed') ?></h5>
                <p class="text-gray-500 small mb-3"><?= t('plugin_get_started') ?></p>
                <a href="<?= url('admin_catalog') ?>?type=plugin" class="btn btn-primary">
                    <i class="fas fa-store me-1"></i> <?= t('browse_catalog') ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($allPlugins as $plugin): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="card shadow-sm h-100 plugin-card">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0 fw-bold"><?= escape($plugin['name']) ?></h6>
                                <span class="badge <?= $plugin['enabled'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $plugin['enabled'] ? t('enabled') : t('disabled') ?>
                                </span>
                            </div>
                            <p class="text-gray-600 small flex-grow-1"><?= escape($plugin['description'] ?? t('no_description')) ?></p>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                <span class="text-gray-500 small">v<?= escape($plugin['version'] ?? '1.0') ?></span>
                                <div class="d-inline-flex gap-1">
                                    <form method="POST" data-confirm="<?= t($plugin['enabled'] ? 'disable' : 'enable') ?> <?= t('plugin') ?> <?= escape($plugin['name']) ?>?">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                        <?php if ($plugin['enabled']): ?>
                                            <button type="submit" name="action" value="disable" class="btn btn-sm btn-outline-warning" title="<?= t('disable') ?>">
                                                <i class="fas fa-toggle-on"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="enable" class="btn btn-sm btn-outline-success" title="<?= t('enable') ?>">
                                                <i class="fas fa-toggle-off"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                    <form method="POST" data-confirm="<?= t('delete') ?> <?= t('plugin') ?> <?= escape($plugin['name']) ?>?">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                        <button type="submit" name="delete_plugin" value="1" class="btn btn-sm btn-outline-danger" title="<?= t('delete') ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Settings -->
    <div class="card shadow-sm mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-cog me-2"></i><?= t('plugin_settings') ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" id="pluginSettingsForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="save_plugin_settings" value="1">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <label class="form-label mb-0"><?= t('allow_catalog_only') ?></label>
                        <div class="form-text mb-0"><?= t('allow_catalog_only_hint') ?></div>
                    </div>
                    <div class="form-check form-switch">
                        <input type="hidden" name="allow_catalog_only" value="0">
                        <input class="form-check-input" type="checkbox" name="allow_catalog_only" value="1" id="catalogOnlySwitch" <?= (!empty($config['allow_catalog_only'])) ? 'checked' : '' ?>>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
    document.getElementById('catalogOnlySwitch').addEventListener('change', function() {
        document.getElementById('pluginSettingsForm').submit();
    });
    </script>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
