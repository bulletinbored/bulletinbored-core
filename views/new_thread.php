<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Thread - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .form-container { background: white; padding: 20px; border-radius: 5px; }
        input, textarea { width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box; border: 1px solid #ddd; border-radius: 3px; }
        textarea { height: 200px; resize: vertical; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
        .error { color: red; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <a href="<?= base_url() ?>/?action=home" style="color: white;">← Back to Forum</a>
        <?php if (function_exists('is_admin') && is_admin()): ?>
            | <a href="<?= base_url() ?>/?action=admin" style="color: white;">Admin</a>
        <?php endif; ?>
    </div>

    <div class="form-container">
        <h2>New Thread</h2>
        <?php if (isset($error)): ?>
            <p class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="POST" action="<?= base_url() ?>/?action=create_thread" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <label>Category:</label>
            <select name="category_id" required>
                <?php
                $cats = $pdo->query("SELECT * FROM categories ORDER BY position")->fetchAll();
                foreach ($cats as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= escape($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Title:</label>
            <input type="text" name="title" required>
            <label>Content:</label>
            <textarea name="content" required></textarea>
            <label>Attachments:</label>
            <input type="file" name="attachments[]" multiple>
            <button type="submit" class="btn">Create Thread</button>
            <a href="<?= base_url() ?>/?action=home" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</body>
</html>