<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $config['site_name'] ?? 'Forum' ?> - Thread</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .header a { color: white; margin-right: 15px; text-decoration: none; }
        .thread { background: white; padding: 15px; margin-bottom: 10px; border-radius: 5px; }
        .post { background: #fafafa; padding: 15px; margin: 10px 0; border-left: 3px solid #007bff; }
        .reply-form { background: white; padding: 15px; border-radius: 5px; margin-top: 20px; }
        textarea, input { width: 100%; padding: 8px; margin: 5px 0; box-sizing: border-box; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border: none; border-radius: 3px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="header">
        <h1><a href="/"><?= $config['site_name'] ?? 'Forum' ?></a></h1>
        <a href="/">Home</a> | <a href="/logout">Logout</a>
    </div>

    <div class="thread">
        <h2><?= htmlspecialchars($thread['title'] ?? '') ?></h2>
        <p><?= nl2br(htmlspecialchars($thread['content'] ?? '')) ?></p>
    </div>

    <h3>Replies</h3>
    <?php foreach ($posts ?? [] as $post): ?>
        <div class="post">
            <strong><?= htmlspecialchars($post['author'] ?? 'Unknown') ?>:</strong>
            <p><?= nl2br(htmlspecialchars($post['content'] ?? '')) ?></p>
        </div>
    <?php endforeach; ?>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="reply-form">
            <h3>Reply</h3>
            <form method="POST" action="/reply">
                <input type="hidden" name="thread_id" value="<?= $thread['id'] ?? '' ?>">
                <textarea name="content" rows="5" required></textarea>
                <button type="submit" class="btn">Submit Reply</button>
            </form>
        </div>
    <?php endif; ?>
</body>
</html>