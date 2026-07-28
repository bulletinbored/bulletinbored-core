<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .panel { background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .btn { padding: 5px 10px; margin: 2px; text-decoration: none; border-radius: 3px; border: none; cursor: pointer; }
        .btn-approve { background: #28a745; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Panel</h1>
        <a href="<?= base_url() ?>/?action=home" style="color: white;">Back to Forum</a>
    </div>

    <div class="panel">
        <h2>Categories</h2>
        <table>
            <tr><th>ID</th><th>Name</th><th>Description</th><th>Actions</th></tr>
            <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?= $cat['id'] ?></td>
                <td><?= escape($cat['name']) ?></td>
                <td><?= escape($cat['description'] ?? '') ?></td>
                <td>
                    <form method="POST" action="<?= base_url() ?>/?action=edit_category&id=<?= $cat['id'] ?>" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="text" name="name" value="<?= escape($cat['name']) ?>" required style="width: 150px;">
                        <input type="text" name="description" value="<?= escape($cat['description'] ?? '') ?>" style="width: 200px;">
                        <button class="btn btn-approve">Save</button>
                    </form>
                    <form method="POST" action="<?= base_url() ?>/?action=delete_category&id=<?= $cat['id'] ?>" style="display:inline" onsubmit="return confirm('Delete this category?')">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <button class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <form method="POST" action="<?= base_url() ?>/?action=create_category" style="margin-top: 10px;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="text" name="name" placeholder="New category name" required>
            <input type="text" name="description" placeholder="Description">
            <button type="submit" class="btn">Add Category</button>
        </form>
    </div>

    <div class="panel">
        <h2>Pending Threads</h2>
        <?php if (empty($pending_threads)): ?>
            <p>No pending threads.</p>
        <?php else: ?>
        <table>
            <tr><th>ID</th><th>Title</th><th>Author</th><th>Actions</th></tr>
            <?php foreach ($pending_threads as $thread): ?>
            <tr>
                <td><?= $thread['id'] ?></td>
                <td><?= escape($thread['title']) ?></td>
                <td><?= escape($thread['author'] ?? 'Unknown') ?></td>
                <td>
                    <form method="POST" action="<?= base_url() ?>/?action=moderate" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="do" value="approve">
                        <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                        <button class="btn btn-approve">Approve</button>
                    </form>
                    <form method="POST" action="<?= base_url() ?>/?action=moderate" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                        <button class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>