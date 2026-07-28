<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Register - <?= $config['site_name'] ?? 'Forum' ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-container { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        input { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; border: 1px solid #ddd; border-radius: 3px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; width: 100%; border: none; border-radius: 3px; cursor: pointer; }
        .error { color: red; margin-bottom: 15px; }
        .link { margin-top: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Register</h2>
        <?php if (isset($error)): ?>
            <p class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="POST" action="<?= base_url() ?>/?action=do_register">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="text" name="username" placeholder="Username" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" class="btn">Register</button>
        </form>
        <div class="link">
            <a href="<?= base_url() ?>/?action=login">Already have an account? Login</a>
        </div>
    </div>
</body>
</html>