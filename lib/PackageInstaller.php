<?php

/**
 * PackageInstaller — unified package installation lifecycle.
 *
 * Shared by PluginManager, ThemeManager, and UpdateManager.
 *
 * Lifecycle:
 *   1. extractToTemp() — safe ZIP extraction to temp directory
 *   2. verifyManifest() — validate manifest.json
 *   3. verifyFiles() — check declared files match actual files
 *   4. flatten() — normalize nested directory structure
 *   5. detectName() — determine package name from manifest
 *   6. commit() — atomic rename to final location
 *   7. rollback() — delete temp directory on failure
 */
class PackageInstaller
{
    private string $packagesDir;
    private string $verifyConfigKey;

    public function __construct(string $packagesDir, string $verifyConfigKey)
    {
        $this->packagesDir = rtrim($packagesDir, '/');
        $this->verifyConfigKey = $verifyConfigKey;
    }

    /**
     * Install a ZIP package to the given directory.
     *
     * @param string $zipPath Path to the ZIP file
     * @param string $targetDir Final destination directory
     * @param callable|null $verifyCallback Optional callback for additional verification
     * @return array ['success' => bool, 'message' => string]
     */
    public function install(string $zipPath, string $targetDir, ?callable $verifyCallback = null): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'message' => 'File not found'];
        }

        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'The PHP zip extension is not enabled'];
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            return ['success' => false, 'message' => 'Invalid ZIP file'];
        }

        $tmpDir = $this->packagesDir . '/.install-tmp-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0755, true)) {
            $zip->close();
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Cannot create temporary directory'];
        }

        $ok = $this->safeExtractZip($zip, $tmpDir);
        $zip->close();
        @unlink($zipPath);

        if (!$ok) {
            $this->deleteDir($tmpDir);
            return ['success' => false, 'message' => 'Invalid ZIP entries'];
        }

        if ($this->verifyFilesEnabled()) {
            $check = $this->verifyInstalledFiles($tmpDir);
            if (!$check['success']) {
                $this->deleteDir($tmpDir);
                return $check;
            }
        }

        $this->flattenNestedDir($tmpDir);

        if ($verifyCallback !== null) {
            $verifyResult = $verifyCallback($tmpDir);
            if ($verifyResult !== null) {
                $this->deleteDir($tmpDir);
                return $verifyResult;
            }
        }

        if (@rename($tmpDir, $targetDir) === false) {
            $this->deleteDir($tmpDir);
            return ['success' => false, 'message' => 'Failed to move package to final location'];
        }

        return ['success' => true, 'message' => 'Package installed'];
    }

    /**
     * Safe ZIP extraction with Zip Slip protection.
     */
    public function safeExtractZip(ZipArchive $zip, string $dest): bool
    {
        $dest = rtrim(str_replace('\\', '/', $dest), '/');
        $realDest = realpath($dest);
        if ($realDest === false) {
            return false;
        }
        $realDest = str_replace('\\', '/', $realDest);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $name = str_replace('\\', '/', $name);
            if (str_starts_with($name, '/') || str_contains($name, '..')) {
                return false;
            }

            $target = $dest . '/' . $name;
            if (!str_starts_with($target, $realDest . '/')) {
                return false;
            }
        }

        return $zip->extractTo($dest);
    }

    /**
     * Verify that every extracted folder honours the "files" list in its manifest.json.
     */
    public function verifyInstalledFiles(string $targetDir): array
    {
        $pending = [];
        foreach (glob($targetDir . '/*', GLOB_ONLYDIR) as $dir) {
            $manifestFile = $dir . '/manifest.json';
            if (!file_exists($manifestFile)) {
                continue;
            }
            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!is_array($manifest) || empty($manifest['files']) || !is_array($manifest['files'])) {
                continue;
            }
            $expected = array_map(fn($f) => ltrim(str_replace('\\', '/', (string)$f), '/'), $manifest['files']);

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
                    'package' => basename($dir),
                    'missing' => array_values($missing),
                    'extra' => array_values($extra),
                ];
            }
        }

        if (!empty($pending)) {
            $detail = '';
            foreach ($pending as $p) {
                $detail .= "\n- " . $p['package'];
                if (!empty($p['missing'])) {
                    $detail .= "\n  missing: " . implode(', ', $p['missing']);
                }
                if (!empty($p['extra'])) {
                    $detail .= "\n  undeclared: " . implode(', ', $p['extra']);
                }
            }
            return ['success' => false, 'message' => 'Package integrity check failed:' . $detail];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    /**
     * Flatten nested directory structure (e.g. package-1.0.0/file → file).
     */
    public function flattenNestedDir(string $targetDir): void
    {
        $entries = glob($targetDir . '/*', GLOB_ONLYDIR);
        if (count($entries) !== 1) {
            return;
        }
        $nested = $entries[0];
        foreach (glob($nested . '/*') as $item) {
            $destItem = $targetDir . '/' . basename($item);
            if (file_exists($destItem)) {
                continue;
            }
            rename($item, $destItem);
        }
        @rmdir($nested);
    }

    /**
     * Detect package name from manifest.json.
     */
    public function detectName(string $dir): ?string
    {
        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $subdir) {
            if (file_exists($subdir . '/manifest.json')) {
                return basename($subdir);
            }
        }
        $entries = glob($dir . '/*', GLOB_ONLYDIR);
        if (count($entries) === 1) {
            return basename($entries[0]);
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
     * Recursively delete a directory.
     */
    public function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $item) {
            is_dir($item) ? $this->deleteDir($item) : unlink($item);
        }
        rmdir($dir);
    }

    private function verifyFilesEnabled(): bool
    {
        $config = App::getInstance()->config;
        return !isset($config[$this->verifyConfigKey]) || $config[$this->verifyConfigKey] !== false;
    }
}
