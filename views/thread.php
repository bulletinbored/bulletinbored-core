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
    </style>
</head>
<body>
    <div class="header">
        <a href="/?action=home">← Back to Forum</a>
    </div>

    <div class="post">
        <h2><?= escape($thread['title'] ?? '') ?></h2>
        <p><?= nl2br(escape($thread['content'] ?? '')) ?></p>
    </div>

    <h3>Replies</h3>
    <?php foreach ($posts ?? [] as $post): ?>
        <div class="post">
            <strong><?= escape($post['author']) ?>:</strong>
            <p><?= nl2br(escape($post['content'])) ?></p>
        </div>
    <?php endforeach; ?>

    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <div class="reply-form">
            <h3>Reply</h3>
            <form method="POST" action="/?action=reply">
                <input type="hidden" name="thread_id" value="<?= $thread['id'] ?? '' ?>">
                <textarea name="content" rows="4" required></textarea><br>
                <button type="submit" class="btn">Submit Reply</button>
            </form>
        </div>
    <?php endif; ?>
</body>
</html>