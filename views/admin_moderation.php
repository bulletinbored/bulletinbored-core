<?php 
global $pdo;
$pendingThreads = $pdo->query("
    SELECT t.*, u.username as author 
    FROM threads t 
    LEFT JOIN users u ON t.user_id = u.id 
    WHERE t.status = 'pending' 
    ORDER BY t.created_at DESC
")->fetchAll();
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header('Moderation'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Moderation Queue</h2>
    </div>
    
    <?php if (empty($pendingThreads)): ?>
        <div class="alert alert-info">
            <i class="fas fa-check-circle me-2"></i>No pending threads to moderate.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingThreads as $thread): ?>
                    <tr>
                        <td><?= escape($thread['title']) ?></td>
                        <td><?= escape($thread['author'] ?? 'Unknown') ?></td>
                        <td><?= escape($thread['created_at']) ?></td>
                        <td class="text-end">
                            <form method="POST" action="<?= base_url() ?>/?action=moderate" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="do" value="approve">
                                <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                                <button class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
                            </form>
                            <form method="POST" action="<?= base_url() ?>/?action=moderate" class="d-inline ms-1" onsubmit="return confirm('Delete this thread?')">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?= $thread['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash me-1"></i>Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <a href="<?= base_url() ?>/?action=admin" class="btn btn-secondary mb-3">Back to Dashboard</a>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>