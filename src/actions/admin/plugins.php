<?php

function handle_admin_plugins(string $method): \Bulletin\Response|bool
{
    global $config, $pluginManager, $updateManager;

    $adminPluginError = '';
    $adminPluginSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }
        if (!rate_limit('admin_plugins', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            throw new \Bulletin\TooManyRequestsException('You are performing too many plugin operations. Please try again later.');
        }
        if (isset($_POST['save_plugin_settings'])) {
            $config['allow_catalog_only'] = !empty($_POST['allow_catalog_only']) ? 1 : 0;
            $config['plugin_verify_files'] = !empty($_POST['plugin_verify_files']) ? 1 : 0;

            file_put_contents(__DIR__ . '/../../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $adminPluginSuccess = t('settings_saved');
        } elseif (isset($_POST['install_plugin']) && !empty($_FILES['plugin_zip']['tmp_name'])) {
            $tmpPath = $_FILES['plugin_zip']['tmp_name'];
            // Detect if this is an update (plugin already exists)
            $zip = new ZipArchive();
            $pluginName = null;
            if ($zip->open($tmpPath) === true) {
                $manifestRaw = $zip->getFromName('manifest.json');
                if ($manifestRaw !== false) {
                    $manifest = json_decode($manifestRaw, true);
                    $pluginName = strtolower($manifest['name'] ?? '');
                }
                $zip->close();
            }
            
            $isUpdate = $pluginName && $pluginManager->isEnabled($pluginName);
            
            if ($isUpdate) {
                $result = $pluginManager->updateFromZip($pluginName, $tmpPath);
                log_security_event('plugin_update', ['plugin' => $pluginName, 'success' => (int)$result['success'], 'message' => $result['message']]);
            } else {
                $result = $pluginManager->installFromZip($tmpPath);
                log_security_event('plugin_install', ['plugin' => $_POST['plugin_name'] ?? 'unknown', 'success' => (int)$result['success'], 'message' => $result['message']]);
            }
            
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
                $catalog = isset($updateManager) ? $updateManager->getCatalog() : (json_decode((string)@file_get_contents(__DIR__ . '/../../../data/catalog.json'), true) ?: []);
                $catalogItem = array_values(array_filter($catalog, fn($i) => strtolower($i['name'] ?? '') === $name && strtolower($i['type'] ?? '') === 'plugin'));
                if (empty($catalogItem)) {
                    $adminPluginError = 'Catalog-only mode: this entry is not present in the catalog.';
                } elseif (($catalogItem[0]['author_type'] ?? '') === 'third_party') {
                    $adminPluginError = 'Catalog-only mode: third-party plugins cannot be installed. Only bulletinbored team plugins are allowed.';
                } else {
                    $result = $pluginManager->installFromRepo($repo, $tag, $name);
                    if ($result['success']) {
                        $adminPluginSuccess = 'Installed from catalog';
                    } else {
                        $adminPluginError = $result['message'];
                    }
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
