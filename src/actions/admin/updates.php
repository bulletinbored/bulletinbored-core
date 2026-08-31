<?php

function handle_admin_updates(string $method): \Bulletin\Response|bool
{
    global $config, $pluginManager, $themeManager, $updateManager;

    $updateResults = null;
    $updateError = '';
    $updateSuccess = '';

    if ($method === 'POST' && isset($_POST['check_updates'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } else {
            $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
            $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
            $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
            $catalog = is_array($remoteCatalog)
                ? $remoteCatalog
                : (file_exists(__DIR__.'/../../../data/catalog.json') ? json_decode(file_get_contents(__DIR__.'/../../../data/catalog.json'), true) : []);
            $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager, $catalog);
        }
    }

    if ($method === 'POST' && isset($_POST['apply_update'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } else {
            $type = $_POST['type'] ?? '';
            $name = $_POST['name'] ?? '';

            if ($type === 'core' && !empty($_POST['core_tag'])) {
                $tag = ltrim($_POST['core_tag'], 'v');
                if (version_compare($tag, $config['version'] ?? '1.0.0', '<=')) {
                    $updateError = 'No newer version available';
                } elseif ($updateManager->applyCoreUpdate($tag)) {
                    $updateSuccess = 'Core updated to v' . escape($tag);
                    log_security_event('core_update', ['tag' => $tag]);
                    clearstatcache();
                    $versionFile = __DIR__ . '/../../../VERSION';
                    if (file_exists($versionFile)) {
                        $config['version'] = trim(@file_get_contents($versionFile));
                    }
                } else {
                    $updateError = 'Failed to update core';
                    log_security_event('core_update_failed', ['tag' => $tag]);
                }
            } elseif (($type === 'plugins' || $type === 'themes') && !empty($_POST['ext_tag'])) {
                $tag = ltrim($_POST['ext_tag'], 'v');
                $extName = $name ?? '';
                $installedVersion = '1.0.0';
                if ($type === 'plugins' && $pluginManager) {
                    $plugin = $pluginManager->getAll();
                    $plugin = $plugin[$extName] ?? null;
                    $installedVersion = $plugin['version'] ?? '1.0.0';
                } elseif ($type === 'themes' && $themeManager) {
                    $theme = $themeManager->getAll();
                    $theme = $theme[$extName] ?? null;
                    $installedVersion = $theme['version'] ?? '1.0.0';
                }
                if (version_compare($tag, $installedVersion, '<=')) {
                    $updateError = 'No newer version available';
                } elseif ($updateManager->applyExtensionUpdate($type === 'plugins' ? 'plugin' : 'theme', $extName, $tag)) {
                    $updateSuccess = 'Extension updated to v' . escape($tag);
                    log_security_event('extension_update', ['type' => $type, 'name' => $extName, 'tag' => $tag]);
                    if ($type === 'plugins' && $pluginManager) {
                        $pluginManager->discover();
                    } elseif ($type === 'themes' && $themeManager) {
                        $themeManager->discover();
                    }
                    $installedPath = __DIR__ . '/../../../data/installed.json';
                    $installedData = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins' => [], 'themes' => []];
                    if (!is_array($installedData)) {
                        $installedData = ['plugins' => [], 'themes' => []];
                    }
                    $group = $type === 'plugins' ? 'plugins' : 'themes';
                    if (isset($installedData[$group][$extName])) {
                        $installedData[$group][$extName]['version'] = $tag;
                    }
                    file_put_contents($installedPath, json_encode($installedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $updateError = 'Failed to update extension';
                    log_security_event('extension_update_failed', ['type' => $type, 'name' => $extName, 'tag' => $tag]);
                }
            } elseif (!empty($_FILES['update_package']['tmp_name'])) {
                $tmpPath = $_FILES['update_package']['tmp_name'];
                $result = $updateManager->applyUpdate($type, $name, $tmpPath);
                if ($result) {
                    $updateSuccess = 'Update applied successfully';
                } else {
                    $updateError = 'Failed to apply update';
                }
            } else {
                $updateError = 'No update package uploaded';
            }

            $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager);
        }
    }

    $updateStatus = $updateResults ?? null;
    include __DIR__ . '/../../../views/admin_updates.php';
    return true;
}
