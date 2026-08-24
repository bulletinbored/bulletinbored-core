<?php

class UpdateManager
{
    private string $manifestPath;
    private array $manifest = [];
    private ?string $updateServer = null;
    private ?string $updateMirror = null;
    private ?string $githubToken = null;
    private int $cacheTTL = 3600;

    public function __construct(string $manifestPath, ?string $updateServer = null, ?string $githubToken = null, ?string $updateMirror = null)
    {
        $this->manifestPath = $manifestPath;
        $this->updateServer = $updateServer;
        $this->githubToken = $githubToken;
        $this->updateMirror = rtrim($updateMirror ?? '', '/');
        $this->loadManifest();
    }

    private function cachePath(): string
    {
        return dirname($this->manifestPath) . '/update-cache.json';
    }

    private function loadCache(): array
    {
        $path = $this->cachePath();
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveCache(array $cache): void
    {
        file_put_contents($this->cachePath(), json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function getCachedVersion(string $type, ?string $name = null): ?string
    {
        $cache = $this->loadCache();
        $key = $type . ':' . ($name ?? 'core');
        if (isset($cache[$key]['version'], $cache[$key]['timestamp']) && (time() - (int)$cache[$key]['timestamp'] < $this->cacheTTL)) {
            return $cache[$key]['version'];
        }

        return null;
    }

    private function setCachedVersion(string $type, ?string $name, string $version): void
    {
        if ($version === '' || $version === null) {
            return;
        }
        $cache = $this->loadCache();
        $key = $type . ':' . ($name ?? 'core');
        $cache[$key] = [
            'version' => $version,
            'timestamp' => time(),
        ];
        $this->saveCache($cache);
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
        $results['core']['remote'] = $this->fetchRemoteVersion('core');
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
            $remote = $this->fetchRemoteVersion('plugin', $key, $repoUrl);
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
            $remote = $this->fetchRemoteVersion('theme', $key, $repoUrl);
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

    /**
     * HTTP GET helper that prefers cURL (reliable with HTTPS on Windows/XAMPP)
     * and falls back to file_get_contents when cURL is unavailable.
     */
    private function httpGet(string $url, int $timeout = 10, ?string $token = null): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'bulletinbored-update-checker/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            if ($token) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $token]);
            }
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0 || $body === false) {
                return null;
            }
            return $body;
        }

        $headers = [
            'timeout' => $timeout,
            'user_agent' => 'bulletinbored-update-checker/1.0',
        ];
        if ($token) {
            $headers['header'] = 'Authorization: token ' . $token;
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => $headers]));
        return $body === false ? null : $body;
    }

    private function fetchRemoteVersion(string $type, ?string $name = null, ?string $repoUrl = null): ?string
    {
        $cached = $this->getCachedVersion($type, $name);
        if ($cached !== null) {
            return $cached;
        }

        $version = null;

        // Primary source: a static mirror (versions.json) to avoid GitHub API
        // rate limits. One request per check instead of one per extension.
        if ($this->updateMirror) {
            $json = $this->httpGet($this->updateMirror . '/versions.json', 10);
            if ($json !== null) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    if ($type === 'core') {
                        $version = $data['core']['version'] ?? null;
                    } else {
                        $section = $data[$type . 's'] ?? $data[$type] ?? [];
                        $key = is_string($name) ? strtolower($name) : null;
                        if ($key !== null && isset($section[$key]['version'])) {
                            $version = $section[$key]['version'];
                        }
                    }
                }
            }
        }

        // Fallback: GitHub releases/latest API (needs a repo URL).
        if ($version === null && !$repoUrl && !empty($this->updateServer)
            && preg_match('#github\.com/([^/]+)/([^/]+)#i', $this->updateServer, $m)) {
            $repoUrl = 'https://github.com/' . $m[1] . '/' . $m[2];
        }
        if ($version === null && $repoUrl && preg_match('#(?:https?://)?github\.com/([^/]+)/([^/]+)(?:/|$)#i', $repoUrl, $m)) {
            $apiUrl = 'https://api.github.com/repos/' . rawurlencode($m[1]) . '/' . rawurlencode($m[2]) . '/releases/latest';
            $json = $this->httpGet($apiUrl, 10, $this->githubToken);
            if ($json) {
                $release = json_decode($json, true);
                if (is_array($release) && !empty($release['tag_name'])) {
                    $version = ltrim($release['tag_name'], 'v');
                }
            }
        }

        if ($version !== null) {
            $this->setCachedVersion($type, $name, $version);
        }

        return $version;
    }

    public function applyUpdate(string $type, string $name, string $zipPath): bool
    {
        if (!file_exists($zipPath)) {
            return false;
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return false;
        }

        $extractTo = __DIR__ . '/../';
        $zip->extractTo($extractTo);
        $zip->close();
        @unlink($zipPath);

        $version = '1.0.0';
        if ($type === 'plugin') {
            $pm = new PluginManager(__DIR__ . '/../plugins', __DIR__ . '/../data/plugins.json');
            $version = $pm->getVersion($name);
        } elseif ($type === 'theme') {
            $tm = new ThemeManager(__DIR__ . '/../themes', __DIR__ . '/../data/themes.json', 'freshbored');
            $version = $tm->getVersion($name);
        }

        $this->setVersion($type, $name, $version);
        $this->clearCache();
        return true;
    }

    public function applyCoreUpdate(string $tag): bool
    {
        $zipUrl = 'https://github.com/bulletinbored/bulletinbored-core/archive/refs/tags/' . rawurlencode($tag) . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'bbcore') . '.zip';
        error_log('BB CORE START tag=' . $tag . ' zip=' . $zipUrl);
        $data = $this->httpGet($zipUrl, 30);
        if ($data === null) {
            error_log('BB CORE FAIL download');
            return false;
        }
        file_put_contents($tmpZip, $data);
        error_log('BB CORE downloaded size=' . strlen($data));

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            error_log('BB CORE FAIL zip open');
            @unlink($tmpZip);
            return false;
        }

        $extractTo = __DIR__ . '/../';
        $zip->extractTo($extractTo);
        $zip->close();
        @unlink($tmpZip);
        error_log('BB CORE extracted');

        $topFolder = glob($extractTo . 'bulletinbored-core-*', GLOB_ONLYDIR);
        if (empty($topFolder)) {
            error_log('BB CORE FAIL no top folder');
            return false;
        }
        error_log('BB CORE topFolder=' . $topFolder[0]);

        $src = $topFolder[0];
        $items = @scandir($src);
        if ($items === false) {
            error_log('BB CORE FAIL scandir');
            return false;
        }
        error_log('BB CORE items=' . count($items));

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $src . '/' . $item;
            $targetPath = $extractTo . $item;
            if (is_dir($srcPath)) {
                if (!is_dir($targetPath)) {
                    if (!rename($srcPath, $targetPath)) {
                        error_log('BB CORE FAIL rename dir ' . $item);
                        $this->deleteRecursive($src);
                        return false;
                    }
                } else {
                    $this->copyRecursive($srcPath, $targetPath);
                    if (basename($srcPath) !== '.kilo' && !$this->deleteRecursive($srcPath)) {
                        error_log('BB CORE FAIL delete dir ' . $item);
                        $this->deleteRecursive($src);
                        return false;
                    }
                }
            } elseif (is_file($srcPath)) {
                if (!file_exists($targetPath)) {
                    if (!rename($srcPath, $targetPath)) {
                        error_log('BB CORE FAIL rename file ' . $item);
                        $this->deleteRecursive($src);
                        return false;
                    }
                } else {
                    $this->copyRecursive($srcPath, $targetPath);
                    if (basename($srcPath) !== '.kilo') {
                        $this->deleteRecursive($srcPath);
                    }
                }
            }
        }
        $this->deleteRecursive($src);
        error_log('BB CORE copied all items');
        error_log('BB CORE cleaned src');

        $this->setVersion('core', 'core', $tag);
        $versionFile = __DIR__ . '/../VERSION';
        if (is_writable($versionFile)) {
            file_put_contents($versionFile, $tag);
        }

        $configJsonPath = __DIR__ . '/../config.json';
        $legacyConfigPath = __DIR__ . '/../config.php';
        if (file_exists($configJsonPath) && is_writable($configJsonPath)) {
            $config = json_decode(file_get_contents($configJsonPath), true);
            if (is_array($config)) {
                $config['version'] = ltrim($tag, 'v');
                file_put_contents($configJsonPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } elseif (file_exists($legacyConfigPath) && is_writable($legacyConfigPath)) {
            $content = file_get_contents($legacyConfigPath);
            $content = preg_replace(
                <<<'REGEX'
                /\$config\['version'\]\s*=\s*\'[^\']*\';/
                REGEX
                ,
                "\$config['version'] = '" . ltrim($tag, 'v') . "';",
                $content
            );
            if ($content !== null) {
                file_put_contents($legacyConfigPath, $content);
            }
        }

        $this->clearCache();
        return true;
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
        $data = $this->httpGet($zipUrl, 30);
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

        $tmpExtract = $extractTo . '_update_' . uniqid() . '/';
        mkdir($tmpExtract, 0755, true);
        $zip->extractTo($tmpExtract);
        $zip->close();
        @unlink($tmpZip);

        $src = $tmpExtract;
        $topFolders = glob($tmpExtract . '*', GLOB_ONLYDIR);
        if (count($topFolders) === 1) {
            $src = $topFolders[0];
        }

        $pluginDir = $extractTo . $name;
        $oldDir = $extractTo . '_old_' . $name . '_' . uniqid();
        if (is_dir($pluginDir)) {
            if (!@rename($pluginDir, $oldDir)) {
                $this->deleteRecursive($tmpExtract);
                return false;
            }
        }

        if (!@rename($src, $pluginDir)) {
            if (is_dir($oldDir)) {
                @rename($oldDir, $pluginDir);
            }
            $this->deleteRecursive($tmpExtract);
            return false;
        }

        $this->deleteRecursive($oldDir);
        $this->deleteRecursive($tmpExtract);

        $targetDir = $extractTo . $name;
        if (!is_dir($targetDir) || !glob($targetDir . '/*')) {
            return false;
        }

        // Keep the installed version in sync with the applied tag, regardless of
        // the "Version:" declared inside the package files.
        $manifestFile = $targetDir . '/manifest.json';
        if (file_exists($manifestFile)) {
            $pm = json_decode(file_get_contents($manifestFile), true);
            if (is_array($pm)) {
                $pm['version'] = ltrim($tag, 'v');
                file_put_contents($manifestFile, json_encode($pm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } else {
            file_put_contents($manifestFile, json_encode(['version' => ltrim($tag, 'v')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        foreach (glob($targetDir . '/*.php') as $phpFile) {
            $content = file_get_contents($phpFile);
            if (preg_match('/Version:\s*([\d\.]+)/i', $content)) {
                $content = preg_replace('/(Version:\s*)[\d\.]+/i', '$1' . ltrim($tag, 'v'), $content);
                file_put_contents($phpFile, $content);
                break;
            }
        }

        $this->setVersion($type . 's', $name, $tag);
        $this->clearCache();
        return true;
    }

    private function copyRecursive(string $src, string $dst): void
    {
        if (is_dir($src)) {
            if (!is_dir($dst)) {
                if (file_exists($dst)) {
                    @unlink($dst);
                }
                mkdir($dst, 0755, true);
            }
            $items = glob($src . '/*');
            foreach ($items as $item) {
                $basename = basename($item);
                $target = $dst . '/' . $basename;
                if (is_dir($item)) {
                    $this->copyRecursive($item, $target);
                } elseif (is_file($item)) {
                    copy($item, $target);
                }
            }
        } elseif (is_file($src)) {
            if (is_dir($dst)) {
                $this->deleteRecursive($dst);
            }
            copy($src, $dst);
        }
    }

    private function deleteRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return @unlink($dir);
        }
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }

    private function clearCache(): void
    {
        @unlink($this->cachePath());
    }

    public function getRemoteVersion(string $type, string $name, ?string $repoUrl = null): ?string
    {
        return $this->fetchRemoteVersion($type, $name, $repoUrl);
    }

    public function getLockedExtensions(): array
    {
        return ['php', 'css', 'js', 'json', 'sql', 'html', 'md', 'txt', 'ico', 'gif', 'png', 'jpg', 'jpeg', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot'];
    }
}
