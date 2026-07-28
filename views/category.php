<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= escape($category['name'] ?? 'Category') ?> - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .thread { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .thread h3 { margin: 0 0 10px 0; }
        .btn { background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; }
        .pagination { margin-top: 20px; text-align: center; }
        .pagination a { padding: 5px 10px; margin: 0 2px; background: #f0f0f0; text-decoration: none; border-radius: 3px; }
        .pagination a.active { background: #007bff; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <a href="<?= base_url() ?>/?action=home" style="color: white;">← Back to Forum</a>
    </div>

    <h2><?= escape($category['name'] ?? '') ?></h2>
    <p><?= escape($category['description'] ?? '') ?></p>

    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <div style="margin-bottom: 20px;">
            <a href="<?= base_url() ?>/?action=new_thread" class="btn">New Thread</a>
        </div>
    <?php endif; ?>

    <?php foreach ($threads ?? [] as $thread): ?>
        <div class="thread">
            <h3><a href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>"><?= escape($thread['title']) ?></a></h3>
            <p><?= nl2br(escape($thread['content'])) ?></p>
            <small>by <?= escape($thread['author']) ?></small>
        </div>
    <?php endforeach; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="<?= base_url() ?>/?action=category&id=<?= $category['id'] ?>&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</body>
</html>