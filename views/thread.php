<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= escape($thread['title'] ?? 'Thread') ?> - Forum</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .post { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .reply-form { background: #eee; padding: 15px; margin-top: 20px; border-radius: 5px; }
        textarea { width: 100%; padding: 8px; }
        .btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 3px; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
        .btn-delete { background: #dc3545; }
    </style>
</head>
<body>
<div class="header">
        <a href="<?= base_url() ?>/?action=home" style="color: white;">← Back to Forum</a>
        <?php if (function_exists('is_admin') && is_admin()): ?>
            | <a href="<?= base_url() ?>/?action=admin" style="color: white;">Admin</a>
        <?php endif; ?>
    </div>

    <div class="post">
        <h2><?= escape($thread['title'] ?? '') ?></h2>
        <p><?= nl2br(escape($thread['content'] ?? '')) ?></p>
        <small>in <?= escape($thread['category_name'] ?? 'General') ?> — by <?= escape($thread['author']) ?></small>
    </div>

    <?php
    $uploadsStmt = $pdo->prepare("SELECT * FROM uploads WHERE thread_id = ? AND post_id IS NULL ORDER BY created_at ASC");
    $uploadsStmt->execute([$_GET['id'] ?? 0]);
    $uploads = $uploadsStmt->fetchAll();
    if (!empty($uploads)): ?>
        <h4>Attachments</h4>
        <?php foreach ($uploads as $upload): ?>
            <div>
                <a href="<?= base_url() ?>/uploads/<?= $upload['filename'] ?>" download="<?= escape($upload['original_name']) ?>">
                    <?= escape($upload['original_name']) ?>
                </a>
                <span style="color: #666;">(<?= round($upload['size'] / 1024, 1) ?> KB)</span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h3>Replies</h3>
    <?php foreach ($posts ?? [] as $post): ?>
        <div class="post">
            <strong><?= escape($post['author']) ?>:</strong>
            <p><?= nl2br(escape($post['content'])) ?></p>
            <?php if (function_exists('is_logged_in') && is_logged_in() && ($_SESSION['user_id'] == $post['user_id'] || is_admin())): ?>
                <div style="margin-top: 5px;">
                    <a href="<?= base_url() ?>/?action=edit_post&id=<?= $post['id'] ?>" class="btn" style="font-size: 0.8em;">Edit</a>
                    <form method="POST" action="<?= base_url() ?>/?action=delete_post&id=<?= $post['id'] ?>" style="display:inline" onsubmit="return confirm('Delete this post?')">
                        <button type="submit" class="btn btn-delete" style="font-size: 0.8em;">Delete</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>&post_page=<?= $i ?>" class="<?= $i === $postPage ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <div class="reply-form">
            <h3>Reply</h3>
            <form method="POST" action="<?= base_url() ?>/?action=reply">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="thread_id" value="<?= $thread['id'] ?? '' ?>">
                <textarea name="content" rows="4" required></textarea><br>
                <button type="submit" class="btn">Submit Reply</button>
            </form>
        </div>
    <?php endif; ?>
</body>
</html>