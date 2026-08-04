<?php
include __DIR__.'/header.php';
render_header($category['name'] ?? 'Category');

$listUrlBase = 'category';
$listUrlArgs = ['id' => $category['id'] ?? 0, 'slug' => slugify($category['name'] ?? '')];
?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item active"><?= escape($category['name'] ?? '') ?></li>
        </ol>
    </nav>

    <header class="page-head">
        <div>
            <h1 class="page-title"><?= escape($category['name'] ?? '') ?></h1>
            <p class="page-subtitle">
                <?php if (!empty($category['description'])): ?>
                    <?= escape($category['description']) ?> <span class="dot">·</span>
                <?php endif; ?>
                <?= t('discussions_count', ['n' => compact_number($total ?? 0)]) ?>
            </p>
        </div>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="<?= url('new_thread', ['category' => $category['id'] ?? 0]) ?>" class="btn btn-brand d-none d-md-inline-flex">
                <i class="fas fa-pen-to-square me-2"></i><?= t('new_thread') ?>
            </a>
        <?php endif; ?>
    </header>

    <?php include __DIR__.'/partials/thread_list.php'; ?>
<?php render_footer(); ?>
