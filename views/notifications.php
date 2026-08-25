<?php include __DIR__.'/header.php'; render_header('Notifications'); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item active"><?= t('notifications') ?></li>
        </ol>
    </nav>

    <h1 class="page-title mb-3"><i class="fas fa-bell me-2"></i><?= t('notifications') ?></h1>

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
                    $nLabel = $n['title'] ?: notification_label($n);
                    // Some legacy rows stored a raw translation key (or text with
                    // lost parameters) directly in the title/message. Translate it
                    // when it is a known key so the user never sees the key itself.
                    if (preg_match('/^[a-z_]+$/', $nLabel) && t($nLabel) !== $nLabel) {
                        $nLabel = t($nLabel);
                    }
                    $nMessage = $n['message'] ?? '';
                    if (preg_match('/^[a-z_]+$/', $nMessage) && t($nMessage) !== $nMessage) {
                        $nMessage = t($nMessage);
                    }
                ?>
                    <div class="list-group-item <?= $n['is_read'] ? '' : 'list-group-item-warning' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1"><?= escape($nLabel) ?></h6>
                                <?php if ($nMessage !== '' && $nMessage !== $nLabel): ?>
                                    <p class="mb-1 small text-muted"><?= escape($nMessage) ?></p>
                                <?php endif; ?>
                            <small class="text-muted"><i class="fas fa-clock me-1"></i><?= escape($nFormattedDate) ?></small>
                        </div>
                        <div class="ms-3">
                            <?php if ($n['link']): ?>
                                <a href="<?= escape($n['link']) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt me-1"></i>View</a>
                            <?php endif; ?>
                            <?php if (!$n['is_read']): ?>
                                <form method="POST" action="<?= url('notifications') ?>" class="d-inline" data-confirm="Mark as read?">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="do" value="mark_read">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
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