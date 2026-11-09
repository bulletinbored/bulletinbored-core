<?php

/**
 * UsersActionsCoverageTest — tests that directly call handler functions for line coverage.
 * Uses mocked dependencies to exercise all code paths in src/actions/users.php and lib/AuthZ.php.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../src/Errors.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Renderer.php';
require_once __DIR__ . '/../src/actions/users.php';

function setupCoverageDB(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            session_version INTEGER DEFAULT 1,
            email_verified INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0
        );
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Insert default roles with permissions
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'user',
        json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'moderator',
        json_encode(['threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'posts.delete'])
    ]);
    $pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
        'admin',
        json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'users.edit', 'users.delete', 'roles.manage', 'categories.manage', 'settings.manage', 'plugins.manage', 'themes.manage', 'langs.manage'])
    ]);

    return $pdo;
}

function setupCoverageApp(PDO $pdo): void
{
    App::reset();
    $app = App::getInstance();
    $app->pdo = $pdo;
    $app->config = [
        'site_name' => 'Test Forum',
        'base_url' => 'http://localhost/test',
        'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'avatar_max_size_mb' => 2,
        'posts_per_page' => 15,
    ];
    $app->authz = new AuthZ($pdo);
    $app->pluginManager = null;
    
    // Initialize i18n
    $app->i18n = [];
    $app->i18n['core'] = [
        'login' => 'Login',
        'register' => 'Register',
        'logout' => 'Logout',
        'username' => 'Username',
        'password' => 'Password',
        'email' => 'Email',
        'login_subtitle' => 'Sign in to your account',
        'register_subtitle' => 'Create a new account',
        'password_min' => 'Minimum 10 characters with upper, lower, number',
        'no_account' => 'No account?',
        'have_account' => 'Have an account?',
        'password_reset_request' => 'Password Reset',
        'forgot_subtitle' => 'Enter your email to reset password',
        'send_reset_link' => 'Send Reset Link',
        'back_to_login' => 'Back to Login',
        'password_reset_success' => 'Password Reset',
        'reset_subtitle' => 'Enter new password',
        'new_password' => 'New Password',
        'confirm_password' => 'Confirm Password',
        'save_changes' => 'Save Changes',
        'profile' => 'Profile',
        'edit_profile' => 'Edit Profile',
        'edit_profile_hint' => 'Update your profile information',
        'avatar' => 'Avatar',
        'avatar_hint' => 'Max 2MB, JPG/PNG/GIF/WebP',
        'upload_avatar' => 'Upload Avatar',
        'remove_avatar' => 'Remove Avatar',
        'avatar_removed' => 'Avatar removed',
        'leave_blank_keep' => 'Leave blank to keep current password',
        'register_check_email' => 'Check your email to verify your account',
        'verify_email_success' => 'Email Verified',
        'threads_by' => 'Threads by',
        'no_threads' => 'No threads yet',
        'discussions' => 'Discussions',
        'replies' => 'Replies',
        'joined' => 'Joined',
        'role_admin' => 'Admin',
        'role_moderator' => 'Moderator',
        'role_member' => 'Member',
        'banned' => 'Banned',
        'suspended' => 'Suspended',
        'send_message' => 'Send Message',
        'unban_user' => 'Unban User',
        'ban_user' => 'Ban User',
        'suspend' => 'Suspend',
        'suspend_days' => 'Suspend for how many days?',
        'all_discussions' => 'All Discussions',
        'sticky' => 'Sticky',
        'locked' => 'Locked',
        'home' => 'Home',
    ];
    $GLOBALS['translations'] = &$app->i18n['core'];
}

function cleanupCoverageSession(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (!headers_sent()) {
        session_start();
    }
    
    // Clean rate limit files
    $rateLimitDir = __DIR__ . '/../data/ratelimit';
    if (is_dir($rateLimitDir)) {
        foreach (glob($rateLimitDir . '/*.json') as $bucket) {
            @unlink($bucket);
        }
    }
}

// Mock view rendering to avoid including actual view files
function mockViewInclude(string $viewPath): void
{
    // Just capture that the view would be included
    // The actual handler returns true after including the view
}

// ============================================================
// HANDLE_LOGIN COVERAGE TESTS
// ============================================================

function test_handle_login_get_covers_view_include(): Test
{
    $t = new Test('handle_login - GET covers view include path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Mock the view include by checking the function returns true
    $result = handle_login('GET');
    
    $t->assert('Returns true (view rendered)', $result === true);
    $t->assert('Session not set', !isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_handle_login_post_success_covers_all_paths(): Test
{
    $t = new Test('handle_login - POST success covers all success paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create a test user
    $password = 'MyP4ssword123';
    $pdo->prepare("INSERT INTO users (username, password, email, role, email_verified, status) VALUES (?, ?, ?, 'user', 1, 'active')")
        ->execute(['testuser', password_hash($password, PASSWORD_DEFAULT), 'test@example.com']);
    
    // Simulate POST request
    $_POST = [
        'username' => 'testuser',
        'password' => $password,
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_login('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    $t->assert('Session user_id set', isset($_SESSION['user_id']));
    $t->assertEquals('Session username', 'testuser', $_SESSION['username'] ?? '');
    $t->assertEquals('Session role', 'user', $_SESSION['user_role'] ?? '');
    $t->assertEquals('Session status', 'active', $_SESSION['user_status'] ?? '');
    $t->assert('Session version set', isset($_SESSION['session_version']));
    $t->assert('Session email set', isset($_SESSION['email']));
    $t->assert('Session avatar set', isset($_SESSION['avatar']));
    $t->assert('Session suspension_time set', isset($_SESSION['user_suspension_time']));
    
    App::reset();
    return $t;
}

function test_handle_login_post_wrong_password_covers_error_path(): Test
{
    $t = new Test('handle_login - POST wrong password covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    
    $_POST = [
        'username' => 'testuser',
        'password' => 'WrongPassword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_login('POST');
    
    $t->assert('Returns true (view rendered with error)', $result === true);
    $t->assert('Session not set', !isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_handle_login_post_nonexistent_user_covers_error_path(): Test
{
    $t = new Test('handle_login - POST nonexistent user covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'username' => 'nonexistent',
        'password' => 'MyP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_login('POST');
    
    $t->assert('Returns true (view rendered with error)', $result === true);
    $t->assert('Session not set', !isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_handle_login_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_login - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    
    $_POST = [
        'username' => 'testuser',
        'password' => 'MyP4ssword123',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_login('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

function test_handle_login_post_rate_limited_covers_throw(): Test
{
    $t = new Test('handle_login - POST rate limited covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['ratelimited', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'rl@example.com']);
    
    // Exhaust rate limit
    $rlKey = rate_limit_client_ip() . '|' . 'ratelimited';
    for ($i = 0; $i < 6; $i++) {
        rate_limit('login', 5, 900, $rlKey);
    }
    
    $_POST = [
        'username' => 'ratelimited',
        'password' => 'MyP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_login('POST');
    } catch (\Bulletin\TooManyRequestsException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Too many login attempts. Please try again later.', $e->getMessage());
    }
    $t->assertTrue('Throws TooManyRequestsException when rate limited', $threw);
    
    App::reset();
    return $t;
}

function test_handle_login_already_logged_in_covers_redirect(): Test
{
    $t = new Test('handle_login - already logged in covers redirect path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_SESSION = ['user_id' => 1, 'user_role' => 'user', 'username' => 'existing', 'session_version' => 1];
    
    $result = handle_login('GET');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    App::reset();
    return $t;
}

function test_handle_login_banned_user_covers_throw(): Test
{
    $t = new Test('handle_login - banned user covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, status, email_verified) VALUES (?, ?, ?, 'banned', 1)")
        ->execute(['banneduser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'banned@example.com']);
    
    $_POST = [
        'username' => 'banneduser',
        'password' => 'MyP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_login('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Account banned', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for banned user', $threw);
    
    App::reset();
    return $t;
}

function test_handle_login_suspended_user_covers_throw(): Test
{
    $t = new Test('handle_login - suspended user covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $futureTime = time() + 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, status, suspension_time, email_verified) VALUES (?, ?, ?, 'suspended', ?, 1)")
        ->execute(['suspendeduser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'suspended@example.com', $futureTime]);
    
    $_POST = [
        'username' => 'suspendeduser',
        'password' => 'MyP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_login('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Account suspended', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for suspended user', $threw);
    
    App::reset();
    return $t;
}

function test_handle_login_expired_suspension_covers_reactivation(): Test
{
    $t = new Test('handle_login - expired suspension covers reactivation path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pastTime = time() - 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, status, suspension_time, email_verified) VALUES (?, ?, ?, 'suspended', ?, 1)")
        ->execute(['expiredsuspended', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'expired@example.com', $pastTime]);
    
    $_POST = [
        'username' => 'expiredsuspended',
        'password' => 'MyP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_login('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    $t->assert('Session user_id set', isset($_SESSION['user_id']));
    $t->assertEquals('Session status updated to active', 'active', $_SESSION['user_status'] ?? '');
    
    // Verify database was updated
    $stmt = $pdo->prepare("SELECT status FROM users WHERE username = ?");
    $stmt->execute(['expiredsuspended']);
    $user = $stmt->fetch();
    $t->assertEquals('Database status updated to active', 'active', $user['status'] ?? '');
    
    App::reset();
    return $t;
}

function test_handle_login_unverified_email_covers_throw(): Test
{
    $t = new Test('handle_login - unverified email covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['unverified', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'unverified@example.com']);
    
    $_POST = [
        'username' => 'unverified',
        'password' => 'MyP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_login('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Email not verified', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for unverified email', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_REGISTER COVERAGE TESTS
// ============================================================

function test_handle_register_get_covers_view_include(): Test
{
    $t = new Test('handle_register - GET covers view include path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $result = handle_register('GET');
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_register_post_success_covers_all_paths(): Test
{
    $t = new Test('handle_register - POST success covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'username' => 'newuser',
        'email' => 'new@example.com',
        'password' => 'StrongP4ssword',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_register('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    // Verify user created in database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['newuser']);
    $user = $stmt->fetch();
    $t->assert('User created in database', $user !== false);
    $t->assertEquals('Username', 'newuser', $user['username'] ?? '');
    $t->assertEquals('Email', 'new@example.com', $user['email'] ?? '');
    $t->assertEquals('Role', 'user', $user['role'] ?? '');
    $t->assertEquals('Email verified', 0, $user['email_verified'] ?? 1);
    $t->assertTrue('Password hashed correctly', password_verify('StrongP4ssword', $user['password'] ?? ''));
    
    // Verify email verification token created
    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $verification = $stmt->fetch();
    $t->assert('Email verification token created', $verification !== false);
    $t->assertEquals('Token not used', 0, $verification['used'] ?? 1);
    
    App::reset();
    return $t;
}

function test_handle_register_post_duplicate_username_covers_error(): Test
{
    $t = new Test('handle_register - POST duplicate username covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)")
        ->execute(['existing', password_hash('password123', PASSWORD_DEFAULT), 'existing@test.com']);
    
    $_POST = [
        'username' => 'existing',
        'email' => 'new@example.com',
        'password' => 'StrongP4ssword',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_register('POST');
    
    $t->assert('Returns true (view rendered with error)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_register_post_empty_fields_covers_error(): Test
{
    $t = new Test('handle_register - POST empty fields covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'username' => '',
        'email' => 'test@example.com',
        'password' => '',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_register('POST');
    
    $t->assert('Returns true (view rendered with error)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_register_post_weak_password_covers_error(): Test
{
    $t = new Test('handle_register - POST weak password covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $weakPasswords = [
        'short' => 'Ab1',
        'no_upper' => 'mypassword123',
        'no_lower' => 'MYPASSWORD123',
        'no_number' => 'MyPasswordHere',
    ];
    
    foreach ($weakPasswords as $type => $pw) {
        cleanupCoverageSession();
        $_POST = [
            'username' => 'user_' . $type,
            'email' => 'test@example.com',
            'password' => $pw,
            'csrf_token' => generate_csrf_token(),
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $result = handle_register('POST');
        $t->assert("Weak password ({$type}) rejected", $result === true);
    }
    
    App::reset();
    return $t;
}

function test_handle_register_post_rate_limited_covers_throw(): Test
{
    $t = new Test('handle_register - POST rate limited covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Exhaust rate limit
    for ($i = 0; $i < 6; $i++) {
        rate_limit('register', 5, 3600);
    }
    
    $_POST = [
        'username' => 'ratelimited',
        'email' => 'rl@example.com',
        'password' => 'StrongP4ssword',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_register('POST');
    } catch (\Bulletin\TooManyRequestsException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Too many registration attempts. Please try again later.', $e->getMessage());
    }
    $t->assertTrue('Throws TooManyRequestsException when rate limited', $threw);
    
    App::reset();
    return $t;
}

function test_handle_register_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_register - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'username' => 'newuser',
        'email' => 'new@example.com',
        'password' => 'StrongP4ssword',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_register('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_LOGOUT COVERAGE TESTS
// ============================================================

function test_handle_logout_post_covers_session_destroy(): Test
{
    $t = new Test('handle_logout - POST covers session destroy path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = generate_csrf_token();
    
    $result = handle_logout();
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    $t->assert('Session destroyed', !isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_handle_logout_get_covers_redirect(): Test
{
    $t = new Test('handle_logout - GET covers redirect path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    $result = handle_logout();
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    $t->assert('Session still exists (not destroyed on GET)', isset($_SESSION['user_id']));
    
    App::reset();
    return $t;
}

function test_handle_logout_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_logout - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_SESSION = [
        'user_id' => 42,
        'user_role' => 'user',
        'username' => 'testuser',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'invalid-token';
    
    $threw = false;
    try {
        handle_logout();
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_VERIFY_EMAIL COVERAGE TESTS
// ============================================================

function test_handle_verify_email_success_covers_all_paths(): Test
{
    $t = new Test('handle_verify_email - success covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user with unverified email
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create verification token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $verificationId = $pdo->lastInsertId();
    
    $_GET['token'] = $token;
    
    $result = handle_verify_email();
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    // Verify email_verified updated
    $stmt = $pdo->prepare("SELECT email_verified FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Email verified set to 1', 1, $user['email_verified'] ?? 0);
    
    // Verify token marked as used
    $stmt = $pdo->prepare("SELECT used FROM email_verifications WHERE id = ?");
    $stmt->execute([$verificationId]);
    $verification = $stmt->fetch();
    $t->assertEquals('Token marked as used', 1, $verification['used'] ?? 0);
    
    App::reset();
    return $t;
}

function test_handle_verify_email_invalid_token_covers_throw(): Test
{
    $t = new Test('handle_verify_email - invalid token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_GET['token'] = 'invalid-token';
    
    $threw = false;
    try {
        handle_verify_email();
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid verification token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for invalid token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_verify_email_expired_token_covers_throw(): Test
{
    $t = new Test('handle_verify_email - expired token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create EXPIRED verification token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $_GET['token'] = $token;
    
    $threw = false;
    try {
        handle_verify_email();
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid verification token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for expired token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_verify_email_already_used_token_covers_throw(): Test
{
    $t = new Test('handle_verify_email - already used token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create verification token marked as used
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at, used) VALUES (?, ?, ?, ?, 1)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $_GET['token'] = $token;
    
    $threw = false;
    try {
        handle_verify_email();
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid verification token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for already used token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_verify_email_missing_token_covers_throw(): Test
{
    $t = new Test('handle_verify_email - missing token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // No token in GET
    unset($_GET['token']);
    
    $threw = false;
    try {
        handle_verify_email();
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid verification token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for missing token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_verify_email_token_hash_mismatch_covers_throw(): Test
{
    $t = new Test('handle_verify_email - token hash mismatch covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 0)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create verification token with WRONG hash
    $token = bin2hex(random_bytes(32));
    $wrongHash = hash('sha256', 'wrong-token');
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO email_verifications (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $wrongHash, $expires]);
    
    $_GET['token'] = $token;
    
    $threw = false;
    try {
        handle_verify_email();
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid verification token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for hash mismatch', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_PROFILE COVERAGE TESTS
// ============================================================

function test_handle_profile_user_found_covers_view(): Test
{
    $t = new Test('handle_profile - user found covers view path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, created_at) VALUES (?, ?, ?, 'user', 'active', datetime('now'))")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    
    $result = handle_profile(['user' => 'testuser']);
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_profile_user_not_found_covers_throw(): Test
{
    $t = new Test('handle_profile - user not found covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $threw = false;
    try {
        handle_profile(['user' => 'nonexistent']);
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'User not found', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for nonexistent user', $threw);
    
    App::reset();
    return $t;
}

function test_handle_profile_banned_user_covers_view(): Test
{
    $t = new Test('handle_profile - banned user covers view path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, created_at) VALUES (?, ?, ?, 'user', 'banned', datetime('now'))")
        ->execute(['banneduser', password_hash('password123', PASSWORD_DEFAULT), 'banned@example.com']);
    
    $result = handle_profile(['user' => 'banneduser']);
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_profile_suspended_user_covers_view(): Test
{
    $t = new Test('handle_profile - suspended user covers view path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $futureTime = time() + 86400;
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, suspension_time, created_at) VALUES (?, ?, ?, 'user', 'suspended', ?, datetime('now'))")
        ->execute(['suspendeduser', password_hash('password123', PASSWORD_DEFAULT), 'suspended@example.com', $futureTime]);
    
    $result = handle_profile(['user' => 'suspendeduser']);
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_EDIT_PROFILE COVERAGE TESTS
// ============================================================

function test_handle_edit_profile_not_logged_in_covers_throw(): Test
{
    $t = new Test('handle_edit_profile - not logged in covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $threw = false;
    try {
        handle_edit_profile('GET');
    } catch (\Bulletin\UnauthorizedException $e) {
        $threw = true;
    }
    $t->assertTrue('Throws UnauthorizedException when not logged in', $threw);
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_get_covers_view(): Test
{
    $t = new Test('handle_edit_profile - GET covers view path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $result = handle_edit_profile('GET');
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_username_update_covers_all(): Test
{
    $t = new Test('handle_edit_profile - POST username update covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['olduser', password_hash('password123', PASSWORD_DEFAULT), 'old@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'olduser',
        'email' => 'old@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_POST = [
        'username' => 'newuser',
        'email' => 'old@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_edit_profile('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    // Verify database update
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Username updated in database', 'newuser', $user['username'] ?? '');
    
    // Verify session update
    $t->assertEquals('Username updated in session', 'newuser', $_SESSION['username'] ?? '');
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_duplicate_username_covers_error(): Test
{
    $t = new Test('handle_edit_profile - POST duplicate username covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create two users
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['user1', password_hash('password123', PASSWORD_DEFAULT), 'user1@example.com']);
    $userId1 = $pdo->lastInsertId();
    
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['user2', password_hash('password123', PASSWORD_DEFAULT), 'user2@example.com']);
    $userId2 = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId2,
        'user_role' => 'user',
        'username' => 'user2',
        'email' => 'user2@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_POST = [
        'username' => 'user1',
        'email' => 'user2@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_edit_profile('POST');
    
    $t->assert('Returns true (view rendered with error)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_email_update_covers_all(): Test
{
    $t = new Test('handle_edit_profile - POST email update covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'old@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'old@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_POST = [
        'username' => 'testuser',
        'email' => 'new@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_edit_profile('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    // Verify database update
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Email updated in database', 'new@example.com', $user['email'] ?? '');
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_password_update_covers_all(): Test
{
    $t = new Test('handle_edit_profile - POST password update covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_POST = [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_edit_profile('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    // Verify database update
    $stmt = $pdo->prepare("SELECT password, session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertTrue('Password hashed correctly', password_verify('NewP4ssword123', $user['password'] ?? ''));
    $t->assertEquals('Session version incremented', 2, $user['session_version'] ?? 0);
    
    // Verify session update
    $t->assertEquals('Session version updated', 2, $_SESSION['session_version'] ?? 0);
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_weak_password_covers_error(): Test
{
    $t = new Test('handle_edit_profile - POST weak password covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $weakPasswords = [
        'short' => 'Ab1',
        'no_upper' => 'mypassword123',
        'no_lower' => 'MYPASSWORD123',
        'no_number' => 'MyPasswordHere',
    ];
    
    foreach ($weakPasswords as $type => $pw) {
        cleanupCoverageSession();
        $_SESSION = [
            'user_id' => $userId,
            'user_role' => 'user',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'user_status' => 'active',
            'session_version' => 1,
        ];
        
        $_POST = [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => $pw,
            'csrf_token' => generate_csrf_token(),
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $result = handle_edit_profile('POST');
        $t->assert("Weak password ({$type}) rejected", $result === true);
    }
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_rate_limited_covers_throw(): Test
{
    $t = new Test('handle_edit_profile - POST rate limited covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Exhaust rate limit
    for ($i = 0; $i < 22; $i++) {
        rate_limit('edit_profile', 20, 3600, (string)$userId);
    }
    
    $_POST = [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_edit_profile('POST');
    } catch (\Bulletin\TooManyRequestsException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'You are updating your profile too fast. Please try again later.', $e->getMessage());
    }
    $t->assertTrue('Throws TooManyRequestsException when rate limited', $threw);
    
    App::reset();
    return $t;
}

function test_handle_edit_profile_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_edit_profile - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_POST = [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_edit_profile('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_REMOVE_AVATAR COVERAGE TESTS
// ============================================================

function test_handle_remove_avatar_not_logged_in_covers_throw(): Test
{
    $t = new Test('handle_remove_avatar - not logged in covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $threw = false;
    try {
        handle_remove_avatar('POST');
    } catch (\Bulletin\UnauthorizedException $e) {
        $threw = true;
    }
    $t->assertTrue('Throws UnauthorizedException when not logged in', $threw);
    
    App::reset();
    return $t;
}

function test_handle_remove_avatar_post_success_covers_all(): Test
{
    $t = new Test('handle_remove_avatar - POST success covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user with avatar and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, avatar, session_version) VALUES (?, ?, ?, 'user', 'active', 'avatar_1.jpg', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'avatar' => 'avatar_1.jpg',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = generate_csrf_token();
    
    $result = handle_remove_avatar('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    // Verify database update
    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertEquals('Avatar cleared in database', '', $user['avatar'] ?? 'not_empty');
    
    // Verify session update
    $t->assertEquals('Avatar cleared in session', '', $_SESSION['avatar'] ?? 'not_empty');
    
    App::reset();
    return $t;
}

function test_handle_remove_avatar_post_rate_limited_covers_throw(): Test
{
    $t = new Test('handle_remove_avatar - POST rate limited covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Exhaust rate limit
    for ($i = 0; $i < 22; $i++) {
        rate_limit('remove_avatar', 20, 3600, (string)$userId);
    }
    
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = generate_csrf_token();
    
    $threw = false;
    try {
        handle_remove_avatar('POST');
    } catch (\Bulletin\TooManyRequestsException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'You are removing avatars too fast. Please try again later.', $e->getMessage());
    }
    $t->assertTrue('Throws TooManyRequestsException when rate limited', $threw);
    
    App::reset();
    return $t;
}

function test_handle_remove_avatar_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_remove_avatar - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and log in
    $pdo->prepare("INSERT INTO users (username, password, email, role, status, session_version) VALUES (?, ?, ?, 'user', 'active', 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'invalid-token';
    
    $threw = false;
    try {
        handle_remove_avatar('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_FORGOT_PASSWORD COVERAGE TESTS
// ============================================================

function test_handle_forgot_password_get_covers_view(): Test
{
    $t = new Test('handle_forgot_password - GET covers view path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $result = handle_forgot_password('GET');
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_forgot_password_post_user_exists_covers_all(): Test
{
    $t = new Test('handle_forgot_password - POST user exists covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('MyP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $_POST = [
        'email' => 'test@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_forgot_password('POST');
    
    $t->assert('Returns true (view rendered with success)', $result === true);
    
    // Verify reset token created
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $reset = $stmt->fetch();
    $t->assert('Reset token created', $reset !== false);
    $t->assertEquals('User ID matches', $userId, $reset['user_id'] ?? 0);
    $t->assertEquals('Token not used', 0, $reset['used'] ?? 1);
    
    App::reset();
    return $t;
}

function test_handle_forgot_password_post_user_not_exists_covers_silent(): Test
{
    $t = new Test('handle_forgot_password - POST user not exists covers silent success');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'email' => 'nonexistent@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_forgot_password('POST');
    
    $t->assert('Returns true (view rendered with success)', $result === true);
    
    // Should not create reset token (silent success for security)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    $t->assertEquals('No reset token created', 0, $count);
    
    App::reset();
    return $t;
}

function test_handle_forgot_password_post_rate_limited_covers_throw(): Test
{
    $t = new Test('handle_forgot_password - POST rate limited covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Exhaust rate limit
    for ($i = 0; $i < 6; $i++) {
        rate_limit('forgot_password', 5, 3600);
    }
    
    $_POST = [
        'email' => 'test@example.com',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_forgot_password('POST');
    } catch (\Bulletin\TooManyRequestsException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Too many requests. Please try again later.', $e->getMessage());
    }
    $t->assertTrue('Throws TooManyRequestsException when rate limited', $threw);
    
    App::reset();
    return $t;
}

function test_handle_forgot_password_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_forgot_password - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'email' => 'test@example.com',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_forgot_password('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

// ============================================================
// HANDLE_RESET_PASSWORD COVERAGE TESTS
// ============================================================

function test_handle_reset_password_get_covers_view(): Test
{
    $t = new Test('handle_reset_password - GET covers view path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_GET['token'] = 'valid-token';
    
    $result = handle_reset_password('GET');
    
    $t->assert('Returns true (view rendered)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_get_missing_token_covers_throw(): Test
{
    $t = new Test('handle_reset_password - GET missing token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    unset($_GET['token']);
    
    $threw = false;
    try {
        handle_reset_password('GET');
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Page not found', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for missing token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_success_covers_all(): Test
{
    $t = new Test('handle_reset_password - POST success covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified, session_version) VALUES (?, ?, ?, 1, 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $resetId = $pdo->lastInsertId();
    
    $_POST = [
        'token' => $token,
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_reset_password('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    
    // Verify password updated
    $stmt = $pdo->prepare("SELECT password, session_version FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $t->assertTrue('Password hashed correctly', password_verify('NewP4ssword123', $user['password'] ?? ''));
    $t->assertEquals('Session version incremented', 2, $user['session_version'] ?? 0);
    
    // Verify token marked as used
    $stmt = $pdo->prepare("SELECT used FROM password_resets WHERE id = ?");
    $stmt->execute([$resetId]);
    $reset = $stmt->fetch();
    $t->assertEquals('Token marked as used', 1, $reset['used'] ?? 0);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_password_mismatch_covers_error(): Test
{
    $t = new Test('handle_reset_password - POST password mismatch covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and reset token
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $_POST = [
        'token' => $token,
        'password' => 'NewP4ssword123',
        'confirm_password' => 'DifferentP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_reset_password('POST');
    
    $t->assert('Returns true (view rendered with error)', $result === true);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_weak_password_covers_error(): Test
{
    $t = new Test('handle_reset_password - POST weak password covers error path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user and reset token
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $weakPasswords = [
        'short' => 'Ab1',
        'no_upper' => 'mypassword123',
        'no_lower' => 'MYPASSWORD123',
        'no_number' => 'MyPasswordHere',
    ];
    
    foreach ($weakPasswords as $type => $pw) {
        cleanupCoverageSession();
        
        $_POST = [
            'token' => $token,
            'password' => $pw,
            'confirm_password' => $pw,
            'csrf_token' => generate_csrf_token(),
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $result = handle_reset_password('POST');
        $t->assert("Weak password ({$type}) rejected", $result === true);
    }
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_invalid_token_covers_throw(): Test
{
    $t = new Test('handle_reset_password - POST invalid token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'token' => 'invalid-token',
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_reset_password('POST');
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid or expired reset token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for invalid token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_expired_token_covers_throw(): Test
{
    $t = new Test('handle_reset_password - POST expired token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create EXPIRED reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $_POST = [
        'token' => $token,
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_reset_password('POST');
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid or expired reset token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for expired token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_already_used_token_covers_throw(): Test
{
    $t = new Test('handle_reset_password - POST already used token covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token marked as used
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at, used) VALUES (?, ?, ?, ?, 1)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    
    $_POST = [
        'token' => $token,
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_reset_password('POST');
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid or expired reset token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for already used token', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_token_hash_mismatch_covers_throw(): Test
{
    $t = new Test('handle_reset_password - POST token hash mismatch covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['testuser', password_hash('password123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Create reset token with WRONG hash
    $token = bin2hex(random_bytes(32));
    $wrongHash = hash('sha256', 'wrong-token');
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $wrongHash, $expires]);
    
    $_POST = [
        'token' => $token,
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_reset_password('POST');
    } catch (\Bulletin\NotFoundException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Invalid or expired reset token', $e->getMessage());
    }
    $t->assertTrue('Throws NotFoundException for hash mismatch', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_rate_limited_covers_throw(): Test
{
    $t = new Test('handle_reset_password - POST rate limited covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Exhaust rate limit
    for ($i = 0; $i < 12; $i++) {
        rate_limit('reset_password', 10, 3600);
    }
    
    $_POST = [
        'token' => 'some-token',
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_reset_password('POST');
    } catch (\Bulletin\TooManyRequestsException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'Too many attempts. Please try again later.', $e->getMessage());
    }
    $t->assertTrue('Throws TooManyRequestsException when rate limited', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_csrf_invalid_covers_throw(): Test
{
    $t = new Test('handle_reset_password - POST invalid CSRF covers throw path');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    $_POST = [
        'token' => 'some-token',
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => 'invalid-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $threw = false;
    try {
        handle_reset_password('POST');
    } catch (\Bulletin\ForbiddenException $e) {
        $threw = true;
        $t->assertEquals('Exception message', 'CSRF token invalid', $e->getMessage());
    }
    $t->assertTrue('Throws ForbiddenException for invalid CSRF', $threw);
    
    App::reset();
    return $t;
}

function test_handle_reset_password_post_session_update_covers_all(): Test
{
    $t = new Test('handle_reset_password - POST session update for current user covers all paths');
    
    $pdo = setupCoverageDB();
    setupCoverageApp($pdo);
    cleanupCoverageSession();
    
    // Create a test user
    $pdo->prepare("INSERT INTO users (username, password, email, email_verified, session_version) VALUES (?, ?, ?, 1, 1)")
        ->execute(['testuser', password_hash('OldP4ssword123', PASSWORD_DEFAULT), 'test@example.com']);
    $userId = $pdo->lastInsertId();
    
    // Simulate current user logged in
    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'user_status' => 'active',
        'session_version' => 1,
    ];
    
    // Create reset token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("INSERT INTO password_resets (user_id, token, token_hash, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $tokenHash, $expires]);
    $resetId = $pdo->lastInsertId();
    
    $_POST = [
        'token' => $token,
        'password' => 'NewP4ssword123',
        'confirm_password' => 'NewP4ssword123',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    $result = handle_reset_password('POST');
    
    $t->assert('Returns redirect response', $result instanceof \Bulletin\Response);
    $t->assertEquals('Session version updated for current user', 2, $_SESSION['session_version'] ?? 0);
    
    App::reset();
    return $t;
}

register_tests(
    // handle_login coverage tests
    'test_handle_login_get_covers_view_include',
    'test_handle_login_post_success_covers_all_paths',
    'test_handle_login_post_wrong_password_covers_error_path',
    'test_handle_login_post_nonexistent_user_covers_error_path',
    'test_handle_login_post_csrf_invalid_covers_throw',
    'test_handle_login_post_rate_limited_covers_throw',
    'test_handle_login_already_logged_in_covers_redirect',
    'test_handle_login_banned_user_covers_throw',
    'test_handle_login_suspended_user_covers_throw',
    'test_handle_login_expired_suspension_covers_reactivation',
    'test_handle_login_unverified_email_covers_throw',
    
    // handle_register coverage tests
    'test_handle_register_get_covers_view_include',
    'test_handle_register_post_success_covers_all_paths',
    'test_handle_register_post_duplicate_username_covers_error',
    'test_handle_register_post_empty_fields_covers_error',
    'test_handle_register_post_weak_password_covers_error',
    'test_handle_register_post_rate_limited_covers_throw',
    'test_handle_register_post_csrf_invalid_covers_throw',
    
    // handle_logout coverage tests
    'test_handle_logout_post_covers_session_destroy',
    'test_handle_logout_get_covers_redirect',
    'test_handle_logout_post_csrf_invalid_covers_throw',
    
    // handle_verify_email coverage tests
    'test_handle_verify_email_success_covers_all_paths',
    'test_handle_verify_email_invalid_token_covers_throw',
    'test_handle_verify_email_expired_token_covers_throw',
    'test_handle_verify_email_already_used_token_covers_throw',
    'test_handle_verify_email_missing_token_covers_throw',
    'test_handle_verify_email_token_hash_mismatch_covers_throw',
    
    // handle_profile coverage tests
    'test_handle_profile_user_found_covers_view',
    'test_handle_profile_user_not_found_covers_throw',
    'test_handle_profile_banned_user_covers_view',
    'test_handle_profile_suspended_user_covers_view',
    
    // handle_edit_profile coverage tests
    'test_handle_edit_profile_not_logged_in_covers_throw',
    'test_handle_edit_profile_get_covers_view',
    'test_handle_edit_profile_post_username_update_covers_all',
    'test_handle_edit_profile_post_duplicate_username_covers_error',
    'test_handle_edit_profile_post_email_update_covers_all',
    'test_handle_edit_profile_post_password_update_covers_all',
    'test_handle_edit_profile_post_weak_password_covers_error',
    'test_handle_edit_profile_post_rate_limited_covers_throw',
    'test_handle_edit_profile_post_csrf_invalid_covers_throw',
    
    // handle_remove_avatar coverage tests
    'test_handle_remove_avatar_not_logged_in_covers_throw',
    'test_handle_remove_avatar_post_success_covers_all',
    'test_handle_remove_avatar_post_rate_limited_covers_throw',
    'test_handle_remove_avatar_post_csrf_invalid_covers_throw',
    
    // handle_forgot_password coverage tests
    'test_handle_forgot_password_get_covers_view',
    'test_handle_forgot_password_post_user_exists_covers_all',
    'test_handle_forgot_password_post_user_not_exists_covers_silent',
    'test_handle_forgot_password_post_rate_limited_covers_throw',
    'test_handle_forgot_password_post_csrf_invalid_covers_throw',
    
    // handle_reset_password coverage tests
    'test_handle_reset_password_get_covers_view',
    'test_handle_reset_password_get_missing_token_covers_throw',
    'test_handle_reset_password_post_success_covers_all',
    'test_handle_reset_password_post_password_mismatch_covers_error',
    'test_handle_reset_password_post_weak_password_covers_error',
    'test_handle_reset_password_post_invalid_token_covers_throw',
    'test_handle_reset_password_post_expired_token_covers_throw',
    'test_handle_reset_password_post_already_used_token_covers_throw',
    'test_handle_reset_password_post_token_hash_mismatch_covers_throw',
    'test_handle_reset_password_post_rate_limited_covers_throw',
    'test_handle_reset_password_post_csrf_invalid_covers_throw',
    'test_handle_reset_password_post_session_update_covers_all'
);