<?php include __DIR__.'/header.php'; render_header('Notifications'); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <li class="breadcrumb-item active"><?= t('notifications') ?></li>
        </ol>
    </nav>

    <h4 class="mb-3"><i class="fas fa-bell me-2"></i><?= t('notifications') ?></h4>

    <?php if (!empty($notifications ?? [])): ?>
        <form method="POST" action="<?= url('notifications') ?>" class="text-end mb-3">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="do" value="mark_all_read">
            <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-check-double me-1"></i>Mark all read</button>
        </form>
    <?php endif; ?>

    <?php if (empty($notifications ?? [])): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-bell-slash fa-2x mb-3"></i>
                <p class="mb-0">No notifications yet.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($notifications as $n): 
                $nDate = $n['created_at'] ?? '';
                $nFormattedDate = $nDate ? date('M j, Y H:i', strtotime($nDate)) : '';
            ?>
                <div class="list-group-item <?= $n['read'] ? '' : 'list-group-item-warning' ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1"><?= escape($n['title']) ?></h6>
                            <p class="mb-1 small text-muted"><?= escape($n['message'] ?? '') ?></p>
                            <small class="text-muted"><i class="fas fa-clock me-1"></i><?= escape($nFormattedDate) ?></small>
                        </div>
                        <div class="ms-3">
                            <?php if ($n['link']): ?>
                                <a href="<?= escape($n['link']) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt me-1"></i>View</a>
                            <?php endif; ?>
                            <?php if (!$n['read']): ?>
                                <form method="POST" action="<?= url('notifications', ['do' => 'mark_read', 'id' => $n['id']]) ?>" class="d-inline" onsubmit="return confirm('Mark as read?')">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-check me-1"></i>Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php render_footer(); ?>