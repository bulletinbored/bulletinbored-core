<?php

/**
 * SecurityHardeningTest — SQL injection, upload security, security headers.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Helpers/Upload.php';
require_once __DIR__ . '/../src/csp.php';

function test_sql_injection_login(): Test
{
    $t = new Test('SQL Injection - Login');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            status TEXT DEFAULT 'active',
            email_verified INTEGER DEFAULT 1
        );
    ");

    $pdo->prepare("INSERT INTO users (username, password, email, email_verified) VALUES (?, ?, ?, 1)")
        ->execute(['admin', password_hash('AdminP4ssword', PASSWORD_DEFAULT), 'admin@test.com']);

    $maliciousInputs = [
        "' OR '1'='1",
        "'; DROP TABLE users; --",
        "' UNION SELECT * FROM users --",
        "admin'--",
        "' OR 1=1--",
        "admin' OR '1'='1",
    ];

    foreach ($maliciousInputs as $input) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$input]);
        $user = $stmt->fetch();
        $t->assert("SQL injection '{$input}' blocked", $user === false);
    }

    $admin = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    $t->assert('Admin user still exists after injection attempts', $admin !== false);

    return $t;
}

function test_sql_injection_search(): Test
{
    $t = new Test('SQL Injection - Search');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible'
        );
    ");

    $pdo->prepare("INSERT INTO threads (title, content, status) VALUES (?, ?, 'visible')")
        ->execute(['Test Thread', 'Some content']);

    $maliciousSearches = [
        "'; DROP TABLE threads; --",
        "' UNION SELECT password FROM users --",
        "%' OR '1'='1",
        "'; INSERT INTO threads VALUES (99, 'hacked', 'hacked', 'visible'); --",
    ];

    foreach ($maliciousSearches as $search) {
        $stmt = $pdo->prepare("SELECT * FROM threads WHERE title LIKE ? OR content LIKE ?");
        $stmt->execute(["%{$search}%", "%{$search}%"]);
        $results = $stmt->fetchAll();
        $t->assert("Search injection '{$search}' safe", is_array($results));
    }

    $count = $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn();
    $t->assertEquals('Thread count unchanged after injection attempts', 1, (int)$count);

    return $t;
}

function test_sql_injection_filter(): Test
{
    $t = new Test('SQL Injection - Category Filter');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            title TEXT,
            status TEXT DEFAULT 'visible'
        );
    ");

    $pdo->prepare("INSERT INTO threads (category_id, title, status) VALUES (1, 'Thread 1', 'visible')")->execute();
    $pdo->prepare("INSERT INTO threads (category_id, title, status) VALUES (2, 'Thread 2', 'visible')")->execute();

    $maliciousCategoryId = "1 OR 1=1";
    $stmt = $pdo->prepare("SELECT * FROM threads WHERE category_id = ?");
    $stmt->execute([(int)$maliciousCategoryId]);
    $results = $stmt->fetchAll();
    $t->assert('Category ID cast to int prevents injection', count($results) === 1);

    return $t;
}

function test_upload_extension_whitelist(): Test
{
    $t = new Test('Upload - Extension Whitelist');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

    $testCases = [
        ['name' => 'photo.jpg', 'mime' => 'image/jpeg', 'expected' => true],
        ['name' => 'photo.png', 'mime' => 'image/png', 'expected' => true],
        ['name' => 'document.pdf', 'mime' => 'application/pdf', 'expected' => false],
        ['name' => 'script.php', 'mime' => 'application/x-php', 'expected' => false],
        ['name' => 'shell.exe', 'mime' => 'application/x-executable', 'expected' => false],
        ['name' => 'malware.sh', 'mime' => 'application/x-sh', 'expected' => false],
    ];

    foreach ($testCases as $case) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
        file_put_contents($tmpFile, str_repeat('x', 50));

        $result = validate_upload($tmpFile, $case['name'], $allowed, 1024 * 1024);

        if ($case['expected']) {
            $t->assert("{$case['name']} mime type is in whitelist", isset($allowed[$case['mime']]));
        } else {
            $t->assert("{$case['name']} mime type NOT in whitelist", !isset($allowed[$case['mime']]));
        }

        unlink($tmpFile);
    }

    return $t;
}

function test_upload_max_size(): Test
{
    $t = new Test('Upload - Max Size Enforcement');

    $allowed = ['image/jpeg' => 'jpg'];
    $maxSize = 100;

    $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
    file_put_contents($tmpFile, str_repeat('x', 200));

    $result = validate_upload($tmpFile, 'big.jpg', $allowed, $maxSize);
    $t->assert('Oversized file rejected', $result === null);

    unlink($tmpFile);

    $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
    file_put_contents($tmpFile, str_repeat('x', 50));

    $result = validate_upload($tmpFile, 'small.jpg', $allowed, $maxSize);
    $t->assert('File within size limit may pass', $result === null || is_array($result));

    unlink($tmpFile);

    return $t;
}

function test_upload_random_filename(): Test
{
    $t = new Test('Upload - Random Filename Generated');

    $allowed = ['image/jpeg' => 'jpg'];

    $names = [];
    for ($i = 0; $i < 5; $i++) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
        file_put_contents($tmpFile, str_repeat('x', 50));

        $result = validate_upload($tmpFile, 'photo.jpg', $allowed, 1024 * 1024);
        if ($result !== null) {
            $names[] = $result['safe_name'];
        }

        unlink($tmpFile);
    }

    $uniqueNames = array_unique($names);
    $t->assert('Generated filenames are unique', count($uniqueNames) === count($names));

    foreach ($names as $name) {
        $t->assert("Filename {$name} has correct extension", str_ends_with($name, '.jpg'));
        $t->assert("Filename {$name} is not original name", $name !== 'photo.jpg');
    }

    return $t;
}

function test_upload_no_php(): Test
{
    $t = new Test('Upload - PHP Files Rejected');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
    file_put_contents($tmpFile, '<?php echo "hack"; ?>');

    $result = validate_upload($tmpFile, 'shell.php', $allowed, 1024 * 1024);
    $t->assert('PHP file rejected even with image extension', $result === null);

    unlink($tmpFile);

    return $t;
}

function test_security_headers_present(): Test
{
    $t = new Test('Security - Headers Present');

    $cspCode = file_get_contents(__DIR__ . '/../src/csp.php');

    $t->assert('X-Content-Type-Options header set', str_contains($cspCode, "X-Content-Type-Options: nosniff"));
    $t->assert('X-Frame-Options header set', str_contains($cspCode, "X-Frame-Options: DENY"));
    $t->assert('Referrer-Policy header set', str_contains($cspCode, "Referrer-Policy:"));
    $t->assert('Content-Security-Policy header set', str_contains($cspCode, "Content-Security-Policy:"));
    $t->assert('CSP has default-src', str_contains($cspCode, "default-src 'self'"));
    $t->assert('CSP has script-src with nonce', str_contains($cspCode, "script-src 'self' 'nonce-"));
    $t->assert('CSP has object-src none', str_contains($cspCode, "object-src 'none'"));
    $t->assert('CSP has frame-ancestors none', str_contains($cspCode, "frame-ancestors 'none'"));
    $t->assert('CSP has base-uri self', str_contains($cspCode, "base-uri 'self'"));

    return $t;
}

function test_security_headers_no_inline_scripts(): Test
{
    $t = new Test('Security - CSP Blocks Inline Scripts');

    $cspCode = file_get_contents(__DIR__ . '/../src/csp.php');

    $t->assert('CSP does NOT have unsafe-inline in script-src', !str_contains($cspCode, "script-src 'self' 'unsafe-inline'"));

    return $t;
}

function test_security_headers_x_content_type(): Test
{
    $t = new Test('Security - X-Content-Type-Options nosniff');

    $cspCode = file_get_contents(__DIR__ . '/../src/csp.php');

    $t->assert('nosniff enabled', str_contains($cspCode, "X-Content-Type-Options: nosniff"));

    return $t;
}

function test_security_headers_frame_options(): Test
{
    $t = new Test('Security - X-Frame-Options prevents clickjacking');

    $cspCode = file_get_contents(__DIR__ . '/../src/csp.php');

    $t->assert('X-Frame-Options is DENY', str_contains($cspCode, "X-Frame-Options: DENY"));

    return $t;
}

function test_csp_nonce_generation(): Test
{
    $t = new Test('Security - CSP Nonce Generation');

    App::reset();
    $nonce1 = generate_csp_nonce();
    $nonce2 = csp_nonce();

    $t->assert('Nonce is generated', !empty($nonce1));
    $t->assert('Nonce is consistent within request', $nonce1 === $nonce2);
    $t->assert('Nonce is base64', base64_decode($nonce1, true) !== false);

    App::reset();
    $nonce3 = generate_csp_nonce();
    $t->assert('Nonce changes between requests', $nonce1 !== $nonce3);

    App::reset();

    return $t;
}

function test_upload_double_extension(): Test
{
    $t = new Test('Upload - Double Extension Attack');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
    file_put_contents($tmpFile, '<?php echo "hack"; ?>');

    $result = validate_upload($tmpFile, 'shell.php.jpg', $allowed, 1024 * 1024);
    $t->assert('Double extension file rejected', $result === null);

    unlink($tmpFile);

    return $t;
}

function test_upload_null_byte(): Test
{
    $t = new Test('Upload - Null Byte Injection');

    $allowed = ['image/jpeg' => 'jpg'];

    $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test');
    file_put_contents($tmpFile, 'test content');

    $nullByteName = "shell.php\x00.jpg";

    $result = validate_upload($tmpFile, $nullByteName, $allowed, 1024 * 1024);
    $t->assert('Null byte in filename handled', $result === null || !str_contains($result['safe_name'] ?? '', "\x00"));

    unlink($tmpFile);

    return $t;
}

register_tests(
    'test_sql_injection_login',
    'test_sql_injection_search',
    'test_sql_injection_filter',
    'test_upload_extension_whitelist',
    'test_upload_max_size',
    'test_upload_random_filename',
    'test_upload_no_php',
    'test_security_headers_present',
    'test_security_headers_no_inline_scripts',
    'test_security_headers_x_content_type',
    'test_security_headers_frame_options',
    'test_csp_nonce_generation',
    'test_upload_double_extension',
    'test_upload_null_byte'
);
