<?php include __DIR__.'/header.php'; render_header(escape($profileUser['username'] ?? 'Profile')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= base_url() ?>/?action=home">Home</a></li>
            <li class="breadcrumb-item active"><?= escape($profileUser['username'] ?? '') ?></li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card text-center">
                <div class="card-body py-4">
                    <i class="fas fa-user-circle fa-5x text-muted mb-3"></i>
                    <h4><?= escape($profileUser['username'] ?? '') ?></h4>
                    <span class="badge <?= ($profileUser['role'] ?? 'user') === 'admin' ? 'bg-warning' : 'bg-secondary' ?> mb-2">
                        <?= escape(ucfirst($profileUser['role'] ?? 'user')) ?>
                    </span>
                    <p class="text-muted small mb-0"><i class="fas fa-calendar me-1"></i>Joined: <?= escape($profileUser['created_at'] ?? 'N/A') ?></p>
                    <?php if (function_exists('is_logged_in') && is_logged_in() && $_SESSION['user_id'] == $profileUser['id']): ?>
                        <hr><a href="<?= base_url() ?>/?action=edit_profile" class="btn btn-forum btn-sm w-100"><i class="fas fa-edit me-1"></i>Edit Profile</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <h5 class="mb-3"><i class="fas fa-comments me-2"></i>Threads by <?= escape($profileUser['username']) ?></h5>
            <?php if (empty($userThreads ?? [])): ?>
                <div class="card"><div class="card-body text-center py-4 text-muted">No threads yet.</div></div>
            <?php else: ?>
                <?php foreach ($userThreads as $thread): ?>
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-1">
                                <a href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>" class="thread-title"><?= escape($thread['title']) ?></a>
                            </h5>
                            <small class="text-muted"><i class="fas fa-clock me-1"></i><?= escape($thread['created_at'] ?? '') ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php render_footer(); ?>