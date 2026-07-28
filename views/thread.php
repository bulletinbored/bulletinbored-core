<?php include __DIR__.'/header.php'; render_header(escape($thread['title'] ?? 'Thread')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= base_url() ?>/?action=home">Home</a></li>
            <li class="breadcrumb-item active"><?= escape($thread['title'] ?? '') ?></li>
        </ol>
    </nav>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-comment me-2"></i><?= escape($thread['title'] ?? '') ?></span>
            <small class="text-muted">
                <i class="fas fa-user me-1"></i><?= escape($thread['author']) ?> &middot;
                <i class="fas fa-clock me-1"></i><?= escape($thread['created_at'] ?? '') ?>
            </small>
        </div>
        <div class="card-body">
            <p><?= nl2br(escape($thread['content'] ?? '')) ?></p>
            <?php
            $uploadsStmt = $pdo->prepare("SELECT * FROM uploads WHERE thread_id = ? AND post_id IS NULL ORDER BY created_at ASC");
            $uploadsStmt->execute([$_GET['id'] ?? 0]);
            $uploads = $uploadsStmt->fetchAll();
            if (!empty($uploads)): ?>
                <hr>
                <h6><i class="fas fa-paperclip me-1"></i>Attachments</h6>
                <?php foreach ($uploads as $upload): ?>
                    <a href="<?= base_url() ?>/uploads/<?= $upload['filename'] ?>" class="btn btn-outline-secondary btn-sm me-1 mb-1" download>
                        <i class="fas fa-file me-1"></i><?= escape($upload['original_name']) ?>
                        <span class="badge bg-secondary ms-1"><?= round($upload['size'] / 1024, 1) ?> KB</span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card-footer text-muted small">
            <i class="fas fa-folder me-1"></i>Category: <?= escape($thread['category_name'] ?? 'General') ?>
        </div>
    </div>

    <h5 class="mb-3"><i class="fas fa-reply me-2"></i>Replies</h5>

    <?php if (empty($posts ?? [])): ?>
        <div class="card"><div class="card-body text-center py-4 text-muted">No replies yet.</div></div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <strong><i class="fas fa-user-circle me-1"></i><?= escape($post['author']) ?></strong>
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i><?= escape($post['created_at'] ?? '') ?>
                        <?php if (function_exists('is_logged_in') && is_logged_in() && ($_SESSION['user_id'] == $post['user_id'] || is_admin())): ?>
                            &middot; <a href="<?= base_url() ?>/?action=edit_post&id=<?= $post['id'] ?>" class="text-decoration-none"><i class="fas fa-edit"></i></a>
                            <form method="POST" action="<?= base_url() ?>/?action=delete_post&id=<?= $post['id'] ?>" style="display:inline" onsubmit="return confirm('Delete?')">
                                <button type="submit" class="btn btn-link text-danger p-0 ms-1" style="font-size:inherit"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="card-body"><p class="mb-0"><?= nl2br(escape($post['content'])) ?></p></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
        <nav><ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= ($i === ($postPage ?? 1)) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>&post_page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
    <?php endif; ?>

    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-reply me-2"></i>Post a Reply</div>
            <div class="card-body">
                <form method="POST" action="<?= base_url() ?>/?action=reply">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="thread_id" value="<?= $thread['id'] ?? '' ?>">
                    <div class="mb-3"><textarea name="content" class="form-control" rows="4" required></textarea></div>
                    <button type="submit" class="btn btn-forum"><i class="fas fa-paper-plane me-1"></i>Submit Reply</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i><a href="<?= base_url() ?>/?action=login">Login</a> to post a reply.</div>
    <?php endif; ?>
<?php render_footer(); ?>