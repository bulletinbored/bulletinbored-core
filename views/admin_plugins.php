<?php include __DIR__.'/admin_header.php'; render_admin_header(t('plugins')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= t('plugin_manager') ?></h2>
    </div>

    <?php if ($adminPluginSuccess): ?>
        <div class="alert alert-success"><?= escape($adminPluginSuccess) ?></div>
    <?php endif; ?>
    <?php if ($adminPluginError): ?>
        <div class="alert alert-danger"><?= escape($adminPluginError) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('catalog_settings') ?></h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('allow_catalog_only') ?></label>
                            <select name="allow_catalog_only" class="form-select">
                                <option value="1" <?= (!empty($config['allow_catalog_only'])) ? 'selected' : '' ?>><?= t('yes') ?></option>
                                <option value="0" <?= (empty($config['allow_catalog_only'])) ? 'selected' : '' ?>><?= t('no') ?></option>
                            </select>
                            <div class="form-text"><?= t('allow_catalog_only_hint') ?></div>
                        </div>
                        <button type="submit" name="save_plugin_settings" value="1" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= t('save_settings') ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('install_plugin') ?></h6>
                </div>
                <div class="card-body">
                    <p class="mb-0">
                        <button type="button" class="btn btn-link text-warning p-0 text-start" onclick="var e=document.getElementById('pluginWarning');var o=e.classList.toggle('d-none');this.setAttribute('aria-expanded', o?'true':'false');" aria-expanded="false">
                            <i class="fas fa-exclamation-triangle"></i> Notice
                        </button>
                    </p>
                    <div class="mt-2 d-none" id="pluginWarning">
                        <p class="text-warning small mb-0">Third-party plugins are not developed by the bulletinbored team. Install at your own risk and report malicious plugins at <a href="https://www.bulletinbored.net/forum" target="_blank" rel="noopener" class="text-warning">www.bulletinbored.net/forum</a>.</p>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('zip_package') ?></label>
                            <input type="file" name="plugin_zip" accept=".zip" required class="form-control">
                            <div class="form-text"><?= t('plugin_zip_requirement') ?></div>
                        </div>
                        <button type="submit" name="install_plugin" value="1" class="btn btn-primary"><i class="fas fa-upload me-1"></i><?= t('install') ?></button>
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
                    <a href="<?= url('admin_catalog') ?>?type=plugin" class="btn btn-primary"><?= t('browse_catalog') ?></a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('installed_plugins') ?></h6>
                </div>
                <div class="card-body">
                    <?php if (empty($allPlugins)): ?>
                        <p class="text-muted"><?= t('no_plugins_installed') ?></p>
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
                                    <?php foreach ($allPlugins as $plugin): ?>
                                        <tr>
                                            <td><?= escape($plugin['name']) ?></td>
                                            <td><?= escape($plugin['description']) ?></td>
                                            <td><?= escape($plugin['version']) ?></td>
                                            <td>
                                                <?php if ($plugin['enabled']): ?>
                                                    <span class="badge bg-success"><?= t('enabled') ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?= t('disabled') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-inline-flex gap-2">
                                                    <form method="POST" onsubmit="return confirm('<?= t($plugin['enabled'] ? 'disable' : 'enable') ?> <?= t('plugin') ?> <?= escape($plugin['name']) ?>?')">
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
                                                    <form method="POST" onsubmit="return confirm('<?= t('delete') ?> <?= t('plugin') ?> <?= escape($plugin['name']) ?>?');">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                                        <button type="submit" name="delete_plugin" value="1" class="btn btn-sm btn-outline-danger" title="<?= t('delete') ?>">
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
