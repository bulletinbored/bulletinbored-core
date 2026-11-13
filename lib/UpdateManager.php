<?php

require_once __DIR__ . '/Version.php';
require_once __DIR__ . '/UpdateFetcher.php';
require_once __DIR__ . '/UpdateBackup.php';
require_once __DIR__ . '/PackageInstaller.php';

class UpdateManager
{
    private string $manifestPath;
    private array $manifest = [];
    private ?string $updateServer;
    private ?string $updateMirror;
    private UpdateFetcher $fetcher;
    private UpdateBackup $backup;
    private string $rootDir;
    private bool $allowPrerelease;

    public function __construct(
        string $manifestPath,
        ?string $updateServer = null,
        ?string $githubToken = null,
        ?string $updateMirror = null,
        ?string $rootDir = null,
        ?UpdateFetcher $fetcher = null
    ) {
        $this->manifestPath = $manifestPath;
        $this->updateServer = $updateServer;
        $this->updateMirror = $updateMirror !== null ? rtrim($updateMirror, '/') : null;
        $this->rootDir = rtrim($rootDir ?? __DIR__ . '/../', '/');
        $this->loadManifest();

        $dataDir = dirname($manifestPath);
        $this->fetcher = $fetcher ?? new UpdateFetcher($dataDir, $updateServer, $githubToken, $updateMirror);
        $this->backup = new UpdateBackup($dataDir, $this->rootDir);

        // Prereleases are opt-in: a stable installation must never be offered
        // 1.0.0-rc.1, while an installation already running a prerelease may
        // follow the next one.
        $this->allowPrerelease = false;
        if (class_exists('App')) {
            $appConfig = App::getInstance()->config;
            $this->allowPrerelease = !empty($appConfig['allow_prerelease_updates']);
        }
    }

    /**
     * Enable or disable prerelease updates (config: allow_prerelease_updates).
     */
    public function setAllowPrerelease(bool $allow): self
    {
        $this->allowPrerelease = $allow;
        return $this;
    }

    public function allowsPrerelease(): bool
    {
        return $this->allowPrerelease;
    }

    /**
     * A SemVer prerelease has a suffix after the patch number: 1.0.0-rc.1,
     * 1.0.0-beta.2. Build metadata alone (1.0.0+build) is not a prerelease.
     */
    public static function isPrerelease(string $version): bool
    {
        return bb_version_is_prerelease($version);
    }

    /**
     * Should $remote be offered to an installation running $installed?
     *
     * Rules: the remote must be strictly newer, and a prerelease is only
     * offered when the installation is itself a prerelease or the administrator
     * opted in. version_compare() already ranks 1.0.0-rc.1 below 1.0.0.
     */
    public function isUpdateAllowed(string $installed, ?string $remote): bool
    {
        $installed = trim($installed);
        $remote = $remote !== null ? trim($remote) : '';
        if ($remote === '' || $installed === '') {
            return false;
        }
        if (!version_compare($remote, $installed, '>')) {
            return false;
        }
        if (self::isPrerelease($remote) && !$this->allowPrerelease && !self::isPrerelease($installed)) {
            return false;
        }
        return true;
    }

    private function loadManifest(): void
    {
        if (file_exists($this->manifestPath)) {
            $data = json_decode(file_get_contents($this->manifestPath), true);
            $this->manifest = is_array($data) ? $data : [];
        }
    }

