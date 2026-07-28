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
                Logged in: <?= escape($_SESSION['username'] ?? '') ?>
                <?php if (function_exists('is_admin') && is_admin()): ?>
                    | <a href="<?= base_url() ?>/?action=admin">Admin</a>
                <?php endif; ?>
                | <a href="<?= base_url() ?>/?action=profile&user=<?= escape($_SESSION['username'] ?? '') ?>">Profile</a>
                | <a href="<?= base_url() ?>/?action=logout">Logout</a>
            <?php else: ?>
                <a href="<?= base_url() ?>/?action=login">Login</a> | <a href="<?= base_url() ?>/?action=register">Register</a>
            <?php endif; ?>
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <a href="<?= base_url() ?>/?action=new_thread" class="btn">New Thread</a>
        <?php endif; ?>
    </div>

    <form method="GET" action="<?= base_url() ?>/?action=search" style="margin-bottom: 20px;">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="text" name="q" placeholder="Search threads..." required>
        <button type="submit" class="btn">Search</button>
    </form>

    <h2>Categories</h2>
    <ul style="list-style: none; padding: 0;">
        <?php
        $categories = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
        foreach ($categories as $cat): ?>
            <li style="margin-bottom: 5px;">
                <a href="<?= base_url() ?>/?action=category&id=<?= $cat['id'] ?>"><strong><?= escape($cat['name']) ?></strong></a>
                <span style="color: #666; font-size: 0.9em;"> — <?= escape($cat['description'] ?? '') ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2>Threads</h2>
    <?php foreach ($threads ?? [] as $thread): ?>
        <div class="thread">
            <h3><a href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>"><?= escape($thread['title']) ?></a></h3>
            <p><?= nl2br(escape($thread['content'])) ?></p>
            <small>in <?= escape($thread['category_name'] ?? 'General') ?> — by <?= escape($thread['author']) ?></small>
        </div>
    <?php endforeach; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= base_url() ?>/?action=home&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</body>
</html>