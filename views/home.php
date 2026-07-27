<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .thread { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .thread h3 { margin: 0 0 10px 0; }
        .btn { background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; }
        .auth { float: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= $config['site_name'] ?? 'Forum' ?></h1>
        <div class="auth">
            <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                Logged in: <?= escape($_SESSION['username'] ?? '') ?> | <a href="/?action=logout">Logout</a>
            <?php else: ?>
                <a href="/?action=login">Login</a> | <a href="/?action=register">Register</a>
            <?php endif; ?>
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="/?action=new_thread" class="btn">New Thread</a>
        <?php endif; ?>
    </div>

    <h2>Threads</h2>
    <?php foreach ($threads ?? [] as $thread): ?>
        <div class="thread">
            <h3><a href="/?action=thread&id=<?= $thread['id'] ?>"><?= escape($thread['title']) ?></a></h3>
            <p><?= nl2br(escape($thread['content'])) ?></p>
            <small>by <?= escape($thread['author']) ?></small>
        </div>
    <?php endforeach; ?>
</body>
</html>