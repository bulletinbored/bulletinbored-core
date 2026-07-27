<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login - Forum</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .form-container { background: white; padding: 20px; max-width: 400px; margin: 50px auto; border-radius: 5px; }
        input { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; }
        .btn { background: #007bff; color: white; padding: 10px 20px; width: 100%; border: none; border-radius: 3px; cursor: pointer; }
        .error { color: red; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Login</h2>
        <?php if (isset($error)): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="POST" action="/login">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" class="btn">Login</button>
        </form>
        <p style="margin-top: 15px;"><a href="/register">Need an account? Register</a></p>
    </div>
</body>
</html>