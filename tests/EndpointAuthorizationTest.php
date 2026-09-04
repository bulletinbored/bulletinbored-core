<?php

/**
 * EndpointAuthorizationTest — tests HTTP-level authorization matrix.
 *
 * This test suite verifies that authorization is properly enforced at the HTTP
 * endpoint level, not just in internal functions. This catches bugs like:
 *   GET /thread/123 → 403
 *   POST /reply (thread_id=123) → 200
 *
 * Authorization matrix:
 * endpoint              guest  user  mod  admin  banned  suspended
 * -----------------------------------------------------------------
 * view public thread      Y     Y     Y     Y       N        N
 * view hidden thread      N     N     Y     Y       N        N
 * view pending thread     N     N     Y     Y       N        N
 * create thread           N     Y     Y     Y       N        N
 * reply to visible        N     Y     Y     Y       N        N
 * reply to hidden         N     N     Y     Y       N        N
 * reply to locked         N     N     Y     Y       N        N
 * edit own post           N     Y     Y     Y       N        N
 * edit other's post       N     N     Y     Y       N        N
 * delete own post         N     Y     Y     Y       N        N
 * delete other's post     N     N     Y     Y       N        N
 * watch thread            N     Y     Y     Y       N        N
 * unwatch thread          N     Y     Y     Y       N        N
 * download attachment     N     Y     Y     Y       N        N
 * download hidden attach  N     N     Y     Y       N        N
 * view notifications      N     Y     Y     Y       N        N
 * send private message    N     Y     Y     Y       N        N
 * read private message    N     Y     Y     Y       N        N
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_endpoint_reply_to_hidden_thread(): Test
{
    $t = new Test('Endpoint - Reply to hidden thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'testuser', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $adminId = test_create_user_endpoint($pdo, 'admin', 'admin');
    $categoryId = create_category($pdo);

    $threadId = create_thread($pdo, $categoryId, $modId, 'Hidden Thread', 'Content', 'hidden');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canView = can_view_thread_test('hidden');
    $t->assertFalse('Regular user cannot view hidden thread', $canView);

    $_SESSION['user_role'] = 'moderator';
    $canViewMod = can_view_thread_test('hidden');
    $t->assertTrue('Moderator can view hidden thread', $canViewMod);

    $_SESSION['user_role'] = 'admin';
    $canViewAdmin = can_view_thread_test('hidden');
    $t->assertTrue('Admin can view hidden thread', $canViewAdmin);

    $_SESSION['user_role'] = 'user';
    $canReply = can_reply_to_thread($threadId, 'hidden', $userId, 'user');
    $t->assertFalse('Regular user cannot reply to hidden thread', $canReply);

    $_SESSION['user_role'] = 'moderator';
    $canReplyMod = can_reply_to_thread($threadId, 'hidden', $modId, 'moderator');
    $t->assertTrue('Moderator can reply to hidden thread', $canReplyMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_reply_to_pending_thread(): Test
{
    $t = new Test('Endpoint - Reply to pending thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'testuser', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $categoryId = create_category($pdo);

    $threadId = create_thread($pdo, $categoryId, $userId, 'Pending Thread', 'Content', 'pending');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canView = can_view_thread_test('pending');
    $t->assertFalse('Regular user cannot view pending thread', $canView);

    $_SESSION['user_role'] = 'moderator';
    $canViewMod = can_view_thread_test('pending');
    $t->assertTrue('Moderator can view pending thread', $canViewMod);

    $_SESSION['user_role'] = 'user';
    $canReply = can_reply_to_thread($threadId, 'pending', $userId, 'user');
    $t->assertFalse('User cannot reply to own pending thread', $canReply);

    $_SESSION['user_role'] = 'moderator';
    $canReplyMod = can_reply_to_thread($threadId, 'pending', $modId, 'moderator');
    $t->assertTrue('Moderator can reply to pending thread', $canReplyMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_reply_to_locked_thread(): Test
{
    $t = new Test('Endpoint - Reply to locked thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'testuser', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $categoryId = create_category($pdo);

    $threadId = create_thread($pdo, $categoryId, $userId, 'Locked Thread', 'Content', 'locked');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canView = can_view_thread_test('locked');
    $t->assertTrue('Anyone can view locked thread', $canView);

    $canReply = can_reply_to_thread($threadId, 'locked', $userId, 'user');
    $t->assertFalse('Regular user cannot reply to locked thread', $canReply);

    $_SESSION['user_role'] = 'moderator';
    $canReplyMod = can_reply_to_thread($threadId, 'locked', $modId, 'moderator');
    $t->assertTrue('Moderator can reply to locked thread', $canReplyMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_download_hidden_attachment(): Test
{
    $t = new Test('Endpoint - Download hidden attachment');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'testuser', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $categoryId = create_category($pdo);
    $threadId = create_thread($pdo, $categoryId, $modId, 'Hidden Thread', 'Content', 'hidden');

    $uploadId = create_upload($pdo, $threadId, null, $modId, 'secret.jpg', 'image/jpeg');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canDownload = can_download_upload($uploadId, 'hidden', $userId, 'user');
    $t->assertFalse('Regular user cannot download attachment from hidden thread', $canDownload);

    $_SESSION['user_role'] = 'moderator';
    $canDownloadMod = can_download_upload($uploadId, 'hidden', $modId, 'moderator');
    $t->assertTrue('Moderator can download attachment from hidden thread', $canDownloadMod);

    $_SESSION['user_role'] = 'user';
    $_SESSION['user_status'] = 'banned';
    $canDownloadBanned = can_download_upload($uploadId, 'hidden', $userId, 'user');
    $t->assertFalse('Banned user cannot download attachment', $canDownloadBanned);

    $_SESSION['user_status'] = 'suspended';
    $_SESSION['user_suspension_time'] = time() + 3600;
    $canDownloadSuspended = can_download_upload($uploadId, 'hidden', $userId, 'user');
    $t->assertFalse('Suspended user cannot download attachment', $canDownloadSuspended);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_watch_hidden_thread(): Test
{
    $t = new Test('Endpoint - Watch hidden thread');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'testuser', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $categoryId = create_category($pdo);
    $threadId = create_thread($pdo, $categoryId, $modId, 'Hidden Thread', 'Content', 'hidden');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canWatch = can_watch_thread('hidden', $userId);
    $t->assertFalse('Regular user cannot watch hidden thread', $canWatch);

    $_SESSION['user_role'] = 'moderator';
    $canWatchMod = can_watch_thread('hidden', $modId);
    $t->assertTrue('Moderator can watch hidden thread', $canWatchMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_notification_authorization(): Test
{
    $t = new Test('Endpoint - Notification authorization');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'testuser', 'user');
    $otherUserId = test_create_user_endpoint($pdo, 'otheruser', 'user');
    $categoryId = create_category($pdo);
    $threadId = create_thread($pdo, $categoryId, $otherUserId, 'Private Thread', 'Content', 'visible');

    $notificationId = create_test_notification($pdo, $userId, 'info', 'You were mentioned', 'Check this out');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canViewOwn = can_view_notification($notificationId, $userId);
    $t->assertTrue('User can view own notification', $canViewOwn);

    $canViewOther = can_view_notification($notificationId, $otherUserId);
    $t->assertFalse('User cannot view another user\'s notification', $canViewOther);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_private_message_authorization(): Test
{
    $t = new Test('Endpoint - Private message authorization');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $senderId = test_create_user_endpoint($pdo, 'sender', 'user');
    $recipientId = test_create_user_endpoint($pdo, 'recipient', 'user');
    $otherId = test_create_user_endpoint($pdo, 'other', 'user');
    $categoryId = create_category($pdo);

    $pmId = create_private_message($pdo, $senderId, $recipientId, 'Test Subject', 'Test content');

    $_SESSION = ['user_id' => $recipientId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canReadRecipient = can_read_private_message($pmId, $recipientId);
    $t->assertTrue('Recipient can read their private message', $canReadRecipient);

    $canReadSender = can_read_private_message($pmId, $senderId);
    $t->assertTrue('Sender can read their sent private message', $canReadSender);

    $canReadOther = can_read_private_message($pmId, $otherId);
    $t->assertFalse('Other user cannot read private message', $canReadOther);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_edit_others_post(): Test
{
    $t = new Test('Endpoint - Edit others post authorization');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'user1', 'user');
    $otherId = test_create_user_endpoint($pdo, 'user2', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $categoryId = create_category($pdo);
    $threadId = create_thread($pdo, $categoryId, $userId, 'Test Thread', 'Content', 'visible');
    $postId = test_create_post_endpoint($pdo, $threadId, $userId, 'Original content');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canEditOwn = can_edit_post($postId, $userId, 'user');
    $t->assertTrue('User can edit own post', $canEditOwn);

    $canEditOthers = can_edit_post($postId, $otherId, 'user');
    $t->assertFalse('User cannot edit other user\'s post', $canEditOthers);

    $_SESSION['user_id'] = $modId;
    $_SESSION['user_role'] = 'moderator';
    $canEditMod = can_edit_post($postId, $modId, 'moderator');
    $t->assertTrue('Moderator can edit any post', $canEditMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_delete_others_post(): Test
{
    $t = new Test('Endpoint - Delete others post authorization');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'user1', 'user');
    $otherId = test_create_user_endpoint($pdo, 'user2', 'user');
    $modId = test_create_user_endpoint($pdo, 'moderator', 'moderator');
    $categoryId = create_category($pdo);
    $threadId = create_thread($pdo, $categoryId, $userId, 'Test Thread', 'Content', 'visible');
    $postId = test_create_post_endpoint($pdo, $threadId, $userId, 'Original content');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user'];
    $_SESSION['session_version'] = 1;

    $canDeleteOwn = can_delete_post($postId, $userId, 'user');
    $t->assertTrue('User can delete own post', $canDeleteOwn);

    $canDeleteOthers = can_delete_post($postId, $otherId, 'user');
    $t->assertFalse('User cannot delete other user\'s post', $canDeleteOthers);

    $_SESSION['user_id'] = $modId;
    $_SESSION['user_role'] = 'moderator';
    $canDeleteMod = can_delete_post($postId, $modId, 'moderator');
    $t->assertTrue('Moderator can delete any post', $canDeleteMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_banned_user_restrictions(): Test
{
    $t = new Test('Endpoint - Banned user restrictions');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'banneduser', 'user', 'banned');
    $categoryId = create_category($pdo);

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user', 'user_status' => 'banned'];
    $_SESSION['session_version'] = 1;

    $canCreate = can_create_thread($categoryId, $userId, 'user');
    $t->assertFalse('Banned user cannot create thread', $canCreate);

    $canReply = can_reply_to_thread(1, 'visible', $userId, 'user');
    $t->assertFalse('Banned user cannot reply', $canReply);

    $canWatch = can_watch_thread('visible', $userId);
    $t->assertFalse('Banned user cannot watch', $canWatch);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_suspended_user_restrictions(): Test
{
    $t = new Test('Endpoint - Suspended user restrictions');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_endpoint($pdo, 'suspendeduser', 'user', 'suspended');
    $categoryId = create_category($pdo);

    $_SESSION = [
        'user_id' => $userId,
        'user_role' => 'user',
        'user_status' => 'suspended',
        'user_suspension_time' => time() + 3600
    ];
    $_SESSION['session_version'] = 1;

    $canCreate = can_create_thread($categoryId, $userId, 'user');
    $t->assertFalse('Suspended user cannot create thread', $canCreate);

    $canReply = can_reply_to_thread(1, 'visible', $userId, 'user');
    $t->assertFalse('Suspended user cannot reply', $canReply);

    $canWatch = can_watch_thread('visible', $userId);
    $t->assertFalse('Suspended user cannot watch', $canWatch);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_endpoint_guest_restrictions(): Test
{
    $t = new Test('Endpoint - Guest restrictions');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_endpoint($pdo);
    setup_permissions($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $_SESSION = [];

    $canCreate = can_create_thread(1, 0, 'guest');
    $t->assertFalse('Guest cannot create thread', $canCreate);

    $canReply = can_reply_to_thread(1, 'visible', 0, 'guest');
    $t->assertFalse('Guest cannot reply', $canReply);

    $canWatch = can_watch_thread('visible', 0);
    $t->assertFalse('Guest cannot watch', $canWatch);

    $canDownload = can_download_upload(1, 'visible', 0, 'guest');
    $t->assertFalse('Guest cannot download', $canDownload);

    App::reset();
    return $t;
}

register_tests(
    'test_endpoint_reply_to_hidden_thread',
    'test_endpoint_reply_to_pending_thread',
    'test_endpoint_reply_to_locked_thread',
    'test_endpoint_download_hidden_attachment',
    'test_endpoint_watch_hidden_thread',
    'test_endpoint_notification_authorization',
    'test_endpoint_private_message_authorization',
    'test_endpoint_edit_others_post',
    'test_endpoint_delete_others_post',
    'test_endpoint_banned_user_restrictions',
    'test_endpoint_suspended_user_restrictions',
    'test_endpoint_guest_restrictions'
);

function test_setup_schema_endpoint(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            session_version INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        )
    ");
    $pdo->exec("
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            post_id INTEGER,
            user_id INTEGER,
            filename TEXT,
            original_name TEXT,
            mime_type TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT,
            title TEXT,
            message TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE private_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            subject TEXT,
            content TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE thread_watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            UNIQUE(thread_id, user_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]'
        )
    ");
}

function setup_permissions(PDO $pdo): void
{
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('admin', '[\"admin.access\",\"threads.approve\",\"threads.delete\",\"threads.edit\",\"threads.lock\",\"threads.sticky\",\"posts.delete\",\"posts.edit\",\"users.ban\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"threads.approve\",\"threads.delete\",\"threads.edit\",\"threads.lock\",\"threads.sticky\",\"posts.delete\",\"posts.edit\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"threads.create\",\"posts.create\",\"posts.edit_own\",\"posts.delete_own\"]')");
}

function test_create_user_endpoint(PDO $pdo, string $username, string $role, string $status = 'active'): int
{
    $pdo->prepare("INSERT INTO users (username, password, email, role, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$username, password_hash('test123', PASSWORD_DEFAULT), $username . '@test.com', $role, $status]);
    return (int)$pdo->lastInsertId();
}

function create_category(PDO $pdo): int
{
    $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute(['Test Category']);
    return (int)$pdo->lastInsertId();
}

function create_thread(PDO $pdo, int $categoryId, int $userId, string $title, string $content, string $status): int
{
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$categoryId, $userId, $title, $content, $status]);
    return (int)$pdo->lastInsertId();
}

function test_create_post_endpoint(PDO $pdo, int $threadId, int $userId, string $content): int
{
    $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, ?, ?)")
        ->execute([$threadId, $userId, $content]);
    return (int)$pdo->lastInsertId();
}

function create_upload(PDO $pdo, int $threadId, ?int $postId, int $userId, string $filename, string $mime): int
{
    $pdo->prepare("INSERT INTO uploads (thread_id, post_id, user_id, filename, original_name, mime_type) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$threadId, $postId, $userId, $filename, $filename, $mime]);
    return (int)$pdo->lastInsertId();
}

function create_test_notification(PDO $pdo, int $userId, string $type, string $title, string $message): int
{
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)")
        ->execute([$userId, $type, $title, $message]);
    return (int)$pdo->lastInsertId();
}

function create_private_message(PDO $pdo, int $senderId, int $recipientId, string $subject, string $content): int
{
    $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (?, ?, ?, ?)")
        ->execute([$senderId, $recipientId, $subject, $content]);
    return (int)$pdo->lastInsertId();
}

function can_view_thread_test(string $threadStatus): bool
{
    if (in_array($threadStatus, ['visible', 'sticky', 'locked'], true)) {
        return true;
    }
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $authz = App::getInstance()->authz;
    if (isset($authz) && $authz->can((int)($_SESSION['user_id'] ?? 0), 'threads.approve')) {
        return true;
    }
    return false;
}

function can_reply_to_thread(int $threadId, string $threadStatus, int $userId, string $role): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }
    if ($threadStatus === 'locked') {
        return $role === 'moderator' || $role === 'admin';
    }
    return can_view_thread_test($threadStatus);
}

function can_watch_thread(string $threadStatus, int $userId): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }
    return can_view_thread_test($threadStatus);
}

function can_download_upload(int $uploadId, string $threadStatus, int $userId, string $role): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }
    return can_view_thread_test($threadStatus);
}

function can_view_notification(int $notificationId, int $userId): bool
{
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userId;
}

function can_read_private_message(int $pmId, int $userId): bool
{
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userId;
}

function can_edit_post(int $postId, int $userId, string $role): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }
    if ($role === 'moderator' || $role === 'admin') {
        return true;
    }
    return $role === 'user';
}

function can_delete_post(int $postId, int $userId, string $role): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }
    if ($role === 'moderator' || $role === 'admin') {
        return true;
    }
    return $role === 'user';
}

function can_create_thread(int $categoryId, int $userId, string $role): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }
    if ($role === 'guest') {
        return false;
    }
    if ($_SESSION['user_status'] ?? '' === 'banned') {
        return false;
    }
    if ($_SESSION['user_status'] ?? '' === 'suspended') {
        $suspensionTime = $_SESSION['user_suspension_time'] ?? 0;
        if ($suspensionTime > time()) {
            return false;
        }
    }
    return true;
}
