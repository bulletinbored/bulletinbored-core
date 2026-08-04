<?php include __DIR__.'/header.php'; render_header(escape($profileUser['username'] ?? 'Profile')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <li class="breadcrumb-item active"><?= escape($profileUser['username'] ?? '') ?></li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card text-center">
                <div class="card-body py-4">
                    <?php if (!empty($profileUser['avatar'] ?? '')): ?>
                        <img src="<?= base_url() ?>/uploads/avatars/<?= escape($profileUser['avatar']) ?>" alt="Avatar" style="width:120px;height:120px;object-fit:cover;border-radius:50%;" class="mb-3">
                    <?php else: ?>
                        <i class="fas fa-user-circle fa-5x text-muted mb-3"></i>
                    <?php endif; ?>
                    <h4><?= escape($profileUser['username'] ?? '') ?></h4>
                    <span class="badge <?= ($profileUser['role'] ?? 'user') === 'admin' ? 'bg-warning' : 'bg-secondary' ?> mb-2">
                        <?= escape(ucfirst($profileUser['role'] ?? 'user')) ?>
                    </span>
                    <p class="text-muted small mb-0"><i class="fas fa-calendar me-1"></i><?= t('joined') ?>: <?= escape($profileUser['created_at'] ?? 'N/A') ?></p>
                    <?php if (function_exists('is_logged_in') && is_logged_in() && $_SESSION['user_id'] == $profileUser['id']): ?>
                        <hr><a href="<?= url('edit_profile') ?>" class="btn btn-forum btn-sm w-100 mb-2"><i class="fas fa-edit me-1"></i><?= t('edit_profile') ?></a>
                    <?php elseif (function_exists('is_logged_in') && is_logged_in()): ?>
                        <hr><button class="btn btn-forum btn-sm w-100 mb-2" onclick="if(window.textmebored && window.textmebored.openConversation){window.textmebored.openConversation(<?= (int)$profileUser['id'] ?>, '<?= escape(addslashes($profileUser['username'])) ?>');}else{alert('Messaggistica non disponibile');}"><i class="fas fa-envelope me-1"></i><?= t('send_message') ?></button>
                    <?php endif; ?>
                    <?php if (function_exists('is_admin') && is_admin() && $_SESSION['user_id'] != ($profileUser['id'] ?? 0)): ?>
                        <hr>
                        <?php
                        $pStatus = $profileUser['status'] ?? 'active';
                        $pSusp = (int)($profileUser['suspension_time'] ?? 0);
                        $pNow = time();
                        $isBanned = ($pStatus === 'banned');
                        $isSuspended = ($pStatus === 'suspended' && $pSusp > $pNow);
                        ?>
                        <?php if ($isBanned): ?>
                            <p class="mb-2"><span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Banned</span></p>
                        <?php elseif ($isSuspended): ?>
                            <?php
                            $remaining = $pSusp - $pNow;
                            $days = ceil($remaining / 86400);
                            ?>
                            <p class="mb-2"><span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Suspended for <?= $days ?> day<?= $days == 1 ? '' : 's' ?></span></p>
                        <?php else: ?>
                            <p class="mb-2"><span class="badge bg-success"><i class="fas fa-check me-1"></i>Active</span></p>
                        <?php endif; ?>
                        <div class="d-grid gap-2">
                            <?php if ($isBanned || $isSuspended): ?>
                                <form method="POST" action="<?= url('unban_user', ['id' => $profileUser['id']]) ?>" class="d-inline" onsubmit="return confirm('Unban this user?')">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="redirect" value="/u/<?= urlencode($profileUser['username']) ?>">
                                    <button class="btn btn-success btn-sm w-100"><i class="fas fa-unlock me-1"></i>Unban User</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="<?= url('ban_user', ['id' => $profileUser['id']]) ?>" class="d-inline" onsubmit="return confirm('Ban this user?')">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="redirect" value="/u/<?= urlencode($profileUser['username']) ?>">
                                    <button class="btn btn-warning btn-sm w-100"><i class="fas fa-ban me-1"></i>Ban User</button>
                                </form>
                                <form method="POST" action="<?= url('suspend_user', ['id' => $profileUser['id']]) ?>" class="d-inline" onsubmit="var d=prompt('Days to suspend:');if(d){this.querySelector('input[name=days]').value=d;this.submit();}return false;">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="days" value="7">
                                    <input type="hidden" name="redirect" value="/u/<?= urlencode($profileUser['username']) ?>">
                                    <button class="btn btn-info btn-sm w-100"><i class="fas fa-clock me-1"></i>Suspend User</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <h5 class="mb-3"><i class="fas fa-comments me-2"></i><?= t('threads_by') ?> <?= escape($profileUser['username']) ?></h5>
            <?php if (empty($userThreads ?? [])): ?>
                <div class="card"><div class="card-body text-center py-4 text-muted"><?= t('no_threads') ?></div></div>
            <?php else: ?>
                <?php foreach ($userThreads as $thread): ?>
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-1">
                                <a href="<?= url('thread', ['id' => $thread['id'], 'slug' => slugify($thread['title'] ?? '')]) ?>" class="thread-title"><?= escape($thread['title']) ?></a>
                            </h5>
                            <small class="text-muted"><i class="fas fa-clock me-1"></i><?= escape($thread['created_at'] ?? '') ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php render_footer(); ?>