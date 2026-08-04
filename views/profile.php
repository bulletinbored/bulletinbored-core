<?php
include __DIR__.'/header.php';
render_header($profileUser['username'] ?? 'Profile');

$pStatus     = $profileUser['status'] ?? 'active';
$pSusp       = (int)($profileUser['suspension_time'] ?? 0);
$isBanned    = ($pStatus === 'banned');
$isSuspended = ($pStatus === 'suspended' && $pSusp > time());
$isSelf      = function_exists('is_logged_in') && is_logged_in() && ($_SESSION['user_id'] ?? 0) == ($profileUser['id'] ?? -1);
$stats       = $profileStats ?? ['threads' => 0, 'posts' => 0];
?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item active"><?= escape($profileUser['username'] ?? '') ?></li>
        </ol>
    </nav>

    <header class="profile-head">
        <?= render_avatar($profileUser['username'] ?? '', $profileUser['avatar'] ?? '', 84) ?>
        <div class="profile-head-main">
            <h1 class="page-title mb-1"><?= escape($profileUser['username'] ?? '') ?></h1>
            <div class="profile-head-meta">
                <?php $role = $profileUser['role'] ?? 'user'; ?>
                <?php if ($role === 'admin'): ?>
                    <span class="role-badge role-admin"><?= t('role_admin') ?></span>
                <?php elseif ($role === 'moderator'): ?>
                    <span class="role-badge role-mod"><?= t('role_moderator') ?></span>
                <?php else: ?>
                    <span class="role-badge"><?= t('role_member') ?></span>
                <?php endif; ?>
                <?php if ($isBanned): ?>
                    <span class="pill pill-hidden"><i class="fas fa-ban"></i><?= t('banned') ?></span>
                <?php elseif ($isSuspended): ?>
                    <span class="pill pill-locked"><i class="fas fa-clock"></i><?= t('suspended') ?></span>
                <?php endif; ?>
                <span class="dot">·</span>
                <span><?= t('joined') ?> <?= time_ago($profileUser['created_at'] ?? '') ?></span>
                <span class="dot">·</span>
                <span><?= compact_number($stats['threads']) ?> <span class="count-label"><?= t('discussions') ?></span></span>
                <span class="dot">·</span>
                <span><?= compact_number($stats['posts']) ?> <span class="count-label"><?= t('replies') ?></span></span>
            </div>
        </div>
        <div class="profile-head-actions">
            <?php if ($isSelf): ?>
                <a href="<?= url('edit_profile') ?>" class="btn btn-brand btn-sm"><i class="fas fa-pen me-2"></i><?= t('edit_profile') ?></a>
            <?php elseif (function_exists('is_logged_in') && is_logged_in()): ?>
                <button class="btn btn-outline-soft btn-sm" onclick="if(window.textmebored&&window.textmebored.openConversation){window.textmebored.openConversation(<?= (int)$profileUser['id'] ?>,'<?= escape(addslashes($profileUser['username'])) ?>');}">
                    <i class="fas fa-envelope me-2"></i><?= t('send_message') ?>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (function_exists('is_admin') && is_admin() && !$isSelf): ?>
        <div class="mod-bar">
            <span class="mod-bar-label"><i class="fas fa-shield-halved me-1"></i><?= t('moderation') ?></span>
            <?php if ($isBanned || $isSuspended): ?>
                <form method="POST" action="<?= url('unban_user', ['id' => $profileUser['id']]) ?>" class="d-inline" onsubmit="return confirm('<?= t('unban_user') ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="redirect" value="/u/<?= urlencode($profileUser['username']) ?>">
                    <button class="btn btn-outline-soft btn-sm"><i class="fas fa-unlock me-1"></i><?= t('unban_user') ?></button>
                </form>
            <?php else: ?>
                <form method="POST" action="<?= url('ban_user', ['id' => $profileUser['id']]) ?>" class="d-inline" onsubmit="return confirm('<?= t('ban_user') ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="redirect" value="/u/<?= urlencode($profileUser['username']) ?>">
                    <button class="btn btn-outline-danger btn-sm"><i class="fas fa-ban me-1"></i><?= t('ban_user') ?></button>
                </form>
                <form method="POST" action="<?= url('suspend_user', ['id' => $profileUser['id']]) ?>" class="d-inline"
                      onsubmit="var d=prompt('<?= t('suspend_days') ?>');if(d){this.querySelector('input[name=days]').value=d;this.submit();}return false;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="days" value="7">
                    <input type="hidden" name="redirect" value="/u/<?= urlencode($profileUser['username']) ?>">
                    <button class="btn btn-outline-soft btn-sm"><i class="fas fa-clock me-1"></i><?= t('suspend') ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2 class="section-title"><?= t('threads_by') ?> <?= escape($profileUser['username'] ?? '') ?></h2>

    <?php if (empty($userThreads ?? [])): ?>
        <div class="empty-state empty-state-sm">
            <i class="fas fa-inbox"></i>
            <p><?= t('no_threads') ?></p>
        </div>
    <?php else: ?>
        <div class="discussion-list">
            <?php foreach ($userThreads as $item): ?>
                <article class="discussion discussion-compact">
                    <div class="discussion-main">
                        <div class="discussion-top">
                            <?php if (($item['status'] ?? '') === 'sticky'): ?>
                                <span class="pill pill-sticky"><i class="fas fa-thumbtack"></i><?= t('sticky') ?></span>
                            <?php endif; ?>
                            <?php if (($item['status'] ?? '') === 'locked'): ?>
                                <span class="pill pill-locked"><i class="fas fa-lock"></i><?= t('locked') ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="discussion-title">
                            <a href="<?= url('thread', ['id' => $item['id'], 'slug' => slugify($item['title'] ?? '')]) ?>"><?= escape($item['title']) ?></a>
                        </h3>
                        <p class="discussion-meta"><time><?= time_ago($item['created_at'] ?? '') ?></time></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php render_footer(); ?>
