<?php include __DIR__.'/header.php'; render_header(t('new_thread')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <li class="breadcrumb-item active"><?= t('new_thread') ?></li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="fas fa-plus-circle me-2"></i><?= t('create_thread') ?></div>
                <div class="card-body">
                    <form method="POST" action="<?= url('create_thread') ?>" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('category') ?></label>
                            <select name="category_id" class="form-select" required>
                                <?php
                                $cats = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
                                foreach ($cats as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= escape($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('title') ?></label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('content') ?></label>
                            <textarea id="editbored-content" name="content" class="form-control" rows="8" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('attachments') ?></label>
                            <input type="file" name="attachments[]" class="form-control" multiple>
                            <div class="form-text"><?= t('optional') ?>.</div>
                        </div>
                        <button type="submit" class="btn btn-forum"><i class="fas fa-paper-plane me-1"></i><?= t('create_thread') ?></button>
                        <a href="<?= url('home') ?>" class="btn btn-secondary"><?= t('cancel') ?></a>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>