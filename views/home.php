<?php
$listContext = $listContext ?? 'home';
$searchQuery = $_GET['q'] ?? '';
$isSearch    = ($listContext === 'search');

$pageTitle = $isSearch ? t('search_results') : t('all_discussions');
include __DIR__.'/header.php';
render_header($pageTitle);

$listUrlBase = $isSearch ? 'search' : 'home';
$listUrlArgs = $isSearch && $searchQuery !== '' ? ['q' => $searchQuery] : [];
?>
    <header class="page-head">
        <div>
            <h1 class="page-title"><?= escape($pageTitle) ?></h1>
            <p class="page-subtitle">
                <?php if ($isSearch && $searchQuery !== ''): ?>
                    <?= t('search_summary', ['n' => (int)($total ?? 0), 'q' => escape($searchQuery)]) ?>
                <?php else: ?>
                    <?= t('discussions_count', ['n' => compact_number($total ?? 0)]) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="<?= url('new_thread') ?>" class="btn btn-brand d-none d-md-inline-flex">
                <i class="fas fa-pen-to-square me-2"></i><?= t('new_thread') ?>
            </a>
        <?php endif; ?>
    </header>

    <?php if ($isSearch): ?>
        <form class="search-inline" method="GET" action="<?= url('search') ?>">
            <i class="fas fa-search"></i>
            <input type="text" name="q" value="<?= escape($searchQuery) ?>" placeholder="<?= t('search') ?>…" required>
            <button type="submit" class="btn btn-brand btn-sm"><?= t('search') ?></button>
        </form>
    <?php endif; ?>

    <?php include __DIR__.'/partials/thread_list.php'; ?>
<?php render_footer(); ?>
