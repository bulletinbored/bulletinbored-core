<?php

class PluginManager
{
    private string $pluginsDir;
    private string $manifestPath;
    private array $plugins = [];
    private array $hooks = [];
    private array $manifest = [];

    public function __construct(string $pluginsDir, string $manifestPath)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
        $this->manifestPath = $manifestPath;
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

    private function parseMetadata(string $file): array
    {
        $content = file_get_contents($file);
        $meta = [
            'name' => basename($file, '.php'),
            'version' => '1.0.0',
            'author' => '',
            'description' => '',
            'file' => $file,
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

    public function discover(): array
    {
        $this->plugins = [];
        if (!is_dir($this->pluginsDir)) {
            return $this->plugins;
        }

        foreach (glob($this->pluginsDir . '/*.php') as $file) {
            $meta = $this->parseMetadata($file);
            $key = strtolower($meta['name']);
            $meta['enabled'] = $this->manifest[$key]['enabled'] ?? true;
            $this->plugins[$key] = $meta;
        }

        return $this->plugins;
    }

    public function getAll(): array
    {
        if (empty($this->plugins)) {
            $this->discover();
        }
        return $this->plugins;
    }

    public function getEnabled(): array
    {
        return array_filter($this->getAll(), fn($p) => !empty($p['enabled']));
    }

    public function getByName(string $name): ?array
    {
        $key = strtolower($name);
        return $this->getAll()[$key] ?? null;
    }

    public function isEnabled(string $name): bool
    {
        $plugin = $this->getByName($name);
        return $plugin ? !empty($plugin['enabled']) : false;
    }

    public function enable(string $name): bool
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return false;
        }
        $this->plugins[$key]['enabled'] = true;
        $this->manifest[$key] = $this->plugins[$key];
        $this->saveManifest();
        return true;
    }

    public function disable(string $name): bool
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return false;
        }
        $this->plugins[$key]['enabled'] = false;
        $this->manifest[$key] = $this->plugins[$key];
        $this->saveManifest();
        return true;
    }

    public function loadEnabled(): array
    {
        $loaded = [];
        foreach ($this->getEnabled() as $key => $plugin) {
            if (file_exists($plugin['file'])) {
                include $plugin['file'];
                $initFunction = $key . '_init';
                if (function_exists($initFunction)) {
                    $initFunction();
                }
                $loaded[] = $key;
            }
        }
        return $loaded;
    }

    public function addHook(string $event, callable $callback): void
    {
        $this->hooks[$event][] = $callback;
    }

    public function removeHook(string $event, callable $callback): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        foreach ($this->hooks[$event] as $i => $h) {
            if ($h === $callback) {
                unset($this->hooks[$event][$i]);
                break;
            }
        }
    }

    public function runHook(string $event, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        foreach ($this->hooks[$event] as $callback) {
            if (is_callable($callback)) {
                call_user_func_array($callback, $args);
            }
        }
    }

    public function getVersion(string $name): string
    {
        $plugin = $this->getByName($name);
        return $plugin['version'] ?? '1.0.0';
    }

    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'message' => 'File not found'];
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return ['success' => false, 'message' => 'Invalid ZIP file'];
        }

        $dest = rtrim($this->pluginsDir, '/') . '/';
        $zip->extractTo($dest);
        $zip->close();
        @unlink($zipPath);

        $this->plugins = [];
        $this->discover();

        return ['success' => true, 'message' => 'Plugin installed'];
    }

    public function delete(string $name): array
    {
        $key = strtolower($name);
        $this->discover();

        if (!isset($this->plugins[$key])) {
            return ['success' => false, 'message' => 'Plugin not found'];
        }

        $file = $this->plugins[$key]['file'];
        if (file_exists($file)) {
            @unlink($file);
        }

        unset($this->manifest[$key]);
        $this->saveManifest();
        $this->plugins = [];

        return ['success' => true, 'message' => 'Plugin deleted'];
    }
}
