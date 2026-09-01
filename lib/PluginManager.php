<?php

require_once __DIR__ . '/PackageInstaller.php';

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
    private PackageInstaller $installer;

    public function __construct(string $pluginsDir, string $manifestPath)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
        $this->manifestPath = $manifestPath;
        $this->loadManifest();
        $this->installer = new PackageInstaller($this->pluginsDir, 'plugin_verify_files');
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

    /**
     * Resolve plugin dependencies before enabling.
     * Returns ['compatible' => true] or ['compatible' => false, 'reason' => '...'].
     */
    public function checkDependencies(string $name): array
    {
        $plugin = $this->getByName($name);
        if ($plugin === null) {
            return array('compatible' => false, 'reason' => 'Plugin not found');
        }
        if (empty($plugin['folder'])) {
            return array('compatible' => true);
        }
        $dir = $this->pluginsDir . '/' . $plugin['folder'];
        $manifest = $this->parseManifest($dir);
        if (!$manifest || empty($manifest['dependencies'])) {
            return array('compatible' => true);
        }
        $deps = $manifest['dependencies'];
        foreach ($deps as $depName => $constraint) {
            $dep = $this->getByName($depName);
            if ($dep === null) {
                return array('compatible' => false, 'reason' => "Missing dependency: {$depName}");
            }
            if (empty($dep['enabled'])) {
                return array('compatible' => false, 'reason' => "Dependency not enabled: {$depName}");
            }
            if ($constraint && isset($dep['version'])) {
                if (!version_compare($dep['version'], $constraint, '>=')) {
                    return array('compatible' => false, 'reason' => "Dependency {$depName} version {$dep['version']} does not satisfy >= {$constraint}");
                }
            }
        }
        return array('compatible' => true);
    }

    /**
     * Detect circular dependencies using DFS.
     * Returns the cycle path if found, null otherwise.
     */
    public function detectCycle(string $name, array $visited = [], array $path = []): ?array
    {
        $key = strtolower($name);
        if (in_array($key, $path, true)) {
            return [...$path, $key];
        }
        if (in_array($key, $visited, true)) {
            return null;
        }
        $visited[] = $key;
        $path[] = $key;

        $plugin = $this->getByName($key);
        if ($plugin && !empty($plugin['folder'])) {
            $dir = $this->pluginsDir . '/' . $plugin['folder'];
            $manifest = $this->parseManifest($dir);
            if ($manifest && !empty($manifest['dependencies'])) {
                foreach ($manifest['dependencies'] as $depName => $constraint) {
                    $cycle = $this->detectCycle($depName, $visited, $path);
                    if ($cycle !== null) {
                        return $cycle;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Get all plugins that depend on the given plugin (recursive).
     */
    public function getDependents(string $name): array
    {
        $key = strtolower($name);
        $dependents = [];
        foreach ($this->plugins as $pKey => $plugin) {
            if ($pKey === $key || empty($plugin['folder'])) {
                continue;
            }
            $dir = $this->pluginsDir . '/' . $plugin['folder'];
            $manifest = $this->parseManifest($dir);
            if ($manifest && !empty($manifest['dependencies']) && isset($manifest['dependencies'][$key])) {
                $dependents[] = $pKey;
                $dependents = array_merge($dependents, $this->getDependents($pKey));
            }
        }
        return array_values(array_unique($dependents));
    }

    /**
     * Enable a plugin with recursive dependency resolution.
     * Returns true on success, false if dependencies are not met or cycle detected.
     */
    public function enable(string $name): bool
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return false;
        }

        // Check for cycles
        $cycle = $this->detectCycle($key);
        if ($cycle !== null) {
            error_log("Plugin '{$key}' enable failed: circular dependency detected: " . implode(' -> ', $cycle));
            return false;
        }

        // Check dependencies
        $deps = $this->checkDependencies($name);
        if (!$deps['compatible']) {
            error_log("Plugin '{$key}' enable failed: {$deps['reason']}");
            return false;
        }

        $this->plugins[$key]['enabled'] = true;
        $this->manifest[$key] = $this->plugins[$key];
        $this->saveManifest();
        return true;
    }

    /**
     * Disable a plugin and all plugins that depend on it (recursive).
     */
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

        // Recursive cascade: disable all dependents
        $dependents = $this->getDependents($key);
        foreach ($dependents as $dependent) {
            $this->plugins[$dependent]['enabled'] = false;
            $this->manifest[$dependent] = $this->plugins[$dependent];
            $this->saveManifest();
            error_log("Plugin '{$dependent}' auto-disabled: depends on '{$key}'");
        }

        return true;
    }

    /**
     * Get a plugin setting value.
     */
    public function getSetting(string $pluginName, string $key, mixed $default = null): mixed
    {
        $settings = $this->manifest[strtolower($pluginName)]['settings'] ?? [];
        return $settings[$key] ?? $default;
    }

    /**
     * Set a plugin setting value.
     */
    public function setSetting(string $pluginName, string $key, mixed $value): void
    {
        $name = strtolower($pluginName);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$name])) {
            return;
        }
        $this->manifest[$name]['settings'][$key] = $value;
        $this->plugins[$name]['settings'][$key] = $value;
        $this->saveManifest();
    }

    /**
     * Uninstall a plugin: disable, run cleanup, remove files.
     */
    public function uninstall(string $name): array
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return array('success' => false, 'message' => 'Plugin not found');
        }
        $this->disable($name);
        $dir = $this->pluginsDir . '/' . $key;
        if (is_dir($dir)) {
            $this->installer->deleteDir($dir);
        } else {
            $file = $this->pluginsDir . '/' . $key . '.php';
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        unset($this->manifest[$key]);
        unset($this->plugins[$key]);
        $this->saveManifest();
        return array('success' => true, 'message' => 'Plugin uninstalled: ' . $key);
    }

    /**
     * List failed plugins for recovery mode.
     */
    public function getFailedPlugins(): array
    {
        return array_filter($this->getAll(), fn($p) => !empty($p['failed']));
    }

    /**
     * Recover from a failed plugin by disabling it.
     */
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

    public function loadTranslations(string $lang): void
    {
        $app = App::getInstance();
        foreach ($this->getAll() as $key => $plugin) {
            $scope = 'plugin:' . $key;
            $app->i18n[$scope] = [];
            if (empty($plugin['folder'])) {
                continue;
            }
            $langFile = $this->pluginsDir . '/' . $plugin['folder'] . '/lang/' . $lang . '.json';
            if (file_exists($langFile)) {
                $app->i18n[$scope] = load_lang_file($langFile);
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

    private function verifyInstalledFiles(string $targetDir): array
    {
        return $this->installer->verifyInstalledFiles($targetDir);
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

    public function installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array
    {
        $dest = rtrim($this->pluginsDir, '/') . '/';
        $repo = trim($repoUrl, '/');
        $repoName = basename(str_replace(['\\', '.git'], ['', ''], $repo));
        $targetDir = $dest . ($expectedName ?: $repoName);

        // Remove any leftover/partial directory from a previous failed install
        // so we never merge a new download into a broken folder.
        if (is_dir($targetDir)) {
            $this->installer->deleteDir($targetDir);
        }

        require_once __DIR__ . '/repo_install.php';
        $result = install_repo_package($repoUrl, $targetDir, $tag, $expectedName ?: $repoName);
        if (!$result['success']) {
            return $result;
        }

        $this->installer->flattenNestedDir($targetDir);

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
