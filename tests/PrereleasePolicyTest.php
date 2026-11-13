<?php

/**
 * PrereleasePolicyTest — 1.0.0-rc.1 must never be pushed to stable installs.
 *
 * The update checker reads GitHub's /releases/latest (which skips prereleases)
 * and the update mirror; this policy is the client-side guarantee. A version
 * with a SemVer prerelease suffix is only offered when the administrator opted
 * in (allow_prerelease_updates) or the installation already runs a prerelease.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/PackageInstaller.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/ThemeManager.php';
require_once __DIR__ . '/../lib/UpdateFetcher.php';
require_once __DIR__ . '/../lib/UpdateBackup.php';
require_once __DIR__ . '/../lib/UpdateManager.php';

/**
 * Fetcher stub: answers from a fixed value instead of the network.
 */
class PrereleaseStubFetcher extends UpdateFetcher
{
    public ?string $remote = null;
    public int $calls = 0;

    public function __construct()
    {
        parent::__construct(sys_get_temp_dir() . '/bb_prerelease_cache_' . uniqid('', true));
    }

    public function fetchRemoteVersion(string $type, ?string $name = null, ?string $repoUrl = null): ?string
    {
        $this->calls++;
        return $this->remote;
    }
}

/**
 * @return array{0: UpdateManager, 1: PrereleaseStubFetcher, 2: string, 3: PluginManager, 4: ThemeManager}
 */
function prerelease_manager(?string $remote): array
{
    $base = sys_get_temp_dir() . '/bb_prerelease_' . uniqid('', true);
    @mkdir($base . '/plugins', 0755, true);
    @mkdir($base . '/themes', 0755, true);
    @mkdir($base . '/data', 0755, true);
    file_put_contents($base . '/plugins.json', json_encode(['plugins' => []]));
    file_put_contents($base . '/themes.json', json_encode(['themes' => [], 'active' => 'freshbored']));

    $fetcher = new PrereleaseStubFetcher();
    $fetcher->remote = $remote;

    $manager = new UpdateManager(
        $base . '/data/updates.json',
        'https://github.com/bulletinbored/bulletinbored-core',
        null,
        null,
        $base,
        $fetcher
    );

    return [
        $manager,
        $fetcher,
        $base,
        new PluginManager($base . '/plugins', $base . '/plugins.json'),
        new ThemeManager($base . '/themes', $base . '/themes.json', 'freshbored'),
    ];
}

