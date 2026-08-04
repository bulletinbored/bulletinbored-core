<?php
$threadId    = (int)($thread['id'] ?? 0);
$threadUrl   = url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]);
$replyCount  = (int)($thread['reply_count'] ?? 0);
$viewCount   = (int)($thread['view_count'] ?? 0);
$postPage    = (int)($postPage ?? 1);
$perPage     = (int)($perPage ?? 15);
$totalPages  = (int)($totalPages ?? 1);
$isLogged    = function_exists('is_logged_in') && is_logged_in();
$canModerate = $isLogged && in_array($_SESSION['user_role'] ?? 'user', ['admin', 'moderator'], true);
$isLocked    = ($thread['status'] ?? '') === 'locked';

$isWatching = false;
if ($isLogged) {
    try {
        $watchStmt = $pdo->prepare("SELECT COUNT(*) FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
        $watchStmt->execute([$threadId, $_SESSION['user_id']]);
        $isWatching = $watchStmt->fetchColumn() > 0;
    } catch (PDOException $e) {}
}

// Attachments of the opening post
$threadUploads = [];
try {
    $uploadsStmt = $pdo->prepare("SELECT * FROM uploads WHERE thread_id = ? AND post_id IS NULL ORDER BY created_at ASC");
    $uploadsStmt->execute([$threadId]);
    $threadUploads = $uploadsStmt->fetchAll();
} catch (PDOException $e) {}

// Compact recap shown at the top of the sidebar
$sidebarInfo = [
    t('posts')       => compact_number($replyCount + 1),
    t('views')       => compact_number($viewCount),
    t('started')     => time_ago($thread['created_at'] ?? ''),
];
if ($replyCount > 0 && !empty($posts)) {
    $sidebarInfo[t('last_reply')] = time_ago(end($posts)['created_at'] ?? '');
    reset($posts);
}

/** Renders one moderation button as a self contained form. */
function mod_button($threadId, $do, $icon, $label, $variant = 'ghost', $confirm = '') {
    $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\''.escape($confirm).'\')"' : '';
    echo '<form method="POST" action="'.url('frontend_moderate').'" class="d-inline"'.$onsubmit.'>'
       . '<input type="hidden" name="csrf_token" value="'.generate_csrf_token().'">'
       . '<input type="hidden" name="do" value="'.escape($do).'">'
       . '<input type="hidden" name="id" value="'.(int)$threadId.'">'
       . '<button class="btn btn-'.escape($variant).' btn-sm" title="'.escape($label).'">'
       . '<i class="fas '.escape($icon).'"></i><span class="d-none d-xl-inline ms-1">'.escape($label).'</span>'
       . '</button></form>';
}

/** Renders a post block (opening post or reply). */
function render_post($data, $number, $threadId, $threadUrl, $opts = []) {
    $isOp     = !empty($opts['is_op']);
    $anchor   = $isOp ? 'post-1' : 'post-'.(int)($data['id'] ?? 0);
    $author   = $data['author'] ?? '';
    $role     = $data['author_role'] ?? 'user';
    $canEdit  = function_exists('is_logged_in') && is_logged_in()
                && (($_SESSION['user_id'] ?? 0) == ($data['user_id'] ?? -1) || (function_exists('is_admin') && is_admin()));
    ?>
    <article class="post <?= $isOp ? 'post-op' : '' ?>" id="<?= escape($anchor) ?>">
        <div class="post-side">
            <a href="<?= url('profile', ['user' => $author]) ?>" class="post-side-avatar">
                <?= render_avatar($author, $data['author_avatar'] ?? '', 56) ?>
            </a>
            <a href="<?= url('profile', ['user' => $author]) ?>" class="post-author"><?= escape($author ?: '—') ?></a>
            <?php if ($role === 'admin'): ?>
                <span class="role-badge role-admin"><?= t('role_admin') ?></span>
            <?php elseif ($role === 'moderator'): ?>
                <span class="role-badge role-mod"><?= t('role_moderator') ?></span>
            <?php endif; ?>
            <span class="post-side-meta"><?= t('posts') ?> <?= compact_number($data['author_posts'] ?? 0) ?></span>
        </div>

        <div class="post-main">
            <div class="post-head">
                <span class="post-number">#<?= (int)$number ?></span>
                <span class="dot">·</span>
                <time datetime="<?= escape($data['created_at'] ?? '') ?>" title="<?= escape($data['created_at'] ?? '') ?>">
                    <?= t('published') ?> <?= time_ago($data['created_at'] ?? '') ?>
                </time>
                <div class="post-actions">
                    <a class="post-action" href="<?= $threadUrl ?>#<?= escape($anchor) ?>" title="<?= t('permalink') ?>"><i class="fas fa-link"></i></a>
                    <?php if ($canEdit && !$isOp): ?>
                        <a class="post-action" href="<?= url('edit_post', ['id' => $data['id']]) ?>" title="<?= t('edit') ?>"><i class="fas fa-pen"></i></a>
                        <form method="POST" action="<?= url('delete_post', ['id' => $data['id']]) ?>" class="d-inline"
                              onsubmit="return confirm('<?= t('delete_confirm') ?>')">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <button type="submit" class="post-action post-action-danger" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="post-body markdown-body"><?= marked_parse($data['content'] ?? '') ?></div>

            <?php if (!empty($opts['uploads'])): ?>
                <div class="post-attachments">
                    <h6><i class="fas fa-paperclip me-1"></i><?= t('attachments') ?></h6>
                    <?php foreach ($opts['uploads'] as $upload): ?>
                        <a href="<?= base_url() ?>/uploads/<?= escape($upload['filename']) ?>" class="attachment" download>
                            <i class="fas fa-file"></i><?= escape($upload['original_name']) ?>
                            <span><?= round($upload['size'] / 1024, 1) ?> KB</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

include __DIR__.'/header.php';
render_header($thread['title'] ?? 'Thread', ['info' => $sidebarInfo]);
?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item">
                <a href="<?= url('category', ['id' => $thread['category_id'] ?? 0, 'slug' => slugify($thread['category_name'] ?? '')]) ?>">
                    <?= escape($thread['category_name'] ?? 'General') ?>
                </a>
            </li>
            <li class="breadcrumb-item active"><?= escape($thread['title'] ?? '') ?></li>
        </ol>
    </nav>

    <header class="discussion-head">
        <div class="discussion-head-top">
            <a class="pill-cat" href="<?= url('category', ['id' => $thread['category_id'] ?? 0, 'slug' => slugify($thread['category_name'] ?? '')]) ?>">
                <?= escape($thread['category_name'] ?? 'General') ?>
            </a>
            <?php if (($thread['status'] ?? '') === 'sticky'): ?>
                <span class="pill pill-sticky"><i class="fas fa-thumbtack"></i><?= t('sticky') ?></span>
            <?php endif; ?>
            <?php if ($isLocked): ?>
                <span class="pill pill-locked"><i class="fas fa-lock"></i><?= t('locked') ?></span>
            <?php endif; ?>
            <?php if (($thread['status'] ?? '') === 'hidden'): ?>
                <span class="pill pill-hidden"><i class="fas fa-eye-slash"></i><?= t('hidden') ?></span>
            <?php endif; ?>
        </div>

        <h1 class="discussion-heading"><?= escape($thread['title'] ?? '') ?></h1>

        <div class="discussion-head-meta">
            <span><?= t('started_by') ?> <a href="<?= url('profile', ['user' => $thread['author'] ?? '']) ?>"><?= escape($thread['author'] ?? '—') ?></a></span>
            <span class="dot">·</span>
            <span><?= time_ago($thread['created_at'] ?? '') ?></span>
            <span class="dot">·</span>
            <span><i class="fas fa-comment-dots me-1"></i><?= compact_number($replyCount) ?> <span class="count-label"><?= t('replies') ?></span></span>
            <span class="dot">·</span>
            <span><i class="fas fa-eye me-1"></i><?= compact_number($viewCount) ?> <span class="count-label"><?= t('views') ?></span></span>

            <div class="discussion-head-actions">
                <?php if ($isLogged): ?>
                    <a class="btn btn-outline-soft btn-sm"
                       href="<?= $isWatching ? url('unwatch', ['thread_id' => $threadId]) : url('watch', ['thread_id' => $threadId]) ?>">
                        <i class="fas <?= $isWatching ? 'fa-bell-slash' : 'fa-bell' ?> me-1"></i><?= $isWatching ? t('unwatch') : t('watch') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canModerate): ?>
            <div class="mod-bar">
                <span class="mod-bar-label"><i class="fas fa-shield-halved me-1"></i><?= t('moderation') ?></span>
                <?php
                if (($thread['status'] ?? '') === 'pending') {
                    mod_button($threadId, 'approve', 'fa-check', t('approve_thread'), 'outline-soft');
                }
                if ($isLocked) {
                    mod_button($threadId, 'unlock', 'fa-unlock', t('unlock_thread'), 'outline-soft');
                } else {
                    mod_button($threadId, 'lock', 'fa-lock', t('lock_thread'), 'outline-soft');
                }
                if (($thread['status'] ?? '') === 'sticky') {
                    mod_button($threadId, 'unsticky', 'fa-thumbtack', t('unsticky_thread'), 'outline-soft');
                } else {
                    mod_button($threadId, 'sticky', 'fa-thumbtack', t('sticky_thread'), 'outline-soft');
                }
                if (($thread['status'] ?? '') === 'hidden') {
                    mod_button($threadId, 'approve', 'fa-eye', t('approve_thread'), 'outline-soft');
                } else {
                    mod_button($threadId, 'hide', 'fa-eye-slash', t('hide_thread'), 'outline-soft', t('hide_confirm'));
                }
                mod_button($threadId, 'delete', 'fa-trash', t('delete'), 'outline-danger', t('delete_thread_confirm'));
                ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="post-stream">
        <?php if ($postPage === 1): ?>
            <?php render_post($thread, 1, $threadId, $threadUrl, ['is_op' => true, 'uploads' => $threadUploads]); ?>
        <?php else: ?>
            <a class="stream-jump" href="<?= url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? '')]) ?>">
                <i class="fas fa-arrow-up me-2"></i><?= t('back_to_first_post') ?>
            </a>
        <?php endif; ?>

        <?php if (empty($posts ?? [])): ?>
            <div class="empty-state empty-state-sm">
                <i class="fas fa-comments"></i>
                <p><?= t('no_replies') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $index => $post): ?>
                <?php render_post($post, ($postPage - 1) * $perPage + $index + 2, $threadId, $threadUrl); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pager" aria-label="<?= t('pagination') ?>">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $postPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= url('thread', ['id' => $threadId, 'slug' => slugify($thread['title'] ?? ''), 'post_page' => $i]) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ($isLogged): ?>
        <?php if ($isLocked && !$canModerate): ?>
            <div class="reply-box reply-box-locked">
                <i class="fas fa-lock"></i>
                <p class="mb-0"><?= t('thread_locked') ?></p>
            </div>
        <?php else: ?>
            <section class="reply-box" id="reply">
                <h2 class="reply-title"><i class="fas fa-reply me-2"></i><?= t('reply') ?></h2>
                <form method="POST" action="<?= url('reply') ?>">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                    <div class="mb-3">
                        <textarea id="editbored-content" name="content" class="form-control" rows="6"
                                  placeholder="<?= t('reply_placeholder') ?>" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-brand"><i class="fas fa-paper-plane me-2"></i><?= t('submit_reply') ?></button>
                </form>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="reply-box reply-box-cta">
            <h2 class="reply-title"><?= t('join_conversation') ?></h2>
            <p class="mb-3"><?= t('join_conversation_text') ?></p>
            <a href="<?= url('register') ?>" class="btn btn-brand"><?= t('register') ?></a>
            <a href="<?= url('login') ?>" class="btn btn-outline-soft"><?= t('login') ?></a>
        </section>
    <?php endif; ?>
<?php render_footer(); ?>
