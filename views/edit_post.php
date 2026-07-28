<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Post - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .form-container { background: white; padding: 20px; border-radius: 5px; }
        textarea { width: 100%; padding: 8px; margin: 8px 0; box-sizing: border-box; }
        .btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 3px; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
        .error { color: red; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <a href="<?= base_url() ?>/?action=thread&id=<?= $post['thread_id'] ?? '' ?>" style="color: white;">← Back to Thread</a>
    </div>

    <div class="form-container">
        <h2>Edit Post</h2>
        <?php if (isset($error)): ?>
            <p class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="POST" action="<?= base_url() ?>/?action=update_post">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="post_id" value="<?= $post['id'] ?? '' ?>">
            <textarea name="content" rows="6" required><?= escape($post['content'] ?? '') ?></textarea><br>
            <button type="submit" class="btn">Update Post</button>
            <a href="<?= base_url() ?>/?action=thread&id=<?= $post['thread_id'] ?? '' ?>" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</body>
</html>