<?php

/**
 * VersionConsistencyTest — the packaged version and the sample config must not
 * drift apart. Guards against shipping a config-sample whose version disagrees
 * with the VERSION file (which is what the installer and telemetry report).
 * Release notes live on GitHub Releases, not in the repository.
 */

require_once __DIR__ . '/harness.php';

function test_version_files_consistent(): Test
{
    $t = new Test('Version - VERSION, config-sample and release notes agree');

    $root = dirname(__DIR__);
    $versionFile = $root . '/VERSION';
    $t->assert('VERSION file exists', is_file($versionFile));

    $version = trim((string)@file_get_contents($versionFile));
    // SemVer with an optional prerelease suffix (1.0.0, 1.0.0-rc.1, 1.0.0-beta.2).
    $t->assert(
        "VERSION is a semantic version (got: {$version})",
        (bool)preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)
    );

    $sampleRaw = (string)@file_get_contents($root . '/config-sample.json');
    $sample = json_decode($sampleRaw, true);
    $t->assert('config-sample.json is valid JSON', is_array($sample));
    $t->assertEquals('config-sample version matches VERSION', $version, (string)($sample['version'] ?? ''));

    // RELEASE-NOTES.md is gitignored, so it may be absent in a packaged
    // distribution: only check it when present.
    $notesFile = $root . '/RELEASE-NOTES.md';
    if (is_file($notesFile)) {
        $notes = (string)file_get_contents($notesFile);
        $matched = (bool)preg_match('/^##\s+(\d+\.\d+\.\d+)/m', $notes, $m);
        $t->assert('RELEASE-NOTES has a version heading', $matched);
        if ($matched) {
            $t->assertEquals('release notes match VERSION', $version, $m[1]);
        }
    } else {
        $t->assert('RELEASE-NOTES absent: check skipped', true);
    }

    return $t;
}

register_tests('test_version_files_consistent');
