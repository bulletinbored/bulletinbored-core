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
    private ?Bulletin\Router $router = null;
    private array $routeRegistrations = [];
    private array $middlewareRegistrations = [];

    public function __construct(string $pluginsDir, string $manifestPath)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
        $this->manifestPath = $manifestPath;
        $this->loadManifest();
    }

    public function setRouter(Bulletin\Router $router): void
    {
        $this->router = $router;
    }

    public function registerRoute(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $this->routeRegistrations[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler, 'middleware' => $middleware];
    }

    public function registerMiddleware(string $name, callable $fn): void
    {
        $this->middlewareRegistrations[] = ['name' => $name, 'fn' => $fn];
    }

    public function getRouter(): ?Bulletin\Router
    {
        return $this->router;
    }

    public function applyRoutes(): void
    {
        if ($this->router === null) {
            return;
        }
        foreach ($this->middlewareRegistrations as $mw) {
            $this->router = $this->router->registerMiddleware($mw['name'], $mw['fn']);
        }
        foreach ($this->routeRegistrations as $route) {
            $method = strtolower($route['method']);
            if ($method === 'any') {
                $this->router = $this->router->any($route['pattern'], $route['handler']);
            } else {
                $this->router = $this->router->$method($route['pattern'], $route['handler']);
            }
        }
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

    private function parseManifest(string $dir): ?array
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

    /**
     * Read a file with a few retries. On Windows the file can momentarily be
     * locked by another process (web server, antivirus, a file watcher, ...)
     * which would otherwise cause plugin discovery to fail with a misleading
     * "not a valid plugin" error.
     */
    private function readFileRetry(string $path): ?string
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
            $langFile = $this->pluginsDir . '/' . $plugin['folder'] . '/lang/' . $lang . '.json';
            if (file_exists($langFile)) {
                $GLOBALS['i18n'][$scope] = load_lang_file($langFile);
            }
        }
    }

    public function loadEnabled(): array
    {
        $loaded = [];
        foreach ($this->getEnabled() as $key => $plugin) {
            if (empty($plugin['file']) || !file_exists($plugin['file'])) {
                continue;
            }
            try {
                include $plugin['file'];
                $initFunction = $key . '_init';
                if (function_exists($initFunction)) {
                    $initFunction();
                }
                $loaded[] = $key;
            } catch (\Throwable $e) {
                // Isolate failure: one broken plugin must not crash the forum.
                error_log("Plugin '{$key}' failed to load: " . $e->getMessage());
                $this->plugins[$key]['enabled'] = false;
                $this->plugins[$key]['failed'] = true;
                $this->plugins[$key]['fail_reason'] = $e->getMessage();
                // Run admin notification hook so the admin knows.
                $this->runHook('plugin_load_failed', $key, $e);
            }
        }
        return $loaded;
    }

    /**
     * Validate a plugin manifest against the v1 schema.
     * Backward compatible: accepts both legacy format (name only) and v1 (id + name).
     * Returns ['valid' => true] or ['valid' => false, 'errors' => [...]].
     */
    public function validateManifest(array $manifest): array
    {
        $errors = [];

        // 'id' is optional in v1 — defaults to 'name' for backward compatibility.
        // If present, it must be a valid lowercase alphanumeric + hyphens string.
        if (isset($manifest['id'])) {
            if (!is_string($manifest['id']) || !preg_match('/^[a-z][a-z0-9-]*$/', $manifest['id'])) {
                $errors[] = "Invalid 'id' format: must be lowercase alphanumeric + hyphens, starting with a letter";
            }
        }

        // 'name' is required (used as fallback for 'id' in legacy manifests)
        if (empty($manifest['name']) || !is_string($manifest['name'])) {
            $errors[] = "Missing or invalid 'name' (required, string)";
        }

        if (empty($manifest['version']) || !is_string($manifest['version'])) {
            $errors[] = "Missing or invalid 'version' (required, semver string)";
        }

        // Optional fields with validation
        if (isset($manifest['core']) && !is_string($manifest['core'])) {
            $errors[] = "Invalid 'core' (should be a version constraint string like '>=0.5.0 <2.0.0')";
        }

        if (isset($manifest['php']) && !is_string($manifest['php'])) {
            $errors[] = "Invalid 'php' (should be a version constraint string like '>=8.1')";
        }

        if (isset($manifest['permissions']) && !is_array($manifest['permissions'])) {
            $errors[] = "Invalid 'permissions' (should be an array of permission strings)";
        }

        if (isset($manifest['bootstrap']) && !is_string($manifest['bootstrap'])) {
            $errors[] = "Invalid 'bootstrap' (should be a filename string)";
        }

        // Check core compatibility
        if (empty($errors) && !empty($manifest['core'])) {
            $coreVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
            if (!$this->satisfiesConstraint($coreVersion, $manifest['core'])) {
                $errors[] = "Core version {$coreVersion} does not satisfy constraint '{$manifest['core']}'";
            }
        }

        // Check PHP compatibility
        if (empty($errors) && !empty($manifest['php'])) {
            if (!$this->satisfiesConstraint(PHP_VERSION, $manifest['php'])) {
                $errors[] = "PHP version " . PHP_VERSION . " does not satisfy constraint '{$manifest['php']}'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Normalize a manifest: derive 'id' from 'name' if not set (backward compat).
     */
    public function normalizeManifest(array $manifest): array
    {
        if (empty($manifest['id']) && !empty($manifest['name'])) {
            $id = strtolower($manifest['name']);
            $id = preg_replace('/[^a-z0-9-]+/', '-', $id);
            $id = trim($id, '-');
            $manifest['id'] = $id;
        }
        return $manifest;
    }
    private function satisfiesConstraint(string $version, string $constraint): bool
    {
        $constraints = preg_split('/\s+/', trim($constraint));
        foreach ($constraints as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (!preg_match('/^(>=|<=|>|<|==|!=)(.+)$/', $c, $m)) {
                continue;
            }
            $op = $m[1];
            $target = trim($m[2]);
            if (!version_compare($version, $target, $op)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the current state of a plugin.
     * Possible states: enabled, disabled, incompatible, corrupted, failed, not_found
     */
    public function getPluginState(string $name): string
    {
        $key = strtolower($name);
        $plugin = $this->getAll()[$key] ?? null;

        if ($plugin === null) {
            return 'not_found';
        }

        if (!empty($plugin['failed'])) {
            return 'failed';
        }

        if (!empty($plugin['file']) && !file_exists($plugin['file'])) {
            return 'corrupted';
        }

        // Check compatibility
        if (!empty($plugin['folder'])) {
            $dir = $this->pluginsDir . '/' . $plugin['folder'];
            $manifest = $this->parseManifest($dir);
            if ($manifest) {
                $validation = $this->validateManifest($manifest);
                if (!$validation['valid']) {
                    return 'incompatible';
                }
            }
        }

        return !empty($plugin['enabled']) ? 'enabled' : 'disabled';
    }

    public function addHook(string $event, callable $callback, int $priority = 10): void
    {
        $this->hooks[$event][] = ['cb' => $callback, 'prio' => $priority];
        usort($this->hooks[$event], fn($a, $b) => $a['prio'] <=> $b['prio']);
    }

    public function removeHook(string $event, callable $callback): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        foreach ($this->hooks[$event] as $i => $h) {
            if ($h['cb'] === $callback) {
                unset($this->hooks[$event][$i]);
                break;
            }
        }
        $this->hooks[$event] = array_values($this->hooks[$event]);
    }

    public function runHook(string $event, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                call_user_func_array($h['cb'], $args);
            }
        }
    }

    /**
     * Like runHook() but returns the first non-null value produced by a
     * callback. Used by filters that may override a core value (e.g. content
     * rendering). If no callback returns a non-null value, null is returned.
     */
    public function applyHook(string $event, mixed ...$args): mixed
    {
        if (!isset($this->hooks[$event])) {
            return null;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                $result = call_user_func_array($h['cb'], $args);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Filter a value through all callbacks registered for an event.
     * Each callback receives the value and can modify/return it.
     * Unlike applyHook(), this chains: output of one becomes input of next.
     *
     * @param string $event Hook name
     * @param mixed $value The value to filter
     * @param mixed ...$args Additional context passed to each callback
     * @return mixed The filtered value
     */
    public function filter(string $event, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->hooks[$event])) {
            return $value;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                $result = call_user_func($h['cb'], $value, ...$args);
                if ($result !== null) {
                    $value = $result;
                }
            }
        }
        return $value;
    }

    /**
     * Check if any callback registered for an event returns truthy.
     * Useful for permission/authorization hooks where any "veto" blocks the action.
     *
     * @param string $event Hook name
     * @param mixed ...$args
     * @return bool True if at least one callback returns true
     */
    public function checkHook(string $event, mixed ...$args): bool
    {
        if (!isset($this->hooks[$event])) {
            return false;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb']) && call_user_func_array($h['cb'], $args)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if ALL callbacks registered for an event return truthy.
     * Useful for multi-factor auth or cumulative permission checks.
     *
     * @param string $event Hook name
     * @param mixed ...$args
     * @return bool True only if all callbacks return true (or no callbacks exist)
     */
    public function checkHookAll(string $event, mixed ...$args): bool
    {
        if (!isset($this->hooks[$event])) {
            return true;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb']) && !call_user_func_array($h['cb'], $args)) {
                return false;
            }
        }
        return true;
    }

    public function captureHook(string $event, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        ob_start();
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                call_user_func_array($h['cb'], $args);
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
    /**
     * Move a file or directory with retries, tolerating transient Windows
     * permission/locking errors (e.g. when the destination is momentarily
     * held by another process such as the web server or an antivirus).
     */
    private function moveWithRetry(string $source, string $dest): bool
    {
        for ($i = 0; $i < 5; $i++) {
            clearstatcache(true, $dest);
            if (file_exists($dest)) {
                if (is_dir($dest)) {
                    $this->deleteDir($dest);
                } else {
                    @unlink($dest);
                }
            }
            clearstatcache(true, $source);
            clearstatcache(true, $dest);
            if (@rename($source, $dest)) {
                return true;
            }
            if (@copy($source, $dest)) {
                clearstatcache(true, $source);
                @unlink($source);
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    private function flattenNestedDir(string $targetDir): void
    {
        if (!is_dir($targetDir)) {
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
                    $this->moveWithRetry($child, $childDest);
                }
            } else {
                $this->moveWithRetry($item, $destItem);
            }
        }

        $this->deleteDir($nested);
    }

    private function safeExtractZip(ZipArchive $zip, string $dest): bool
    {
        $dest = rtrim($dest, '/');
        $realDest = realpath($dest);
        if ($realDest === false) {
            return false;
        }

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
        $ok = $this->safeExtractZip($zip, $dest);
        $zip->close();
        @unlink($zipPath);

        if (!$ok) {
            return ['success' => false, 'message' => 'Invalid ZIP entries'];
        }

        $this->flattenNestedDir($dest);

        // Optional integrity checks. These can be disabled by the admin via
        // config: plugin_verify_files = false.
        if ($this->verifyFilesEnabled()) {
            // 1) File-list integrity: reject when declared files are missing or
            //    extra undeclared files are present (potential backdoor).
            $check = $this->verifyInstalledFiles();
            if (!$check['success']) {
                $this->deleteDir(rtrim($dest, '/'));
                $this->plugins = [];
                $this->discover();
                return $check;
            }
        }

        $this->plugins = [];
        $this->discover();

        return ['success' => true, 'message' => 'Plugin installed'];
    }

    private function verifyFilesEnabled(): bool
    {
        global $config;
        return !isset($config['plugin_verify_files']) || $config['plugin_verify_files'] !== false;
    }

    /**
     * Verify that every extracted plugin folder honours the "files" list in its
     * manifest.json: no declared file missing, no undeclared file present.
     * Plugins without a manifest.json or without a "files" key are skipped.
     */
    private function verifyInstalledFiles(): array
    {
        $pending = [];
        foreach (glob($this->pluginsDir . '/*', GLOB_ONLYDIR) as $dir) {
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

            // Collect all actual files below the plugin folder.
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
                    'plugin' => basename($dir),
                    'missing' => array_values($missing),
                    'extra' => array_values($extra),
                ];
            }
        }

        if (!empty($pending)) {
            $detail = '';
            foreach ($pending as $p) {
                $detail .= "\n- " . $p['plugin'];
                if (!empty($p['missing'])) {
                    $detail .= "\n  missing: " . implode(', ', $p['missing']);
                }
                if (!empty($p['extra'])) {
                    $detail .= "\n  undeclared: " . implode(', ', $p['extra']);
                }
            }
            return ['success' => false, 'message' => 'Plugin integrity check failed. The archive contains files not declared in manifest.json (or is missing declared files). This may indicate a tampered package:' . $detail . "\n\nYou can disable this check by setting \$config['plugin_verify_files'] = false; in config.php."];
        }

        return ['success' => true, 'message' => 'ok'];
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

    private function deleteDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!@rmdir($item->getPathname())) {
                    return false;
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    return false;
                }
            }
        }

        return @rmdir($dir);
    }

    public function installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array
    {
        $dest = rtrim($this->pluginsDir, '/') . '/';
        $repo = trim($repoUrl, '/');
        $repoName = basename(str_replace(['\\', '.git'], ['', ''], $repo));
        $targetDir = $dest . ($expectedName ?: $repoName);

        // Remove any leftover/partial directory from a previous failed install
        // so we never merge a new download into a broken folder.
        if (is_dir($targetDir)) {
            $this->deleteDir($targetDir);
        }

        require_once __DIR__ . '/repo_install.php';
        $result = install_repo_package($repoUrl, $targetDir, $tag, $expectedName ?: $repoName);
        if (!$result['success']) {
            return $result;
        }

        $this->flattenNestedDir($targetDir);

        // Give the filesystem a moment and retry discovery: on Windows the
        // freshly written manifest.json can be briefly locked by another
        // process, which would otherwise trigger a false "not a valid plugin".
        $manifest = null;
        for ($i = 0; $i < 10; $i++) {
            $this->plugins = [];
            $this->discover();
            $manifest = $this->getByName($expectedName ?: $repoName);
            if ($manifest && !empty($manifest['file']) && file_exists($manifest['file'])) {
                break;
            }
            clearstatcache();
            usleep(200000);
        }

        if (!$manifest || empty($manifest['file']) || !file_exists($manifest['file'])) {
            if (is_dir($targetDir)) {
                $this->deleteDir($targetDir);
            }
            return ['success' => false, 'message' => 'Installed package is not a valid plugin. Ensure the repository contains a valid manifest.json and bootstrap file.'];
        }

        return ['success' => true, 'message' => 'Plugin installed from repo', 'manifest' => $manifest];
    }
}
