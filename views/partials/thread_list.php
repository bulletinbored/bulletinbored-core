<?php
/**
 * Discussion list.
 *
 * Expects:
 *   $threads      array   rows produced by fetch_threads()
 *   $listUrlBase  string  url() action used for sort/pagination links
 *   $listUrlArgs  array   extra query args kept across sort/pagination links
 *   $sort         string  active sort key
 *   $page         int     current page
 *   $totalPages   int     total pages
 */
$listUrlBase = $listUrlBase ?? 'home';
$listUrlArgs = $listUrlArgs ?? [];
$sort        = $sort ?? 'latest';
$page        = (int)($page ?? 1);
$totalPages  = (int)($totalPages ?? 1);
?>

<div class="sort-bar">
    <?php foreach (thread_sort_options() as $sortKey => $sortLabel): ?>
        <a class="sort-link <?= $sort === $sortKey ? 'active' : '' ?>"
           href="<?= url($listUrlBase, array_merge($listUrlArgs, ['sort' => $sortKey])) ?>"><?= t($sortLabel) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($threads)): ?>
    <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <p><?= t('no_threads') ?></p>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="<?= url('new_thread') ?>" class="btn btn-brand btn-sm"><i class="fas fa-pen-to-square me-2"></i><?= t('new_thread') ?></a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="discussion-list">
        <?php foreach ($threads as $item):
            $itemUrl    = url('thread', ['id' => $item['id'], 'slug' => slugify($item['title'] ?? '')]);
            $replyCount = (int)($item['reply_count'] ?? 0);
            $viewCount  = (int)($item['view_count'] ?? 0);
            $lastAuthor = $item['last_author'] ?? '';
        ?>
            <article class="discussion">
                <a class="discussion-avatar" href="<?= url('profile', ['user' => $item['author'] ?? '']) ?>" tabindex="-1" aria-hidden="true">
                    <?= render_avatar($item['author'] ?? '', $item['author_avatar'] ?? '', 46) ?>
                </a>

                <div class="discussion-main">
                    <div class="discussion-top">
                        <?php if (!empty($item['category_name'])): ?>
                            <a class="pill-cat" href="<?= url('category', ['id' => $item['category_id'] ?? 0, 'slug' => slugify($item['category_name'])]) ?>">
                                <?= escape($item['category_name']) ?>
                            </a>
                        <?php endif; ?>
                        <?php if (($item['status'] ?? '') === 'sticky'): ?>
                            <span class="pill pill-sticky"><i class="fas fa-thumbtack"></i><?= t('sticky') ?></span>
                        <?php endif; ?>
                        <?php if (($item['status'] ?? '') === 'locked'): ?>
                            <span class="pill pill-locked"><i class="fas fa-lock"></i><?= t('locked') ?></span>
                        <?php endif; ?>
                        <?php if (($item['status'] ?? '') === 'hidden'): ?>
                            <span class="pill pill-hidden"><i class="fas fa-eye-slash"></i><?= t('hidden') ?></span>
                        <?php endif; ?>
                    </div>

                    <h2 class="discussion-title">
                        <a href="<?= $itemUrl ?>"><?= escape($item['title'] ?? '') ?></a>
                    </h2>

                    <p class="discussion-meta">
                        <?= t('started_by') ?>
                        <a href="<?= url('profile', ['user' => $item['author'] ?? '']) ?>"><?= escape($item['author'] ?? '—') ?></a>
                        <span class="dot">·</span>
                        <time datetime="<?= escape($item['created_at'] ?? '') ?>"><?= time_ago($item['created_at'] ?? '') ?></time>
                    </p>

                    <?php if ($replyCount > 0 && $lastAuthor !== ''): ?>
                        <div class="last-activity">
                            <span class="last-activity-label"><?= t('last_activity') ?></span>
                            <div class="last-activity-body">
                                <?= render_avatar($lastAuthor, $item['last_author_avatar'] ?? '', 24) ?>
                                <a class="last-activity-user" href="<?= url('profile', ['user' => $lastAuthor]) ?>"><?= escape($lastAuthor) ?></a>
                                <span class="dot">·</span>
                                <time datetime="<?= escape($item['last_post_at'] ?? '') ?>"><?= time_ago($item['last_post_at'] ?? '') ?></time>
                                <a class="last-activity-excerpt" href="<?= $itemUrl ?>#post-<?= (int)($item['last_post_id'] ?? 0) ?>">
                                    <?= escape(excerpt($item['last_post_content'] ?? '')) ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="discussion-stats">
                    <div class="dstat">
                        <span class="dstat-value"><?= compact_number($viewCount) ?></span>
                        <span class="dstat-label"><?= t('views') ?></span>
                    </div>
                    <div class="dstat">
                        <span class="dstat-value"><?= compact_number($replyCount) ?></span>
                        <span class="dstat-label"><?= t('replies') ?></span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
    <nav class="pager" aria-label="<?= t('pagination') ?>">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= url($listUrlBase, array_merge($listUrlArgs, ['sort' => $sort, 'page' => max(1, $page - 1)])) ?>">&laquo;</a>
            </li>
            <?php
            $from = max(1, $page - 2);
            $to   = min($totalPages, $from + 4);
            $from = max(1, $to - 4);
            for ($i = $from; $i <= $to; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url($listUrlBase, array_merge($listUrlArgs, ['sort' => $sort, 'page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= url($listUrlBase, array_merge($listUrlArgs, ['sort' => $sort, 'page' => min($totalPages, $page + 1)])) ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
