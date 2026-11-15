<?php

/**
 * AtomicFileTest — the single implementation used by the installer (config.json),
 * by telemetry (install_id) and by the update manager (manifest, VERSION,
 * config.json) for every write that must not be lost or exposed.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/AtomicFile.php';

function atomic_file_dir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/bb_atomic_' . $prefix . '_' . uniqid('', true);
    @mkdir($dir, 0755, true);
    return $dir;
}

function atomic_file_cleanup(string $dir): void
{
    // scandir(), not glob with GLOB_BRACE: that constant does not exist on
    // every platform (musl builds), and hidden temporary files must be removed.
    foreach (@scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? @rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function atomic_file_mode(string $path): int
{
    clearstatcache(true, $path);
    return (int)(@fileperms($path) & 0777);
}

function test_atomic_file_writes_with_the_requested_mode(): Test
{
    $t = new Test('AtomicFile - writes the content with the requested mode');

    $dir = atomic_file_dir('mode');
    $file = $dir . '/config.json';

    try {
        $t->assertTrue('Write succeeds', AtomicFile::write($file, '{"version":"1.0.0"}', 0600, true));
        $t->assertEquals('Content written exactly', '{"version":"1.0.0"}', file_get_contents($file));
        $t->assertEquals('Mode applied', 0600, atomic_file_mode($file));
        $t->assertCount('No temporary file left behind', 0, glob($dir . '/.bb-tmp-*') ?: []);

        // A second write replaces the file and keeps the requested mode.
        $t->assertTrue('Overwrite succeeds', AtomicFile::write($file, '{"version":"1.0.1"}', 0644, true));
        $t->assertEquals('New content', '{"version":"1.0.1"}', file_get_contents($file));
        $t->assertEquals('New mode applied', 0644, atomic_file_mode($file));
    } finally {
        atomic_file_cleanup($dir);
    }

    return $t;
}

function test_atomic_file_rejects_bad_input(): Test
{
    $t = new Test('AtomicFile - invalid content leaves the previous file');

    $dir = atomic_file_dir('invalid');
    $file = $dir . '/config.json';

    try {
        AtomicFile::write($file, '{"version":"1.0.0"}', 0600, true);

        $t->assertFalse('Invalid JSON rejected', AtomicFile::write($file, '{"version":', 0600, true));
        $t->assertEquals('Previous content untouched', '{"version":"1.0.0"}', file_get_contents($file));
        $t->assertEquals('Mode untouched', 0600, atomic_file_mode($file));
        $t->assertCount('No temporary file left behind', 0, glob($dir . '/.bb-tmp-*') ?: []);

        $t->assertFalse('Missing directory rejected', AtomicFile::write($dir . '/nope/x.json', '{}', 0600, true));
        $t->assertFalse('Nothing created', is_dir($dir . '/nope'));
    } finally {
        atomic_file_cleanup($dir);
    }

    return $t;
}

function test_atomic_file_detects_a_short_write(): Test
{
    $t = new Test('AtomicFile - a short write never replaces the file');

    $dir = atomic_file_dir('short');
    $file = $dir . '/config.json';
    file_put_contents($file, '{"version":"old"}');
    chmod($file, 0600);

    try {
        $shortWriter = function (string $path, string $contents): int {
            @file_put_contents($path, substr($contents, 0, 4));
            return 4;
        };

        $t->assertFalse('Short write reported as failure', AtomicFile::write($file, '{"version":"new"}', 0600, true, $shortWriter));
        $t->assertEquals('Original content kept', '{"version":"old"}', file_get_contents($file));
        $t->assertCount('Temporary file removed', 0, glob($dir . '/.bb-tmp-*') ?: []);
    } finally {
        atomic_file_cleanup($dir);
    }

    return $t;
}

register_tests(
    'test_atomic_file_writes_with_the_requested_mode',
    'test_atomic_file_rejects_bad_input',
    'test_atomic_file_detects_a_short_write'
);
