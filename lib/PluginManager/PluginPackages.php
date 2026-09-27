<?php

/**
 * PluginPackages — install, update, uninstall and delete operations for
 * PluginManager.
 */
trait PluginPackages
{
    public function uninstall(string $name, bool $rollbackMigrations = false): array
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return array('success' => false, 'message' => 'Plugin not found');
        }
        $entry = $this->plugins[$key];

        $this->disable($name);

        $this->runHook('plugin_uninstalling', $key, $entry);

        $this->callLifecycle($entry, 'on_uninstall');

        $dir = null;
        $file = null;
        if (!empty($entry['folder'])) {
            $dir = $this->pluginsDir . '/' . $entry['folder'];
        } else {
            $file = !empty($entry['file']) ? $entry['file'] : ($this->pluginsDir . '/' . $key . '.php');
        }

        if ($dir !== null && is_dir($dir)) {
            $this->installer->deleteDir($dir);
            clearstatcache();
            if (is_dir($dir)) {
                return ['success' => false, 'message' => 'Plugin directory could not be deleted. It may be in use by another process.'];
            }
        } elseif ($file !== null && file_exists($file)) {
            @unlink($file);
            clearstatcache();
            if (file_exists($file)) {
                return ['success' => false, 'message' => 'Plugin file could not be deleted. It may be in use by another process.'];
            }
        }

        $this->callLifecycle($entry, 'cleanup');

        if ($rollbackMigrations) {
            $this->callLifecycle($entry, 'migration_rollback');
        }

        $this->removeInstalledRecord($key);

        unset($this->manifest[$key]);
        unset($this->plugins[$key]);
        $this->saveManifest();

        $this->runHook('plugin_uninstalled', $key);

        return array('success' => true, 'message' => 'Plugin uninstalled: ' . $key);
    }

    /**
     * Run a plugin lifecycle hook (if defined). Returns false when the hook
     * throws, so callers can roll back; the error is logged here.
     */
    private function callLifecycle(array $entry, string $hookName): bool
    {
        $file = $entry['file'] ?? null;
        if (!$file || !file_exists($file)) {
            return true;
        }
        $key = strtolower($entry['name'] ?? '');
        $fn = $key . '_' . $hookName;
        if (!function_exists($fn)) {
            return true;
        }
        try {
            $fn();
            return true;
        } catch (\Throwable $e) {
            error_log("Plugin '{$key}' lifecycle hook '{$hookName}' failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Read the installed.json record for a plugin (or null).
     */
    private function readInstalledRecord(string $key): ?array
    {
        $path = dirname($this->manifestPath) . '/installed.json';
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || !isset($data['plugins'][$key]) || !is_array($data['plugins'][$key])) {
            return null;
        }
        return $data['plugins'][$key];
    }

    /**
     * Restore (or remove) the installed.json record for a plugin after a
     * failed install/update, keeping the metadata consistent with the files.
     */
    private function restoreInstalledRecord(string $key, ?array $record): void
    {
        $path = dirname($this->manifestPath) . '/installed.json';
        if (!file_exists($path)) {
            return;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return;
        }
        if (!isset($data['plugins']) || !is_array($data['plugins'])) {
            $data['plugins'] = [];
        }
        if ($record === null) {
            unset($data['plugins'][$key]);
        } else {
            $data['plugins'][$key] = $record;
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function removeInstalledRecord(string $key): void
    {
        $installedPath = $this->manifestPath;
        $installedDir = dirname($installedPath);
        $candidates = [
            $installedDir . '/installed.json',
            $installedDir . '/../data/installed.json',
        ];
        foreach ($candidates as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data) || !isset($data['plugins'][$key])) {
                continue;
            }
            unset($data['plugins'][$key]);
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function getFailedPlugins(): array
    {
        return array_filter($this->getAll(), fn($p) => !empty($p['failed']));
    }

    public function recoverPlugin(string $name): bool
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return false;
        }
        $this->plugins[$key]['enabled'] = false;
        $this->plugins[$key]['failed'] = false;
        unset($this->plugins[$key]['fail_reason']);
        $this->manifest[$key] = $this->plugins[$key];
        $this->saveManifest();
        return true;
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
                $this->installer->deleteDir($dir);
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
                        $this->installer->deleteDir($dir);
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

    /**
     * Package names must map directly to a folder/file name under plugins/.
     */
    private function isValidPackageName(string $name): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name);
    }

    /**
     * Download a repository package into the given staging directory, returning
     * ['success' => bool, 'message' => string]. Kept as a method (rather than
     * calling install_repo_package() inline) so tests can substitute a local
     * fixture without network or git.
     */
    protected function fetchRepoPackage(string $repoUrl, string $stagingDir, ?string $tag, string $name): array
    {
        require_once __DIR__ . '/../repo_install.php';
        return install_repo_package($repoUrl, $stagingDir, $tag, $name);
    }

    public function installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array
    {
        $repo = trim($repoUrl, '/');
        $repoName = basename(str_replace(['\\', '.git'], ['', ''], $repo));
        $name = $expectedName ?: $repoName;

        if (!$this->isValidPackageName(strtolower($name))) {
            return ['success' => false, 'message' => 'Invalid plugin name'];
        }

        // Download into a staging directory first, then commit through exactly
        // the same pipeline as installFromZip(): package validation, backup,
        // recordInstalled, hooks, on_install/on_update and rollback. Nothing is
        // removed from the live install until the replacement is fully
        // downloaded, extracted and validated.
        $stagingParent = rtrim($this->pluginsDir, '/') . '/.repo-tmp-' . bin2hex(random_bytes(6));
        $stagingDir = $stagingParent . '/' . $name;
        if (!@mkdir($stagingParent, 0755, true)) {
            return ['success' => false, 'message' => 'Cannot create temporary directory for repository install'];
        }

        $result = $this->fetchRepoPackage($repoUrl, $stagingDir, $tag, $name);
        if (!$result['success']) {
            $this->installer->deleteDir($stagingParent);
            return $result;
        }

        $this->installer->flattenNestedDir($stagingDir);
        $this->installer->stripRepoOnlyFiles($stagingDir);

        // Same integrity check PackageInstaller runs for ZIP installs.
        $app = App::getInstance();
        $verifyFiles = !isset($app->config['plugin_verify_files']) || $app->config['plugin_verify_files'] !== false;
        if ($verifyFiles) {
            $check = $this->installer->verifyInstalledFiles($stagingDir);
            if (empty($check['success'])) {
                $this->installer->deleteDir($stagingParent);
                return $check;
            }
        }

        // Apply the same manifest/version validation a ZIP package must pass.
        $verify = $this->verifyExtractedPackage($stagingDir);
        if ($verify !== null) {
            $this->installer->deleteDir($stagingParent);
            return $verify;
        }

        $targetDir = rtrim($this->pluginsDir, '/') . '/' . $name;
        $replacing = is_dir($targetDir);
        $backupDir = null;
        if ($replacing) {
            $backupDir = rtrim($this->pluginsDir, '/') . '/_old_' . $name . '_' . uniqid();
            if (!@rename($targetDir, $backupDir)) {
                $this->installer->deleteDir($stagingParent);
                return ['success' => false, 'message' => 'Failed to back up existing plugin before reinstall'];
            }
        }

        if (!@rename($stagingDir, $targetDir)) {
            if ($backupDir !== null && is_dir($backupDir)) {
                @rename($backupDir, $targetDir);
            }
            $this->installer->deleteDir($stagingParent);
            return ['success' => false, 'message' => 'Failed to move package to final location'];
        }
        $this->installer->deleteDir($stagingParent);

        // Reinstalling over an existing plugin is an update: run the same
        // update hooks (plugin_updated/on_update) the ZIP update pipeline runs.
        $afterSuccess = $replacing ? function (array $manifest) use ($name) {
            $this->runHook('plugin_updated', $name, $manifest);
            if (!$this->callLifecycle($manifest, 'on_update')) {
                throw new \RuntimeException('on_update failed');
            }
        } : null;

        $finalized = $this->finalizeInstall($targetDir, $backupDir, $name, $replacing, $afterSuccess, 'the repository');
        if (!empty($finalized['success'])) {
            $finalized['message'] = 'Plugin installed from repo';
        }
        return $finalized;
    }

    /**
     * Unified install pipeline. Used by both fresh installs and updates.
     * Identical security checks apply in both cases.
     *
     * @param string $zipPath Path to source ZIP
     * @param string|null $expectedName Optional expected plugin name (folder name) — if null, inferred from manifest
     * @param bool $replacing If true, an existing folder is moved to backup before install
     * @return array {success, message, manifest?}
     */
    public function installFromZip(string $zipPath, ?string $expectedName = null, bool $replacing = false, ?callable $afterSuccess = null): array
    {
        $verifyCallback = function (string $tmpDir) {
            $verify = $this->verifyExtractedPackage($tmpDir);
            return $verify;
        };

        if ($expectedName === null) {
            $name = $this->detectNameFromZip($zipPath);
            if ($name === null) {
                @unlink($zipPath);
                return ['success' => false, 'message' => 'Could not determine plugin name from ZIP manifest'];
            }
            $expectedName = $name;
        }

        if (!$this->isValidPackageName(strtolower($expectedName))) {
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Invalid plugin name'];
        }

        $targetDir = rtrim($this->pluginsDir, '/') . '/' . $expectedName;
        $backupDir = null;

        if ($replacing && is_dir($targetDir)) {
            $backupDir = rtrim($this->pluginsDir, '/') . '/_old_' . $expectedName . '_' . uniqid();
            if (!@rename($targetDir, $backupDir)) {
                @unlink($zipPath);
                return ['success' => false, 'message' => 'Failed to back up existing plugin before update'];
            }
        } elseif (!$replacing && is_dir($targetDir)) {
            @unlink($zipPath);
            return ['success' => false, 'message' => "Plugin '{$expectedName}' is already installed"];
        }

        $result = $this->installer->install($zipPath, $targetDir, $verifyCallback);

        if (!$result['success']) {
            if ($backupDir !== null && is_dir($backupDir)) {
                @rename($backupDir, $targetDir);
            } elseif (is_dir($targetDir)) {
                $this->installer->deleteDir($targetDir);
            }
            return $result;
        }

        return $this->finalizeInstall($targetDir, $backupDir, $expectedName, $replacing, $afterSuccess);
    }

    /**
     * Shared final step of the install/update pipeline. The new files are
     * expected to already be at $targetDir and, when replacing, the previous
     * version at $backupDir. It discovers the plugin, records it, runs the
     * lifecycle hooks (on_install for fresh installs, the caller's
     * update-specific callback for updates) and rolls everything back — files,
     * metadata and backup — if any step fails.
     *
     * Used by both installFromZip() and installFromRepo() so a package gets
     * exactly the same guarantees no matter how it was obtained.
     *
     * @param string $sourceLabel Human-readable origin used in error messages
     * @return array {success, message, manifest?}
     */
    private function finalizeInstall(string $targetDir, ?string $backupDir, string $expectedName, bool $replacing, ?callable $afterSuccess = null, string $sourceLabel = 'the ZIP'): array
    {
        $this->plugins = [];
        $this->discover();
        $manifest = $this->getByName($expectedName);

        if (!$manifest || empty($manifest['file']) || !file_exists($manifest['file'])) {
            // Invalid package: drop the new files and restore the previous
            // version if this was a replacing (update) install.
            if (is_dir($targetDir)) {
                $this->installer->deleteDir($targetDir);
            }
            if ($backupDir !== null && is_dir($backupDir)) {
                @rename($backupDir, $targetDir);
            }
            return ['success' => false, 'message' => "Installed package is not a valid plugin. Ensure {$sourceLabel} contains a valid manifest.json and bootstrap file."];
        }

        // Snapshot the previous installed.json record so a late failure can
        // restore metadata as well as files.
        $prevRecord = $this->readInstalledRecord($expectedName);

        // Keep the backup (and the previous metadata) until the whole
        // rollbackable operation — recording, hooks, on_install and the
        // update-specific callback — has succeeded.
        try {
            $this->recordInstalled($expectedName, $manifest);
            $this->runHook('plugin_installed', $expectedName, $manifest);

            // on_install is for fresh installations only: an update must not
            // repeat one-off setup work.
            if (!$replacing && !$this->callLifecycle($manifest, 'on_install')) {
                throw new \RuntimeException('on_install failed');
            }

            // Update-specific hooks run before the backup is discarded so a
            // failure here is still rollbackable.
            if ($afterSuccess !== null) {
                $afterSuccess($manifest);
            }
        } catch (\Throwable $e) {
            if (is_dir($targetDir)) {
                $this->installer->deleteDir($targetDir);
            }
            if ($backupDir !== null && is_dir($backupDir)) {
                @rename($backupDir, $targetDir);
            }
            $this->restoreInstalledRecord($expectedName, $prevRecord);
            $this->plugins = [];
            $this->discover();
            error_log("Plugin '{$expectedName}' install failed after extraction: " . $e->getMessage());
            return ['success' => false, 'message' => 'Plugin install failed and was rolled back: ' . $e->getMessage()];
        }

        if ($backupDir !== null && is_dir($backupDir)) {
            $this->installer->deleteDir($backupDir);
        }

        $this->plugins = [];
        $this->discover();

        return ['success' => true, 'message' => $replacing ? 'Plugin updated' : 'Plugin installed', 'manifest' => $manifest];
    }

    /**
     * Update an installed plugin from a ZIP. Reuses installFromZip with $replacing=true.
     */
    public function updateFromZip(string $name, string $zipPath): array
    {
        $key = strtolower($name);
        if (!$this->isValidPackageName($key)) {
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Invalid plugin name'];
        }

        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Plugin not found'];
        }

        $this->runHook('plugin_updating', $key, $this->plugins[$key]);

        // plugin_updated and on_update run inside installFromZip *before* the
        // backup is committed, so a failure here rolls the update back.
        return $this->installFromZip($zipPath, $key, true, function (array $manifest) use ($key) {
            $this->runHook('plugin_updated', $key, $manifest);
            if (!$this->callLifecycle($manifest, 'on_update')) {
                throw new \RuntimeException('on_update failed');
            }
        });
    }

    /**
     * Detect the plugin name from a ZIP file by reading its manifest without extracting.
     */
    public function detectNameFromZip(string $zipPath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $manifestRaw = $zip->getFromName('manifest.json');
        if ($manifestRaw === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $normalized = str_replace('\\', '/', $name);
                if (preg_match('#^([^/]+)/manifest\.json$#', $normalized, $m)) {
                    $manifestRaw = $zip->getFromName($name);
                    if ($manifestRaw !== false) {
                        break;
                    }
                }
            }
        }
        $zip->close();

        if ($manifestRaw === false || $manifestRaw === null) {
            return null;
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || empty($manifest['name'])) {
            return null;
        }

        $name = strtolower((string)$manifest['name']);
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name);
        $name = trim($name, '-');
        if ($name === '') {
            return null;
        }
        return $name;
    }

    /**
     * Verify an extracted plugin package. Returns null on success, or an error array.
     */
    public function verifyExtractedPackage(string $targetDir): ?array
    {
        $manifestFile = $targetDir . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return ['success' => false, 'message' => 'Missing manifest.json'];
        }

        $raw = file_get_contents($manifestFile);
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return ['success' => false, 'message' => 'Invalid manifest.json'];
        }

        if (empty($manifest['name']) || !is_string($manifest['name'])) {
            return ['success' => false, 'message' => 'Manifest missing required field: name'];
        }
        if (empty($manifest['version']) || !is_string($manifest['version'])) {
            return ['success' => false, 'message' => 'Manifest missing required field: version'];
        }

        if (!empty($manifest['core'])) {
            $coreVersion = trim(@file_get_contents(__DIR__ . '/../../VERSION') ?: '0.0.0');
            if (!$this->satisfiesConstraint($coreVersion, $manifest['core'])) {
                return ['success' => false, 'message' => "Core version {$coreVersion} does not satisfy constraint '{$manifest['core']}'"];
            }
        }

        if (!empty($manifest['php'])) {
            if (!$this->satisfiesConstraint(PHP_VERSION, $manifest['php'])) {
                return ['success' => false, 'message' => "PHP version " . PHP_VERSION . " does not satisfy constraint '{$manifest['php']}'"];
            }
        }

        $validation = $this->validateManifest($manifest);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => 'Manifest validation failed: ' . implode('; ', $validation['errors'])];
        }

        return null;
    }

    /**
     * Record a freshly installed plugin in data/installed.json.
     */
    private function recordInstalled(string $key, array $manifest): void
    {
        $installedPath = dirname($this->manifestPath) . '/installed.json';
        $data = ['plugins' => [], 'themes' => []];
        if (file_exists($installedPath)) {
            $existing = json_decode(file_get_contents($installedPath), true);
            if (is_array($existing)) {
                $data = array_merge($data, $existing);
            }
        }
        $data['plugins'][$key] = [
            'name' => $manifest['name'] ?? $key,
            'version' => $manifest['version'] ?? '1.0.0',
            'installed_at' => date('c'),
        ];
        if (!is_dir(dirname($installedPath))) {
            @mkdir(dirname($installedPath), 0755, true);
        }
        file_put_contents($installedPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
