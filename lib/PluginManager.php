<?php

class PluginManager
{
    private const DISABLED_BY_DEFAULT = ['hellobored'];

    private string $pluginsDir;
    private string $manifestPath;
    private array $plugins = [];
    private array $hooks = [];
    private array $manifest = [];
    private array $capturedHead = [];
    private ?string $capturedAdminHead = null;
    private ?string $capturedFrontendHead = null;

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

    private function parseMetadata(string $file, ?string $folder = null): array
    {
        $content = file_get_contents($file);
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

    private function parseManifest(string $dir): ?array
    {
        $manifestFile = $dir . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return null;
        }
        $data = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public function discover(): array
    {
        $this->plugins = [];
        if (!is_dir($this->pluginsDir)) {
            return $this->plugins;
        }

        foreach (glob($this->pluginsDir . '/*.php') as $file) {
            if (basename($file) === 'index.php') {
                $this->plugins['__legacy__'] = [
                    'name' => '__legacy__',
                    'file' => $file,
                    'folder' => null,
                    'type' => 'file',
                ];
                continue;
            }
            $meta = $this->parseMetadata($file);
            $key = strtolower($meta['name']);
            $meta['enabled'] = $this->manifest[$key]['enabled'] ?? !in_array($key, self::DISABLED_BY_DEFAULT, true);
            $this->plugins[$key] = $meta;
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
                $meta['enabled'] = $this->manifest[$key]['enabled'] ?? !in_array($key, self::DISABLED_BY_DEFAULT, true);
                $this->plugins[$key] = $meta;
            }
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

    public function loadTranslations(string $lang): void
    {
        foreach ($this->getAll() as $key => $plugin) {
            $scope = 'plugin:' . $key;
            $GLOBALS['i18n'][$scope] = [];
            if (empty($plugin['folder'])) {
                continue;
            }
            $langFile = $this->pluginsDir . '/' . $plugin['folder'] . '/lang/' . $lang . '.php';
            if (file_exists($langFile)) {
                $data = include $langFile;
                if (is_array($data)) {
                    $GLOBALS['i18n'][$scope] = $data;
                }
            }
        }
    }

    public function loadEnabled(): array
    {
        $loaded = [];
        foreach ($this->getEnabled() as $key => $plugin) {
            if (!empty($plugin['file']) && file_exists($plugin['file'])) {
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

    public function captureHook(string $event, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        ob_start();
        foreach ($this->hooks[$event] as $callback) {
            if (is_callable($callback)) {
                call_user_func_array($callback, $args);
            }
        }
        $this->capturedHead[] = ob_get_clean();
    }

    public function getCapturedHead(bool $admin = false): string
    {
        if ($admin) {
            if ($this->capturedAdminHead === null) {
                $this->capturedAdminHead = implode("\n", array_filter($this->capturedHead, fn($s) => str_starts_with($s, '<script') || str_starts_with($s, '<link')));
            }
            return $this->capturedAdminHead;
        }
        return implode("\n", array_filter($this->capturedHead, fn($s) => str_contains($s, 'editbored') || str_starts_with($s, '<script') || str_starts_with($s, '<link')));
    }

    public function getVersion(string $name): string
    {
        $plugin = $this->getByName($name);
        return $plugin['version'] ?? '1.0.0';
    }

    /**
     * Un-nest a plugin extracted under a single nested folder (e.g. the
     * <repo>-<ref>/ directory GitHub/GitLab ship inside their archives).
     *
     * Files from the nested folder are merged into $targetDir, overwriting
     * any existing counterpart. Once flattened, the now-empty (or duplicate)
     * nested folder is removed so it does not accumulate in production.
     */
    private function flattenNestedDir(string $targetDir): void
    {
        if (!is_dir($targetDir) || file_exists($targetDir . '/manifest.json')) {
            return;
        }

        $nested = null;
        foreach (glob($targetDir . '/*', GLOB_ONLYDIR) as $dir) {
            if (file_exists($dir . '/manifest.json')) {
                $nested = $dir;
                break;
            }
        }
        if ($nested === null || !is_dir($nested)) {
            return;
        }

        foreach (glob($nested . '/*') as $item) {
            $destItem = $targetDir . '/' . basename($item);
            if (is_dir($item)) {
                if (!is_dir($destItem)) {
                    @mkdir($destItem, 0755, true);
                }
                foreach (glob($item . '/*') as $child) {
                    $childDest = $destItem . '/' . basename($child);
                    if (file_exists($childDest)) {
                        if (is_dir($childDest)) {
                            $this->deleteDir($childDest);
                        } else {
                            @unlink($childDest);
                        }
                    }
                    @rename($child, $childDest) or copy($child, $childDest);
                }
            } else {
                if (file_exists($destItem)) {
                    @unlink($destItem);
                }
                @rename($item, $destItem) or copy($item, $destItem);
            }
        }

        $this->deleteDir($nested);
    }

    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'message' => 'File not found'];
        }

        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'The PHP zip extension is not enabled on this server. Enable it or extract the plugin manually into the plugins/ directory.'];
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

        // Normalise the extracted layout (see installFromRepo for details):
        // only "un-nest" when there is no manifest.json directly at the root.
        $this->flattenNestedDir($dest);

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

        $entry = $this->plugins[$key];
        if ($entry['folder']) {
            $dir = rtrim($this->pluginsDir, '/') . '/' . $entry['folder'];
            if (is_dir($dir)) {
                $this->deleteDir($dir);
                clearstatcache();
                if (is_dir($dir)) {
                    return ['success' => false, 'message' => 'Plugin directory could not be deleted. It may be in use by another process.'];
                }
            }
        } elseif (!empty($entry['file']) && file_exists($entry['file'])) {
            @unlink($entry['file']);
            clearstatcache();
            if (file_exists($entry['file'])) {
                return ['success' => false, 'message' => 'Plugin file could not be deleted. It may be in use by another process.'];
            }
        }

        unset($this->manifest[$key]);
        $this->saveManifest();
        $this->plugins = [];

        return ['success' => true, 'message' => 'Plugin deleted'];
    }

    public function removeMissing(): array
    {
        $this->discover();
        $removed = [];
        foreach ($this->plugins as $key => $plugin) {
            if ($plugin['folder']) {
                $dir = rtrim($this->pluginsDir, '/') . '/' . $plugin['folder'];
                $hasManifest = is_dir($dir) && file_exists($dir . '/manifest.json');
                if (!$hasManifest) {
                    unset($this->manifest[$key]);
                    $removed[] = $plugin['name'];
                    if (is_dir($dir)) {
                        $this->deleteDir($dir);
                    }
                }
            } elseif (!empty($plugin['file']) && !file_exists($plugin['file'])) {
                unset($this->manifest[$key]);
                $removed[] = $plugin['name'];
            }
        }
        $this->saveManifest();
        $this->plugins = [];
        return $removed;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
        if (is_dir($dir)) {
            exec('cmd /c rmdir /s /q ' . escapeshellarg($dir) . ' 2>&1', $out, $code);
            clearstatcache();
        }
    }

    public function installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array
    {
        $dest = rtrim($this->pluginsDir, '/') . '/';
        $repo = trim($repoUrl, '/');
        $repoName = basename(str_replace(['\\', '.git'], ['', ''], $repo));
        $targetDir = $dest . ($expectedName ?: $repoName);

        require_once __DIR__ . '/repo_install.php';
        $result = install_repo_package($repoUrl, $targetDir, $tag, $expectedName ?: $repoName);
        if (!$result['success']) {
            return $result;
        }

        // Normalise the extracted layout. GitHub/GitLab archives nest the
        // plugin under a single <repo>-<ref>/ folder, and some plugins also
        // ship multiple top-level folders (assets/, lang/, ...). We only need
        // to "un-nest" when the plugin files are NOT already at the target
        // root (i.e. there is no manifest.json directly inside targetDir).
        $this->flattenNestedDir($targetDir);

        $this->plugins = [];
        $this->discover();

        $manifest = $this->getByName($expectedName ?: $repoName);
        if (!$manifest || empty($manifest['file']) || !file_exists($manifest['file'])) {
            if (is_dir($targetDir)) {
                $this->deleteDir($targetDir);
            }
            return ['success' => false, 'message' => 'Installed package is not a valid plugin. Ensure the repository contains a valid manifest.json and bootstrap file.'];
        }

        return ['success' => true, 'message' => 'Plugin installed from repo', 'manifest' => $manifest];
    }
}
