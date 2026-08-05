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
                                    $latestTag = '';
                                    if (str_contains($repo, 'github.com')) {
                                        $apiUrl = str_replace(['github.com'], ['api.github.com/repos'], $repo) . '/releases/latest';
                                        $json = @file_get_contents($apiUrl);
                                        if ($json) {
                                            $release = json_decode($json, true);
                                            $latestTag = $release['tag_name'] ?? '';
                                        }
                                    }
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
                                        <?php if (!empty($item['official'])): ?>
                                            <span class="badge bg-success ms-1">Official</span>
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
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="name" value="<?= escape($itemName) ?>">
                                            <input type="hidden" name="type" value="<?= escape($type) ?>">
                                            <input type="hidden" name="repo" value="<?= escape($repo) ?>">
                                            <input type="hidden" name="tag" value="<?= escape($latestTag) ?>">
                                            <?php if ($isInstalled): ?>
                                                <button type="submit" name="install_from_catalog" value="1" class="btn btn-sm btn-outline-primary">
                                                    <?= $updateAvailable ? 'Aggiorna' : 'Reinstalla' ?>
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" name="install_from_catalog" value="1" class="btn btn-sm btn-primary">Installa</button>
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
