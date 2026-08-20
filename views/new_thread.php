<?php
include __DIR__.'/header.php';
render_header(t('new_thread'));
$preselected = (int)($_GET['category'] ?? 0);
?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item active"><?= t('new_thread') ?></li>
        </ol>
    </nav>

    <header class="page-head">
        <div>
            <h1 class="page-title"><?= t('create_thread') ?></h1>
            <p class="page-subtitle"><?= t('create_thread_hint') ?></p>
        </div>
    </header>

    <section class="panel">
        <form method="POST" action="<?= url('create_thread') ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <div class="mb-3">
                <label class="form-label"><?= t('category') ?></label>
                <select name="category_id" class="form-select" required>
                    <?php foreach (sidebar_categories() as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $preselected === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= escape($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= t('title') ?></label>
                <input type="text" name="title" class="form-control" placeholder="<?= t('title_placeholder') ?>" required>
            </div>

            <div class="mt-3">
                <label class="form-label"><?= t('content') ?></label>
                <textarea id="editbored-content" name="content" class="form-control" rows="10" required></textarea>
            </div>

            <?php if (!empty($config['attachments_enabled'])): ?>
            <div class="mt-3">
                <label class="form-label"><?= t('attachments') ?> <span class="text-muted fw-normal">(<?= t('optional') ?>)</span></label>
                <input type="file" name="attachments[]" class="form-control" multiple>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-brand"><i class="fas fa-paper-plane me-2"></i><?= t('create_thread') ?></button>
                <a href="<?= url('home') ?>" class="btn btn-outline-soft"><?= t('cancel') ?></a>
            </div>
        </form>
    </section>
<?php render_footer(); ?>
