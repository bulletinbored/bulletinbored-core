<?php

/**
 * PluginDependencies — dependency resolution, cycle detection, enable/disable
 * cascades for PluginManager.
 */
trait PluginDependencies
{
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
}
