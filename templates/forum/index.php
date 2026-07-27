<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $config['site_name'] ?? 'Forum' ?> - Home</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .header a { color: white; margin-right: 15px; text-decoration: none; }
        .thread-list { background: white; padding: 15px; margin-bottom: 10px; border-radius: 5px; }
        .thread-item { border-bottom: 1px solid #eee; padding: 10px 0; }
        .thread-item:last-child { border: none; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= $config['site_name'] ?? 'Forum' ?></h1>
        <?php if (isset($_SESSION['user_id'])): ?>
            Logged in as: <?= $_SESSION['username'] ?? 'User' ?> | <a href="/logout">Logout</a> | <a href="/admin">Admin</a>
        <?php else: ?>
            <a href="/login">Login</a> | <a href="/register">Register</a>
        <?php endif; ?>
    </div>

    <h2>Categories</h2>
    <ul>
        <?php foreach ($categories ?? [] as $category): ?>
            <li><a href="/category/<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>

    <h2>Recent Threads</h2>
    <?php if (isset($_SESSION['user_id'])): ?>
        <div style="margin-bottom: 20px;">
            <a href="/thread/create" class="btn">New Thread</a>
        </div>
    <?php endif; ?>

    <div class="thread-list">
        <?php foreach ($threads ?? [] as $thread): ?>
            <div class="thread-item">
                <a href="/thread/<?= $thread['id'] ?>"><?= htmlspecialchars($thread['title']) ?></a>
                <span style="color: #666; font-size: 0.9em;">by <?= htmlspecialchars($thread['author'] ?? 'Unknown') ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>