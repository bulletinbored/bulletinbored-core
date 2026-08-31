<?php

function handle_admin_catalog(string $method): \Bulletin\Response|bool
{
    global $config, $updateManager;

    $adminCatalogError = '';
    $adminCatalogSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $adminCatalogError = 'Invalid CSRF token';
        } elseif (isset($_POST['uninstall_from_catalog'])) {
            $name = strtolower(trim($_POST['name'] ?? ''));
            $type = strtolower(trim($_POST['type'] ?? ''));
            if ($name === '' || !in_array($type, ['plugin', 'theme'])) {
                $adminCatalogError = 'Invalid request';
            } else {
                $baseDir = $type === 'plugin' ? __DIR__ . '/../../../plugins' : __DIR__ . '/../../../themes';
                $target = $baseDir.'/'.$name;
                if (is_dir($target)) {
                    require_once __DIR__ . '/../../../lib/PluginManager.php';
                    require_once __DIR__ . '/../../../lib/ThemeManager.php';
                    if ($type === 'plugin') {
                        $pm = new PluginManager(__DIR__ . '/../../../plugins', __DIR__ . '/../../../data/plugins.json');
                        $pm->uninstall($name);
                    } else {
                        $tm = new ThemeManager(__DIR__ . '/../../../themes', __DIR__ . '/../../../data/themes.json', 'freshbored');
                        $tm->delete($name);
                    }
                }
                $installedPath = __DIR__.'/../../../data/installed.json';
                $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
                unset($installed[$type.'s'][$name]);
                file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $adminCatalogSuccess = 'Uninstalled successfully';
            }
        } elseif (isset($_POST['install_from_catalog'])) {
            $name = strtolower(trim($_POST['name'] ?? ''));
            $type = strtolower(trim($_POST['type'] ?? ''));
            $repo = trim($_POST['repo'] ?? '');
            $tag = $_POST['tag'] ?? null;
            if ($repo === '' || $name === '' || !in_array($type, ['plugin', 'theme'])) {
                $adminCatalogError = 'Invalid request';
            } else {
                if ($type === 'plugin') {
                    $pluginManager = new PluginManager(__DIR__ . '/../../../plugins', __DIR__ . '/../../../data/plugins.json');
                    $result = $pluginManager->installFromRepo($repo, $tag, $name);
                } else {
                    $themeManager = new ThemeManager(__DIR__ . '/../../../themes', __DIR__ . '/../../../data/themes.json', 'freshbored');
                    $result = $themeManager->installFromRepo($repo, $tag, $name);
                }
                if ($result['success']) {
                    $installedPath = __DIR__.'/../../../data/installed.json';
                    $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
                    if (!isset($installed[$type.'s'])) {
                        $installed[$type.'s'] = [];
                    }
                    $installed[$type.'s'][$name] = [
                        'name' => $name,
                        'repo' => $repo,
                        'version' => $result['manifest']['version'] ?? '1.0.0',
                        'installed_at' => date('c')
                    ];
                    file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $adminCatalogSuccess = 'Installed successfully';
                } else {
                    $adminCatalogError = $result['message'];
                }
            }
        }
    }

    $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
    $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
    $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
    if (is_array($remoteCatalog)) {
        $catalog = $remoteCatalog;
    } else {
        $catalog = json_decode(file_get_contents(__DIR__.'/../../../data/catalog.json'), true) ?: [];
    }
    $installed = json_decode(file_get_contents(__DIR__.'/../../../data/installed.json'), true) ?: ['plugins'=>[], 'themes'=>[]];
    $search = strtolower(trim($_GET['q'] ?? ''));
    if ($search !== '') {
        $catalog = array_filter($catalog, function($item) use ($search) {
            return str_contains(strtolower($item['name'] ?? ''), $search) || str_contains(strtolower($item['description'] ?? ''), $search);
        });
    }
    $typeFilter = strtolower(trim($_GET['type'] ?? ''));
    if ($typeFilter !== '' && $typeFilter !== 'all') {
        $catalog = array_filter($catalog, fn($item) => strtolower($item['type'] ?? '') === $typeFilter);
    }

    foreach ($catalog as $item) {
        $name = strtolower($item['name'] ?? '');
        $type = strtolower($item['type'] ?? '');
        $baseDir = $type === 'plugin' ? __DIR__ . '/../../../plugins' : __DIR__ . '/../../../themes';
        $requiredFile = $type === 'plugin' ? '/manifest.json' : '/style.css';
        $hasFiles = is_dir($baseDir.'/'.$name) && file_exists($baseDir.'/'.$name.$requiredFile);
        if ($hasFiles && !isset($installed[$type.'s'][$name])) {
            $manifestPath = $baseDir.'/'.$name.'/manifest.json';
            $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
            $installed[$type.'s'][$name] = [
                'name' => $name,
                'repo' => $item['repo'] ?? '',
                'version' => $manifest['version'] ?? '1.0.0',
                'installed_at' => date('c')
            ];
        }
    }

    foreach (['plugins','themes'] as $group) {
        foreach ($installed[$group] as $name => $data) {
            $baseDir = $group === 'plugins' ? __DIR__ . '/../../../plugins' : __DIR__ . '/../../../themes';
            $requiredFile = $group === 'plugins' ? '/manifest.json' : '/style.css';
            if (!is_dir($baseDir.'/'.$name) || !file_exists($baseDir.'/'.$name.$requiredFile)) {
                unset($installed[$group][$name]);
            }
        }
    }
    file_put_contents(__DIR__.'/../../../data/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $catalogRemoteVersions = [];
    foreach ($catalog as $item) {
        $name = strtolower($item['name'] ?? '');
        $type = strtolower($item['type'] ?? '');
        $repo = $item['repo'] ?? '';
        $catalogRemoteVersions[$name] = $updateManager->getRemoteVersion($type, $name, $repo);
    }

    include __DIR__ . '/../../../views/admin_catalog.php';
    return true;
}
