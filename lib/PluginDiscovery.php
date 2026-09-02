<?php

class PluginDiscovery
{
    private string $pluginsDir;

    public function __construct(string $pluginsDir)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
    }

    public function getPluginsDir(): string
    {
        return $this->pluginsDir;
    }

    public function parseMetadata(string $file, ?string $folder = null): array
    {
        $content = $this->readFileRetry($file);
        if ($content === null) {
            $content = '';
        }
        $meta = [
            'name' => $folder ?? basename($file, '.php'),
            'version' => '1.0.0',
            'author' => '',
            'description' => '',
            'file' => $file,
            'folder' => $folder,
            'type' => 'file',
        ];

        if (preg_match('/Plugin Name:\s*(.+)/i', $content, $m)) {
            $meta['name'] = trim($m[1]);
        }
        if (preg_match('/Version:\s*([\d\.]+)/i', $content, $m)) {
            $meta['version'] = trim($m[1]);
        }
        if (preg_match('/Author:\s*(.+)/i', $content, $m)) {
            $meta['author'] = trim($m[1]);
        }
        if (preg_match('/Description:\s*(.+)/i', $content, $m)) {
            $meta['description'] = trim($m[1]);
        }

        return $meta;
    }

    public function parseManifest(string $dir): ?array
    {
        $manifestFile = $dir . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return null;
        }
        $raw = $this->readFileRetry($manifestFile);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public function readFileRetry(string $path): ?string
    {
        for ($i = 0; $i < 8; $i++) {
            $content = @file_get_contents($path);
            if ($content !== false) {
                return $content;
            }
            clearstatcache(true, $path);
            usleep(150000);
        }
        return null;
    }

    public function discover(): array
    {
        $plugins = [];
        if (!is_dir($this->pluginsDir)) {
            return $plugins;
        }

        foreach (glob($this->pluginsDir . '/*.php') as $file) {
            if (basename($file) === 'index.php') {
                $plugins['__legacy__'] = [
                    'name' => '__legacy__',
                    'file' => $file,
                    'folder' => null,
                    'type' => 'file',
                ];
                continue;
            }
            $meta = $this->parseMetadata($file);
            $key = strtolower($meta['name']);
            $plugins[$key] = $meta;
        }

        foreach (glob($this->pluginsDir . '/*', GLOB_ONLYDIR) as $dir) {
            $folder = basename($dir);
            $manifest = $this->parseManifest($dir);
            if ($manifest) {
                $bootstrap = $manifest['bootstrap'] ?? ($folder . '.php');
                $bootstrapPath = $dir . '/' . $bootstrap;
                if (!file_exists($bootstrapPath)) {
                    $bootstrapPath = $dir . '/' . $folder . '.php';
                }
                if (!file_exists($bootstrapPath)) {
                    $bootstrapPath = null;
                }
                $meta = [
                    'name' => $manifest['name'] ?? $folder,
                    'version' => $manifest['version'] ?? '1.0.0',
                    'author' => $manifest['author'] ?? '',
                    'description' => $manifest['description'] ?? '',
                    'file' => $bootstrapPath,
                    'folder' => $folder,
                    'type' => 'folder',
                ];
                $key = strtolower($meta['name']);
                $plugins[$key] = $meta;
            }
        }

        return $plugins;
    }

    public function resolveBootstrap(string $dir, string $folder): ?string
    {
        $manifest = $this->parseManifest($dir);
        $bootstrap = $manifest['bootstrap'] ?? ($folder . '.php');
        $bootstrapPath = $dir . '/' . $bootstrap;
        if (!file_exists($bootstrapPath)) {
            $bootstrapPath = $dir . '/' . $folder . '.php';
        }
        if (!file_exists($bootstrapPath)) {
            return null;
        }
        return $bootstrapPath;
    }
}
