<?php include __DIR__.'/header.php'; render_header('New Thread'); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= base_url() ?>/?action=home">Home</a></li>
            <li class="breadcrumb-item active">New Thread</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="fas fa-plus-circle me-2"></i>Create a New Thread</div>
                <div class="card-body">
                    <form method="POST" action="<?= base_url() ?>/?action=create_thread" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select name="category_id" class="form-select" required>
                                <?php
                                $cats = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
                                foreach ($cats as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= escape($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Content</label>
                            <textarea name="content" class="form-control" rows="8" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" name="attachments[]" class="form-control" multiple>
                            <div class="form-text">Optional.</div>
                        </div>
                        <button type="submit" class="btn btn-forum"><i class="fas fa-paper-plane me-1"></i>Create Thread</button>
                        <a href="<?= base_url() ?>/?action=home" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>