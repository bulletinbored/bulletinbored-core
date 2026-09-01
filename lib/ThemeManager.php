<?php

require_once __DIR__ . '/PackageInstaller.php';

class ThemeManager
{
    private string $themesDir;
    private string $manifestPath;
    private string $activeTheme;
    private array $themes = [];
    private array $manifest = [];
    private PackageInstaller $installer;

    public function __construct(string $themesDir, string $manifestPath, string $defaultTheme)
    {
        $this->themesDir = rtrim($themesDir, '/');
        $this->manifestPath = $manifestPath;
        $this->activeTheme = $defaultTheme;
        $this->loadManifest();
        $this->installer = new PackageInstaller($this->themesDir, 'theme_verify_files');
    }

    public function loadTranslations(string $lang): void
    {
        foreach ($this->getAll() as $name => $theme) {
            $scope = 'theme:' . strtolower($name);
            $GLOBALS['i18n'][$scope] = [];
            $langFile = $this->themesDir . '/' . $name . '/lang/' . $lang . '.json';
            if (file_exists($langFile)) {
                $GLOBALS['i18n'][$scope] = load_lang_file($langFile);
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
        $cssFile = $this->themes[$name]['css_file'] ?? null;
        $v = ($cssFile && file_exists($cssFile)) ? filemtime($cssFile) : time();
        return base_url() . '/themes/' . rawurlencode($name ?: 'freshbored') . '/style.css?v=' . $v;
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
        $themeName = $this->detectThemeNameFromZip($zipPath);
        if ($themeName === null) {
            return ['success' => false, 'message' => 'Cannot detect theme name from ZIP'];
        }

        $finalDir = $this->themesDir . '/' . $themeName;

        if (is_dir($finalDir)) {
            return ['success' => false, 'message' => "Theme already exists: {$themeName}"];
        }

        $result = $this->installer->install($zipPath, $finalDir, function ($tmpDir) {
            return $this->verifyInstalledFiles($tmpDir);
        });

        if ($result['success']) {
            $this->themes = [];
            $this->discover();
        }

        return $result;
    }

    /**
     * Detect theme name from a ZIP file without extracting.
     */
    private function detectThemeNameFromZip(string $zipPath): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return null;
        }

        $name = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            if (preg_match('#^([a-z0-9_-]+)/style\.css$#i', $entry, $m)) {
                $name = $m[1];
                break;
            }
        }
        $zip->close();

        if ($name === null) {
            $name = $this->detectNameFromZipContents($zipPath);
        }

        return $name;
    }

    /**
     * Fallback: extract to temp to detect name.
     */
    private function detectNameFromZipContents(string $zipPath): ?string
    {
        $tmpDir = $this->themesDir . '/.name-detect-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0755, true)) {
            return null;
        }

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($tmpDir);
        $zip->close();

        $name = $this->installer->detectName($tmpDir);
        $this->installer->deleteDir($tmpDir);
        return $name;
    }

    private function verifyFilesEnabled(): bool
    {
        global $config;
        return !isset($config['theme_verify_files']) || $config['theme_verify_files'] !== false;
    }

    private function detectThemeName(string $dir): ?string
    {
        if (file_exists($dir . '/style.css')) {
            return basename($dir);
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $subdir) {
            if (file_exists($subdir . '/style.css')) {
                return basename($subdir);
            }
        }
        if (file_exists($dir . '/manifest.json')) {
            $manifest = json_decode(file_get_contents($dir . '/manifest.json'), true);
            if (!empty($manifest['name'])) {
                return strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '-', $manifest['name'])));
            }
        }
        return null;
    }

    /**
     * Verify that every extracted theme folder honours the "files" list in its
     * manifest.json: no declared file missing, no undeclared file present.
     * Themes without a manifest.json or without a "files" key are skipped.
     */
    private function verifyInstalledFiles(string $targetDir): array
    {
        return $this->installer->verifyInstalledFiles($targetDir);
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
        if (is_dir($dir)) {
            $this->installer->deleteDir($dir);
            clearstatcache();
            if (is_dir($dir)) {
                return ['success' => false, 'message' => 'Theme directory could not be deleted. It may be in use by another process.'];
            }
        }

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

    public function removeMissing(): array
    {
        $this->discover();
        $removed = [];
        foreach ($this->themes as $name => $theme) {
            $hasStyle = is_dir($theme['dir']) && file_exists($theme['css_file']);
            if (!$hasStyle) {
                if ($this->activeTheme === $name) {
                    $this->activeTheme = 'freshbored';
                }
                unset($this->themes[$name]);
                $removed[] = $name;
                if (is_dir($theme['dir'])) {
                    $this->installer->deleteDir($theme['dir']);
                }
            }
        }
        $this->saveManifest();
        return $removed;
    }

    public function installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array
    {
        $dest = rtrim($this->themesDir, '/') . '/';
        $repo = trim($repoUrl, '/');
        $repoName = basename(str_replace(['\\', '.git'], ['', ''], $repo));
        $targetDir = $dest . ($expectedName ?: $repoName);

        if (is_dir($targetDir)) {
            $this->deleteDir($targetDir);
        }

        require_once __DIR__ . '/repo_install.php';
        $result = install_repo_package($repoUrl, $targetDir, $tag, $expectedName ?: $repoName);
        if (!$result['success']) {
            return $result;
        }

        $hasRootAsset = file_exists($targetDir . '/style.css') || file_exists($targetDir . '/manifest.json');
        if (is_dir($targetDir) && !$hasRootAsset) {
            $nested = null;
            foreach (glob($targetDir . '/*', GLOB_ONLYDIR) as $dir) {
                if (file_exists($dir . '/style.css') || file_exists($dir . '/manifest.json')) {
                    $nested = $dir;
                    break;
                }
            }
            if ($nested !== null && is_dir($nested)) {
                foreach (glob($nested . '/*') as $item) {
                    $base = basename($item);
                    $destItem = $targetDir . '/' . $base;
                    if (file_exists($destItem)) {
                        continue;
                    }
                    rename($item, $destItem);
                }
                @rmdir($nested);
            }
        }

        $name = $expectedName ?: $repoName;
        for ($i = 0; $i < 10; $i++) {
            $this->themes = [];
            $this->discover();
            if (!isset($this->themes[$name])) {
                foreach ($this->themes as $themeName => $theme) {
                    if ($theme['dir'] === $targetDir) {
                        $name = $themeName;
                        break;
                    }
                }
            }
            if (isset($this->themes[$name])) {
                break;
            }
            clearstatcache();
            usleep(200000);
        }

        if (!isset($this->themes[$name])) {
            if (is_dir($targetDir)) {
                $this->deleteDir($targetDir);
            }
            return ['success' => false, 'message' => 'Installed package is not a valid theme. Ensure the repository contains a valid style.css and optional manifest.json.'];
        }

        return ['success' => true, 'message' => 'Theme installed from repo', 'manifest' => $this->themes[$name]];
    }
}
