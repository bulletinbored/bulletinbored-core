<?php include __DIR__.'/admin_header.php'; render_admin_header(t('catalog')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><?= t('catalog') ?></h2>
    </div>

    <?php if ($adminCatalogSuccess): ?>
        <div class="alert alert-success"><?= escape($adminCatalogSuccess) ?></div>
    <?php endif; ?>
    <?php if ($adminCatalogError): ?>
        <div class="alert alert-danger"><?= escape($adminCatalogError) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-6">
            <form method="GET" class="d-flex">
                <input type="text" name="q" class="form-control me-2" placeholder="<?= t('search_catalog') ?>" value="<?= escape($search ?? '') ?>">
                <button type="submit" class="btn btn-primary"><?= t('search') ?></button>
            </form>
        </div>
        <div class="col-md-6 mt-2 mt-md-0">
            <div class="btn-group">
                <a href="?q=<?= urlencode($search ?? '') ?>&type=" class="btn btn-sm btn-outline-primary <?= ($typeFilter ?? '') === '' ? 'active' : '' ?>">All</a>
                <a href="?q=<?= urlencode($search ?? '') ?>&type=plugin" class="btn btn-sm btn-outline-primary <?= ($typeFilter ?? '') === 'plugin' ? 'active' : '' ?>">Plugin</a>
                <a href="?q=<?= urlencode($search ?? '') ?>&type=theme" class="btn btn-sm btn-outline-primary <?= ($typeFilter ?? '') === 'theme' ? 'active' : '' ?>">Temi</a>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><?= t('available_items') ?></h6>
        </div>
        <div class="card-body">
            <?php if (empty($catalog)): ?>
                <p class="text-muted"><?= t('no_items_in_catalog') ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th><?= t('name') ?></th>
                                <th><?= t('description') ?></th>
                                <th><?= t('author') ?></th>
                                <th><?= t('version') ?></th>
                                <th><?= t('type') ?></th>
                                <th><?= t('status') ?></th>
                                <th><?= t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($catalog as $item): ?>
                                <?php
                                    $itemName = strtolower($item['name'] ?? '');
                                    $repo = $item['repo'] ?? '';
                                    $type = strtolower($item['type'] ?? '');
                                    $latestTag = $catalogRemoteVersions[$itemName] ?? '';
                                    if (!$latestTag && !empty($item['version'])) {
                                        $latestTag = $item['version'];
                                    }
                                    $installedItem = $installed['plugins'][$itemName] ?? $installed['themes'][$itemName] ?? null;
                                    $installedVersion = $installedItem['version'] ?? '';
                                    $isInstalled = $installedItem !== null;
                                    $updateAvailable = $isInstalled && $latestTag && $latestTag !== $installedVersion;
                                ?>
                                <tr>
                                    <td>
                                        <?= escape($item['name']) ?>
                                        <?php if (($item['author_type'] ?? '') === 'first_party'): ?>
                                            <span class="badge bg-info ms-1">bulletinbored</span>
                                        <?php elseif (($item['author_type'] ?? '') === 'third_party'): ?>
                                            <span class="badge bg-warning ms-1">third-party</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= escape($item['description']) ?></td>
                                    <td><?= escape($item['author'] ?? '-') ?></td>
                                    <td><?= escape($latestTag ?: '-') ?></td>
                                    <td>
                                        <span class="badge bg-<?= $type === 'plugin' ? 'primary' : 'secondary' ?>">
                                            <?= escape(ucfirst($type)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isInstalled): ?>
                                            <span class="badge bg-success"><?= t('installed') ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= t('not_installed') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $isThird = ($item['author_type'] ?? '') === 'third_party'; ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="name" value="<?= escape($itemName) ?>">
                                            <input type="hidden" name="type" value="<?= escape($type) ?>">
                                            <input type="hidden" name="repo" value="<?= escape($repo) ?>">
                                            <input type="hidden" name="tag" value="<?= escape($latestTag) ?>">
                                            <?php if ($isInstalled): ?>
                                                <?php if ($updateAvailable): ?>
                                                    <?php if ($isThird): ?>
                                                        <p class="mb-0">
                                                            <button type="button" class="btn btn-link text-warning p-0 text-start" data-warning-toggle="catalogWarning<?= escape($itemName) ?>_update" aria-expanded="false">
                                                                <i class="fas fa-exclamation-triangle"></i> Notice
                                                            </button>
                                                        </p>
                                                        <div class="mt-2 d-none" id="catalogWarning<?= escape($itemName) ?>_update">
<p class="text-warning small mb-2">Third-party <?= $type ?>s are not developed by the bulletinbored team. Install at your own risk and report malicious <?= $type ?>s at <a href="https://www.bulletinbored.net/forum" target="_blank" rel="noopener" class="text-warning">www.bulletinbored.net/forum</a>.</p>
                                                        </div>
                                                    <?php endif; ?>
                                                    <button type="submit" name="install_from_catalog" value="1" class="btn btn-sm btn-primary"<?= $isThird ? ' data-confirm="Warning: this is a third-party ' . $type . ' not developed by the bulletinbored team. We do not assume responsibility. Continue?"' : '' ?>><?= t('update') ?></button>
                                                <?php endif; ?>
                                                <button type="submit" name="uninstall_from_catalog" value="1" class="btn btn-sm btn-outline-danger" data-confirm="<?= t('delete') ?> <?= escape($item['name']) ?>?"><?= t('uninstall') ?></button>
                                            <?php else: ?>
                                                <?php if ($isThird): ?>
                                                    <p class="mb-0">
                                                        <button type="button" class="btn btn-link text-warning p-0 text-start" data-warning-toggle="catalogWarning<?= escape($itemName) ?>_install" aria-expanded="false">
                                                            <i class="fas fa-exclamation-triangle"></i> Notice
                                                        </button>
                                                    </p>
                                                    <div class="mt-2 d-none" id="catalogWarning<?= escape($itemName) ?>_install">
                                                        <p class="text-warning small mb-2"><?= ucfirst($type) ?>s from third parties are not developed by bulletinbored team. Install at your own risk and report malicious <?= $type ?>s at www.bulletinbored.net/forum.</p>
                                                    </div>
                                                <?php endif; ?>
                                                <button type="submit" name="install_from_catalog" value="1" class="btn btn-sm btn-primary"<?= $isThird ? ' data-confirm="Warning: this is a third-party ' . $type . ' not developed by the bulletinbored team. We do not assume responsibility. Continue?"' : '' ?>><?= t('install') ?></button>
                                            <?php endif; ?>
                                        </form>
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
<?php include __DIR__.'/admin_footer.php'; ?>
