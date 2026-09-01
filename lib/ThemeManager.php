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
        if (!file_exists($zipPath)) {
            return ['success' => false, 'message' => 'File not found'];
        }

        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'The PHP zip extension is not enabled on this server. Enable it or extract the theme manually into the themes/ directory.'];
        }

        require_once __DIR__ . '/repo_install.php';

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return ['success' => false, 'message' => 'Invalid ZIP file'];
        }

        $tmpDir = $this->themesDir . '/.install-tmp-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0755, true)) {
            $zip->close();
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Cannot create temporary directory'];
        }

        $ok = extract_zip($zipPath, $tmpDir);
        $zip->close();
        @unlink($zipPath);

        if (!$ok) {
            $this->deleteDir($tmpDir);
            return ['success' => false, 'message' => 'Invalid ZIP entries (Zip Slip protection)'];
        }

        // Normalise the extracted layout: a theme ZIP may nest files under a
        // single <repo>-<ref>/ folder. Only "un-nest" when there is no
        // style.css (or manifest.json) directly at the target root.
        if (!file_exists($tmpDir . '/style.css') && !file_exists($tmpDir . '/manifest.json')) {
            $nested = null;
            foreach (glob($tmpDir . '*', GLOB_ONLYDIR) as $dir) {
                if (file_exists($dir . '/style.css') || file_exists($dir . '/manifest.json')) {
                    $nested = $dir;
                    break;
                }
            }
            if ($nested !== null && is_dir($nested)) {
                foreach (glob($nested . '/*') as $item) {
                    $base = basename($item);
                    $destItem = $tmpDir . '/' . $base;
                    if (file_exists($destItem)) {
                        continue;
                    }
                    rename($item, $destItem);
                }
                @rmdir($nested);
            }
        }

        $this->themes = [];
        $this->discover();

        if ($this->verifyFilesEnabled()) {
            $check = $this->verifyInstalledFiles($tmpDir);
            if (!$check['success']) {
                $this->deleteDir($tmpDir);
                return $check;
            }
        }

        $themeName = $this->detectThemeName($tmpDir);
        if ($themeName === null) {
            $this->deleteDir($tmpDir);
            return ['success' => false, 'message' => 'Cannot detect theme name'];
        }

        $finalDir = $this->themesDir . '/' . $themeName;
        if (@rename($tmpDir, $finalDir) === false) {
            $this->deleteDir($tmpDir);
            return ['success' => false, 'message' => 'Failed to move theme to final location'];
        }

        $this->themes = [];
        $this->discover();

        return ['success' => true, 'message' => 'Theme installed'];
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
    private function verifyInstalledFiles(?string $targetDir = null): array
    {
        $baseDir = $targetDir ?? $this->themesDir;
        $pending = [];
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) as $dir) {
            $manifestFile = $dir . '/manifest.json';
            if (!file_exists($manifestFile)) {
                continue;
            }
            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!is_array($manifest) || empty($manifest['files']) || !is_array($manifest['files'])) {
                continue;
            }
            $expected = array_map(function ($f) {
                return ltrim(str_replace('\\', '/', (string)$f), '/');
            }, $manifest['files']);

            // Collect all actual files below the theme folder.
            $actual = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
                if ($item->isFile()) {
                    $actual[] = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1)), '/');
                }
            }

            $missing = array_diff($expected, $actual);
            $extra = array_diff($actual, $expected);
            if (!empty($missing) || !empty($extra)) {
                $pending[] = [
                    'theme' => basename($dir),
                    'missing' => array_values($missing),
                    'extra' => array_values($extra),
                ];
            }
        }

        if (!empty($pending)) {
            $detail = '';
            foreach ($pending as $p) {
                $detail .= "\n- " . $p['theme'];
                if (!empty($p['missing'])) {
                    $detail .= "\n  missing: " . implode(', ', $p['missing']);
                }
                if (!empty($p['extra'])) {
                    $detail .= "\n  undeclared: " . implode(', ', $p['extra']);
                }
            }
            return ['success' => false, 'message' => 'Theme integrity check failed. The archive contains files not declared in manifest.json (or is missing declared files). This may indicate a tampered package:' . $detail . "\n\nYou can disable this check by setting \$config['theme_verify_files'] = false; in config.json."];
        }

        return ['success' => true, 'message' => 'ok'];
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
            $this->deleteDir($dir);
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
                    $this->deleteDir($theme['dir']);
                }
            }
        }
        $this->saveManifest();
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
            if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
                exec('takeown /f ' . escapeshellarg($dir) . ' /r /d y 2>nul');
                exec('icacls ' . escapeshellarg($dir) . ' /grant administrators:F /t 2>nul');
                exec('cmd /c rmdir /s /q ' . escapeshellarg($dir) . ' 2>nul');
            } else {
                exec('cmd /c rmdir /s /q ' . escapeshellarg($dir) . ' 2>&1', $out, $code);
            }
            clearstatcache();
        }
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
