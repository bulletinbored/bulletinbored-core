<?php
include __DIR__.'/admin_header.php';
render_admin_header(t('catalog'));

$categoryOrder = [
    'Content & Editing',
    'Communication',
    'Engagement & Social',
    'SEO & Discovery',
    'Performance & Maintenance',
    'Security',
    'Developer Tools',
    'Themes',
    'Core',
    'Other',
];
$categoryMeta = [
    'Content & Editing' => ['icon' => 'fa-pen-nib', 'tone' => 'content'],
    'Communication' => ['icon' => 'fa-comments', 'tone' => 'comm'],
    'Engagement & Social' => ['icon' => 'fa-fire-flame-curved', 'tone' => 'engage'],
    'SEO & Discovery' => ['icon' => 'fa-magnifying-glass-chart', 'tone' => 'seo'],
    'Performance & Maintenance' => ['icon' => 'fa-screwdriver-wrench', 'tone' => 'maint'],
    'Security' => ['icon' => 'fa-shield-halved', 'tone' => 'security'],
    'Developer Tools' => ['icon' => 'fa-code', 'tone' => 'dev'],
    'Themes' => ['icon' => 'fa-palette', 'tone' => 'theme'],
    'Core' => ['icon' => 'fa-cube', 'tone' => 'core'],
    'Other' => ['icon' => 'fa-puzzle-piece', 'tone' => 'default'],
];

$activeCategory = strtolower(trim((string)($categoryFilter ?? '')));
$catalogQuery = static function (array $overrides = []) use ($search, $typeFilter): string {
    $params = [
        'q' => (string)($search ?? ''),
        'type' => (string)($typeFilter ?? ''),
        'category' => (string)($categoryFilter ?? ''),
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = (string)$value;
    }
    $params = array_filter($params, static fn($value) => $value !== '' && $value !== 'all');
    return '?' . http_build_query($params);
};

$entries = [];
$totalCount = 0;
$installedCount = 0;
$updateCount = 0;
$officialCount = 0;

foreach ($catalog as $item) {
    $itemName = strtolower((string)($item['name'] ?? ''));
    $repo = (string)($item['repo'] ?? '');
    $type = strtolower((string)($item['type'] ?? 'plugin'));
    $category = (string)($item['category'] ?? 'Other');

    $latestTag = (string)($catalogRemoteVersions[$itemName] ?? '');
    if ($latestTag === '' && !empty($item['version'])) {
        $latestTag = (string)$item['version'];
    }
    $installedItem = $installed['plugins'][$itemName] ?? $installed['themes'][$itemName] ?? null;
    $installedVersion = (string)($installedItem['version'] ?? '');
    $isInstalled = $installedItem !== null;
    $normalizedLatest = $latestTag !== '' ? ltrim($latestTag, 'vV') : '';
    $normalizedInstalled = $installedVersion !== '' ? ltrim($installedVersion, 'vV') : '';
    $updateAvailable = $isInstalled && $normalizedLatest !== '' && $normalizedInstalled !== '' && version_compare($normalizedLatest, $normalizedInstalled, '>');
    $isOfficial = (($item['official'] ?? false) === true) || (($item['author_type'] ?? '') === 'first_party');
    $isThird = !$isOfficial && $type !== 'core';

    $entries[] = [
        'item' => $item,
        'name' => $itemName,
        'repo' => $repo,
        'type' => $type,
        'category' => $category,
        'latestTag' => $latestTag,
        'installedVersion' => $installedVersion,
        'isInstalled' => $isInstalled,
        'updateAvailable' => $updateAvailable,
        'isOfficial' => $isOfficial,
        'isThird' => $isThird,
    ];

    $totalCount++;
    $installedCount += $isInstalled ? 1 : 0;
    $updateCount += $updateAvailable ? 1 : 0;
    $officialCount += $isOfficial ? 1 : 0;
}

$categoryPositions = array_flip($categoryOrder);
usort($entries, static function (array $a, array $b) use ($categoryPositions): int {
    $pa = $categoryPositions[$a['category']] ?? 999;
    $pb = $categoryPositions[$b['category']] ?? 999;
    return $pa <=> $pb ?: strcasecmp($a['name'], $b['name']);
});

