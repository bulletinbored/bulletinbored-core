<?php

/**
 * PostsIsolatedIntegrationTest — integration tests that run in isolated subprocesses
 * to achieve line coverage for action files without function redeclaration conflicts.
 */

require_once __DIR__ . '/harness.php';

function runIsolatedTest(string $testScript): array
{
    $php = PHP_BINARY;
    // Write test script to a temporary file to avoid shell escaping issues
    $tempFile = sys_get_temp_dir() . '/isolated_test_' . uniqid() . '.php';
    file_put_contents($tempFile, $testScript);
    $cmd = "$php \"$tempFile\" 2>&1";
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);
    @unlink($tempFile);
    return [
        'output' => implode("\n", $output),
        'returnVar' => $returnVar,
    ];
}

/**
 * Generate a test script with proper absolute paths
 */
function makeIsolatedTestScript(string $testCode): string
{
    $baseDir = __DIR__;
    $env = createTestEnvironment($baseDir);
    // The env already includes session setup, so we just need to add the test code
    return $env . "\n\$BASE_DIR = '" . addslashes($baseDir) . "';\n" . $testCode;
}

function createTestEnvironment(string $baseDir): string
{
    return <<<PHP
<?php
define('BULLETIN_TEST_MODE', true);

require_once '$baseDir/../src/bootstrap.php';
require_once '$baseDir/../src/helpers.php';
require_once '$baseDir/../src/Errors.php';
require_once '$baseDir/../src/Helpers/AuthHelpers.php';
require_once '$baseDir/../src/csp.php';
require_once '$baseDir/../lib/AuthZ.php';
require_once '$baseDir/../lib/PluginManager.php';

// Create database first
\$pdo = new PDO('sqlite::memory:');
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec("
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
        email_verified INTEGER DEFAULT 1,
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
    CREATE TABLE roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        permissions TEXT DEFAULT '[]'
    );
    CREATE TABLE uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        thread_id INTEGER,
        post_id INTEGER,
        filename TEXT,
        original_name TEXT,
        size INTEGER,
        mime_type TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE thread_watchers (
        thread_id INTEGER,
        user_id INTEGER,
        PRIMARY KEY (thread_id, user_id)
    );
    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT,
        message TEXT,
        link TEXT,
        is_read INTEGER DEFAULT 0,
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
");

\$pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
    'user',
    json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own', 'threads.edit_own', 'threads.delete_own'])
]);
\$pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
    'moderator',
    json_encode(['threads.create', 'threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'moderation.manage'])
]);
\$pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
    'admin',
    json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy'])
]);

\$pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (1, 'General', 'General discussion', 1)")->execute();
\$pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (2, 'Tech', 'Technology', 2)")->execute();
\$pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (3, 'Moderator Only', 'Moderators only', 3, 'moderator')")->execute();
\$pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (4, 'Admin Only', 'Admins only', 4, 'admin')")->execute();
\$pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (5, 'Custom Roles', 'Custom roles', 5, '[\"moderator\",\"admin\"]')")->execute();

\$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
    ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
\$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'user1', ?, 'user1@test.com', 'user', 1)")
    ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
\$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (3, 'user2', ?, 'user2@test.com', 'user', 1)")
    ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
\$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (10, 'moduser', ?, 'mod@test.com', 'moderator', 1)")
    ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

// Initialize App singleton
require_once '$baseDir/../src/App.php';
App::reset();
\$app = App::getInstance();
\$app->pdo = \$pdo;
\$app->config = [
    'site_name' => 'Test Forum',
    'base_url' => 'http://localhost',
    'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'avatar_max_size_mb' => 2,
    'image_max_size_mb' => 5,
    'posts_per_page' => 15,
];
\$app->authz = new AuthZ(\$pdo);
\$app->pluginManager = null;

// Generate CSP nonce
\$cspNonce = generate_csp_nonce();

// Start session manually since test mode skips session_setup
\$sessionDir = sys_get_temp_dir() . '/bulletin_test_sessions';
if (!is_dir(\$sessionDir)) {
    @mkdir(\$sessionDir, 0755, true);
}
if (is_dir(\$sessionDir) && is_writable(\$sessionDir)) {
    session_save_path(\$sessionDir);
}
session_name('BBSESSID');
// Destroy any existing session first
if (session_status() !== PHP_SESSION_NONE) {
    session_destroy();
}
session_start();

// Default session for logged-in user
\$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];
PHP;

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
        email_verified INTEGER DEFAULT 1,
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
    CREATE TABLE roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        permissions TEXT DEFAULT '[]'
    );
    CREATE TABLE uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        thread_id INTEGER,
        post_id INTEGER,
        filename TEXT,
        original_name TEXT,
        size INTEGER,
        mime_type TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE thread_watchers (
        thread_id INTEGER,
        user_id INTEGER,
        PRIMARY KEY (thread_id, user_id)
    );
    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT,
        message TEXT,
        link TEXT,
        is_read INTEGER DEFAULT 0,
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
");

$pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
    'user',
    json_encode(['threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own', 'threads.edit_own', 'threads.delete_own'])
]);
$pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
    'moderator',
    json_encode(['threads.create', 'threads.approve', 'posts.edit', 'threads.delete', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy', 'moderation.manage'])
]);
$pdo->prepare("INSERT OR IGNORE INTO roles (name, permissions) VALUES (?, ?)")->execute([
    'admin',
    json_encode(['admin.access', 'threads.delete', 'users.ban', 'threads.approve', 'posts.edit', 'threads.lock', 'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy'])
]);

$pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (1, 'General', 'General discussion', 1)")->execute();
$pdo->prepare("INSERT INTO categories (id, name, description, position) VALUES (2, 'Tech', 'Technology', 2)")->execute();
$pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (3, 'Moderator Only', 'Moderators only', 3, 'moderator')")->execute();
$pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (4, 'Admin Only', 'Admins only', 4, 'admin')")->execute();
$pdo->prepare("INSERT INTO categories (id, name, description, position, allowed_roles) VALUES (5, 'Custom Roles', 'Custom roles', 5, '[\"moderator\",\"admin\"]')")->execute();

$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (1, 'admin', ?, 'admin@test.com', 'admin', 1)")
    ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (2, 'user1', ?, 'user1@test.com', 'user', 1)")
    ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (3, 'user2', ?, 'user2@test.com', 'user', 1)")
    ->execute([password_hash('user123', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (10, 'moduser', ?, 'mod@test.com', 'moderator', 1)")
    ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

App::reset();
$app = App::getInstance();
$app->pdo = $pdo;
$app->config = [
    'site_name' => 'Test Forum',
    'base_url' => 'http://localhost',
    'avatar_allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'avatar_max_size_mb' => 2,
    'image_max_size_mb' => 5,
    'posts_per_page' => 15,
];
$app->authz = new AuthZ($pdo);
$app->pluginManager = null;

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

function t(string $key, array $params = []): string {
    return $key;
}
PHP;
}

// ============================================================
// POSTS-NEW.PHP INTEGRATION TESTS
// ============================================================

function test_posts_new_get_renders_view(): Test
{
    $t = new Test('Isolated - posts-new.php GET Renders View');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('GET');
$output = ob_get_clean();
echo "RESULT:" . ($result === true ? 'true' : gettype($result)) . "\n";
echo "OUTPUT_LEN:" . strlen($output) . "\n";
echo "HAS_FORM:" . (strpos($output, '<form') !== false ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is true', strpos($output, 'RESULT:true') !== false);
    $t->assert('Output contains form', strpos($output, 'HAS_FORM:yes') !== false);

    return $t;
}

function test_posts_new_post_success(): Test
{
    $t = new Test('Isolated - posts-new.php POST Success');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Integration Test Thread',
    'content' => 'This is integration test content',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
    echo "HEADERS:" . json_encode($result->getHeaders()) . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
$stmt->execute(['Integration Test Thread']);
$thread = $stmt->fetch();
echo "THREAD_CREATED:" . ($thread ? 'yes' : 'no') . "\n";
if ($thread) {
    echo "THREAD_CONTENT:" . $thread['content'] . "\n";
    echo "THREAD_USER_ID:" . $thread['user_id'] . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Thread created in database', strpos($output, 'THREAD_CREATED:yes') !== false);
    $t->assert('Thread content correct', strpos($output, 'THREAD_CONTENT:This is integration test content') !== false);
    $t->assert('Thread user_id correct', strpos($output, 'THREAD_USER_ID:2') !== false);

    return $t;
}

function test_posts_new_post_empty_title(): Test
{
    $t = new Test('Isolated - posts-new.php POST Empty Title');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => '',
    'content' => 'Content without title',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT:" . ($result === true ? 'true' : gettype($result)) . "\n";
echo "HAS_ERROR:" . (strpos($output, 'Title and content are required') !== false ? 'yes' : 'no') . "\n";
echo "HAS_FORM:" . (strpos($output, '<form') !== false ? 'yes' : 'no') . "\n";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM threads");
$stmt->execute();
$count = (int)$stmt->fetchColumn();
echo "THREAD_COUNT:" . $count . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is true', strpos($output, 'RESULT:true') !== false);
    $t->assert('Output contains error message', strpos($output, 'HAS_ERROR:yes') !== false);
    $t->assert('Output contains form', strpos($output, 'HAS_FORM:yes') !== false);
    $t->assert('No thread created', strpos($output, 'THREAD_COUNT:0') !== false);

    return $t;
}

function test_posts_new_post_empty_content(): Test
{
    $t = new Test('Isolated - posts-new.php POST Empty Content');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Title without content',
    'content' => '',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT:" . ($result === true ? 'true' : gettype($result)) . "\n";
echo "HAS_ERROR:" . (strpos($output, 'Title and content are required') !== false ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is true', strpos($output, 'RESULT:true') !== false);
    $t->assert('Output contains error message', strpos($output, 'HAS_ERROR:yes') !== false);

    return $t;
}

function test_posts_new_post_csrf_invalid(): Test
{
    $t = new Test('Isolated - posts-new.php POST Invalid CSRF');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Valid Title',
    'content' => 'Valid Content',
    'category_id' => '1',
    'csrf_token' => 'invalid-token',
];
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('POST');
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:CSRF token invalid') !== false);

    return $t;
}

function test_posts_new_not_logged_in(): Test
{
    $t = new Test('Isolated - posts-new.php Not Logged In');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('GET');
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\UnauthorizedException $e) {
    echo "UNAUTHORIZED\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('UnauthorizedException thrown', strpos($output, 'UNAUTHORIZED') !== false);

    return $t;
}

function test_posts_new_no_permission(): Test
{
    $t = new Test('Isolated - posts-new.php No Permission');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (99, 'restricted', ?, 'restricted@test.com', 'restricted', 1)")
    ->execute([password_hash('password123', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('restricted', '[]')")->execute();

$_SESSION = [
    'user_id' => 99,
    'user_role' => 'restricted',
    'username' => 'restricted',
    'user_status' => 'active',
    'session_version' => 1,
];
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('GET');
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Not authorized to create threads') !== false);

    return $t;
}

function test_posts_new_category_moderator_only(): Test
{
    $t = new Test('Isolated - posts-new.php Moderator Only Category');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Test Thread',
    'content' => 'Test Content',
    'category_id' => '3',
    'csrf_token' => generate_csrf_token(),
];
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('POST');
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:') !== false);

    return $t;
}

function test_posts_new_moderator_can_access_moderator_category(): Test
{
    $t = new Test('Isolated - posts-new.php Moderator Can Access Moderator Category');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 10,
    'user_role' => 'moderator',
    'username' => 'moduser',
    'user_status' => 'active',
    'session_version' => 1,
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Moderator Thread',
    'content' => 'Moderator Content',
    'category_id' => '3',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
$stmt->execute(['Moderator Thread']);
$thread = $stmt->fetch();
echo "THREAD_CREATED:" . ($thread ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Thread created by moderator', strpos($output, 'THREAD_CREATED:yes') !== false);

    return $t;
}

function test_posts_new_admin_can_access_admin_category(): Test
{
    $t = new Test('Isolated - posts-new.php Admin Can Access Admin Category');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 1,
    'user_role' => 'admin',
    'username' => 'admin',
    'user_status' => 'active',
    'session_version' => 1,
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Admin Thread',
    'content' => 'Admin Content',
    'category_id' => '4',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
$stmt->execute(['Admin Thread']);
$thread = $stmt->fetch();
echo "THREAD_CREATED:" . ($thread ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Thread created by admin', strpos($output, 'THREAD_CREATED:yes') !== false);

    return $t;
}

function test_posts_new_plugin_filter_modifies_data(): Test
{
    $t = new Test('Isolated - posts-new.php Plugin Filter Modifies Data');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pluginManager = new class {
    public array $filters = [];
    public array $hooks = [];
    public array $filterCalls = [];
    public array $hookCalls = [];
    public function filter(string $name, $data, ...$args) {
        $this->filterCalls[] = ['name' => $name, 'data' => $data, 'args' => $args];
        if (isset($this->filters[$name])) {
            return $this->filters[$name]($data, ...$args);
        }
        return $data;
    }
    public function runHook(string $name, ...$args): void {
        $this->hookCalls[] = ['name' => $name, 'args' => $args];
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $hook) { $hook(...$args); }
        }
    }
    public function checkHook(string $name, ...$args): bool {
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $hook) { if ($hook(...$args)) return true; }
        }
        return false;
    }
};
$app = App::getInstance();
$app->pluginManager = $pluginManager;

$pluginManager->filters['thread_before_create'] = function($data) {
    $data['title'] = '[Modified] ' . $data['title'];
    $data['content'] = $data['content'] . ' [Modified]';
    return $data;
};

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Original Title',
    'content' => 'Original Content',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM threads WHERE title = ?");
$stmt->execute(['[Modified] Original Title']);
$thread = $stmt->fetch();
echo "THREAD_CREATED:" . ($thread ? 'yes' : 'no') . "\n";
if ($thread) {
    echo "THREAD_CONTENT:" . $thread['content'] . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Modified thread created', strpos($output, 'THREAD_CREATED:yes') !== false);
    $t->assert('Content also modified', strpos($output, 'THREAD_CONTENT:Original Content [Modified]') !== false);

    return $t;
}

function test_posts_new_plugin_block_prevents_creation(): Test
{
    $t = new Test('Isolated - posts-new.php Plugin Block Prevents Creation');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pluginManager = new class {
    public array $filters = [];
    public array $hooks = [];
    public function filter(string $name, $data, ...$args) { return $data; }
    public function runHook(string $name, ...$args): void {}
    public function checkHook(string $name, ...$args): bool {
        if (isset($this->hooks[$name])) {
            foreach ($this->hooks[$name] as $hook) { if ($hook(...$args)) return true; }
        }
        return false;
    }
};
$app = App::getInstance();
$app->pluginManager = $pluginManager;

$pluginManager->hooks['thread_create_block'] = [function($data) { return true; }];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Blocked Thread',
    'content' => 'Blocked Content',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('POST');
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM threads");
$stmt->execute();
$count = (int)$stmt->fetchColumn();
echo "THREAD_COUNT:" . $count . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Thread creation blocked') !== false);
    $t->assert('No thread created', strpos($output, 'THREAD_COUNT:0') !== false);

    return $t;
}

function test_posts_new_notify_mentioned_users(): Test
{
    $t = new Test('Isolated - posts-new.php Notify Mentioned Users');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (20, 'mentioneduser', ?, 'mentioned@test.com', 'user', 1)")
    ->execute([password_hash('password123', PASSWORD_DEFAULT)]);

function t(string $key, array $params = []): string { return $key; }

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => 'Test Thread',
    'content' => 'Hello @mentioneduser, check this out!',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-new.php';
$result = handle_new_thread('POST');
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 20 AND type = 'mention'");
$stmt->execute();
$notification = $stmt->fetch();
echo "NOTIFICATION_CREATED:" . ($notification ? 'yes' : 'no') . "\n";
if ($notification) {
    echo "NOTIFICATION_USER_ID:" . $notification['user_id'] . "\n";
    echo "NOTIFICATION_TYPE:" . $notification['type'] . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Notification created', strpos($output, 'NOTIFICATION_CREATED:yes') !== false);
    $t->assert('Notification for mentioned user', strpos($output, 'NOTIFICATION_USER_ID:20') !== false);
    $t->assert('Notification type mention', strpos($output, 'NOTIFICATION_TYPE:mention') !== false);

    return $t;
}

function test_posts_new_rate_limited(): Test
{
    $t = new Test('Isolated - posts-new.php Rate Limited');

    $testScript = makeIsolatedTestScript(<<<'PHP'
for ($i = 0; $i < 20; $i++) {
    $_POST = [
        'title' => "Thread $i",
        'content' => "Content $i",
        'category_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('POST');
    ob_get_clean();
}

$_POST = [
    'title' => 'Rate Limited Thread',
    'content' => 'Rate Limited Content',
    'category_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-new.php';
    $result = handle_new_thread('POST');
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\TooManyRequestsException $e) {
    echo "TOO_MANY:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('TooManyRequestsException thrown', strpos($output, 'TOO_MANY:You are posting too fast') !== false);

    return $t;
}

// ============================================================
// POSTS-EDIT.PHP INTEGRATION TESTS
// ============================================================

function test_posts_edit_reply_post_success(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Success');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 3,
    'user_role' => 'user',
    'username' => 'user2',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => (string)$threadId,
    'content' => 'This is a reply',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-edit.php';
$result = handle_reply_post();
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM posts WHERE content = ?");
$stmt->execute(['This is a reply']);
$post = $stmt->fetch();
echo "POST_CREATED:" . ($post ? 'yes' : 'no') . "\n";
if ($post) {
    echo "POST_THREAD_ID:" . $post['thread_id'] . "\n";
    echo "POST_USER_ID:" . $post['user_id'] . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Post created in database', strpos($output, 'POST_CREATED:yes') !== false);
    $t->assert('Post thread_id correct', strpos($output, 'POST_THREAD_ID:1') !== false);
    $t->assert('Post user_id correct', strpos($output, 'POST_USER_ID:3') !== false);

    return $t;
}

function test_posts_edit_reply_post_empty_content(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Empty Content');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 3,
    'user_role' => 'user',
    'username' => 'user2',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => (string)$threadId,
    'content' => '',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ValidationException $e) {
    echo "VALIDATION:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ValidationException thrown', strpos($output, 'VALIDATION:Content is required') !== false);

    return $t;
}

function test_posts_edit_reply_post_invalid_thread(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Invalid Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 3,
    'user_role' => 'user',
    'username' => 'user2',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => '999',
    'content' => 'Reply to invalid thread',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\NotFoundException $e) {
    echo "NOT_FOUND:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('NotFoundException thrown', strpos($output, 'NOT_FOUND:Thread not found') !== false);

    return $t;
}

function test_posts_edit_reply_post_locked_thread(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Locked Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Locked Thread', 'Thread content', 'locked')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 3,
    'user_role' => 'user',
    'username' => 'user2',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => (string)$threadId,
    'content' => 'Reply to locked thread',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Thread is locked') !== false);

    return $t;
}

function test_posts_edit_reply_post_moderator_can_reply_locked(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Moderator Can Reply Locked');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Locked Thread', 'Thread content', 'locked')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 10,
    'user_role' => 'moderator',
    'username' => 'moduser',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => (string)$threadId,
    'content' => 'Moderator reply to locked thread',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require_once $BASE_DIR . '/../src/actions/posts-edit.php';
$result = handle_reply_post();
$output = ob_get_clean();
echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}
$stmt = $pdo->prepare("SELECT * FROM posts WHERE content = ?");
$stmt->execute(['Moderator reply to locked thread']);
$post = $stmt->fetch();
echo "POST_CREATED:" . ($post ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Post created by moderator', strpos($output, 'POST_CREATED:yes') !== false);

    return $t;
}

function test_posts_edit_reply_post_csrf_invalid(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Invalid CSRF');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 3,
    'user_role' => 'user',
    'username' => 'user2',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => (string)$threadId,
    'content' => 'Valid content',
    'csrf_token' => 'invalid-token',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:CSRF token invalid') !== false);

    return $t;
}

function test_posts_edit_reply_post_not_logged_in(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Not Logged In');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'thread_id' => '1',
    'content' => 'Reply',
    'csrf_token' => generate_csrf_token(),
];
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\UnauthorizedException $e) {
    echo "UNAUTHORIZED\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('UnauthorizedException thrown', strpos($output, 'UNAUTHORIZED') !== false);

    return $t;
}

function test_posts_edit_reply_post_no_permission(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post No Permission');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO users (id, username, password, email, role, email_verified) VALUES (99, 'restricted', ?, 'restricted@test.com', 'restricted', 1)")
    ->execute([password_hash('password123', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO roles (name, permissions) VALUES ('restricted', '[]')")->execute();

$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 99,
    'user_role' => 'restricted',
    'username' => 'restricted',
    'user_status' => 'active',
    'session_version' => 1,
];
$_POST = [
    'thread_id' => (string)$threadId,
    'content' => 'Reply',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Not authorized to create replies') !== false);

    return $t;
}

function test_posts_edit_reply_post_rate_limited(): Test
{
    $t = new Test('Isolated - posts-edit.php handle_reply_post Rate Limited');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 3,
    'user_role' => 'user',
    'username' => 'user2',
    'user_status' => 'active',
    'session_version' => 1,
];

for ($i = 0; $i < 30; $i++) {
    $_POST = [
        'thread_id' => (string)$threadId,
        'content' => "Reply $i",
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    ob_get_clean();
}

$_POST = [
    'thread_id' => (string)$threadId,
    'content' => 'Rate limited reply',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-edit.php';
    $result = handle_reply_post();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\TooManyRequestsException $e) {
    echo "TOO_MANY:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('TooManyRequestsException thrown', strpos($output, 'TOO_MANY:You are posting too fast') !== false);

    return $t;
}

// ============================================================
// POSTS-THREAD.PHP INTEGRATION TESTS
// ============================================================

function test_posts_thread_view_success(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_thread_view Success');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'First reply', 'visible')")->execute();
$pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 3, 'Second reply', 'visible')")->execute();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['id' => (string)$threadId];

ob_start();
require_once $BASE_DIR . '/../src/actions/posts-thread.php';
$result = handle_thread_view([]);
$output = ob_get_clean();

echo "RESULT:" . ($result === true ? 'true' : gettype($result)) . "\n";
echo "OUTPUT_LEN:" . strlen($output) . "\n";
echo "HAS_THREAD:" . (strpos($output, 'Test Thread') !== false ? 'yes' : 'no') . "\n";
echo "HAS_REPLY:" . (strpos($output, 'First reply') !== false ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is true', strpos($output, 'RESULT:true') !== false);
    $t->assert('Output contains thread title', strpos($output, 'HAS_THREAD:yes') !== false);
    $t->assert('Output contains reply', strpos($output, 'HAS_REPLY:yes') !== false);

    return $t;
}

function test_posts_thread_view_not_found(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_thread_view Not Found');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['id' => '999'];

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_thread_view([]);
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\NotFoundException $e) {
    echo "NOT_FOUND:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('NotFoundException thrown', strpos($output, 'NOT_FOUND:Thread not found') !== false);

    return $t;
}

function test_posts_thread_view_hidden_status(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_thread_view Hidden Status');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['id' => (string)$threadId];

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_thread_view([]);
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Thread not available') !== false);

    return $t;
}

function test_posts_thread_watch_success(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Success');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
require_once $BASE_DIR . '/../src/actions/posts-thread.php';
$result = handle_watch();
$output = ob_get_clean();

echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}

$stmt = $pdo->prepare("SELECT * FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
$stmt->execute([$threadId, 2]);
$watcher = $stmt->fetch();
echo "WATCHER_ADDED:" . ($watcher ? 'yes' : 'no') . "\n";

echo "SESSION_WATCHED:" . (isset($_SESSION['watched_threads']) && in_array($threadId, $_SESSION['watched_threads']) ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Watcher added to database', strpos($output, 'WATCHER_ADDED:yes') !== false);
    $t->assert('Thread added to session watched', strpos($output, 'SESSION_WATCHED:yes') !== false);

    return $t;
}

function test_posts_thread_watch_already_watching(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Already Watching');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")->execute([$threadId, 2]);

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
require_once $BASE_DIR . '/../src/actions/posts-thread.php';
$result = handle_watch();
$output = ob_get_clean();

echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
$stmt->execute([$threadId, 2]);
$count = (int)$stmt->fetchColumn();
echo "WATCHER_COUNT:" . $count . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Only one watcher entry', strpos($output, 'WATCHER_COUNT:1') !== false);

    return $t;
}

function test_posts_thread_watch_not_logged_in(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Not Logged In');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'thread_id' => '1',
    'csrf_token' => generate_csrf_token(),
];

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_watch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\UnauthorizedException $e) {
    echo "UNAUTHORIZED\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('UnauthorizedException thrown', strpos($output, 'UNAUTHORIZED') !== false);

    return $t;
}

function test_posts_thread_watch_invalid_thread(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Invalid Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => '999',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_watch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\NotFoundException $e) {
    echo "NOT_FOUND:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('NotFoundException thrown', strpos($output, 'NOT_FOUND:Thread not found') !== false);

    return $t;
}

function test_posts_thread_watch_hidden_thread(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Hidden Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_watch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Thread not available') !== false);

    return $t;
}

function test_posts_thread_watch_csrf_invalid(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Invalid CSRF');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => 'invalid-token',
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_watch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:CSRF token invalid') !== false);

    return $t;
}

function test_posts_thread_watch_rate_limited(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_watch Rate Limited');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

for ($i = 0; $i < 30; $i++) {
    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_watch();
    ob_get_clean();
}

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_watch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\TooManyRequestsException $e) {
    echo "TOO_MANY:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('TooManyRequestsException thrown', strpos($output, 'TOO_MANY:You are watching too many threads') !== false);

    return $t;
}

function test_posts_thread_unwatch_success(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Success');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO thread_watchers (thread_id, user_id) VALUES (?, ?)")->execute([$threadId, 2]);

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
    'watched_threads' => [$threadId],
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
require_once $BASE_DIR . '/../src/actions/posts-thread.php';
$result = handle_unwatch();
$output = ob_get_clean();

echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}

$stmt = $pdo->prepare("SELECT * FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
$stmt->execute([$threadId, 2]);
$watcher = $stmt->fetch();
echo "WATCHER_REMOVED:" . ($watcher ? 'no' : 'yes') . "\n";

echo "SESSION_UNWATCHED:" . (isset($_SESSION['watched_threads']) && !in_array($threadId, $_SESSION['watched_threads']) ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('Watcher removed from database', strpos($output, 'WATCHER_REMOVED:yes') !== false);
    $t->assert('Thread removed from session watched', strpos($output, 'SESSION_UNWATCHED:yes') !== false);

    return $t;
}

function test_posts_thread_unwatch_not_watching(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Not Watching');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->exec("CREATE TABLE IF NOT EXISTS thread_watchers (thread_id INTEGER, user_id INTEGER, PRIMARY KEY (thread_id, user_id))");

$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
require_once $BASE_DIR . '/../src/actions/posts-thread.php';
$result = handle_unwatch();
$output = ob_get_clean();

echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
}

$stmt = $pdo->prepare("SELECT * FROM thread_watchers WHERE thread_id = ? AND user_id = ?");
$stmt->execute([$threadId, 2]);
$watcher = $stmt->fetch();
echo "WATCHER_EXISTS:" . ($watcher ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Redirect status 302', strpos($output, 'STATUS:302') !== false);
    $t->assert('No watcher to remove', strpos($output, 'WATCHER_EXISTS:no') !== false);

    return $t;
}

function test_posts_thread_unwatch_not_logged_in(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Not Logged In');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'thread_id' => '1',
    'csrf_token' => generate_csrf_token(),
];

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_unwatch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\UnauthorizedException $e) {
    echo "UNAUTHORIZED\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('UnauthorizedException thrown', strpos($output, 'UNAUTHORIZED') !== false);

    return $t;
}

function test_posts_thread_unwatch_invalid_thread(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Invalid Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => '999',
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_unwatch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\NotFoundException $e) {
    echo "NOT_FOUND:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('NotFoundException thrown', strpos($output, 'NOT_FOUND:Thread not found') !== false);

    return $t;
}

function test_posts_thread_unwatch_hidden_thread(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Hidden Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_unwatch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Thread not available') !== false);

    return $t;
}

function test_posts_thread_unwatch_csrf_invalid(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Invalid CSRF');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => 'invalid-token',
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_unwatch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:CSRF token invalid') !== false);

    return $t;
}

function test_posts_thread_unwatch_rate_limited(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_unwatch Rate Limited');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

for ($i = 0; $i < 30; $i++) {
    $_POST = [
        'thread_id' => (string)$threadId,
        'csrf_token' => generate_csrf_token(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_unwatch();
    ob_get_clean();
}

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_unwatch();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\TooManyRequestsException $e) {
    echo "TOO_MANY:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('TooManyRequestsException thrown', strpos($output, 'TOO_MANY:You are unwatching too many threads') !== false);

    return $t;
}

function test_posts_thread_upload_image_success(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Success');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->exec("CREATE TABLE IF NOT EXISTS uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, thread_id INTEGER, post_id INTEGER, filename TEXT, original_name TEXT, size INTEGER, mime_type TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_FILES = [
    'image' => [
        'tmp_name' => '/tmp/test_image.jpg',
        'name' => 'test_image.jpg',
        'size' => 1024,
        'type' => 'image/jpeg',
        'error' => UPLOAD_ERR_OK,
    ],
];

// Create a dummy image file
$tmpFile = '/tmp/test_image.jpg';
file_put_contents($tmpFile, 'fake image data');

$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
require_once $BASE_DIR . '/../src/actions/posts-thread.php';
$result = handle_upload_image();
$output = ob_get_clean();

echo "RESULT_TYPE:" . gettype($result) . "\n";
if ($result instanceof \Bulletin\Response) {
    echo "STATUS:" . $result->getStatus() . "\n";
    echo "BODY:" . $result->getBody() . "\n";
}

$stmt = $pdo->prepare("SELECT * FROM uploads WHERE thread_id = ?");
$stmt->execute([$threadId]);
$upload = $stmt->fetch();
echo "UPLOAD_CREATED:" . ($upload ? 'yes' : 'no') . "\n";
if ($upload) {
    echo "UPLOAD_FILENAME:" . $upload['filename'] . "\n";
}

@unlink('/tmp/test_image.jpg');
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Result is Response object', strpos($output, 'RESULT_TYPE:object') !== false);
    $t->assert('Status 200', strpos($output, 'STATUS:200') !== false);
    $t->assert('Upload record created', strpos($output, 'UPLOAD_CREATED:yes') !== false);

    return $t;
}

function test_posts_thread_upload_image_not_logged_in(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Not Logged In');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'thread_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
$_FILES = ['image' => ['tmp_name' => '']];

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\UnauthorizedException $e) {
    echo "UNAUTHORIZED\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('UnauthorizedException thrown', strpos($output, 'UNAUTHORIZED') !== false);

    return $t;
}

function test_posts_thread_upload_image_no_file(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image No File');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
$_FILES = ['image' => ['tmp_name' => '']];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ValidationException $e) {
    echo "VALIDATION:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ValidationException thrown', strpos($output, 'VALIDATION:No file uploaded') !== false);

    return $t;
}

function test_posts_thread_upload_image_invalid_mime(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Invalid MIME');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$mime = 'application/pdf';
echo "PDF_ALLOWED:" . (isset($allowed[$mime]) ? 'yes' : 'no') . "\n";

$mime = 'image/jpeg';
echo "JPEG_ALLOWED:" . (isset($allowed[$mime]) ? 'yes' : 'no') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('PDF not allowed', strpos($output, 'PDF_ALLOWED:no') !== false);
    $t->assert('JPEG allowed', strpos($output, 'JPEG_ALLOWED:yes') !== false);

    return $t;
}

function test_posts_thread_upload_image_invalid_thread(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Invalid Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => '999',
    'csrf_token' => generate_csrf_token(),
];
$_FILES = ['image' => ['tmp_name' => '/tmp/test.jpg']];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\NotFoundException $e) {
    echo "NOT_FOUND:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('NotFoundException thrown', strpos($output, 'NOT_FOUND:Thread not found') !== false);

    return $t;
}

function test_posts_thread_upload_image_hidden_thread(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Hidden Thread');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Hidden Thread', 'Thread content', 'hidden')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => generate_csrf_token(),
];
$_FILES = ['image' => ['tmp_name' => '/tmp/test.jpg']];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:Thread not available') !== false);

    return $t;
}

function test_posts_thread_upload_image_csrf_invalid(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Invalid CSRF');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

$_POST = [
    'thread_id' => (string)$threadId,
    'csrf_token' => 'invalid-token',
];
$_FILES = ['image' => ['tmp_name' => '/tmp/test.jpg']];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\ForbiddenException $e) {
    echo "FORBIDDEN:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('ForbiddenException thrown', strpos($output, 'FORBIDDEN:CSRF token invalid') !== false);

    return $t;
}

function test_posts_thread_upload_image_rate_limited(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Rate Limited');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

for ($i = 0; $i < 20; $i++) {
    $_POST = [
        'thread_id' => '1',
        'csrf_token' => generate_csrf_token(),
    ];
    $_FILES = ['image' => ['tmp_name' => '/tmp/test.jpg']];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    ob_get_clean();
}

$_POST = [
    'thread_id' => '1',
    'csrf_token' => generate_csrf_token(),
];
$_FILES = ['image' => ['tmp_name' => '/tmp/test.jpg']];
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start();
    require_once $BASE_DIR . '/../src/actions/posts-thread.php';
    $result = handle_upload_image();
    $output = ob_get_clean();
    echo "NO_EXCEPTION\n";
} catch (\Bulletin\TooManyRequestsException $e) {
    echo "TOO_MANY:" . $e->getMessage() . "\n";
}
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('TooManyRequestsException thrown', strpos($output, 'TOO_MANY:You are uploading too fast') !== false);

    return $t;
}

function test_posts_thread_upload_image_post_context(): Test
{
    $t = new Test('Isolated - posts-thread.php handle_upload_image Post Context');

    $testScript = makeIsolatedTestScript(<<<'PHP'
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Test Thread', 'Thread content', 'visible')")->execute();
$threadId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO posts (thread_id, user_id, content, status) VALUES (?, 2, 'Post content', 'visible')")->execute();
$postId = (int)$pdo->lastInsertId();

$_SESSION = [
    'user_id' => 2,
    'user_role' => 'user',
    'username' => 'user1',
    'user_status' => 'active',
    'session_version' => 1,
];

// Test post belongs to thread
$postCheck = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND thread_id = ?");
$postCheck->execute([$postId, $threadId]);
$post = $postCheck->fetch();
echo "POST_BELONGS:" . ($post ? 'yes' : 'no') . "\n";

// Test post from different thread
$pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, status) VALUES (1, 2, 'Other Thread', 'Thread content', 'visible')")->execute();
$otherThreadId = (int)$pdo->lastInsertId();

$postCheck->execute([$postId, $otherThreadId]);
$post = $postCheck->fetch();
echo "POST_NOT_BELONGS:" . ($post ? 'no' : 'yes') . "\n";
PHP
);

    $result = runIsolatedTest($testScript);
    $output = $result['output'];

    $t->assert('Process exited successfully', $result['returnVar'] === 0);
    $t->assert('Post belongs to thread', strpos($output, 'POST_BELONGS:yes') !== false);
    $t->assert('Post does not belong to other thread', strpos($output, 'POST_NOT_BELONGS:yes') !== false);

    return $t;
}

register_tests(
    // posts-new.php tests
    'test_posts_new_get_renders_view',
    'test_posts_new_post_success',
    'test_posts_new_post_empty_title',
    'test_posts_new_post_empty_content',
    'test_posts_new_post_csrf_invalid',
    'test_posts_new_not_logged_in',
    'test_posts_new_no_permission',
    'test_posts_new_category_moderator_only',
    'test_posts_new_moderator_can_access_moderator_category',
    'test_posts_new_admin_can_access_admin_category',
    'test_posts_new_plugin_filter_modifies_data',
    'test_posts_new_plugin_block_prevents_creation',
    'test_posts_new_notify_mentioned_users',
    'test_posts_new_rate_limited',

    // posts-edit.php tests
    'test_posts_edit_reply_post_success',
    'test_posts_edit_reply_post_empty_content',
    'test_posts_edit_reply_post_invalid_thread',
    'test_posts_edit_reply_post_locked_thread',
    'test_posts_edit_reply_post_moderator_can_reply_locked',
    'test_posts_edit_reply_post_csrf_invalid',
    'test_posts_edit_reply_post_not_logged_in',
    'test_posts_edit_reply_post_no_permission',
    'test_posts_edit_reply_post_rate_limited',

    // posts-thread.php tests
    'test_posts_thread_view_success',
    'test_posts_thread_view_not_found',
    'test_posts_thread_view_hidden_status',
    'test_posts_thread_watch_success',
    'test_posts_thread_watch_already_watching',
    'test_posts_thread_watch_not_logged_in',
    'test_posts_thread_watch_invalid_thread',
    'test_posts_thread_watch_hidden_thread',
    'test_posts_thread_watch_csrf_invalid',
    'test_posts_thread_watch_rate_limited',
    'test_posts_thread_unwatch_success',
    'test_posts_thread_unwatch_not_watching',
    'test_posts_thread_unwatch_not_logged_in',
    'test_posts_thread_unwatch_invalid_thread',
    'test_posts_thread_unwatch_hidden_thread',
    'test_posts_thread_unwatch_csrf_invalid',
    'test_posts_thread_unwatch_rate_limited',
    'test_posts_thread_upload_image_success',
    'test_posts_thread_upload_image_not_logged_in',
    'test_posts_thread_upload_image_no_file',
    'test_posts_thread_upload_image_invalid_mime',
    'test_posts_thread_upload_image_invalid_thread',
    'test_posts_thread_upload_image_hidden_thread',
    'test_posts_thread_upload_image_csrf_invalid',
    'test_posts_thread_upload_image_rate_limited',
    'test_posts_thread_upload_image_post_context'
);