<?php include __DIR__.'/header.php'; render_header(escape($category['name'] ?? 'Category')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <li class="breadcrumb-item active"><?= escape($category['name'] ?? '') ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-folder me-2"></i><?= escape($category['name'] ?? '') ?></h4>
            <small class="text-muted"><?= escape($category['description'] ?? '') ?></small>
        </div>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="<?= url('new_thread') ?>" class="btn btn-forum btn-sm"><i class="fas fa-plus me-1"></i><?= t('new_thread') ?></a>
        <?php endif; ?>
    </div>

    <?php if (empty($threads ?? [])): ?>
        <div class="card"><div class="card-body text-center py-5 text-muted"><?= t('no_threads') ?></div></div>
    <?php else: ?>
        <?php foreach ($threads as $thread): ?>
            <div class="card">
                <div class="card-body">
<h5 class="card-title mb-1">
                         <a href="<?= url('thread', ['id' => $thread['id'], 'slug' => slugify($thread['title'] ?? '')]) ?>" class="thread-title"><?= escape($thread['title']) ?></a>
                         <?php if ($thread['status'] === 'sticky'): ?>
                             <span class="badge bg-info ms-1" style="font-size:0.65rem"><i class="fas fa-thumbtack"></i></span>
                         <?php endif; ?>
                         <?php if ($thread['status'] === 'locked'): ?>
                             <span class="badge bg-warning ms-1" style="font-size:0.65rem"><i class="fas fa-lock"></i></span>
                         <?php endif; ?>
                         <?php if ($thread['status'] === 'hidden'): ?>
                             <span class="badge bg-dark ms-1" style="font-size:0.65rem"><i class="fas fa-eye-slash"></i></span>
                         <?php endif; ?>
                     </h5>
                    <p class="card-text text-muted small mb-2"><?= nl2br(escape(substr($thread['content'] ?? '', 0, 200))) ?><?php if (strlen($thread['content'] ?? '') > 200): ?>...<?php endif; ?></p>
                    <small class="text-muted"><i class="fas fa-user me-1"></i><?= escape($thread['author']) ?> &middot; <i class="fas fa-clock me-1"></i><?= escape($thread['created_at'] ?? '') ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
        <nav><ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= ($i === ($page ?? 1)) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('category', ['id' => $category['id'], 'slug' => slugify($category['name'] ?? ''), 'page' => $i]) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
    <?php endif; ?>
<?php render_footer(); ?>