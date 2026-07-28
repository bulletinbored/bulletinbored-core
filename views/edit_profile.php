<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Profile - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-container { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        input { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; border: 1px solid #ddd; border-radius: 3px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; width: 100%; border: none; border-radius: 3px; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Edit Profile</h2>
        <form method="POST" action="<?= base_url() ?>/?action=update_profile">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <label>Username:</label>
            <input type="text" name="username" value="<?= escape($_SESSION['username'] ?? '') ?>">
            <label>New Password (leave blank to keep current):</label>
            <input type="password" name="password" placeholder="New password">
            <button type="submit" class="btn">Save Changes</button>
            <a href="<?= base_url() ?>/?action=profile&user=<?= escape($_SESSION['username'] ?? '') ?>" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</body>
</html>