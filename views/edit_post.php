<?php
include __DIR__.'/header.php';
render_header(t('edit_post'));
$backUrl = url('thread', ['id' => $post['thread_id'] ?? 0, 'slug' => slugify($post['thread_title'] ?? '')]);
?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item"><a href="<?= $backUrl ?>"><?= escape($post['thread_title'] ?? 'Thread') ?></a></li>
            <li class="breadcrumb-item active"><?= t('edit_post') ?></li>
        </ol>
    </nav>

    <header class="page-head">
        <div>
            <h1 class="page-title"><?= t('edit_post') ?></h1>
        </div>
    </header>

    <section class="panel">
        <form method="POST" action="<?= url('edit_post', ['id' => $post['id'] ?? 0]) ?>">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="post_id" value="<?= (int)($post['id'] ?? 0) ?>">
            <div class="mb-3">
                <textarea id="editbored-content" name="content" class="form-control" rows="10" required><?= escape($post['content'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-brand"><i class="fas fa-save me-2"></i><?= t('save_changes') ?></button>
                <a href="<?= $backUrl ?>" class="btn btn-outline-soft"><?= t('cancel') ?></a>
            </div>
        </form>
    </section>
<?php render_footer(); ?>
