<?php

/**
 * Applies a single update target. Returns 'applied', 'skipped' or 'failed'.
 */
function admin_apply_update_target(\UpdateManager $updateManager, array &$config, $pluginManager, $themeManager, string $type, string $name, string $tag): string
{
    if ($type === 'core') {
        if (version_compare($tag, $config['version'] ?? '1.0.0', '<=')) {
            return 'skipped';
        }
        if ($updateManager->applyCoreUpdate($tag)) {
            log_security_event('core_update', ['tag' => $tag]);
            clearstatcache();
            $versionFile = __DIR__ . '/../../../VERSION';
            if (file_exists($versionFile)) {
                $config['version'] = trim(@file_get_contents($versionFile));
            }
            return 'applied';
        }
        log_security_event('core_update_failed', ['tag' => $tag]);
        return 'failed';
    }

    if ($type !== 'plugins' && $type !== 'themes') {
        return 'failed';
    }

    $installedVersion = '1.0.0';
    if ($type === 'plugins' && $pluginManager) {
        $plugin = $pluginManager->getAll();
        $plugin = $plugin[$name] ?? null;
        $installedVersion = $plugin['version'] ?? '1.0.0';
    } elseif ($type === 'themes' && $themeManager) {
        $theme = $themeManager->getAll();
        $theme = $theme[$name] ?? null;
        $installedVersion = $theme['version'] ?? '1.0.0';
    }

    if (version_compare($tag, $installedVersion, '<=')) {
        return 'skipped';
    }

    if (!$updateManager->applyExtensionUpdate($type === 'plugins' ? 'plugin' : 'theme', $name, $tag)) {
        log_security_event('extension_update_failed', ['type' => $type, 'name' => $name, 'tag' => $tag]);
        return 'failed';
    }

    log_security_event('extension_update', ['type' => $type, 'name' => $name, 'tag' => $tag]);
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
    if (isset($installedData[$group][$name])) {
        $installedData[$group][$name]['version'] = $tag;
    }
    file_put_contents($installedPath, json_encode($installedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return 'applied';
}

function handle_admin_updates(string $method): \Bulletin\Response|bool
{
    global $config, $pluginManager, $themeManager, $updateManager;

    $updateResults = null;
    $updateError = '';
    $updateSuccess = '';

    if ($method === 'POST' && isset($_POST['check_updates'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } elseif (!rate_limit('admin_updates_check', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            $updateError = 'You are checking for updates too fast. Please try again later.';
        } else {
            // Explicit check: force-refresh the catalog (which is also cached to
            // data/catalog.json) so newly catalogued extensions are seen.
            $catalog = $updateManager->getCatalog(true);
            $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager, $catalog);
        }
    }

    if ($method === 'POST' && isset($_POST['apply_update'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } elseif (!rate_limit('admin_updates_apply', 10, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            $updateError = 'You are applying updates too fast. Please try again later.';
        } else {
            $type = $_POST['type'] ?? '';
            $name = $_POST['name'] ?? '';
            $tag = '';
            if ($type === 'core' && !empty($_POST['core_tag'])) {
                $tag = ltrim($_POST['core_tag'], 'v');
            } elseif (($type === 'plugins' || $type === 'themes') && !empty($_POST['ext_tag'])) {
                $tag = ltrim($_POST['ext_tag'], 'v');
            }

            if ($tag !== '') {
                $status = admin_apply_update_target($updateManager, $config, $pluginManager, $themeManager, $type, $name, $tag);
                if ($status === 'applied') {
                    $updateSuccess = ($type === 'core' ? 'Core' : 'Extension') . ' updated to v' . escape($tag);
                } elseif ($status === 'skipped') {
                    $updateError = 'No newer version available';
                } else {
                    $updateError = $type === 'core' ? 'Failed to update core' : 'Failed to update extension';
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

    if ($method === 'POST' && isset($_POST['apply_all_updates'])) {
        if (!csrf_validate_request()) {
            $updateError = 'Invalid CSRF token';
        } elseif (!rate_limit('admin_updates_apply_all', 5, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            $updateError = 'You are applying updates too fast. Please try again later.';
        } else {
            $status = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager);
            $applied = 0;
            $failed = 0;

            foreach (['plugins', 'themes'] as $group) {
                foreach ($status[$group] ?? [] as $extName => $info) {
                    if (!($info['update_available'] ?? false)) {
                        continue;
                    }
                    $tag = ltrim((string)($info['remote'] ?? ''), 'v');
                    if ($tag === '') {
                        continue;
                    }
                    $result = admin_apply_update_target($updateManager, $config, $pluginManager, $themeManager, $group, $extName, $tag);
                    if ($result === 'applied') {
                        $applied++;
                    } elseif ($result === 'failed') {
                        $failed++;
                    }
                }
            }

            if ($status['core']['update_available'] ?? false) {
                $tag = ltrim((string)($status['core']['remote'] ?? ''), 'v');
                if ($tag !== '') {
                    $result = admin_apply_update_target($updateManager, $config, $pluginManager, $themeManager, 'core', 'core', $tag);
                    if ($result === 'applied') {
                        $applied++;
                    } elseif ($result === 'failed') {
                        $failed++;
                    }
                }
            }

            if ($applied > 0) {
                $updateSuccess = t('update_all_success', ['n' => $applied]);
            }
            if ($failed > 0) {
                $updateError = t('update_all_failed', ['n' => $failed]);
            }
            if ($applied === 0 && $failed === 0) {
                $updateError = t('update_all_none');
            }

            $updateResults = $updateManager->checkAll($config['version'] ?? '1.0.0', $pluginManager, $themeManager);
        }
    }

    $updateStatus = $updateResults ?? null;
    include __DIR__ . '/../../../views/admin_updates.php';
    return true;
}
