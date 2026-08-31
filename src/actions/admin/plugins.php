<?php

function handle_admin_plugins(string $method): \Bulletin\Response|bool
{
    global $config, $pluginManager;

    $adminPluginError = '';
    $adminPluginSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $adminPluginError = 'Invalid CSRF token';
        } else {
            if (isset($_POST['save_plugin_settings'])) {
                $config['allow_catalog_only'] = !empty($_POST['allow_catalog_only']) ? 1 : 0;
                $config['plugin_verify_files'] = !empty($_POST['plugin_verify_files']) ? 1 : 0;

                file_put_contents(__DIR__ . '/../../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $adminPluginSuccess = t('settings_saved');
            } elseif (isset($_POST['install_plugin']) && !empty($_FILES['plugin_zip']['tmp_name'])) {
                $tmpPath = $_FILES['plugin_zip']['tmp_name'];
                $result = $pluginManager->installFromZip($tmpPath);
                log_security_event('plugin_install', ['plugin' => $_POST['plugin_name'] ?? 'unknown', 'success' => (int)$result['success'], 'message' => $result['message']]);
                if ($result['success']) {
                    $adminPluginSuccess = $result['message'];
                } else {
                    $adminPluginError = $result['message'];
                }
            } elseif (isset($_POST['install_from_catalog'])) {
                $repo = $_POST['repo'] ?? '';
                $tag = $_POST['tag'] ?? null;
                $name = strtolower($_POST['plugin_name'] ?? '');
                if (!empty($config['allow_catalog_only'])) {
                    $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
                    $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
                    $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
                    $catalog = is_array($remoteCatalog) ? $remoteCatalog : (json_decode(file_get_contents(__DIR__ . '/../../../data/catalog.json'), true) ?: []);
                    $catalogItem = array_filter($catalog, fn($i) => strtolower($i['name'] ?? '') === $name && strtolower($i['type'] ?? '') === 'plugin');
                    $catalogItem = array_values($catalogItem);
                    if (empty($catalogItem)) {
                        $adminPluginError = 'Catalog-only mode: this entry is not present in the catalog.';
                        goto skip_catalog_install;
                    }
                    if (($catalogItem[0]['author_type'] ?? '') === 'third_party') {
                        $adminPluginError = 'Catalog-only mode: third-party plugins cannot be installed. Only bulletinbored team plugins are allowed.';
                        goto skip_catalog_install;
                    }
                }
                skip_catalog_install:
                if ($repo === '' || $name === '') {
                    $adminPluginError = 'Invalid catalog item';
                } else {
                    $result = $pluginManager->installFromRepo($repo, $tag, $name);
                    if ($result['success']) {
                        $adminPluginSuccess = 'Installed from catalog';
                    } else {
                        $adminPluginError = $result['message'];
                    }
                }
            } elseif (isset($_POST['delete_plugin'])) {
                $pluginName = $_POST['plugin_name'] ?? '';
                $result = $pluginManager->uninstall($pluginName);
                if ($result['success']) {
                    $adminPluginSuccess = $result['message'];
                    log_admin_action('plugin_delete', ['plugin' => $pluginName]);
                } else {
                    $adminPluginError = $result['message'];
                }
            } elseif (isset($_POST['action'])) {
                $pluginName = $_POST['plugin_name'] ?? '';
                if ($_POST['action'] === 'enable') {
                    if ($pluginManager->enable($pluginName)) {
                        $adminPluginSuccess = 'Plugin enabled';
                        log_admin_action('plugin_enable', ['plugin' => $pluginName]);
                    } else {
                        $adminPluginError = 'Plugin not found or dependencies not met';
                    }
                } elseif ($_POST['action'] === 'disable') {
                    if ($pluginManager->disable($pluginName)) {
                        $adminPluginSuccess = 'Plugin disabled';
                        log_admin_action('plugin_disable', ['plugin' => $pluginName]);
                    } else {
                        $adminPluginError = 'Plugin not found';
                    }
                }
            }
        }
    }

    $allPlugins = $pluginManager->getAll();
    $missingPlugins = $pluginManager->removeMissing();
    if (!empty($missingPlugins)) {
        $installedPath = __DIR__.'/../../../data/installed.json';
        $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
        foreach ($missingPlugins as $removed) {
            $key = strtolower($removed);
            unset($installed['plugins'][$key]);
        }
        file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    include __DIR__ . '/../../../views/admin_plugins.php';
    return true;
}
