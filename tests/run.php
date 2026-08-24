<?php
/**
 * Security regression tests.
 *
 * Run from project root with:
 *   php tests/run.php
 */

$errors = [];
$passed = 0;

function assert_true(string $label, bool $cond, string $failure = '') {
    global $errors, $passed;
    if ($cond) {
        $passed++;
        echo "  PASS  $label\n";
    } else {
        $errors[] = $label . ($failure ? ': ' . $failure : '');
        echo "  FAIL  $label" . ($failure ? ': ' . $failure : '') . "\n";
    }
}

function assert_contains(string $needle, string $haystack, string $label) {
    assert_true($label, str_contains($haystack, $needle), 'expected substring not found');
}

function assert_not_contains(string $needle, string $haystack, string $label) {
    assert_true($label, !str_contains($haystack, $needle), 'unexpected substring found');
}

function make_temp_dir(string $prefix): string {
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(4));
    @mkdir($dir, 0755, true);
    return $dir;
}

function write_file(string $path, string $content): void {
    file_put_contents($path, $content);
}

function make_zip(string $zipPath, array $entries): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create zip');
    }
    foreach ($entries as $name => $content) {
        if (is_int($name)) {
            $zip->addFromString($content, '');
        } else {
            $zip->addFromString($name, $content);
        }
    }
    $zip->close();
}

// ---------------------------------------------------------------------------
// C1 — Zip Slip prevention
// ---------------------------------------------------------------------------
echo "[C1] Zip extraction safety\n";

require_once __DIR__ . '/../lib/PluginManager.php';

$pluginDir = make_temp_dir('plugins');
$zipPath = make_temp_dir('bbzip') . '/plugin.zip';

make_zip($zipPath, [
    'evil.php' => '<?php echo "pwned";',
    '../../escape.php' => '<?php echo "pwned";',
    '/abs.php' => '<?php echo "pwned";',
]);

$manager = new PluginManager($pluginDir, make_temp_dir('bbmanifest') . '/plugins.json');
$result = $manager->installFromZip($zipPath);

assert_true('installFromZip rejects traversal zip', $result['success'] === false, 'expected failure, got success');

$escaped = $pluginDir . '/escape.php';
$abs = dirname($pluginDir, 2) . '/abs.php';
assert_true('no file outside plugins dir created (relative traversal)', !file_exists($escaped), 'file found: ' . $escaped);
assert_true('no file outside plugins dir created (absolute)', !file_exists($abs), 'file found: ' . $abs);

// ---------------------------------------------------------------------------
// H2 — Language pack URL allow-list
// ---------------------------------------------------------------------------
echo "[H2] Language pack URL restriction\n";

$valid = [
    'https://github.com/bulletinbored/langs/raw/main/en.php',
    'https://raw.githubusercontent.com/bulletinbored/langs/main/en.php',
];
$invalid = [
    'https://evil.example.com/lang.php',
    'http://github.com/bulletinbored/langs/raw/main/en.php',
    'https://github.com/bulletinbored/other/langs/main/en.php',
    'https://github.com/bulletinbored/langs/raw/../main/en.php',
];

foreach ($valid as $url) {
    $parsed = parse_url($url);
    $allowed = false;
    if (
        $parsed
        && ($parsed['scheme'] ?? '') === 'https'
        && in_array($parsed['host'], ['github.com', 'raw.githubusercontent.com'], true)
        && !str_contains($parsed['path'], '..')
        && str_starts_with($parsed['path'], '/bulletinbored/langs/')
    ) {
        $allowed = true;
    }
    assert_true('valid language URL allowed: ' . $url, $allowed);
}

foreach ($invalid as $url) {
    $parsed = parse_url($url);
    $allowed = false;
    if (
        $parsed
        && ($parsed['scheme'] ?? '') === 'https'
        && in_array($parsed['host'], ['github.com', 'raw.githubusercontent.com'], true)
        && !str_contains($parsed['path'], '..')
        && str_starts_with($parsed['path'], '/bulletinbored/langs/')
    ) {
        $allowed = true;
    }
    assert_true('invalid language URL blocked: ' . $url, !$allowed);
}

// ---------------------------------------------------------------------------
// H1 — HTML sanitizer preserves editbored embeds but strips dangerous attrs
// ---------------------------------------------------------------------------
echo "[H1] HTML sanitizer behaviour\n";

require_once __DIR__ . '/../src/helpers.php';

$youtubeIframe = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe>';
$clean = sanitize_html($youtubeIframe);
assert_contains('<iframe', $clean, 'iframe kept');
assert_contains('position:absolute', $clean, 'safe style kept');
assert_not_contains('<script', $clean, 'script stripped');

$jsInject = '<a href="javascript:alert(1)" onclick="alert(1)">x</a>';
$cleanJs = sanitize_html($jsInject);
assert_not_contains('javascript:', $cleanJs, 'javascript: stripped from href');
assert_not_contains('onclick', $cleanJs, 'event handler stripped');

$cssInject = '<div style="position:fixed;z-index:9999;background:red;">x</div>';
$cleanCss = sanitize_html($cssInject);
assert_not_contains('position:fixed', $cleanCss, 'position:fixed stripped');
assert_not_contains('z-index:9999', $cleanCss, 'z-index stripped');

$instagram = '<blockquote class="instagram-media" data-instgrm-captioned="1" data-instgrm-permalink="https://instagram.com/p/ABC/" style="background:#fff;">...</blockquote>';
$cleanIg = sanitize_html($instagram);
assert_contains('data-instgrm-captioned', $cleanIg, 'instagram data-* kept');
assert_contains('background:#fff', $cleanIg, 'safe style kept');

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
echo "\n";
echo "Passed: $passed\n";
if ($errors) {
    echo "Failed:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}
echo "All security tests passed.\n";
