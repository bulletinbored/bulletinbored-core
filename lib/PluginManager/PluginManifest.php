<?php

/**
 * PluginManifest — manifest validation, version constraints and state for
 * PluginManager.
 */
trait PluginManifest
{
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
            $coreVersion = trim(file_get_contents(__DIR__ . '/../../VERSION'));
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
                return false;
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
}
