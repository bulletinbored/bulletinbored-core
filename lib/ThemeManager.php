<?php

class ThemeManager
{
    private string $themesDir;
    private string $manifestPath;
    private string $activeTheme;
    private array $themes = [];
    private array $manifest = [];

    public function __construct(string $themesDir, string $manifestPath, string $defaultTheme)
    {
        $this->themesDir = rtrim($themesDir, '/');
        $this->manifestPath = $manifestPath;
        $this->activeTheme = $defaultTheme;
        $this->loadManifest();
    }

    public function loadTranslations(string $lang): void
    {
        foreach ($this->getAll() as $name => $theme) {
            $scope = 'theme:' . strtolower($name);
            $GLOBALS['i18n'][$scope] = [];
            $langFile = $this->themesDir . '/' . $name . '/lang/' . $lang . '.php';
            if (file_exists($langFile)) {
                $data = include $langFile;
                if (is_array($data)) {
                    $GLOBALS['i18n'][$scope] = $data;
                }
            }
        }
    }

    private function loadManifest(): void
    {
        if (file_exists($this->manifestPath)) {
            $data = json_decode(file_get_contents($this->manifestPath), true);
            $this->manifest = is_array($data) ? $data : [];
            if (!empty($this->manifest['active'])) {
                $this->activeTheme = $this->manifest['active'];
            }
        }
    }

    private function saveManifest(): void
    {
        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->manifest['active'] = $this->activeTheme;
        file_put_contents($this->manifestPath, json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function parseThemeManifest(string $themeDir, string $name): array
    {
        $meta = [
            'name' => $name,
            'version' => '1.0.0',
            'author' => '',
            'description' => '',
            'dir' => $themeDir,
            'css_file' => $themeDir . '/style.css',
        ];

        $manifestFile = $themeDir . '/manifest.json';
        if (file_exists($manifestFile)) {
            $data = json_decode(file_get_contents($manifestFile), true);
            if (is_array($data)) {
                $meta = array_merge($meta, $data);
            }
        }

        return $meta;
    }

    public function discover(): array
    {
        $this->themes = [];
        if (!is_dir($this->themesDir)) {
            return $this->themes;
        }

        foreach (glob($this->themesDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (!file_exists($dir . '/style.css')) {
                continue;
            }
            $this->themes[$name] = $this->parseThemeManifest($dir, $name);
            $this->themes[$name]['active'] = ($name === $this->activeTheme);
        }

        return $this->themes;
    }

    public function getAll(): array
    {
        if (empty($this->themes)) {
            $this->discover();
        }
        return $this->themes;
    }

    public function getActive(): string
    {
        $this->discover();
        if (!isset($this->themes[$this->activeTheme])) {
            $this->activeTheme = '';
            foreach ($this->themes as $name => $theme) {
                $this->activeTheme = $name;
                break;
            }
            $this->saveManifest();
        }
        return $this->activeTheme;
    }

    public function getActiveMeta(): ?array
    {
        $active = $this->getActive();
        return $this->themes[$active] ?? null;
    }

    public function activate(string $name): bool
    {
        $this->discover();
        if (!isset($this->themes[$name])) {
            return false;
        }
        $this->activeTheme = $name;
        foreach ($this->themes as $k => $theme) {
            $this->themes[$k]['active'] = ($k === $name);
        }
        $this->saveManifest();
        return true;
    }

    public function getCssUrl(?string $name = null): string
    {
        if ($name === null) {
            $name = $this->getActive();
        }
        $this->discover();
        if (!isset($this->themes[$name]) || !file_exists($this->themes[$name]['css_file'])) {
            $name = '';
            foreach ($this->themes as $n => $t) {
                if (file_exists($t['css_file'])) {
                    $name = $n;
                    break;
                }
            }
        }
        return base_url() . '/themes/' . rawurlencode($name ?: 'freshbored') . '/style.css';
    }

    public function getCssPath(?string $name = null): string
    {
        if ($name === null) {
            $name = $this->getActive();
        }
        $this->discover();
        if (!isset($this->themes[$name])) {
            return $this->themesDir . '/freshbored/style.css';
        }
        return $this->themes[$name]['css_file'];
    }

    public function getVersion(string $name): string
    {
        $this->discover();
        return $this->themes[$name]['version'] ?? '1.0.0';
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

        $dest = rtrim($this->themesDir, '/') . '/';
        $zip->extractTo($dest);
        $zip->close();
        @unlink($zipPath);

        $this->themes = [];
        $this->discover();

        return ['success' => true, 'message' => 'Theme installed'];
    }

    public function delete(string $name): array
    {
        $this->discover();
        if (!isset($this->themes[$name])) {
            return ['success' => false, 'message' => 'Theme not found'];
        }

        if ($name === 'freshbored') {
            return ['success' => false, 'message' => 'Cannot delete default theme'];
        }

        $dir = $this->themes[$name]['dir'];
        $this->deleteDir($dir);

        if ($this->activeTheme === $name) {
            $this->activeTheme = '';
            foreach ($this->themes as $n => $t) {
                if ($n !== 'freshbored' && $n !== $name && file_exists($t['css_file'])) {
                    $this->activeTheme = $n;
                    break;
                }
            }
            if (empty($this->activeTheme)) {
                $this->activeTheme = 'freshbored';
            }
            $this->saveManifest();
        }

        $this->themes = [];
        return ['success' => true, 'message' => 'Theme deleted'];
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
    }
}
