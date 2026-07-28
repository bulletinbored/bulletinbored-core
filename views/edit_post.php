<?php include __DIR__.'/header.php'; render_header(t('edit_profile')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <li class="breadcrumb-item"><a href="<?= url('thread', ['id' => $post['thread_id'] ?? '']) ?>">Thread</a></li>
            <li class="breadcrumb-item active">Edit Post</li>
        </ol>
    </nav>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="fas fa-edit me-2"></i>Edit Post</div>
                <div class="card-body">
                    <form method="POST" action="<?= url('update_post') ?>">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?? '' ?>">
                        <div class="mb-3">
                            <textarea name="content" class="form-control" rows="8" required><?= escape($post['content'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-forum"><i class="fas fa-save me-1"></i>Update Post</button>
                        <a href="<?= url('thread', ['id' => $post['thread_id'] ?? '']) ?>" class="btn btn-secondary"><?= t('cancel') ?></a>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>