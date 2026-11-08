<?php

function handle_admin_themes(string $method): \Bulletin\Response|bool
{
    global $config, $themeManager, $updateManager;

    $adminThemeError = '';
    $adminThemeSuccess = '';
    file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: method=$method, _POST=" . json_encode($_POST) . ", csrf_token=" . (isset($_POST['csrf_token']) ? 'set' : 'not set') . "\n", FILE_APPEND);
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: Inside POST+csrf block\n", FILE_APPEND);
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }
        if (!rate_limit('admin_themes', 20, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            throw new \Bulletin\TooManyRequestsException('You are performing too many theme operations. Please try again later.');
        }
        file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: After rate_limit, checking install_theme=" . (isset($_POST['install_theme']) ? 'set' : 'not set') . ", install_from_catalog=" . (isset($_POST['install_from_catalog']) ? 'set' : 'not set') . ", activate_theme=" . (isset($_POST['activate_theme']) ? 'set' : 'not set') . ", delete_theme=" . (isset($_POST['delete_theme']) ? 'set' : 'not set') . "\n", FILE_APPEND);
        file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: _FILES[theme_zip][tmp_name]=" . (!empty($_FILES['theme_zip']['tmp_name']) ? $_FILES['theme_zip']['tmp_name'] : 'empty') . "\n", FILE_APPEND);
        if (isset($_POST['install_theme']) && !empty($_FILES['theme_zip']['tmp_name'])) {
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: ENTERED install_theme branch\n", FILE_APPEND);
            $tmpPath = $_FILES['theme_zip']['tmp_name'];
            $result = $themeManager->installFromZip($tmpPath);
            if ($result['success']) {
                $adminThemeSuccess = $result['message'];
            } else {
                $adminThemeError = $result['message'];
            }
        } elseif (isset($_POST['install_from_catalog'])) {
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: ENTERED install_from_catalog branch\n", FILE_APPEND);
            $repo = $_POST['repo'] ?? '';
            $tag = $_POST['tag'] ?? null;
            $name = strtolower($_POST['theme_name'] ?? '');
            if (!empty($config['allow_catalog_only'])) {
                $catalog = isset($updateManager) ? $updateManager->getCatalog() : (json_decode((string)@file_get_contents(__DIR__ . '/../../../data/catalog.json'), true) ?: []);
                $catalogItem = array_values(array_filter($catalog, fn($i) => strtolower($i['name'] ?? '') === $name && strtolower($i['type'] ?? '') === 'theme'));
                if (empty($catalogItem)) {
                    $adminThemeError = 'Catalog-only mode: this entry is not present in the catalog.';
                } elseif (($catalogItem[0]['author_type'] ?? '') === 'third_party') {
                    $adminThemeError = 'Catalog-only mode: third-party themes cannot be installed. Only bulletinbored team themes are allowed.';
                } else {
                    $result = $themeManager->installFromRepo($repo, $tag, $name);
                    if ($result['success']) {
                        $adminThemeSuccess = 'Installed from catalog';
                    } else {
                        $adminThemeError = $result['message'];
                    }
                }
            }
        } elseif (isset($_POST['activate_theme'])) {
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: ENTERED activate_theme branch - START\n", FILE_APPEND);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: isset(_POST[activate_theme])=" . (isset($_POST['activate_theme']) ? 'true' : 'false') . "\n", FILE_APPEND);
            $themeName = $_POST['theme_name'] ?? $_POST['theme'] ?? '';
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: activate_theme POST detected, theme_name=" . ($_POST['theme_name'] ?? 'null') . ", theme=" . ($_POST['theme'] ?? 'null') . "\n", FILE_APPEND);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: themeName resolved to: $themeName\n", FILE_APPEND);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: themeManager object ID: " . spl_object_id($themeManager) . "\n", FILE_APPEND);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: _POST=" . json_encode($_POST) . "\n", FILE_APPEND);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: About to call activate()\n", FILE_APPEND);
            $activateResult = $themeManager->activate($themeName);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: activate() returned: " . ($activateResult ? 'true' : 'false') . "\n", FILE_APPEND);
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: themeManager activeTheme after activate: " . $themeManager->getActive() . "\n", FILE_APPEND);
            if ($activateResult) {
                $adminThemeSuccess = 'Theme activated';
                log_admin_action('theme_activate', ['theme' => $themeName]);
            } else {
                $adminThemeError = 'Theme not found';
            }
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: ENTERED activate_theme branch - END\n", FILE_APPEND);
        } elseif (isset($_POST['delete_theme'])) {
            $themeName = $_POST['theme_name'] ?? $_POST['theme'] ?? '';
            $result = $themeManager->delete($themeName);
            if ($result['success']) {
                $adminThemeSuccess = $result['message'];
                log_admin_action('theme_delete', ['theme' => $themeName]);
            } else {
                $adminThemeError = $result['message'];
            }
        } elseif (isset($_POST['save_theme_settings'])) {
            $config['allow_catalog_only'] = !empty($_POST['allow_catalog_only']) ? 1 : 0;
            file_put_contents(__DIR__ . '/../../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $adminThemeSuccess = t('settings_saved');
        } else {
            file_put_contents('/tmp/theme_debug.log', "THEME DEBUG: No branch matched, _POST=" . json_encode($_POST) . "\n", FILE_APPEND);
        }
    }

    $allThemes = $themeManager->getAll();
    $missingThemes = $themeManager->removeMissing();
    if (!empty($missingThemes)) {
        $installedPath = __DIR__.'/../../../data/installed.json';
        $installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins'=>[], 'themes'=>[]];
        foreach ($missingThemes as $removed) {
            $key = strtolower($removed);
            unset($installed['themes'][$key]);
        }
        file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    include __DIR__ . '/../../../views/admin_themes.php';
    return true;
}