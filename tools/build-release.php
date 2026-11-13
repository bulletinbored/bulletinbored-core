<?php

/**
 * build-release.php — assemble the official distribution ZIP.
 *
 * The distributed archive is a product of its own: it must contain everything
 * a fresh installation needs and nothing that belongs to development or to a
 * specific deployment (tests, CI config, config.json, database, sessions,
 * logs, tools). A whitelist is used instead of a blacklist so a new development
 * file cannot leak into a release by accident.
 *
 * Usage:
 *   php tools/build-release.php                      # dist/bulletinbored-core-<VERSION>.zip
 *   php tools/build-release.php --output=/tmp/pkg.zip
 *   php tools/build-release.php --allow-dirty        # allow building from a dirty tree
 *
 * The script writes <zip>.sha256 next to the archive and exits non-zero when the
 * package is missing a required file or contains a forbidden one.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Files shipped at the archive root. */
const RELEASE_ROOT_FILES = [
    '.htaccess',
    'CLA.md',
    'LICENSE',
    'README.md',
    'VERSION',
    'bb.php',
    'config-sample.json',
    'favicon.svg',
    'index.php',
    'install.php',
    'install2.php',
    'install3.php',
    'nginx.conf',
    'router.php',
    'web.config',
];

/** Directories shipped in full. */
const RELEASE_DIRECTORIES = [
    'api',
    'assets',
    'lib',
    'migrations',
    'src',
    'themes/freshbored',
    'views',
];

/** Individual files inside otherwise runtime-only directories. */
const RELEASE_EXTRA_FILES = [
    'data/.htaccess',
    'data/catalog.json',
    'data/lang-meta.json',
    'data/telemetry.json',
    'data/themes.json',
    'lang/en.json',
    'uploads/.htaccess',
];

/** Paths that must never appear in a package. */
const RELEASE_FORBIDDEN_PATTERNS = [
    '#^tests/#',
    '#^\.github/#',
    '#^tools/#',
    '#^docs/#',
    '#^\.git/#',
    '#^config\.(json|php)$#',
    '#^\.user\.ini$#',
    '#^editor\.png$#',
    '#^TODO\.md$#',
    '#^/#',
    '#^data/database\.sqlite$#',
    '#^data/sessions/#',
    '#^data/logs/#',
    '#^data/ratelimit/#',
    '#^data/backups/#',
    '#^data/\.migration_lock$#',
    '#^data/update-cache\.json$#',
    '#^uploads/(?!\.htaccess)#',
    '#\.(zip|sha256)$#',
];

function build_release_fail(string $message): void
{
    fwrite(STDERR, "build-release: {$message}\n");
    exit(1);
}

function build_release_add_dir(ZipArchive $zip, string $root, string $relative): int
{
    $absolute = $root . '/' . $relative;
    if (!is_dir($absolute)) {
        return 0;
    }

    $added = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        if ($item->isLink() || !$item->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $item->getPathname());
        $local = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
        if (build_release_is_forbidden($local)) {
            continue;
        }
        if (!$zip->addFile($path, $local)) {
            build_release_fail('cannot add ' . $local . ' to the archive');
        }
        $added++;
    }
    return $added;
}

function build_release_is_forbidden(string $relative): bool
{
    foreach (RELEASE_FORBIDDEN_PATTERNS as $pattern) {
        if (preg_match($pattern, $relative)) {
            return true;
        }
    }
    return false;
}

$options = getopt('', ['output::', 'allow-dirty']);
$version = trim((string)@file_get_contents($root . '/VERSION'));
if ($version === '' || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
    build_release_fail('VERSION is missing or not a semantic version (1.0.0, 1.0.0-rc.1, ...)');
}

if (!class_exists('ZipArchive')) {
    build_release_fail('the zip extension (ZipArchive) is required');
}

// Refuse to package uncommitted work unless explicitly allowed: a release must
// be reproducible from a commit, and "it works on my machine with these local
// edits" is exactly what should not reach users.
if (!isset($options['allow-dirty']) && is_dir($root . '/.git')) {
    $status = @shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>/dev/null');
    $status = is_string($status) ? trim($status) : '';
    if ($status !== '') {
        $lines = preg_split('/\R/', $status);
        build_release_fail(
            'the working tree has uncommitted changes (' . count($lines) . ' path(s)).'
            . ' Commit or stash them, or pass --allow-dirty to build anyway.'
        );
    }
}

