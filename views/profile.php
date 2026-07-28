<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= escape($profileUser['username'] ?? 'Profile') ?> - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #333; color: white; padding: 10px 20px; margin: -20px -20px 20px; }
        .profile { background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .thread { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .btn { background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="header">
        <a href="<?= base_url() ?>/?action=home" style="color: white;">← Back to Forum</a>
    </div>

    <div class="profile">
        <h2>Profile: <?= escape($profileUser['username'] ?? '') ?></h2>
        <p>Role: <?= escape($profileUser['role'] ?? 'user') ?></p>
        <p>Member since: <?= escape($profileUser['created_at'] ?? 'N/A') ?></p>
        <?php if (function_exists('is_logged_in') && is_logged_in() && $_SESSION['user_id'] == $profileUser['id']): ?>
            <a href="<?= base_url() ?>/?action=edit_profile" class="btn">Edit Profile</a>
        <?php endif; ?>
    </div>

    <h3>Threads by <?= escape($profileUser['username']) ?></h3>
    <?php foreach ($userThreads ?? [] as $thread): ?>
        <div class="thread">
            <h3><a href="<?= base_url() ?>/?action=thread&id=<?= $thread['id'] ?>"><?= escape($thread['title']) ?></a></h3>
            <small><?= escape($thread['created_at'] ?? '') ?></small>
        </div>
    <?php endforeach; ?>
</body>
</html>