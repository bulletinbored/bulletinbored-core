<?php

class UpdateManager
{
    private string $manifestPath;
    private array $manifest = [];
    private ?string $updateServer = null;

    public function __construct(string $manifestPath, ?string $updateServer = null)
    {
        $this->manifestPath = $manifestPath;
        $this->updateServer = $updateServer;
        $this->loadManifest();
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
        ThemeManager $themeManager
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

        foreach ($pluginManager->getAll() as $key => $plugin) {
            $remote = $this->fetchRemoteVersion('plugin', $key);
            $updateAvailable = $remote && version_compare($remote, $plugin['version'], '>');
            $results['plugins'][$key] = [
                'installed' => $plugin['version'],
                'remote' => $remote,
                'update_available' => $updateAvailable,
                'update_url' => $updateAvailable ? ($this->updateServer . '/plugin/' . rawurlencode($key) . '.zip') : null,
            ];
            $this->recordCheck('plugins', $key, $remote);
        }

        foreach ($themeManager->getAll() as $key => $theme) {
            $remote = $this->fetchRemoteVersion('theme', $key);
            $updateAvailable = $remote && version_compare($remote, ($theme['version'] ?? '1.0.0'), '>');
            $results['themes'][$key] = [
                'installed' => $theme['version'] ?? '1.0.0',
                'remote' => $remote,
                'update_available' => $updateAvailable,
                'update_url' => $updateAvailable ? ($this->updateServer . '/theme/' . rawurlencode($key) . '.zip') : null,
            ];
            $this->recordCheck('themes', $key, $remote);
        }

        return $results;
    }

    private function fetchRemoteVersion(string $type, ?string $name = null): ?string
    {
        if (empty($this->updateServer)) {
            return null;
        }

        $url = $this->updateServer . '/versions.json';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'bulletinbored-update-checker/1.0',
            ]
        ]);

        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        if ($type === 'core') {
            return $data['core']['version'] ?? null;
        }

        $section = $data[$type . 's'] ?? $data[$type] ?? [];
        if (is_array($section)) {
            return $section[$name]['version'] ?? null;
        }

        return null;
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
        return true;
    }

    public function getLockedExtensions(): array
    {
        return ['php', 'css', 'js', 'json', 'sql', 'html', 'md', 'txt', 'ico', 'gif', 'png', 'jpg', 'jpeg', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot'];
    }
}
