<?php include __DIR__.'/admin_header.php'; render_admin_header(t('themes')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= t('theme_manager') ?></h2>
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
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('select_active_theme') ?></h6>
                </div>
                <div class="card-body">
                    <form method="POST" class="d-flex align-items-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="flex-grow-1">
                            <label class="form-label"><?= t('active_theme') ?></label>
                            <select name="theme_name" class="form-select">
                                <?php foreach ($allThemes as $theme): ?>
                                    <option value="<?= escape($theme['name']) ?>" <?= $theme['active'] ? 'selected' : '' ?>><?= escape($theme['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="activate_theme" value="1" class="btn btn-primary"><i class="fas fa-check me-1"></i><?= t('activate') ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('install_theme') ?></h6>
                </div>
                <div class="card-body">
                    <p class="mb-0">
                        <button type="button" class="btn btn-link text-warning p-0 text-start" data-warning-toggle="themeWarning" aria-expanded="false">
                            <i class="fas fa-exclamation-triangle"></i> Notice
                        </button>
                    </p>
                    <div class="mt-2 d-none" id="themeWarning">
                        <p class="text-warning small mb-0">Third-party themes are not developed by the bulletinbored team. Install at your own risk and report malicious themes at <a href="https://www.bulletinbored.net/forum" target="_blank" rel="noopener" class="text-warning">www.bulletinbored.net/forum</a>.</p>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('zip_package') ?></label>
                            <input type="file" name="theme_zip" accept=".zip" required class="form-control">
                            <div class="form-text"><?= t('theme_zip_requirement') ?></div>
                        </div>
                        <button type="submit" name="install_theme" value="1" class="btn btn-primary"><i class="fas fa-upload me-1"></i><?= t('install') ?></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('catalog') ?></h6>
                </div>
                <div class="card-body">
                    <a href="<?= url('admin_catalog') ?>?type=theme" class="btn btn-primary"><?= t('browse_catalog') ?></a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('available_themes') ?></h6>
                </div>
                <div class="card-body">
                    <?php if (empty($allThemes)): ?>
                        <p class="text-muted"><?= t('no_themes_found') ?></p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th><?= t('name') ?></th>
                                        <th><?= t('description') ?></th>
                                        <th><?= t('version') ?></th>
                                        <th><?= t('status') ?></th>
                                        <th><?= t('actions') ?></th>
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
                                                    <span class="badge bg-success"><?= t('active') ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?= t('inactive') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$theme['active']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="theme_name" value="<?= escape($theme['name']) ?>">
                                                        <button type="submit" name="activate_theme" value="1" class="btn btn-sm btn-primary"><?= t('activate') ?></button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($theme['name'] !== 'freshbored'): ?>
                                                        <form method="POST" class="d-inline" data-confirm="<?= t('delete') ?> <?= t('theme') ?> <?= escape($theme['name']) ?>?">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="theme_name" value="<?= escape($theme['name']) ?>">
                                                            <button type="submit" name="delete_theme" value="1" class="btn btn-sm btn-danger"><?= t('delete') ?></button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted"><?= t('default') ?></span>
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
