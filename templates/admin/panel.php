<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - Forum</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .panel { background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .btn { padding: 5px 10px; margin: 2px; text-decoration: none; border-radius: 3px; }
        .btn-approve { background: #28a745; color: white; }
        .btn-delete { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Panel</h1>
        <a href="/">Back to Forum</a>
    </div>

    <div class="panel">
        <h2>Pending Threads</h2>
        <table>
            <tr><th>ID</th><th>Title</th><th>Author</th><th>Actions</th></tr>
            <?php foreach ($pending_threads ?? [] as $thread): ?>
            <tr>
                <td><?= $thread['id'] ?></td>
                <td><?= htmlspecialchars($thread['title'] ?? '') ?></td>
                <td><?= htmlspecialchars($thread['author'] ?? 'Unknown') ?></td>
                <td>
                    <form method="POST" action="/admin/moderate" style="display:inline">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="target_type" value="thread">
                        <input type="hidden" name="target_id" value="<?= $thread['id'] ?>">
                        <button class="btn btn-approve">Approve</button>
                    </form>
                    <form method="POST" action="/admin/moderate" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="target_type" value="thread">
                        <input type="hidden" name="target_id" value="<?= $thread['id'] ?>">
                        <button class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Pending Posts</h2>
        <table>
            <tr><th>ID</th><th>Content</th><th>Author</th><th>Actions</th></tr>
            <?php foreach ($pending_posts ?? [] as $post): ?>
            <tr>
                <td><?= $post['id'] ?></td>
                <td><?= htmlspecialchars(substr($post['content'] ?? '', 0, 100)) ?>...</td>
                <td><?= htmlspecialchars($post['author'] ?? 'Unknown') ?></td>
                <td>
                    <form method="POST" action="/admin/moderate" style="display:inline">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="target_type" value="post">
                        <input type="hidden" name="target_id" value="<?= $post['id'] ?>">
                        <button class="btn btn-approve">Approve</button>
                    </form>
                    <form method="POST" action="/admin/moderate" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="target_type" value="post">
                        <input type="hidden" name="target_id" value="<?= $post['id'] ?>">
                        <button class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</body>
</html>