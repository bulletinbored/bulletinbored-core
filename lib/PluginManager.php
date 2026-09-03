<?php

require_once __DIR__ . '/PackageInstaller.php';
require_once __DIR__ . '/PluginDiscovery.php';

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
    private PluginDiscovery $discovery;
    private ?array $dependentsCache = null;

    public function __construct(string $pluginsDir, string $manifestPath)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
        $this->manifestPath = $manifestPath;
        $this->loadManifest();
        $this->installer = new PackageInstaller($this->pluginsDir, 'plugin_verify_files');
        $this->discovery = new PluginDiscovery($this->pluginsDir);
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

    public function discover(): array
    {
        $this->plugins = $this->discovery->discover();
        $this->dependentsCache = null;
        foreach ($this->plugins as $key => &$plugin) {
            if (!isset($plugin['enabled'])) {
                $plugin['enabled'] = $this->manifest[$key]['enabled'] ?? !in_array($key, self::DISABLED_BY_DEFAULT, true);
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
        $manifest = $this->discovery->parseManifest($dir);
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
            $depVersion = $dep['version'] ?? '1.0.0';
            if (!empty($dep['folder'])) {
                $depDir = $this->pluginsDir . '/' . $dep['folder'];
                $depManifest = $this->discovery->parseManifest($depDir);
                if ($depManifest && !empty($depManifest['version'])) {
                    $depVersion = $depManifest['version'];
                }
            }
            if ($constraint) {
                if (!$this->satisfiesConstraint($depVersion, $constraint)) {
                    return array('compatible' => false, 'reason' => "Dependency {$depName} version {$depVersion} does not satisfy '{$constraint}'");
                }
            }
        }
        return array('compatible' => true);
    }

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
            $manifest = $this->discovery->parseManifest($dir);
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
     * Return all plugins that transitively depend on $name.
     * Memoized per-instance to avoid quadratic re-parsing of manifests.
     * Cache is invalidated when the plugin set changes.
     */
    public function getDependents(string $name): array
    {
        $key = strtolower($name);

        if ($this->dependentsCache === null) {
            $this->dependentsCache = [];
            foreach ($this->plugins as $pKey => $plugin) {
                if (empty($plugin['folder'])) {
                    continue;
                }
                $dir = $this->pluginsDir . '/' . $plugin['folder'];
                $manifest = $this->discovery->parseManifest($dir);
                if ($manifest && !empty($manifest['dependencies'])) {
                    foreach ($manifest['dependencies'] as $depName => $_) {
                        $this->dependentsCache[strtolower($depName)][] = $pKey;
                    }
                }
            }
        }

        $visited = [];
        $stack = [$key];
        $result = [];
        while (!empty($stack)) {
            $cur = array_pop($stack);
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            if (!isset($this->dependentsCache[$cur])) {
                continue;
            }
            foreach ($this->dependentsCache[$cur] as $dependent) {
                if (!isset($visited[$dependent])) {
                    $result[$dependent] = true;
                    $stack[] = $dependent;
                }
            }
        }

        return array_keys($result);
    }

    public function invalidateDependentsCache(): void
    {
        $this->dependentsCache = null;
    }

    /**
     * Disable a plugin and cascade-disable all transitive dependents.
     * Disabling is one-way automatic; dependents must be re-enabled explicitly by the user.
     * This matches the spec: "if I disable C, A and B are also disabled; if I re-enable C,
     * A and B are NOT automatically re-enabled".
     */
    public function disable(string $name): bool
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return false;
        }

        $dependents = $this->getDependents($key);
        $toDisable = array_unique(array_merge([$key], $dependents));
        sort($toDisable);

        $anyChanged = false;
        foreach ($toDisable as $k) {
            if (empty($this->plugins[$k]['enabled'])) {
                continue;
            }
            $this->plugins[$k]['enabled'] = false;
            $this->plugins[$k]['auto_disabled_by'] = $k === $key ? null : $key;
            $this->manifest[$k] = $this->plugins[$k];
            $anyChanged = true;
            if ($k !== $key) {
                error_log("Plugin '{$k}' auto-disabled: depends on '{$key}'");
                $this->runHook('plugin_auto_disabled', $k, $key);
            }
        }

        if ($anyChanged) {
            $this->saveManifest();
        }

        $this->runHook('plugin_disabled', $key);
        return true;
    }

    /**
     * Re-enable a plugin. Does NOT automatically re-enable dependencies or dependents.
     * Use enableWithDeps() to re-enable the full dependency chain.
     */
    public function enable(string $name): bool
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return false;
        }

        $cycle = $this->detectCycle($key);
        if ($cycle !== null) {
            error_log("Plugin '{$key}' enable failed: circular dependency detected: " . implode(' -> ', $cycle));
            return false;
        }

        $deps = $this->checkDependencies($name);
        if (!$deps['compatible']) {
            error_log("Plugin '{$key}' enable failed: {$deps['reason']}");
            return false;
        }

        $this->plugins[$key]['enabled'] = true;
        unset($this->plugins[$key]['auto_disabled_by']);
        $this->manifest[$key] = $this->plugins[$key];
        $this->saveManifest();
        $this->runHook('plugin_enabled', $key);
        return true;
    }

    /**
     * Re-enable a plugin and walk down its dependency chain, enabling anything still
     * missing. This is the only way to bring a disabled cascade back up. Each plugin in
     * the chain is enabled only if its own dependencies are satisfied.
     */
    public function enableWithDeps(string $name): array
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            return ['success' => false, 'enabled' => [], 'failed' => [$key => 'Plugin not found']];
        }

        $visited = [];
        $enabled = [];
        $failed = [];
        $this->walkEnableWithDeps($key, $visited, $enabled, $failed);

        if (!empty($enabled)) {
            $this->saveManifest();
        }

        return [
            'success' => empty($failed),
            'enabled' => $enabled,
            'failed' => $failed,
        ];
    }

    private function walkEnableWithDeps(string $key, array &$visited, array &$enabled, array &$failed): void
    {
        if (isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        if (!empty($this->plugins[$key]['folder'])) {
            $dir = $this->pluginsDir . '/' . $this->plugins[$key]['folder'];
            $manifest = $this->discovery->parseManifest($dir);
            if ($manifest && !empty($manifest['dependencies'])) {
                foreach ($manifest['dependencies'] as $depName => $_) {
                    $depKey = strtolower($depName);
                    if (!isset($this->plugins[$depKey])) {
                        $failed[$key] = "Missing dependency: {$depName}";
                        return;
                    }
                    $this->walkEnableWithDeps($depKey, $visited, $enabled, $failed);
                    if (empty($this->plugins[$depKey]['enabled'])) {
                        $failed[$key] = "Dependency not enabled: {$depName}";
                        return;
                    }
                }
            }
        }

        if (empty($this->plugins[$key]['enabled'])) {
            $this->plugins[$key]['enabled'] = true;
            unset($this->plugins[$key]['auto_disabled_by']);
            $this->manifest[$key] = $this->plugins[$key];
            $enabled[] = $key;
        }
    }

    public function getSetting(string $pluginName, string $key, mixed $default = null): mixed
    {
        $settings = $this->manifest[strtolower($pluginName)]['settings'] ?? [];
        return $settings[$key] ?? $default;
    }

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

    private function callLifecycle(array $entry, string $hookName): void
    {
        $file = $entry['file'] ?? null;
        if (!$file || !file_exists($file)) {
            return;
        }
        $key = strtolower($entry['name'] ?? '');
        $fn = $key . '_' . $hookName;
        if (function_exists($fn)) {
            try {
                $fn();
            } catch (\Throwable $e) {
                error_log("Plugin '{$key}' lifecycle hook '{$hookName}' failed: " . $e->getMessage());
            }
        }
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
                error_log("Plugin '{$key}' failed to load: " . $e->getMessage());
                $this->plugins[$key]['enabled'] = false;
                $this->plugins[$key]['failed'] = true;
                $this->plugins[$key]['fail_reason'] = $e->getMessage();
                $this->runHook('plugin_load_failed', $key, $e);
            }
        }
        return $loaded;
    }

    public function validateManifest(array $manifest): array
    {
        $errors = [];

        if (isset($manifest['id'])) {
            if (!is_string($manifest['id']) || !preg_match('/^[a-z][a-z0-9-]*$/', $manifest['id'])) {
                $errors[] = "Invalid 'id' format: must be lowercase alphanumeric + hyphens, starting with a letter";
            }
        }

        if (empty($manifest['name']) || !is_string($manifest['name'])) {
            $errors[] = "Missing or invalid 'name' (required, string)";
        }

        if (empty($manifest['version']) || !is_string($manifest['version'])) {
            $errors[] = "Missing or invalid 'version' (required, semver string)";
        }

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

        if (empty($errors) && !empty($manifest['core'])) {
            $coreVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
            if (!$this->satisfiesConstraint($coreVersion, $manifest['core'])) {
                $errors[] = "Core version {$coreVersion} does not satisfy constraint '{$manifest['core']}'";
            }
        }

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

        if (!empty($plugin['folder'])) {
            $dir = $this->pluginsDir . '/' . $plugin['folder'];
            $manifest = $this->discovery->parseManifest($dir);
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

        if (is_dir($targetDir)) {
            $this->installer->deleteDir($targetDir);
        }

        require_once __DIR__ . '/repo_install.php';
        $result = install_repo_package($repoUrl, $targetDir, $tag, $expectedName ?: $repoName);
        if (!$result['success']) {
            return $result;
        }

        $this->installer->flattenNestedDir($targetDir);

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
                $this->installer->deleteDir($targetDir);
            }
            return ['success' => false, 'message' => 'Installed package is not a valid plugin. Ensure the repository contains a valid manifest.json and bootstrap file.'];
        }

        return ['success' => true, 'message' => 'Plugin installed from repo', 'manifest' => $manifest];
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
    public function installFromZip(string $zipPath, ?string $expectedName = null, bool $replacing = false): array
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

        if ($backupDir !== null && is_dir($backupDir)) {
            $this->installer->deleteDir($backupDir);
        }

        $this->plugins = [];
        $this->discover();
        $manifest = $this->getByName($expectedName);

        if (!$manifest || empty($manifest['file']) || !file_exists($manifest['file'])) {
            if (is_dir($targetDir)) {
                $this->installer->deleteDir($targetDir);
            }
            if ($backupDir !== null && is_dir($backupDir)) {
                @rename($backupDir, $targetDir);
            }
            return ['success' => false, 'message' => 'Installed package is not a valid plugin. Ensure the ZIP contains a valid manifest.json and bootstrap file.'];
        }

        $this->recordInstalled($expectedName, $manifest);

        $this->runHook('plugin_installed', $expectedName, $manifest);
        $this->callLifecycle($manifest, 'on_install');

        return ['success' => true, 'message' => $replacing ? 'Plugin updated' : 'Plugin installed', 'manifest' => $manifest];
    }

    /**
     * Update an installed plugin from a ZIP. Reuses installFromZip with $replacing=true.
     */
    public function updateFromZip(string $name, string $zipPath): array
    {
        $key = strtolower($name);
        $this->plugins = $this->getAll();
        if (!isset($this->plugins[$key])) {
            @unlink($zipPath);
            return ['success' => false, 'message' => 'Plugin not found'];
        }

        $this->runHook('plugin_updating', $key, $this->plugins[$key]);

        $result = $this->installFromZip($zipPath, $key, true);

        if ($result['success']) {
            $this->runHook('plugin_updated', $key, $result['manifest'] ?? null);
            $this->callLifecycle($result['manifest'] ?? [], 'on_update');
        }

        return $result;
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
            $coreVersion = trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0');
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
