<?php include __DIR__.'/admin_header.php'; render_admin_header(t('themes')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('theme_manager') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('themes') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('admin_catalog') ?>?type=theme" class="btn btn-outline-primary">
                <i class="fas fa-store me-1"></i> <?= t('browse_catalog') ?>
            </a>
        </div>
    </div>

    <?php if ($adminThemeSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= escape($adminThemeSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($adminThemeError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?= escape($adminThemeError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Install Section -->
    <div class="row mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-upload me-2"></i><?= t('install_theme') ?></h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 small mb-3">
                        <i class="fas fa-exclamation-triangle mt-05"></i>
                        <span><?= t('theme_warning_text') ?></span>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('zip_package') ?></label>
                            <input type="file" name="theme_zip" accept=".zip" required class="form-control">
                            <div class="form-text"><?= t('theme_zip_requirement') ?></div>
                        </div>
                        <button type="submit" name="install_theme" value="1" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i> <?= t('install') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i><?= t('theme_how_it_works') ?></h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                                <i class="fas fa-store text-primary"></i>
                            </div>
                            <p class="small text-gray-600 mb-0"><?= t('theme_step_browse') ?></p>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                                <i class="fas fa-download text-primary"></i>
                            </div>
                            <p class="small text-gray-600 mb-0"><?= t('theme_step_install') ?></p>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px">
                                <i class="fas fa-palette text-primary"></i>
                            </div>
                            <p class="small text-gray-600 mb-0"><?= t('theme_step_enable') ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- Active Theme Selector -->
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-palette me-2"></i><?= t('select_active_theme') ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="col-md-8">
                            <label class="form-label"><?= t('active_theme') ?></label>
                            <select name="theme_name" class="form-select">
                                <?php foreach ($allThemes as $theme): ?>
                                    <option value="<?= escape($theme['name']) ?>" <?= $theme['active'] ? 'selected' : '' ?>><?= escape($theme['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="activate_theme" value="1" class="btn btn-primary">
                                <i class="fas fa-check me-1"></i> <?= t('activate') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- Catalog Settings -->
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-shield-halved me-2"></i><?= t('catalog_settings') ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="themeSettingsForm">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="save_theme_settings" value="1">
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
        </div>
    </div>

    <!-- Installed Themes -->
    <h5 class="mb-3"><i class="fas fa-images me-2"></i><?= t('available_themes') ?></h5>

    <?php if (empty($allThemes)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-palette fa-3x text-gray-400 mb-3"></i>
                <h5 class="text-gray-600"><?= t('no_themes_found') ?></h5>
                <p class="text-gray-500 small mb-3"><?= t('theme_get_started') ?></p>
                <a href="<?= url('admin_catalog') ?>?type=theme" class="btn btn-primary">
                    <i class="fas fa-store me-1"></i> <?= t('browse_catalog') ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($allThemes as $theme): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="card shadow-sm h-100 theme-card">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0 fw-bold"><?= escape($theme['name']) ?></h6>
                                <span class="badge <?= $theme['active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $theme['active'] ? t('active') : t('inactive') ?>
                                </span>
                            </div>
                            <p class="text-gray-600 small flex-grow-1"><?= escape($theme['description'] ?? t('no_description')) ?></p>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                <span class="text-gray-500 small">v<?= escape($theme['version'] ?? '1.0') ?></span>
                                <div class="d-inline-flex gap-1">
                                    <?php if (!$theme['active']): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="theme_name" value="<?= escape($theme['name']) ?>">
                                            <button type="submit" name="activate_theme" value="1" class="btn btn-sm btn-outline-primary" title="<?= t('activate') ?>">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($theme['name'] !== 'freshbored'): ?>
                                        <form method="POST" data-confirm="<?= t('delete') ?> <?= t('theme') ?> <?= escape($theme['name']) ?>?">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="theme_name" value="<?= escape($theme['name']) ?>">
                                            <button type="submit" name="delete_theme" value="1" class="btn btn-sm btn-outline-danger" title="<?= t('delete') ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
