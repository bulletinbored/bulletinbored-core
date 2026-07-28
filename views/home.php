<?php include __DIR__.'/header.php'; render_header('Home'); ?>
    <div class="row">
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="fas fa-list me-2"></i>Recent Threads</h4>
                <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                    <a href="<?= base_url() ?>/?action=new_thread" class="btn btn-forum btn-sm">
                        <i class="fas fa-plus me-1"></i>New Thread
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($threads ?? [])): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted mb-0">No threads yet. Be the first to start a discussion!</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($threads as $thread): ?>
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-1">
                                <a href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>" class="thread-title">
                                    <?= escape($thread['title']) ?>
                                </a>
                            </h5>
                            <p class="card-text text-muted small mb-2">
                                <?= nl2br(escape(substr($thread['content'] ?? '', 0, 200))) ?>
                                <?php if (strlen($thread['content'] ?? '') > 200): ?>...<?php endif; ?>
                            </p>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-cat"><i class="fas fa-folder me-1"></i><?= escape($thread['category_name'] ?? 'General') ?></span>
                                <small class="text-muted"><i class="fas fa-user me-1"></i><?= escape($thread['author']) ?></small>
                                <small class="text-muted"><i class="fas fa-clock me-1"></i><?= escape($thread['created_at'] ?? '') ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (($totalPages ?? 1) > 1): ?>
                <nav><ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= ($i === ($page ?? 1)) ? 'active' : '' ?>">
                            <a class="page-link" href="<?= base_url() ?>/?action=home&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul></nav>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="fas fa-th-large me-2"></i>Categories</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($categories ?? [] as $cat): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <a href="<?= base_url() ?>/?action=category&id=<?= $cat['id'] ?>" class="text-decoration-none">
                                    <i class="fas fa-folder me-2 text-muted"></i><?= escape($cat['name']) ?>
                                </a>
                                <small class="text-muted"><?= escape($cat['description'] ?? '') ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>