    /**
     * Persist the manifest. Returns false when the file could not be written,
     * so callers (in particular the core update finalization) do not report
     * success for metadata that never reached the disk.
     */
    private function saveManifest(): bool
    {
        $dir = dirname($this->manifestPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('UpdateManager: cannot create manifest directory ' . $dir);
            return false;
        }
        $json = json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log('UpdateManager: cannot encode the update manifest');
            return false;
        }
        $written = @file_put_contents($this->manifestPath, $json);
        if ($written === false) {
            error_log('UpdateManager: cannot write the update manifest ' . $this->manifestPath);
            return false;
        }
        return true;
    }

    private function getSection(string $type, string $name): array
    {
        $this->manifest[$type] = $this->manifest[$type] ?? [];
        return $this->manifest[$type];
    }

    private function getEntry(string $type, string $name): array
    {
        $section = $this->getSection($type, $name);
        return $section[$name] ?? ['version' => '1.0.0', 'last_check' => null, 'remote_version' => null, 'available_update' => null];
    }

    /**
     * Record the installed version of a component.
     * Returns false when the manifest could not be persisted.
     */
    public function setVersion(string $type, string $name, string $version): bool
    {
        $section = $this->getSection($type, $name);
        $section[$name]['version'] = $version;
        $section[$name]['last_updated'] = date('c');
        $this->manifest[$type] = $section;
        return $this->saveManifest();
    }

    public function getVersion(string $type, string $name): string
    {
        return $this->getEntry($type, $name)['version'] ?? '1.0.0';
    }

    public function recordCheck(string $type, string $name, ?string $remoteVersion, ?string $updateUrl = null, ?string $updateNotes = null): void
    {
        $section = $this->getSection($type, $name);
        $section[$name]['remote_version'] = $remoteVersion;
        $section[$name]['last_check'] = date('c');
        if ($updateUrl) {
            $section[$name]['available_update'] = [
                'url' => $updateUrl,
                'notes' => $updateNotes,
            ];
        } else {
            $section[$name]['available_update'] = null;
        }
        $this->manifest[$type] = $section;
        $this->saveManifest();
    }

    public function getAvailableUpdate(string $type, string $name): ?array
    {
        return $this->getEntry($type, $name)['available_update'] ?? null;
    }

    public function checkAll(
        string $coreVersion,
        PluginManager $pluginManager,
        ThemeManager $themeManager,
        ?array $catalog = null
    ): array {
        $results = [
            'core' => ['installed' => $coreVersion, 'remote' => null, 'update_available' => false, 'update_url' => null],
        ];
        $results['core']['remote'] = $this->fetcher->fetchRemoteVersion('core');
        $results['core']['update_available'] = $this->isUpdateAllowed($coreVersion, $results['core']['remote']);
        if ($results['core']['update_available']) {
            $results['core']['update_url'] = $this->updateServer;
        }
        // Surfaced by the admin UI: "a newer prerelease exists but this
        // installation only follows stable releases".
        $results['core']['prerelease_ignored'] = $results['core']['remote'] !== null
            && self::isPrerelease((string)$results['core']['remote'])
            && !$results['core']['update_available']
            && version_compare((string)$results['core']['remote'], $coreVersion, '>');
        $this->recordCheck('core', 'core', $results['core']['remote']);

        $catalog = $catalog ?? [];
        $catalogMap = [];
        $catalogVersions = [];
        foreach ($catalog as $item) {
            $key = strtolower($item['name'] ?? '');
            $catalogMap[$key] = $item['repo'] ?? null;
            $catalogVersions[$key] = $item['version'] ?? null;
        }

        foreach ($pluginManager->getAll() as $key => $plugin) {
            $repoUrl = $catalogMap[$key] ?? null;
            $remote = $this->fetcher->fetchRemoteVersion('plugin', $key, $repoUrl);
            if ($remote === null && !empty($catalogVersions[$key])) {
                $remote = $catalogVersions[$key];
            }
            $updateAvailable = $this->isUpdateAllowed((string)$plugin['version'], $remote);
            $results['plugins'][$key] = [
                'installed' => $plugin['version'],
                'remote' => $remote,
                'update_available' => $updateAvailable,
                'update_url' => $updateAvailable ? $repoUrl : null,
            ];
            $this->recordCheck('plugins', $key, $remote);
        }

        foreach ($themeManager->getAll() as $key => $theme) {
            $repoUrl = $catalogMap[$key] ?? null;
            $remote = $this->fetcher->fetchRemoteVersion('theme', $key, $repoUrl);
            if ($remote === null && !empty($catalogVersions[$key])) {
                $remote = $catalogVersions[$key];
            }
            $updateAvailable = $this->isUpdateAllowed((string)($theme['version'] ?? '1.0.0'), $remote);
            $results['themes'][$key] = [
                'installed' => $theme['version'] ?? '1.0.0',
                'remote' => $remote,
                'update_available' => $updateAvailable,
                'update_url' => $updateAvailable ? $repoUrl : null,
            ];
            $this->recordCheck('themes', $key, $remote);
        }

        return $results;
    }

    public function preflight(string $type, string $tag, int $requiredBytes = 0): array
    {
        $errors = [];

        if ($type === 'core') {
            if (version_compare(PHP_VERSION, '8.1.0', '<')) {
                $errors[] = 'PHP 8.1+ required, current: ' . PHP_VERSION;
            }
        }

        $needed = $requiredBytes > 0 ? $requiredBytes : (50 * 1024 * 1024);
        $root = $this->rootDir;
        $freeSpace = @disk_free_space($root);
        if ($freeSpace !== false && $freeSpace < $needed) {
            $errors[] = sprintf(
                'Insufficient disk space: %s free, %s needed',
                $this->formatBytes($freeSpace),
                $this->formatBytes($needed)
            );
        }

        if (!is_writable($root)) {
            $errors[] = 'Root directory is not writable: ' . $root;
        }

        $configPath = $root . '/config.json';
        if (file_exists($configPath) && !is_writable($configPath)) {
            $errors[] = 'config.json is not writable';
        }

        return $errors;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float)$bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    public function applyUpdate(string $type, string $name, string $zipPath): bool
    {
        if (!file_exists($zipPath)) {
            return false;
        }

        // The admin form submits "plugins"/"themes"; normalise to the singular
        // values used by the extension pipeline.
        $type = rtrim(strtolower($type), 's');

        if ($type === 'plugin' || $type === 'theme') {
            return $this->applyExtensionUpdateFromZip($type, $name, $zipPath);
        }

        return $this->applyCoreUpdateFromZip($zipPath);
    }

    private function applyExtensionUpdateFromZip(string $type, string $name, string $zipPath): bool
    {
        $errors = $this->preflight($type, '');
        if (!empty($errors)) {
            error_log('BB EXTENSION PREFLIGHT FAIL: ' . implode('; ', $errors));
            return false;
        }

        require_once __DIR__ . '/PluginManager.php';
        require_once __DIR__ . '/ThemeManager.php';

        $root = $this->rootDir;
        $dataDir = dirname($this->manifestPath);

        // Delegate to the manager that owns the package lifecycle, so backup,
        // manifest validation, installed.json, lifecycle hooks (on_update) and
        // rollback all follow exactly one code path instead of the simplified
        // pipeline this method used to implement.
        if ($type === 'plugin') {
            $manager = new PluginManager($root . '/plugins', $dataDir . '/plugins.json');
            $result = $manager->updateFromZip($name, $zipPath);
            $group = 'plugins';
        } else {
            $manager = new ThemeManager($root . '/themes', $dataDir . '/themes.json', 'freshbored');
            $result = $manager->updateFromZip($name, $zipPath);
            $group = 'themes';
        }

        if (empty($result['success'])) {
            error_log('BB EXTENSION UPDATE FAIL (' . $type . ' ' . $name . '): ' . ($result['message'] ?? 'unknown'));
            return false;
        }

        $version = $result['manifest']['version'] ?? '1.0.0';
        $this->setVersion($group, $name, $version);
        $this->fetcher->clearCache();
        return true;
    }

    /**
     * Verify a freshly extracted extension package.
     * Returns null on success, or ['success'=>false, 'message'=>...] to abort.
     */
    public function verifyExtractedPackage(string $type, string $targetDir): ?array
    {
        $manifestFile = $targetDir . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return ['success' => false, 'message' => 'Missing manifest.json'];
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            return ['success' => false, 'message' => 'Invalid manifest.json'];
        }

        if (empty($manifest['name'])) {
            return ['success' => false, 'message' => 'Manifest missing required field: name'];
        }
        if (empty($manifest['version'])) {
            return ['success' => false, 'message' => 'Manifest missing required field: version'];
        }

        if (isset($manifest['id']) && !preg_match('/^[a-z][a-z0-9-]*$/', $manifest['id'])) {
            return ['success' => false, 'message' => "Manifest invalid 'id' format: must be lowercase alphanumeric + hyphens, starting with a letter"];
        }

        if (isset($manifest['permissions']) && !is_array($manifest['permissions'])) {
            return ['success' => false, 'message' => "Manifest invalid 'permissions' (should be an array of permission strings)"];
        }

        if (isset($manifest['bootstrap']) && !is_string($manifest['bootstrap'])) {
            return ['success' => false, 'message' => "Manifest invalid 'bootstrap' (should be a filename string)"];
        }

        if (!empty($manifest['core'])) {
            $coreVersion = trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0');
            $constraint = $manifest['core'];
            // Requirements are matched against the base version, so a plugin
            // asking for ">=1.0.0" installs on 1.0.0-rc.1.
            $ok = $this->checkVersionConstraint(bb_version_base($coreVersion), $constraint);
            if (!$ok) {
                return ['success' => false, 'message' => "Core version {$coreVersion} does not satisfy constraint '{$constraint}'"];
            }
        }

        if (!empty($manifest['php'])) {
            $ok = $this->checkVersionConstraint(PHP_VERSION, $manifest['php']);
            if (!$ok) {
                return ['success' => false, 'message' => "PHP version " . PHP_VERSION . " does not satisfy constraint '{$manifest['php']}'"];
            }
        }

        return null;
    }

    /**
     * Check a version against a constraint string (e.g. ">=8.1 <9.0").
     * Returns true if all parts of the constraint are satisfied.
     */
    public function checkVersionConstraint(string $version, string $constraint): bool
    {
        $parts = preg_split('/\s+/', trim($constraint));
        foreach ($parts as $c) {
            $c = trim($c);
            if ($c === '') {
                continue;
            }
            if (!preg_match('/^(>=|<=|>|<|==|!=)(.+)$/', $c, $m)) {
                return false;
            }
            if (!version_compare($version, trim($m[2]), $m[1])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Apply a core update from a ZIP archive uploaded by an administrator.
     *
     * Shares every post-copy step with applyCoreUpdate() through
     * completeCoreUpdate()/finalizeCoreUpdate(), so both update routes leave
     * the installation in exactly the same state.
     */
    private function applyCoreUpdateFromZip(string $zipPath): bool
    {
        $errors = $this->preflight('core', '');
        if (!empty($errors)) {
            error_log('BB CORE PREFLIGHT FAIL: ' . implode('; ', $errors));
            return false;
        }

        require_once __DIR__ . '/repo_install.php';

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            error_log('BB CORE FAIL zip open');
            return false;
        }

        $tmpExtract = rtrim(sys_get_temp_dir(), '/') . '/bb_upd_' . uniqid() . '/';
        mkdir($tmpExtract, 0755, true);
        $ok = extract_zip($zipPath, $tmpExtract);
        $zip->close();
        @unlink($zipPath);

        if (!$ok) {
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL unsafe zip');
            return false;
        }

        return $this->completeCoreUpdate($tmpExtract);
    }

    public function applyCoreUpdate(string $tag): bool
    {
        $errors = $this->preflight('core', $tag);
        if (!empty($errors)) {
            error_log('BB CORE PREFLIGHT FAIL: ' . implode('; ', $errors));
            return false;
        }

        $zipUrl = 'https://github.com/bulletinbored/bulletinbored-core/archive/refs/tags/' . rawurlencode($tag) . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'bbcore') . '.zip';
        error_log('BB CORE START tag=' . $tag . ' zip=' . $zipUrl);
        $data = $this->fetcher->httpGet($zipUrl, 30);
        if ($data === null) {
            error_log('BB CORE FAIL download');
            return false;
        }
        file_put_contents($tmpZip, $data);
        error_log('BB CORE downloaded size=' . strlen($data));

        require_once __DIR__ . '/repo_install.php';

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            error_log('BB CORE FAIL zip open');
            @unlink($tmpZip);
            return false;
        }

        $tmpExtract = rtrim(sys_get_temp_dir(), '/') . '/bb_core_' . uniqid() . '/';
        mkdir($tmpExtract, 0755, true);
        $ok = extract_zip($tmpZip, $tmpExtract);
        $zip->close();
        @unlink($tmpZip);

        if (!$ok) {
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL unsafe zip');
            return false;
        }

        return $this->completeCoreUpdate($tmpExtract, $tag);
    }

    /**
     * Install an already extracted core package and finalize it.
     *
     * @param string      $tmpExtract Directory the archive was extracted into.
     * @param string|null $tag        Version being applied; when null it is read
     *                                from the VERSION file in the package.
     */
    private function completeCoreUpdate(string $tmpExtract, ?string $tag = null): bool
    {
        // A GitHub archive wraps everything in a single "<project>-<version>/"
        // directory; a hand-made release ZIP usually has the files at the root
        // but may also contain exactly one real directory (e.g. lib/). Only
        // treat the single directory as a wrapper when it actually holds the
        // application entry points.
        $sourceDir = $tmpExtract;
        if (!file_exists($tmpExtract . '/index.php') || !file_exists($tmpExtract . '/VERSION')) {
            $topDirs = glob($tmpExtract . '*', GLOB_ONLYDIR) ?: [];
            if (count($topDirs) === 1
                && file_exists($topDirs[0] . '/index.php')
                && file_exists($topDirs[0] . '/VERSION')) {
                $sourceDir = $topDirs[0];
            }
        }
        if (!file_exists($sourceDir . '/index.php') || !file_exists($sourceDir . '/VERSION')) {
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL invalid package structure');
            return false;
        }

        // Read the version shipped in the package before the files are copied:
        // the manual ZIP route has no tag to fall back on.
        $packageVersion = $this->readPackageVersion($sourceDir);
        $version = $tag !== null ? $tag : $packageVersion;

        $backupPath = $this->backup->backupCore();
        if ($backupPath === null) {
            error_log('BB CORE FAIL: backup failed, aborting update');
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $root = $this->rootDir;

        try {
            $this->backup->copyRecursive($sourceDir, $root);
        } catch (\Throwable $e) {
            $this->backup->restoreCoreBackup($backupPath);
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL copy error: ' . $e->getMessage());
            return false;
        }
        $this->backup->deleteRecursive($tmpExtract);

        $this->flattenNestedCoreDir($root);
        error_log('BB CORE extracted');

        return $this->finalizeCoreUpdate($root, $version);
    }

    /**
     * Extract a version number from a package VERSION file.
     * Returns null when the file is missing or does not look like a version.
     */
    private function readPackageVersion(string $sourceDir): ?string
    {
        $raw = @file_get_contents($sourceDir . '/VERSION');
        if ($raw === false) {
            return null;
        }
        $version = trim($raw);
        if ($version === '' || !preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }
        return $version;
    }

    /**
     * GitHub archives sometimes contain a top-level project folder that ends up
     * nested inside the installation root (e.g. bulletinbored-core-1.0.0/).
     * Move its contents one level up and remove the wrapper directory.
     */
    private function flattenNestedCoreDir(string $root): void
    {
        foreach (glob($root . '/bulletinbored-core-*', GLOB_ONLYDIR) ?: [] as $nested) {
            foreach (glob($nested . '/*') ?: [] as $item) {
                $base = basename($item);
                $dest = $root . '/' . $base;
                if (is_dir($item)) {
                    $this->backup->copyRecursive($item, $dest);
                } elseif (is_file($item)) {
                    if (is_dir($dest)) {
                        $this->backup->deleteRecursive($dest);
                    }
                    copy($item, $dest);
                }
            }
            $this->backup->deleteRecursive($nested);
        }
    }

    /**
     * Post-copy steps shared by every core update route.
     *
     * Keeping them in one place is what makes "update from upload" behave like
     * "update from repository": installer scripts are removed, the version is
     * recorded in the manifest, in VERSION and in config.json, and the update
     * cache is cleared.
     */
    /**
     * Post-copy steps shared by every core update route.
     *
     * Every metadata write is verified: the update is reported as successful
     * only when VERSION, config.json and the manifest all carry the new
     * version. The files themselves are already in place at this point, so a
     * failure here is reported (and logged with the exact step) instead of
     * leaving a silent mismatch between the code and the recorded version; a
     * retry re-runs the copy and the metadata writes idempotently.
     */
    private function finalizeCoreUpdate(string $root, ?string $version): bool
    {
        $this->removeInstallerScripts($root);

        $failures = [];
        $normalized = $version !== null ? ltrim(trim($version), 'v') : '';
        if ($normalized !== '' && preg_match('/^\d+\.\d+\.\d+/', $normalized)) {
            if (!$this->setVersion('core', 'core', $normalized)) {
                $failures[] = 'update manifest';
            }

            $versionFile = rtrim($root, '/') . '/VERSION';
            if (is_file($versionFile)) {
                if (@file_put_contents($versionFile, $normalized) === false) {
                    $failures[] = 'VERSION';
                }
            }

            if (!$this->updateVersionInConfig($root, $normalized)) {
                $failures[] = 'config.json';
            }
        } else {
            error_log('BB CORE WARN: package version unknown, version metadata left unchanged');
        }

        $this->fetcher->clearCache();

        if (!empty($failures)) {
            error_log('BB CORE FAIL: files updated but version metadata not finalized ('
                . implode(', ', $failures) . '). Fix permissions and re-run the update.');
            return false;
        }
        return true;
    }

    /**
     * Update the version stored in config.json (or the legacy config.php).
     * Returns false when the file exists but could not be updated.
     */
    private function updateVersionInConfig(string $root, string $tag): bool
    {
        $configJsonPath = rtrim($root, '/') . '/config.json';
        $legacyConfigPath = rtrim($root, '/') . '/config.php';
        $version = ltrim($tag, 'v');

        if (file_exists($configJsonPath)) {
            if (!is_writable($configJsonPath)) {
                error_log('UpdateManager: config.json is not writable, version not updated');
                return false;
            }
            $raw = @file_get_contents($configJsonPath);
            $config = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($config)) {
                error_log('UpdateManager: config.json is not valid JSON, version not updated');
                return false;
            }
            $config['version'] = $version;
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false || @file_put_contents($configJsonPath, $json) === false) {
                error_log('UpdateManager: cannot write config.json, version not updated');
                return false;
            }
            return true;
        }

        if (file_exists($legacyConfigPath)) {
            if (!is_writable($legacyConfigPath)) {
                error_log('UpdateManager: config.php is not writable, version not updated');
                return false;
            }
            $content = @file_get_contents($legacyConfigPath);
            if (!is_string($content)) {
                return false;
            }
            $updated = preg_replace(
                '/\$config\[\'version\'\]\s*=\s*\'[^\']*\';/',
                "\$config['version'] = '{$version}';",
                $content
            );
            if (!is_string($updated)) {
                return false;
            }
            if (@file_put_contents($legacyConfigPath, $updated) === false) {
                error_log('UpdateManager: cannot write config.php, version not updated');
                return false;
            }
            return true;
        }

        // No configuration file at all: there is no version to update, but that
        // is not an error (fresh unpack, tests).
        return true;
    }

    /**
     * Return the extension catalog, refreshing it from the mirror when the
     * local cache (data/catalog.json) is missing or older than $ttl seconds.
     * Falls back to the local cache when the mirror is unreachable.
     */
    public function getCatalog(bool $forceRefresh = false, int $ttl = 3600): array
    {
        $localPath = __DIR__ . '/../data/catalog.json';
        $local = [];
        if (file_exists($localPath)) {
            $decoded = json_decode((string)file_get_contents($localPath), true);
            if (is_array($decoded)) {
                $local = $decoded;
            }
        }

        $isFresh = file_exists($localPath) && (time() - (int)@filemtime($localPath)) < $ttl;
        if (!$forceRefresh && $isFresh && !empty($local)) {
            return $local;
        }

        if (!empty($this->updateMirror)) {
            $json = $this->fetcher->httpGet($this->updateMirror . '/catalog.json', 10);
            if ($json !== null) {
                $remote = json_decode($json, true);
                if (is_array($remote)) {
                    $dir = dirname($localPath);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    @file_put_contents($localPath, json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    return $remote;
                }
            }
        }

        return $local;
    }

    public function applyExtensionUpdate(string $type, string $name, string $tag, ?string $repoUrl = null): bool
    {
        $key = strtolower($name);

        // Resolve the repository from the (auto-refreshed) catalog. The local
        // data/catalog.json was previously used alone and could be stale, which
        // made updates for newly catalogued extensions fail.
        if (!$repoUrl) {
            foreach ($this->getCatalog() as $item) {
                if (strtolower($item['name'] ?? '') === $key && strtolower($item['type'] ?? '') === $type) {
                    $repoUrl = $item['repo'] ?? null;
                    break;
                }
            }
        }

        // Fall back to the repository recorded at install time: a plugin/theme
        // may be installed from a repo without being present in the catalog
        // (e.g. sitemapbored), which previously made every update fail.
        if (!$repoUrl) {
            $installedPath = __DIR__ . '/../data/installed.json';
            if (file_exists($installedPath)) {
                $installed = json_decode(file_get_contents($installedPath), true);
                $group = $type === 'theme' ? 'themes' : 'plugins';
                $entry = $installed[$group][$name] ?? $installed[$group][strtolower($name)] ?? null;
                if (is_array($entry) && !empty($entry['repo'])) {
                    $repoUrl = $entry['repo'];
                }
            }
        }

        if (!$repoUrl || !preg_match('#github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repoUrl, $m)) {
            error_log('BB EXTENSION UPDATE: no repository resolved for ' . $type . ' "' . $name . '" (checked mirror catalog, local catalog, installed.json)');
            return false;
        }

        $zipUrl = 'https://github.com/' . rawurlencode($m[1]) . '/' . rawurlencode($m[2]) . '/archive/refs/tags/' . rawurlencode($tag) . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'bbext') . '.zip';
        $data = $this->fetcher->httpGet($zipUrl, 30);
        if ($data === null) {
            error_log('BB EXTENSION UPDATE: download failed for ' . $name . ' tag ' . $tag . ' (' . $zipUrl . ')');
            return false;
        }
        file_put_contents($tmpZip, $data);

        return $this->applyExtensionUpdateFromZip($type, $name, $tmpZip);
    }

    /**
     * Remove the web installer scripts after a core update.
     *
     * Only the setup wizards are removed: they can create a config.json and
     * reset the database, so they must not survive on a live installation.
     * api/install.php is deliberately kept — it is a runtime admin endpoint
     * (plugin/theme install from the catalog) protected by a database-backed
     * administrator check, and deleting it would leave updated installations
     * different from freshly installed ones.
     */
    private function removeInstallerScripts(string $root): void
    {
        if (!file_exists($root . '/config.json')) {
            return;
        }
        $installerScripts = [
            $root . '/install.php',
            $root . '/install2.php',
            $root . '/install3.php',
        ];
        foreach ($installerScripts as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function getRemoteVersion(string $type, string $name, ?string $repoUrl = null): ?string
    {
        return $this->fetcher->fetchRemoteVersion($type, $name, $repoUrl);
    }

    public function getLockedExtensions(): array
    {
        return ['php', 'css', 'js', 'json', 'sql', 'html', 'md', 'txt', 'ico', 'gif', 'png', 'jpg', 'jpeg', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot'];
    }

    public function backupCore(): ?string
    {
        return $this->backup->backupCore();
    }

    public function restoreCoreBackup(string $backupPath): bool
    {
        return $this->backup->restoreCoreBackup($backupPath);
    }

    public function listBackups(): array
    {
        return $this->backup->listBackups();
    }
}
