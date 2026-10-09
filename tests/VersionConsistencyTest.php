<?php

/**
 * VersionConsistencyTest — the packaged version, the sample config and the
 * release notes must not drift apart. Guards against shipping a config-sample
 * or changelog whose version disagrees with the VERSION file (which is what
 * the installer and telemetry report).
 */

require_once __DIR__ . '/harness.php';

function test_version_files_consistent(): Test
{
    $t = new Test('Version - VERSION, config-sample and release notes agree');

    $root = dirname(__DIR__);
    $versionFile = $root . '/VERSION';
    $t->assert('VERSION file exists', is_file($versionFile));

    $version = trim((string)@file_get_contents($versionFile));
    $t->assert('VERSION is a semantic version', (bool)preg_match('/^\d+\.\d+\.\d+$/', $version));

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
