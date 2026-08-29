<?php
/**
 * views/components/thread_modals.php — moderation modals for thread page.
 *
 * @var Bulletin\Renderer $this
 * @var array $thread
 * @var array $posts
 * @var array $categories
 * @var int $postPage
 */

$threadId = (int)($thread['id'] ?? 0);
$allThreads = $GLOBALS['pdo']->query("SELECT title FROM threads WHERE id != {$threadId} ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
?>

<dialog id="move-modal" class="bb-modal">
    <div class="modal-content">
        <form method="POST" action="<?= url('frontend_moderate') ?>">
            <?= $this->csrfField() ?>
            <input type="hidden" name="do" value="move">
            <input type="hidden" name="id" value="<?= $threadId ?>">
            <div class="mb-3">
                <label class="form-label"><?= t('move_thread') ?></label>
                <select name="category_id" class="form-select" required>
                    <option value=""><?= t('select_category') ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <?php if ((int)$cat['id'] !== (int)($thread['category_id'] ?? 0)): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= $this->e($cat['name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close="move-modal"><?= t('cancel') ?></button>
                <button type="submit" class="btn btn-brand"><?= t('move_thread') ?></button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="copy-modal" class="bb-modal">
    <div class="modal-content">
        <form method="POST" action="<?= url('frontend_moderate') ?>">
            <?= $this->csrfField() ?>
            <input type="hidden" name="do" value="copy">
            <input type="hidden" name="id" value="<?= $threadId ?>">
            <div class="mb-3">
                <label class="form-label"><?= t('copy_thread') ?></label>
                <select name="category_id" class="form-select" required>
                    <option value=""><?= t('select_category') ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"><?= $this->e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close="copy-modal"><?= t('cancel') ?></button>
                <button type="submit" class="btn btn-brand"><?= t('copy_thread') ?></button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="merge-modal" class="bb-modal">
    <div class="modal-content">
        <form method="POST" action="<?= url('merge_thread') ?>">
            <?= $this->csrfField() ?>
            <input type="hidden" name="thread_id" value="<?= $threadId ?>">
            <div class="mb-3">
                <label class="form-label"><?= t('merge_thread') ?></label>
                <input type="text" name="target_title" class="form-control" placeholder="<?= t('target_thread_title') ?>" required list="thread-titles">
                <datalist id="thread-titles">
                    <?php foreach ($allThreads as $t): ?>
                        <option value="<?= $this->e($t) ?>">
                    <?php endforeach; ?>
                </datalist>
                <div class="form-text"><?= t('merge_thread_confirm') ?></div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close="merge-modal"><?= t('cancel') ?></button>
                <button type="submit" class="btn btn-brand"><?= t('merge_thread') ?></button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="split-modal" class="bb-modal">
    <div class="modal-content">
        <form method="POST" action="<?= url('split_thread') ?>" id="split-form">
            <?= $this->csrfField() ?>
            <input type="hidden" name="thread_id" value="<?= $threadId ?>">
            <input type="hidden" name="post_ids" id="split-post-ids" value="">
            <div class="mb-3">
                <label class="form-label"><?= t('split_thread') ?></label>
                <input type="text" name="new_title" class="form-control" placeholder="<?= t('new_thread_title') ?>" required>
                <div class="form-text"><?= t('split_thread_confirm') ?></div>
            </div>
            <?php if (!empty($posts)): ?>
                <div class="mb-3">
                    <label class="form-label"><?= t('split_preview') ?></label>
                    <div class="list-group list-group-flush" style="max-height:240px;overflow-y:auto;">
                        <?php foreach ($posts as $index => $post): ?>
                            <label class="list-group-item d-flex align-items-center gap-2">
                                <input type="checkbox" class="split-post-check" value="<?= (int)$post['id'] ?>">
                                <span class="small text-muted">#<?= ($postPage - 1) * 15 + $index + 2 ?></span>
                                <span class="small"><?= $this->e(mb_substr(strip_tags(marked_parse($post['content'] ?? '')), 0, 120)) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-modal-close="split-modal"><?= t('cancel') ?></button>
                <button type="submit" class="btn btn-brand"><?= t('split_thread') ?></button>
            </div>
        </form>
    </div>
</dialog>