function prerelease_cleanup(string $base): void
{
    if (!is_dir($base)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($base);
}

function test_version_base_helpers(): Test
{
    $t = new Test('Prereleases - base version helpers');

    $t->assertEquals('rc suffix stripped', '1.0.0', bb_version_base('1.0.0-rc.1'));
    $t->assertEquals('beta suffix stripped', '2.3.4', bb_version_base('2.3.4-beta.2'));
    $t->assertEquals('build metadata stripped', '1.0.0', bb_version_base('1.0.0+build.5'));
    $t->assertEquals('tag prefix stripped', '1.2.3', bb_version_base('v1.2.3'));
    $t->assertEquals('stable version untouched', '1.0.0', bb_version_base('1.0.0'));
    $t->assertEquals('rc with build metadata', '1.0.0', bb_version_base('1.0.0-rc.1+build.5'));

    // SemVer ordering still applies to the full version.
    $t->assertTrue('rc.1 is below 1.0.0', version_compare('1.0.0-rc.1', '1.0.0', '<'));

    return $t;
}

function test_release_candidate_satisfies_stable_requirements(): Test
{
    $t = new Test('Prereleases - rc satisfies ">=1.0.0" plugin requirements');

    $base = sys_get_temp_dir() . '/bb_prerelease_manifest_' . uniqid('', true);
    @mkdir($base . '/plugins', 0755, true);

    try {
        $manager = new PluginManager($base . '/plugins', $base . '/plugins.json');

        $result = $manager->validateManifest([
            'name' => 'demo',
            'version' => '1.0.0',
            'core' => '>=1.0.0',
        ]);
        $t->assertTrue('Plugin requiring core >=1.0.0 validates on the RC', $result['valid']);
        $t->assertEquals('No error reported', [], $result['errors']);

        $future = $manager->validateManifest([
            'name' => 'demo',
            'version' => '1.0.0',
            'core' => '>=99.0.0',
        ]);
        $t->assertFalse('Impossible requirement still rejected', $future['valid']);
    } finally {
        @unlink($base . '/plugins.json');
        @rmdir($base . '/plugins');
        @rmdir($base);
    }

    return $t;
}

function test_semver_prerelease_detection(): Test
{
    $t = new Test('Prereleases - SemVer detection');

    $t->assertTrue('1.0.0-rc.1 is a prerelease', UpdateManager::isPrerelease('1.0.0-rc.1'));
    $t->assertTrue('1.0.0-beta.2 is a prerelease', UpdateManager::isPrerelease('1.0.0-beta.2'));
    $t->assertFalse('1.0.0 is stable', UpdateManager::isPrerelease('1.0.0'));
    $t->assertFalse('1.0 is stable (short form)', UpdateManager::isPrerelease('1.0'));
    $t->assertFalse('Build metadata alone is not a prerelease', UpdateManager::isPrerelease('1.0.0+build.5'));
    $t->assertTrue('Prerelease with build metadata', UpdateManager::isPrerelease('1.0.0-rc.1+build.5'));

    return $t;
}

function test_prerelease_not_offered_to_stable_installs(): Test
{
    $t = new Test('Prereleases - stable installs only see stable releases');

    [$manager, $fetcher, $base, $plugins, $themes] = prerelease_manager('1.0.0-rc.1');
    try {
        $results = $manager->checkAll('0.9.8', $plugins, $themes);
        $t->assertFalse('1.0.0-rc.1 is not offered to 0.9.8', $results['core']['update_available']);
        $t->assertEquals('Remote version is still reported', '1.0.0-rc.1', $results['core']['remote']);
        $t->assertTrue('The ignored prerelease is flagged for the admin UI', $results['core']['prerelease_ignored']);

        // Opt-in.
        $manager2 = clone $manager;
        $manager2->setAllowPrerelease(true);
        $results2 = $manager2->checkAll('0.9.8', $plugins, $themes);
        $t->assertTrue('Opt-in installations are offered the prerelease', $results2['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    return $t;
}

function test_prerelease_follows_prerelease(): Test
{
    $t = new Test('Prereleases - rc installs follow rc updates');

    [$manager, , $base, $plugins, $themes] = prerelease_manager('1.0.0-rc.2');
    try {
        $results = $manager->checkAll('1.0.0-rc.1', $plugins, $themes);
        $t->assertTrue('rc.1 sees rc.2', $results['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    [$manager, , $base, $plugins, $themes] = prerelease_manager('1.0.0-rc.1');
    try {
        $results = $manager->checkAll('1.0.0-rc.1', $plugins, $themes);
        $t->assertFalse('Same version is not an update', $results['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    [$manager, , $base, $plugins, $themes] = prerelease_manager('1.0.0');
    try {
        $results = $manager->checkAll('1.0.0-rc.1', $plugins, $themes);
        $t->assertTrue('rc.1 is offered the stable 1.0.0', $results['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    [$manager, , $base, $plugins, $themes] = prerelease_manager('1.0.0-rc.3');
    try {
        $results = $manager->checkAll('1.0.0', $plugins, $themes);
        $t->assertFalse('Stable 1.0.0 is not offered an older rc', $results['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    return $t;
}

function test_stable_update_after_prerelease(): Test
{
    $t = new Test('Prereleases - stable upgrades always offered');

    [$manager, , $base, $plugins, $themes] = prerelease_manager('1.1.0');
    try {
        $results = $manager->checkAll('1.0.0', $plugins, $themes);
        $t->assertTrue('1.1.0 offered to 1.0.0', $results['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    [$manager, , $base, $plugins, $themes] = prerelease_manager('1.1.0-rc.1');
    try {
        $results = $manager->checkAll('1.0.0', $plugins, $themes);
        $t->assertFalse('1.1.0-rc.1 not offered to stable 1.0.0', $results['core']['update_available']);
    } finally {
        prerelease_cleanup($base);
    }

    return $t;
}

register_tests(
    'test_version_base_helpers',
    'test_release_candidate_satisfies_stable_requirements',
    'test_semver_prerelease_detection',
    'test_prerelease_not_offered_to_stable_installs',
    'test_prerelease_follows_prerelease',
    'test_stable_update_after_prerelease'
);
