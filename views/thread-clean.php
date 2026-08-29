<?php
/**
 * views/thread-clean.php — clean template using Renderer.
 *
 * No raw PHP logic. All data is pre-computed in the action handler.
 * Variables available: $thread, $posts, $postPage, $totalPages, etc.
 *
 * @var Bulletin\Renderer $this
 * @var array $thread
 * @var array $posts
 * @var int $postPage
 * @var int $totalPages
 * @var bool $isWatching
 * @var bool $canModerate
 * @var bool $isLocked
 * @var bool $isLogged
 * @var array $categories
 * @var array $threadUploads
 * @var string $replyError
 * @var string $replyContent
 * @var array $sidebarInfo
 */

$threadUrl = url('thread', ['id' => $thread['id'], 'slug' => slugify($thread['title'])]);

include __DIR__.'/header.php';
render_header($thread['title'] ?? 'Thread', ['info' => $sidebarInfo]);
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
        <li class="breadcrumb-item">
            <a href="<?= url('category', ['id' => $thread['category_id'] ?? 0, 'slug' => slugify($thread['category_name'] ?? '')]) ?>">
                <?= $this->e($thread['category_name'] ?? 'General') ?>
            </a>
        </li>
        <li class="breadcrumb-item active"><?= $this->e($thread['title'] ?? '') ?></li>
    </ol>
</nav>

<?php if ($canModerate): ?>
    <?= $this->renderComponent('thread_modals', ['thread' => $thread, 'posts' => $posts, 'categories' => $categories, 'postPage' => $postPage]) ?>
<?php endif; ?>

<div class="post-stream">
    <?php if ($postPage === 1): ?>
        <?= $this->renderComponent('post', [
            'post' => $thread,
            'number' => 1,
            'isOp' => true,
            'uploads' => $threadUploads,
            'thread' => $thread,
            'isWatching' => $isWatching,
            'isLocked' => $isLocked,
            'canModerate' => $canModerate,
            'categories' => $categories,
            'isLogged' => $isLogged,
        ]) ?>
    <?php else: ?>
        <a class="stream-jump" href="<?= $threadUrl ?>">
            <i class="fas fa-arrow-up me-2"></i><?= t('back_to_first_post') ?>
        </a>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
        <div class="empty-state empty-state-sm">
            <i class="fas fa-comments"></i>
            <p><?= t('no_replies') ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $index => $post): ?>
            <?= $this->renderComponent('post', [
                'post' => $post,
                'number' => ($postPage - 1) * 15 + $index + 2,
                'isOp' => false,
                'thread' => $thread,
                'canModerate' => $canModerate,
                'categories' => $categories,
                'isLogged' => $isLogged,
            ]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="pager" aria-label="<?= t('pagination') ?>">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $postPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('thread', ['id' => $thread['id'], 'slug' => slugify($thread['title'] ?? ''), 'post_page' => $i]) ?>"><?= $i ?></a>
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
            <?php if ($replyError): ?>
            <div class="alert alert-danger" role="alert">
                <?= $this->e($replyError) ?>
            </div>
            <?php endif; ?>
            <form method="POST" action="<?= url('reply') ?>">
                <?= $this->csrfField() ?>
                <input type="hidden" name="thread_id" value="<?= $thread['id'] ?>">
                <div class="mb-3">
                    <textarea id="editbored-content" name="content" class="form-control" rows="6"
                              placeholder="<?= t('reply_placeholder') ?>" required><?= $this->e($replyContent) ?></textarea>
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
<script src="<?= htmlspecialchars(base_url() . '/assets/js/thread-mod.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