$output = $options['output'] ?? ($root . '/dist/bulletinbored-core-' . $version . '.zip');
if (!is_string($output) || $output === '') {
    build_release_fail('invalid --output value');
}
$outputDir = dirname($output);
if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    build_release_fail('cannot create output directory: ' . $outputDir);
}
@unlink($output);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    build_release_fail('cannot create archive: ' . $output);
}

$count = 0;
foreach (RELEASE_ROOT_FILES as $file) {
    $absolute = $root . '/' . $file;
    if (!is_file($absolute)) {
        build_release_fail('required file missing: ' . $file);
    }
    if (!$zip->addFile($absolute, $file)) {
        build_release_fail('cannot add ' . $file . ' to the archive');
    }
    $count++;
}
foreach (RELEASE_DIRECTORIES as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        build_release_fail('required directory missing: ' . $dir);
    }
    $added = build_release_add_dir($zip, $root, $dir);
    if ($added === 0) {
        build_release_fail('directory is empty, nothing to package: ' . $dir);
    }
    $count += $added;
}
foreach (RELEASE_EXTRA_FILES as $file) {
    $absolute = $root . '/' . $file;
    if (is_file($absolute)) {
        if (!$zip->addFile($absolute, $file)) {
            build_release_fail('cannot add ' . $file . ' to the archive');
        }
        $count++;
    }
}

// Optional release notes generated by the release process.
if (is_file($root . '/RELEASE-NOTES.md')) {
    if (!$zip->addFile($root . '/RELEASE-NOTES.md', 'RELEASE-NOTES.md')) {
        build_release_fail('cannot add RELEASE-NOTES.md to the archive');
    }
    $count++;
}

if (!$zip->close()) {
    build_release_fail('the archive could not be written (disk full, permissions?)');
}
// ZipArchive::close() can report success on a partially written archive: make
// sure the file exists and is readable afterwards.
if (!is_file($output) || filesize($output) === 0) {
    build_release_fail('the archive is empty or missing after close()');
}

// ---------------------------------------------------------------------------
// Self-check: reopen the archive and verify what actually landed in it.
// ---------------------------------------------------------------------------
$verify = new ZipArchive();
if ($verify->open($output) !== true) {
    build_release_fail('cannot reopen the archive for verification');
}

$problems = [];
$requiredInPackage = [
    '.htaccess', 'VERSION', 'index.php', 'bb.php', 'config-sample.json',
    'api/install.php', 'lib/AdminAuth.php', 'lib/UpdateManager.php', 'lib/Migrator.php',
    'src/bootstrap.php', 'src/Helpers/Mail.php', 'lang/en.json', 'data/.htaccess',
    'uploads/.htaccess', 'themes/freshbored/manifest.json', 'migrations',
];
$present = [];
for ($i = 0; $i < $verify->numFiles; $i++) {
    $name = (string)$verify->getNameIndex($i);
    $present[$name] = true;
    if (build_release_is_forbidden($name)) {
        $problems[] = 'forbidden path in package: ' . $name;
    }
}
foreach ($requiredInPackage as $needed) {
    $found = isset($present[$needed]);
    if (!$found) {
        foreach (array_keys($present) as $name) {
            if (str_starts_with($name, $needed . '/')) {
                $found = true;
                break;
            }
        }
    }
    if (!$found) {
        $problems[] = 'missing from package: ' . $needed;
    }
}

$packagedVersion = (string)$verify->getFromName('VERSION');
if (trim($packagedVersion) !== $version) {
    $problems[] = 'VERSION inside the package does not match the build';
}
$verify->close();

if ($problems) {
    @unlink($output);
    foreach ($problems as $problem) {
        fwrite(STDERR, "build-release: {$problem}\n");
    }
    exit(1);
}

$hash = hash_file('sha256', $output);
if (!is_string($hash) || $hash === '') {
    @unlink($output);
    build_release_fail('cannot compute the archive checksum');
}
if (@file_put_contents($output . '.sha256', $hash . '  ' . basename($output) . "\n") === false) {
    @unlink($output);
    build_release_fail('cannot write the checksum file');
}

printf("Built %s (%d files, %.1f KB)\n", $output, $count, filesize($output) / 1024);
printf("SHA-256 %s\n", $hash);
exit(0);
