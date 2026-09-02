<?php

require_once __DIR__ . '/UpdateFetcher.php';
require_once __DIR__ . '/UpdateBackup.php';

class UpdateManager
{
    private string $manifestPath;
    private array $manifest = [];
    private ?string $updateServer;
    private UpdateFetcher $fetcher;
    private UpdateBackup $backup;

    public function __construct(string $manifestPath, ?string $updateServer = null, ?string $githubToken = null, ?string $updateMirror = null)
    {
        $this->manifestPath = $manifestPath;
        $this->updateServer = $updateServer;
        $this->loadManifest();

        $dataDir = dirname($manifestPath);
        $rootDir = rtrim(__DIR__ . '/../', '/');
        $this->fetcher = new UpdateFetcher($dataDir, $updateServer, $githubToken, $updateMirror);
        $this->backup = new UpdateBackup($dataDir, $rootDir);
    }

    private function loadManifest(): void
    {
        if (file_exists($this->manifestPath)) {
            $data = json_decode(file_get_contents($this->manifestPath), true);
            $this->manifest = is_array($data) ? $data : [];
        }
    }

    private function saveManifest(): void
    {
        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->manifestPath, json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

    public function setVersion(string $type, string $name, string $version): void
    {
        $section = $this->getSection($type, $name);
        $section[$name]['version'] = $version;
        $section[$name]['last_updated'] = date('c');
        $this->manifest[$type] = $section;
        $this->saveManifest();
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
        if ($results['core']['remote'] && version_compare($results['core']['remote'], $coreVersion, '>')) {
            $results['core']['update_available'] = true;
            $results['core']['update_url'] = $this->updateServer;
        }
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
            $updateAvailable = $remote && version_compare($remote, $plugin['version'], '>');
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
            $updateAvailable = $remote && version_compare($remote, ($theme['version'] ?? '1.0.0'), '>');
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
        $root = rtrim(__DIR__ . '/../', '/');
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

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        if ($type === 'plugin') {
            $extractTo = rtrim(__DIR__ . '/../plugins', '/') . '/';
        } else {
            $extractTo = rtrim(__DIR__ . '/../themes', '/') . '/';
        }

        require_once __DIR__ . '/repo_install.php';

        $tmpExtract = $extractTo . '_update_' . uniqid() . '/';
        mkdir($tmpExtract, 0755, true);
        $ok = extract_zip($zipPath, $tmpExtract);
        $zip->close();
        @unlink($zipPath);

        if (!$ok) {
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $src = $tmpExtract;
        $topFolders = glob($tmpExtract . '*', GLOB_ONLYDIR);
        if (count($topFolders) === 1) {
            $src = $topFolders[0];
        }

        $targetDir = $extractTo . $name;
        $oldDir = $extractTo . '_old_' . $name . '_' . uniqid();
        if (is_dir($targetDir)) {
            if (!@rename($targetDir, $oldDir)) {
                $this->backup->deleteRecursive($tmpExtract);
                return false;
            }
        }

        if (!@rename($src, $targetDir)) {
            if (is_dir($oldDir)) {
                @rename($oldDir, $targetDir);
            }
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $this->backup->deleteRecursive($oldDir);
        $this->backup->deleteRecursive($tmpExtract);

        if (!is_dir($targetDir) || !glob($targetDir . '/*')) {
            return false;
        }

        $version = $this->detectVersionFromPackage($targetDir);

        $this->syncVersionMetadata($targetDir, $version);
        $this->setVersion($type . 's', $name, $version);
        $this->fetcher->clearCache();
        return true;
    }

    private function applyCoreUpdateFromZip(string $zipPath): bool
    {
        $errors = $this->preflight('core', '');
        if (!empty($errors)) {
            error_log('BB CORE PREFLIGHT FAIL: ' . implode('; ', $errors));
            return false;
        }

        require_once __DIR__ . '/repo_install.php';

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return false;
        }

        $tmpExtract = rtrim(sys_get_temp_dir(), '/') . '/bb_upd_' . uniqid() . '/';
        mkdir($tmpExtract, 0755, true);
        $ok = extract_zip($zipPath, $tmpExtract);
        $zip->close();
        @unlink($zipPath);

        if (!$ok) {
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $topDirs = glob($tmpExtract . '*', GLOB_ONLYDIR);
        $sourceDir = (count($topDirs) === 1) ? $topDirs[0] : $tmpExtract;
        if (!file_exists($sourceDir . '/index.php') || !file_exists($sourceDir . '/VERSION')) {
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL invalid package structure');
            return false;
        }

        $backupPath = $this->backup->backupCore();
        if ($backupPath === null) {
            error_log('BB CORE FAIL: backup failed, aborting update');
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $root = rtrim(__DIR__ . '/../', '/');

        try {
            $this->backup->copyRecursive($sourceDir, $root);
        } catch (\Throwable $e) {
            if ($backupPath !== null) {
                $this->backup->restoreCoreBackup($backupPath);
            }
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL copy error: ' . $e->getMessage());
            return false;
        }
        $this->backup->deleteRecursive($tmpExtract);

        foreach (glob($root . '/bulletinbored-core-*', GLOB_ONLYDIR) as $nested) {
            foreach (glob($nested . '/*') as $item) {
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

        $this->fetcher->clearCache();
        return true;
    }

    private function detectVersionFromPackage(string $targetDir): string
    {
        $manifestFile = $targetDir . '/manifest.json';
        if (file_exists($manifestFile)) {
            $data = json_decode(file_get_contents($manifestFile), true);
            if (is_array($data) && !empty($data['version'])) {
                return $data['version'];
            }
        }

        foreach (glob($targetDir . '/*.php') as $phpFile) {
            $content = file_get_contents($phpFile);
            if (preg_match('/Version:\s*([\d\.]+)/i', $content, $m)) {
                return $m[1];
            }
        }

        return '1.0.0';
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

        $topDirs = glob($tmpExtract . '*', GLOB_ONLYDIR);
        $sourceDir = (count($topDirs) === 1) ? $topDirs[0] : $tmpExtract;
        if (!file_exists($sourceDir . '/index.php') || !file_exists($sourceDir . '/VERSION')) {
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL invalid package structure');
            return false;
        }

        $backupPath = $this->backup->backupCore();
        if ($backupPath === null) {
            error_log('BB CORE FAIL: backup failed, aborting update');
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $root = rtrim(__DIR__ . '/../', '/');

        try {
            $this->backup->copyRecursive($sourceDir, $root);
        } catch (\Throwable $e) {
            if ($backupPath !== null) {
                $this->backup->restoreCoreBackup($backupPath);
            }
            $this->backup->deleteRecursive($tmpExtract);
            error_log('BB CORE FAIL copy error: ' . $e->getMessage());
            return false;
        }
        $this->backup->deleteRecursive($tmpExtract);

        foreach (glob($root . '/bulletinbored-core-*', GLOB_ONLYDIR) as $nested) {
            foreach (glob($nested . '/*') as $item) {
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

        $this->removeInstallerScripts($root);
        error_log('BB CORE extracted');

        $this->setVersion('core', 'core', $tag);
        $versionFile = __DIR__ . '/../VERSION';
        if (is_writable($versionFile)) {
            file_put_contents($versionFile, $tag);
        }

        $this->updateVersionInConfig($tag);

        $this->fetcher->clearCache();
        return true;
    }

    private function updateVersionInConfig(string $tag): void
    {
        $configJsonPath = __DIR__ . '/../config.json';
        $legacyConfigPath = __DIR__ . '/../config.php';
        $version = ltrim($tag, 'v');

        if (file_exists($configJsonPath) && is_writable($configJsonPath)) {
            $config = json_decode(file_get_contents($configJsonPath), true);
            if (is_array($config)) {
                $config['version'] = $version;
                file_put_contents($configJsonPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } elseif (file_exists($legacyConfigPath) && is_writable($legacyConfigPath)) {
            $content = file_get_contents($legacyConfigPath);
            $content = preg_replace(
                '/\$config\[\'version\'\]\s*=\s*\'[^\']*\';/',
                "\$config['version'] = '{$version}';",
                $content
            );
            if ($content !== null) {
                file_put_contents($legacyConfigPath, $content);
            }
        }
    }

    public function applyExtensionUpdate(string $type, string $name, string $tag, ?string $repoUrl = null): bool
    {
        if (!$repoUrl) {
            $catalogPath = __DIR__ . '/../data/catalog.json';
            if (file_exists($catalogPath)) {
                $catalog = json_decode(file_get_contents($catalogPath), true);
                $key = strtolower($name);
                foreach ($catalog as $item) {
                    if (strtolower($item['name'] ?? '') === $key && strtolower($item['type'] ?? '') === $type) {
                        $repoUrl = $item['repo'] ?? null;
                        break;
                    }
                }
            }
        }

        if (!$repoUrl || !preg_match('#github\.com/([^/]+)/([^/]+)$#i', $repoUrl, $m)) {
            return false;
        }

        $zipUrl = 'https://github.com/' . rawurlencode($m[1]) . '/' . rawurlencode($m[2]) . '/archive/refs/tags/' . rawurlencode($tag) . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'bbext') . '.zip';
        $data = $this->fetcher->httpGet($zipUrl, 30);
        if ($data === null) {
            return false;
        }
        file_put_contents($tmpZip, $data);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            return false;
        }

        if ($type === 'plugin') {
            $extractTo = rtrim(__DIR__ . '/../plugins', '/') . '/';
        } else {
            $extractTo = rtrim(__DIR__ . '/../themes', '/') . '/';
        }

        require_once __DIR__ . '/repo_install.php';

        $tmpExtract = $extractTo . '_update_' . uniqid() . '/';
        mkdir($tmpExtract, 0755, true);
        $ok = extract_zip($tmpZip, $tmpExtract);
        $zip->close();
        @unlink($tmpZip);

        if (!$ok) {
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $src = $tmpExtract;
        $topFolders = glob($tmpExtract . '*', GLOB_ONLYDIR);
        if (count($topFolders) === 1) {
            $src = $topFolders[0];
        }

        $pluginDir = $extractTo . $name;
        $oldDir = $extractTo . '_old_' . $name . '_' . uniqid();
        if (is_dir($pluginDir)) {
            if (!@rename($pluginDir, $oldDir)) {
                $this->backup->deleteRecursive($tmpExtract);
                return false;
            }
        }

        if (!@rename($src, $pluginDir)) {
            if (is_dir($oldDir)) {
                @rename($oldDir, $pluginDir);
            }
            $this->backup->deleteRecursive($tmpExtract);
            return false;
        }

        $this->backup->deleteRecursive($oldDir);
        $this->backup->deleteRecursive($tmpExtract);

        $targetDir = $extractTo . $name;
        if (!is_dir($targetDir) || !glob($targetDir . '/*')) {
            return false;
        }

        $this->syncVersionMetadata($targetDir, $tag);

        $this->setVersion($type . 's', $name, $tag);
        $this->fetcher->clearCache();
        return true;
    }

    private function syncVersionMetadata(string $targetDir, string $tag): void
    {
        $version = ltrim($tag, 'v');
        $manifestFile = $targetDir . '/manifest.json';
        if (file_exists($manifestFile)) {
            $pm = json_decode(file_get_contents($manifestFile), true);
            if (is_array($pm)) {
                $pm['version'] = $version;
                file_put_contents($manifestFile, json_encode($pm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } else {
            file_put_contents($manifestFile, json_encode(['version' => $version], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        foreach (glob($targetDir . '/*.php') as $phpFile) {
            $content = file_get_contents($phpFile);
            if (preg_match('/Version:\s*([\d\.]+)/i', $content)) {
                $content = preg_replace('/(Version:\s*)[\d\.]+/i', '$1' . $version, $content);
                file_put_contents($phpFile, $content);
                break;
            }
        }
    }

    private function removeInstallerScripts(string $root): void
    {
        if (!file_exists($root . '/config.json')) {
            return;
        }
        $installerScripts = [
            $root . '/install.php',
            $root . '/install2.php',
            $root . '/install3.php',
            $root . '/api/install.php',
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
