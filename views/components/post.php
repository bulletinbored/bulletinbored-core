<?php
/**
 * views/components/post.php — single post component.
 *
 * @var Bulletin\Renderer $this
 * @var array $post
 * @var int $number
 * @var bool $isOp
 * @var array $thread
 * @var bool $isWatching
 * @var bool $isLocked
 * @var bool $canModerate
 * @var bool $isLogged
 * @var array $categories
 * @var array $uploads
 */

$isOp = !empty($isOp);
$anchor = $isOp ? 'post-1' : 'post-' . (int)($post['id'] ?? 0);
$author = $post['author'] ?? '';
$role = $post['author_role'] ?? 'user';
$canEdit = $isLogged && (($_SESSION['user_id'] ?? 0) == ($post['user_id'] ?? -1) || is_admin());
$threadUrl = url('thread', ['id' => $thread['id'] ?? 0, 'slug' => slugify($thread['title'] ?? '')]);
$uploads = $uploads ?? [];
$isLocked = $isLocked ?? (($thread['status'] ?? '') === 'locked');
$canModerate = $canModerate ?? false;
$isWatching = $isWatching ?? false;
$isLogged = $isLogged ?? false;
$categories = $categories ?? [];
$thread = $thread ?? [];
?>

<article class="post <?= $isOp ? 'post-op' : '' ?>" id="<?= $this->e($anchor) ?>" data-post-id="<?= (int)($post['id'] ?? 0) ?>" data-is-op="<?= $isOp ? '1' : '0' ?>">
    <div class="post-side">
        <a href="<?= url('profile', ['user' => $author]) ?>" class="post-side-avatar">
            <?= render_avatar($author, $post['author_avatar'] ?? '', 56) ?>
        </a>
        <a href="<?= url('profile', ['user' => $author]) ?>" class="post-author"><?= $this->e($author ?: '—') ?></a>
        <?php if ($role === 'admin'): ?>
            <span class="role-badge role-admin"><?= t('role_admin') ?></span>
        <?php elseif ($role === 'moderator'): ?>
            <span class="role-badge role-mod"><?= t('role_moderator') ?></span>
        <?php endif; ?>
        <span class="post-side-meta"><?= t('posts') ?> <?= compact_number($post['author_posts'] ?? 0) ?></span>
    </div>

    <div class="post-main">
        <?php if ($isOp && !empty($thread)): ?>
            <div class="thread-head">
                <div class="thread-head-top">
                    <?php if (!empty($thread['category_name'])): ?>
                        <a class="pill-cat" href="<?= url('category', ['id' => $thread['category_id'] ?? 0, 'slug' => slugify($thread['category_name'] ?? '')]) ?>">
                            <?= $this->e($thread['category_name'] ?? 'General') ?>
                        </a>
                    <?php endif; ?>
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

                <h1 class="thread-heading"><?= $this->e($thread['title'] ?? '') ?></h1>

                <div class="thread-head-meta">
                    <span><?= t('started_by') ?> <a href="<?= url('profile', ['user' => $thread['author'] ?? '']) ?>"><?= $this->e($thread['author'] ?? '—') ?></a></span>
                    <span class="dot">·</span>
                    <span><?= time_ago($thread['created_at'] ?? '') ?></span>
                    <span class="dot">·</span>
                    <span><i class="fas fa-comment-dots me-1"></i><?= compact_number($thread['reply_count'] ?? 0) ?> <span class="count-label"><?= t('replies') ?></span></span>
                    <span class="dot">·</span>
                    <span><i class="fas fa-eye me-1"></i><?= compact_number($thread['view_count'] ?? 0) ?> <span class="count-label"><?= t('views') ?></span></span>

                    <div class="thread-head-actions">
                        <?php if ($isLogged): ?>
                            <a class="btn btn-outline-soft btn-sm"
                               href="<?= $isWatching ? url('unwatch', ['thread_id' => $thread['id'] ?? 0]) : url('watch', ['thread_id' => $thread['id'] ?? 0]) ?>">
                                <i class="fas <?= $isWatching ? 'fa-bell-slash' : 'fa-bell' ?> me-1"></i><?= $isWatching ? t('unwatch') : t('watch') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canModerate): ?>
                    <div class="mod-bar">
                        <div class="dropdown">
                            <button class="btn btn-outline-soft btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-shield-halved"></i> <?= t('moderation') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <?php if (($thread['status'] ?? '') === 'pending'): ?>
                                    <li>
                                        <form method="POST" action="<?= url('frontend_moderate') ?>" class="px-3 py-1">
                                            <?= $this->csrfField() ?>
                                            <input type="hidden" name="do" value="approve">
                                            <input type="hidden" name="id" value="<?= (int)($thread['id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-success w-100"><?= t('approve_thread') ?></button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li>
                                    <form method="POST" action="<?= url('frontend_moderate') ?>" class="px-3 py-1">
                                        <?= $this->csrfField() ?>
                                        <input type="hidden" name="do" value="<?= $isLocked ? 'unlock' : 'lock' ?>">
                                        <input type="hidden" name="id" value="<?= (int)($thread['id'] ?? 0) ?>">
                                        <button type="submit" class="dropdown-item">
                                            <i class="fas fa-<?= $isLocked ? 'unlock' : 'lock' ?>"></i> <?= $isLocked ? t('unlock_thread') : t('lock_thread') ?>
                                        </button>
                                    </form>
                                </li>
                                <li>
                                    <form method="POST" action="<?= url('frontend_moderate') ?>" class="px-3 py-1">
                                        <?= $this->csrfField() ?>
                                        <input type="hidden" name="do" value="<?= ($thread['status'] ?? '') === 'sticky' ? 'unsticky' : 'sticky' ?>">
                                        <input type="hidden" name="id" value="<?= (int)($thread['id'] ?? 0) ?>">
                                        <button type="submit" class="dropdown-item">
                                            <i class="fas fa-thumbtack"></i> <?= ($thread['status'] ?? '') === 'sticky' ? t('unsticky_thread') : t('sticky_thread') ?>
                                        </button>
                                    </form>
                                </li>
                                <li>
                                    <form method="POST" action="<?= url('frontend_moderate') ?>" class="px-3 py-1">
                                        <?= $this->csrfField() ?>
                                        <input type="hidden" name="do" value="<?= ($thread['status'] ?? '') === 'hidden' ? 'approve' : 'hide' ?>">
                                        <input type="hidden" name="id" value="<?= (int)($thread['id'] ?? 0) ?>">
                                        <button type="submit" class="dropdown-item">
                                            <i class="fas fa-<?= ($thread['status'] ?? '') === 'hidden' ? 'eye' : 'eye-slash' ?>"></i> <?= ($thread['status'] ?? '') === 'hidden' ? t('approve_thread') : t('hide_thread') ?>
                                        </button>
                                    </form>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item" data-modal-open="move-modal">
                                        <i class="fas fa-arrows-alt"></i> <?= t('move_thread') ?>
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" data-modal-open="copy-modal">
                                        <i class="fas fa-copy"></i> <?= t('copy_thread') ?>
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" data-modal-open="merge-modal">
                                        <i class="fas fa-code-branch"></i> <?= t('merge_thread') ?>
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" data-modal-open="split-modal">
                                        <i class="fas fa-scissors"></i> <?= t('split_thread') ?>
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="<?= url('frontend_moderate') ?>" class="px-3 py-1" data-confirm="<?= t('delete_thread_confirm') ?>">
                                        <?= $this->csrfField() ?>
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)($thread['id'] ?? 0) ?>">
                                        <button type="submit" class="dropdown-item text-danger">
                                            <i class="fas fa-trash"></i> <?= t('delete') ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="post-head">
            <span class="post-number">#<?= (int)$number ?></span>
            <span class="dot">·</span>
            <time datetime="<?= $this->e($post['created_at'] ?? '') ?>" title="<?= $this->e($post['created_at'] ?? '') ?>">
                <?= t('published') ?> <?= time_ago($post['created_at'] ?? '') ?>
            </time>
            <div class="post-actions">
                <a class="post-action" href="<?= $threadUrl ?>#<?= $this->e($anchor) ?>" title="<?= t('permalink') ?>"><i class="fas fa-link"></i></a>
                <?php if ($canEdit): ?>
                    <a class="post-action" href="<?= url($isOp ? 'edit_thread' : 'edit_post', ['id' => $isOp ? ($thread['id'] ?? 0) : ($post['id'] ?? 0)]) ?>" title="<?= t('edit') ?>"><i class="fas fa-pen"></i></a>
                    <form method="POST" action="<?= url($isOp ? 'delete_thread' : 'delete_post', ['id' => $isOp ? ($thread['id'] ?? 0) : ($post['id'] ?? 0)]) ?>" class="d-inline"
                          data-confirm="<?= t('delete_confirm') ?>">
                        <?= $this->csrfField() ?>
                        <button type="submit" class="post-action post-action-danger" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="post-body markdown-body"><?= marked_parse($post['content'] ?? '') ?></div>

        <?php if (!empty($uploads)): ?>
            <div class="post-attachments">
                <h6><i class="fas fa-paperclip me-1"></i><?= t('attachments') ?></h6>
                <?php foreach ($uploads as $upload): ?>
                    <a href="<?= url('download', ['id' => $upload['id']]) ?>" class="attachment" download>
                        <i class="fas fa-file"></i><?= $this->e($upload['original_name']) ?>
                        <span><?= round($upload['size'] / 1024, 1) ?> KB</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
