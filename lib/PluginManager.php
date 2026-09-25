<?php

require_once __DIR__ . '/PackageInstaller.php';
require_once __DIR__ . '/PluginDiscovery.php';
require_once __DIR__ . '/PluginManager/PluginHooks.php';
require_once __DIR__ . '/PluginManager/PluginManifest.php';
require_once __DIR__ . '/PluginManager/PluginDependencies.php';
require_once __DIR__ . '/PluginManager/PluginPackages.php';

/**
 * PluginManager — discovery, hooks, dependencies and package lifecycle.
 *
 * The class is composed from cohesive traits to keep each concern small:
 *   - {@see PluginHooks}        hook registry and execution
 *   - {@see PluginManifest}     manifest validation, version constraints, state
 *   - {@see PluginDependencies} dependency resolution, enable/disable cascades
 *   - {@see PluginPackages}     install/update/uninstall/delete operations
 */
class PluginManager
{
    use PluginHooks;
    use PluginManifest;
    use PluginDependencies;
    use PluginPackages;

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

    public function getVersion(string $name): string
    {
        $plugin = $this->getByName($name);
        return $plugin['version'] ?? '1.0.0';
    }
}