$categoryChips = [];
foreach (array_keys($catalogCategoryCounts ?? []) as $category) {
    $categoryChips[$category] = (int)($catalogCategoryCounts[$category] ?? 0);
}
uksort($categoryChips, static function (string $a, string $b) use ($categoryPositions): int {
    return ($categoryPositions[$a] ?? 999) <=> ($categoryPositions[$b] ?? 999);
});
?>
<div class="container-fluid">
    <div class="page-head">
        <div>
            <h1 class="page-title"><?= t('catalog') ?></h1>
            <p class="page-subtitle">Extensions for bulletinbored, organised by what they do.</p>
        </div>
    </div>

    <?php if ($adminCatalogSuccess): ?>
        <div class="alert alert-success"><?= escape($adminCatalogSuccess) ?></div>
    <?php endif; ?>
    <?php if ($adminCatalogError): ?>
        <div class="alert alert-danger"><?= escape($adminCatalogError) ?></div>
    <?php endif; ?>

    <div class="catalog-summary">
        <div class="catalog-summary-card">
            <span class="catalog-summary-icon"><i class="fas fa-layer-group"></i></span>
            <div><strong><?= $totalCount ?></strong><span>Extensions</span></div>
        </div>
        <div class="catalog-summary-card">
            <span class="catalog-summary-icon is-success"><i class="fas fa-check"></i></span>
            <div><strong><?= $installedCount ?></strong><span>Installed</span></div>
        </div>
        <div class="catalog-summary-card">
            <span class="catalog-summary-icon is-warning"><i class="fas fa-arrow-up"></i></span>
            <div><strong><?= $updateCount ?></strong><span>Updates</span></div>
        </div>
        <div class="catalog-summary-card">
            <span class="catalog-summary-icon is-official"><i class="fas fa-shield-halved"></i></span>
            <div><strong><?= $officialCount ?></strong><span>Official</span></div>
        </div>
    </div>

    <div class="catalog-toolbar">
        <form method="GET" class="catalog-search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?= escape($search ?? '') ?>" placeholder="<?= t('search_catalog') ?>">
            <?php if (($typeFilter ?? '') !== '' && ($typeFilter ?? '') !== 'all'): ?>
                <input type="hidden" name="type" value="<?= escape($typeFilter) ?>">
            <?php endif; ?>
            <?php if ($activeCategory !== '' && $activeCategory !== 'all'): ?>
                <input type="hidden" name="category" value="<?= escape($categoryFilter ?? '') ?>">
            <?php endif; ?>
        </form>
        <div class="catalog-filter">
            <a href="<?= escape($catalogQuery(['type' => ''])) ?>" class="<?= ($typeFilter ?? '') === '' || ($typeFilter ?? '') === 'all' ? 'is-active' : '' ?>"><?= t('all') ?></a>
            <a href="<?= escape($catalogQuery(['type' => 'plugin'])) ?>" class="<?= ($typeFilter ?? '') === 'plugin' ? 'is-active' : '' ?>"><?= t('plugins') ?></a>
            <a href="<?= escape($catalogQuery(['type' => 'theme'])) ?>" class="<?= ($typeFilter ?? '') === 'theme' ? 'is-active' : '' ?>"><?= t('themes') ?></a>
        </div>
    </div>

    <div class="catalog-category-filter">
        <a href="<?= escape($catalogQuery(['category' => ''])) ?>" class="<?= $activeCategory === '' || $activeCategory === 'all' ? 'is-active' : '' ?>">
            <i class="fas fa-border-all"></i>All <span><?= array_sum($catalogCategoryCounts ?? []) ?></span>
        </a>
        <?php foreach ($categoryChips as $category => $count): ?>
            <?php $meta = $categoryMeta[$category] ?? ['icon' => 'fa-puzzle-piece']; ?>
            <a href="<?= escape($catalogQuery(['category' => $category])) ?>" class="<?= $activeCategory === strtolower($category) ? 'is-active' : '' ?>">
                <i class="fas <?= escape($meta['icon']) ?>"></i><?= escape($category) ?> <span><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($entries)): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-box-open fa-2x mb-3"></i>
                <p class="mb-0"><?= t('no_items_in_catalog') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="catalog-grid">
            <?php foreach ($entries as $entry): ?>
                <?php
                $item = $entry['item'];
                $type = $entry['type'];
                $isCore = $type === 'core';
                $typeIcon = $isCore ? 'fa-cube' : ($type === 'theme' ? 'fa-palette' : 'fa-plug');
                $typeLabel = $isCore ? 'Core' : ucfirst($type);
                $confirm = $entry['isThird']
                    ? 'Warning: this is a community ' . $type . ' not developed or maintained by bulletinbored. Continue?'
                    : '';
                ?>
                <article class="catalog-card">
                    <div class="catalog-card-head">
                        <span class="catalog-type-icon is-<?= $type === 'theme' ? 'theme' : ($isCore ? 'core' : 'plugin') ?>">
                            <i class="fas <?= escape($typeIcon) ?>"></i>
                        </span>
                        <div class="catalog-title">
                            <h3><?= escape($item['name'] ?? $entry['name']) ?></h3>
                            <div class="catalog-badges">
                                <?php if ($entry['isOfficial']): ?>
                                    <span class="catalog-official" title="Developed and maintained by bulletinbored">
                                        <i class="fas fa-shield-halved"></i>Official
                                    </span>
                                <?php elseif (!$isCore): ?>
                                    <span class="catalog-community" title="Community extension">
                                        <i class="fas fa-user-group"></i>Community
                                    </span>
                                <?php endif; ?>
                                <span class="catalog-type-badge"><?= escape($typeLabel) ?></span>
                            </div>
                        </div>
                    </div>

                    <p class="catalog-description"><?= escape($item['description'] ?? '') ?></p>

                    <div class="catalog-meta">
                        <span><i class="fas fa-user"></i><?= escape($item['author'] ?? '-') ?></span>
                        <span><i class="fas fa-tag"></i>v<?= escape($entry['latestTag'] ?: '-') ?></span>
                        <?php if ($entry['isInstalled'] && $entry['installedVersion'] !== ''): ?>
                            <span><i class="fas fa-download"></i>v<?= escape($entry['installedVersion']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($item['tags'])): ?>
                        <div class="catalog-tags">
                            <?php foreach (array_slice($item['tags'], 0, 5) as $tag): ?>
                                <span><?= escape((string)$tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($entry['isThird']): ?>
                        <details class="catalog-warning">
                            <summary><i class="fas fa-triangle-exclamation"></i>Community extension notice</summary>
                            <p>Not developed or maintained by bulletinbored. Install at your own risk. Report malicious extensions on the bulletinbored forum.</p>
                        </details>
                    <?php endif; ?>

                    <footer class="catalog-card-footer">
                        <div class="catalog-status">
                            <?php if ($isCore): ?>
                                <span class="catalog-status-badge is-core">Bundled platform</span>
                            <?php elseif ($entry['updateAvailable']): ?>
                                <span class="catalog-status-badge is-update">Update available</span>
                            <?php elseif ($entry['isInstalled']): ?>
                                <span class="catalog-status-badge is-installed"><?= t('installed') ?></span>
                            <?php else: ?>
                                <span class="catalog-status-badge is-missing"><?= t('not_installed') ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$isCore): ?>
                            <div class="catalog-actions">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="name" value="<?= escape($entry['name']) ?>">
                                    <input type="hidden" name="type" value="<?= escape($type) ?>">
                                    <input type="hidden" name="repo" value="<?= escape($entry['repo']) ?>">
                                    <input type="hidden" name="tag" value="<?= escape($entry['latestTag']) ?>">
                                    <?php if ($entry['isInstalled']): ?>
                                        <?php if ($entry['updateAvailable']): ?>
                                            <button type="submit" name="install_from_catalog" value="1" class="btn btn-sm btn-primary"<?= $confirm !== '' ? ' data-confirm="' . escape($confirm) . '"' : '' ?>>
                                                <?= t('update') ?>
                                            </button>
                                        <?php endif; ?>
                                        <button type="submit" name="uninstall_from_catalog" value="1" class="btn btn-sm btn-outline-danger" data-confirm="<?= t('delete') ?> <?= escape($item['name'] ?? $entry['name']) ?>?">
                                            <?= t('uninstall') ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="install_from_catalog" value="1" class="btn btn-sm btn-primary"<?= $confirm !== '' ? ' data-confirm="' . escape($confirm) . '"' : '' ?>>
                                            <?= t('install') ?>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
