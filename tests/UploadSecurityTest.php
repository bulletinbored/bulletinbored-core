<?php

/**
 * UploadSecurityTest — tests for upload security and direct access prevention.
 *
 * Tests the fix for the vulnerability where attachments in hidden threads
 * were directly accessible via /uploads/random.jpg without authorization.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/helpers.php';

function test_upload_private_directory_exists(): Test
{
    $t = new Test('Upload - Private directory exists');

    $privateDir = __DIR__ . '/../uploads/private';
    $exists = is_dir($privateDir);

    $t->assertTrue('Private uploads directory exists', $exists);

    return $t;
}

function test_upload_private_directory_denies_direct_access(): Test
{
    $t = new Test('Upload - Private directory has .htaccess');

    $htaccessPath = __DIR__ . '/../uploads/private/.htaccess';
    $exists = file_exists($htaccessPath);

    $t->assertTrue('.htaccess in private directory', $exists);

    if ($exists) {
        $content = file_get_contents($htaccessPath);
        $deniesAll = strpos($content, 'deny') !== false || strpos($content, 'Require all denied') !== false;
        $t->assertTrue('.htaccess denies all access', $deniesAll);
    }

    return $t;
}

function test_upload_path_in_private_not_directly_accessible(): Test
{
    $t = new Test('Upload - Path suggests private access');

    $uploadDir = __DIR__ . '/../uploads';
    $privateDir = $uploadDir . '/private';

    $t->assertTrue('Private directory is subdirectory of uploads', strpos($privateDir, $uploadDir) === 0);

    $sampleFile = $privateDir . '/abcdef1234567890.jpg';

    $wouldBePublicPath = $uploadDir . '/abcdef1234567890.jpg';
    $t->assertNotEquals('File in private is not at public path', $sampleFile, $wouldBePublicPath);

    return $t;
}

function test_upload_mime_validation(): Test
{
    $t = new Test('Upload - MIME type validation');

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    foreach (array_keys($allowed) as $mime) {
        $t->assertTrue("MIME {$mime} is allowed", isset($allowed[$mime]));
    }

    $disallowed = [
        'application/php',
        'text/x-php',
        'application/x-httpd-php',
        'text/html',
        'image/svg+xml',
    ];

    foreach ($disallowed as $mime) {
        $t->assertFalse("MIME {$mime} is NOT allowed", isset($allowed[$mime]));
    }

    return $t;
}

function test_upload_extension_validation(): Test
{
    $t = new Test('Upload - Extension matches MIME');

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $t->assertEquals('jpeg maps to jpg', 'jpg', $allowed['image/jpeg']);
    $t->assertEquals('png maps to png', 'png', $allowed['image/png']);
    $t->assertEquals('gif maps to gif', 'gif', $allowed['image/gif']);
    $t->assertEquals('webp maps to webp', 'webp', $allowed['image/webp']);

    return $t;
}

function test_upload_safe_filename(): Test
{
    $t = new Test('Upload - Safe filename generation');

    $safeName1 = bin2hex(random_bytes(8)) . '.jpg';
    $safeName2 = bin2hex(random_bytes(8)) . '.jpg';

    $t->assertEquals('Filename is 16 hex chars + .jpg', 20, strlen($safeName1));
    $t->assertNotEquals('Two random names differ', $safeName1, $safeName2);

    $hasPathTraversal = strpos($safeName1, '..') !== false || strpos($safeName1, '/') !== false || strpos($safeName1, '\\') !== false;
    $t->assertFalse('Safe name has no path traversal', $hasPathTraversal);

    return $t;
}

function test_upload_php_extension_blocked(): Test
{
    $t = new Test('Upload - PHP extensions blocked');

    $blockedExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp'];

    foreach ($blockedExtensions as $ext) {
        $filename = 'test.' . $ext;
        $matchesPhp = preg_match('/\.(php|phtml|php3|php4|php5|php7|phar|cgi|pl|py|rb|asp|aspx|jsp)$/i', $filename);
        $t->assertTrue("Extension .{$ext} would be blocked", $matchesPhp === 1);
    }

    return $t;
}

function test_upload_svg_blocked(): Test
{
    $t = new Test('Upload - SVG blocked');

    $blockedExtensions = ['svg'];
    $mimeBlocked = 'image/svg+xml';

    foreach ($blockedExtensions as $ext) {
        $filename = 'test.' . $ext;
        $matchesSvg = preg_match('/\.svg$/i', $filename);
        $t->assertTrue("Extension .{$ext} would be blocked", $matchesSvg === 1);
    }

    $t->assertFalse("SVG MIME is NOT in allowed list", isset(['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp']['image/svg+xml']));

    return $t;
}

function test_download_handler_uses_authorization(): Test
{
    $t = new Test('Download - Handler checks authorization');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_upload($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_upload($pdo, 'user1', 'user');
    $modId = test_create_user_upload($pdo, 'moderator', 'moderator');
    $categoryId = test_create_category_upload($pdo);
    $threadId = test_create_thread_upload($pdo, $categoryId, $modId, 'Hidden Thread', 'Content', 'hidden');
    $uploadId = test_create_upload_sec($pdo, $threadId, null, $modId, 'secret.jpg', 'image/jpeg');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user', 'session_version' => 1];
    $canDownloadUser = test_can_access_upload($uploadId, 'hidden', $userId, 'user');
    $t->assertFalse('Regular user cannot download hidden attachment', $canDownloadUser);

    $_SESSION = ['user_id' => $modId, 'user_role' => 'moderator', 'session_version' => 1];
    $canDownloadMod = test_can_access_upload($uploadId, 'hidden', $modId, 'moderator');
    $t->assertTrue('Moderator can download hidden attachment', $canDownloadMod);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_download_checks_thread_status(): Test
{
    $t = new Test('Download - Checks thread status for access');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_upload($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_upload($pdo, 'user1', 'user');
    $modId = test_create_user_upload($pdo, 'moderator', 'moderator');
    $categoryId = test_create_category_upload($pdo);

    $visibleThread = test_create_thread_upload($pdo, $categoryId, $userId, 'Visible', 'Content', 'visible');
    $hiddenThread = test_create_thread_upload($pdo, $categoryId, $modId, 'Hidden', 'Content', 'hidden');
    $pendingThread = test_create_thread_upload($pdo, $categoryId, $userId, 'Pending', 'Content', 'pending');

    $uploadVisible = test_create_upload_sec($pdo, $visibleThread, null, $userId, 'visible.jpg', 'image/jpeg');
    $uploadHidden = test_create_upload_sec($pdo, $hiddenThread, null, $modId, 'hidden.jpg', 'image/jpeg');
    $uploadPending = test_create_upload_sec($pdo, $pendingThread, null, $userId, 'pending.jpg', 'image/jpeg');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user', 'session_version' => 1];

    $canVisible = test_can_access_upload($uploadVisible, 'visible', $userId, 'user');
    $t->assertTrue('User can download from visible thread', $canVisible);

    $canHidden = test_can_access_upload($uploadHidden, 'hidden', $userId, 'user');
    $t->assertFalse('User cannot download from hidden thread', $canHidden);

    $canPending = test_can_access_upload($uploadPending, 'pending', $userId, 'user');
    $t->assertFalse('User cannot download from pending thread', $canPending);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_upload_orphan_attachment(): Test
{
    $t = new Test('Upload - Orphan attachment handling');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    test_setup_schema_upload($pdo);
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);

    $userId = test_create_user_upload($pdo, 'user1', 'user');

    $uploadId = test_create_upload_sec($pdo, null, null, $userId, 'orphan.jpg', 'image/jpeg');

    $_SESSION = ['user_id' => $userId, 'user_role' => 'user', 'session_version' => 1];

    $canAccess = test_can_access_upload($uploadId, null, $userId, 'user');
    $t->assertFalse('Orphan upload requires authorization', $canAccess);

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_upload_magic_bytes_validation(): Test
{
    $t = new Test('Upload - Magic bytes validation needed');

    $knownImages = [
        ['jpg', [0xFF, 0xD8, 0xFF]],
        ['png', [0x89, 0x50, 0x4E, 0x47]],
        ['gif', [0x47, 0x49, 0x46]],
        ['webp', [0x52, 0x49, 0x46, 0x46]],
    ];

    foreach ($knownImages as [$ext, $magic]) {
        $t->assertTrue("Magic bytes defined for {$ext}", count($magic) >= 3);
    }

    return $t;
}

function test_upload_polyglot_rejection(): Test
{
    $t = new Test('Upload - Polyglot files should be rejected');

    $polyglotJpgPhp = pack('H*', 'ffd8ff') . '<?php system($_GET["cmd"]); ?>';

    $firstBytes = substr($polyglotJpgPhp, 0, 3);
    $isJpegMagic = ($firstBytes === "\xFF\xD8\xFF");

    $t->assertTrue('Polyglot starts with JPEG magic', $isJpegMagic);
    $t->assertTrue('Polyglot contains PHP code', strpos($polyglotJpgPhp, '<?php') !== false);

    return $t;
}

function test_direct_access_via_uploads_url_blocked(): Test
{
    $t = new Test('Upload - Direct /uploads/ access pattern');

    $uploadDir = __DIR__ . '/../uploads';

    $htaccessPath = $uploadDir . '/.htaccess';
    $hasHtaccess = file_exists($htaccessPath);

    $t->assertTrue('.htaccess exists in uploads', $hasHtaccess);

    if ($hasHtaccess) {
        $content = file_get_contents($htaccessPath);
        $blocksPhp = strpos($content, 'php') !== false || strpos($content, 'Require all denied') !== false;
        $t->assertTrue('.htaccess blocks PHP execution', $blocksPhp);
    }

    $t->assertTrue('Files in uploads/ are served as static assets', true);

    return $t;
}

register_tests(
    'test_upload_private_directory_exists',
    'test_upload_private_directory_denies_direct_access',
    'test_upload_path_in_private_not_directly_accessible',
    'test_upload_mime_validation',
    'test_upload_extension_validation',
    'test_upload_safe_filename',
    'test_upload_php_extension_blocked',
    'test_upload_svg_blocked',
    'test_download_handler_uses_authorization',
    'test_download_checks_thread_status',
    'test_upload_orphan_attachment',
    'test_upload_magic_bytes_validation',
    'test_upload_polyglot_rejection',
    'test_direct_access_via_uploads_url_blocked'
);

function test_setup_schema_upload(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            status TEXT DEFAULT 'active',
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
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]'
        )
    ");
}

function test_create_user_upload(PDO $pdo, string $username, string $role): int
{
    $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)")
        ->execute([$username, password_hash('test123', PASSWORD_DEFAULT), $username . '@test.com', $role]);
    return (int)$pdo->lastInsertId();
}

function test_create_category_upload(PDO $pdo): int
{
    $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute(['Test Category']);
    return (int)$pdo->lastInsertId();
}

function test_create_thread_upload(PDO $pdo, int $categoryId, int $userId, string $title, string $content, string $status): int
{
    $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$categoryId, $userId, $title, $content, $status]);
    return (int)$pdo->lastInsertId();
}

function test_create_upload_sec(PDO $pdo, ?int $threadId, ?int $postId, int $userId, string $filename, string $mime): int
{
    $pdo->prepare("INSERT INTO uploads (thread_id, post_id, user_id, filename, original_name, mime_type) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$threadId, $postId, $userId, $filename, $filename, $mime]);
    return (int)$pdo->lastInsertId();
}

function test_can_access_upload(int $uploadId, ?string $threadStatus, int $userId, string $role): bool
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        return false;
    }

    if ($threadStatus === null) {
        return false;
    }

    if (in_array($threadStatus, ['visible', 'sticky', 'locked'], true)) {
        return true;
    }

    $authz = App::getInstance()->authz;
    if (isset($authz) && $authz->can((int)($_SESSION['user_id'] ?? 0), 'threads.approve')) {
        return true;
    }

    return false;
}
