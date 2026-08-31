<?php

function handle_admin_themes(string $method): \Bulletin\Response|bool
{
    global $config, $themeManager;

    $adminThemeError = '';
    $adminThemeSuccess = '';
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $adminThemeError = 'Invalid CSRF token';
        } else {
            if (isset($_POST['install_theme']) && !empty($_FILES['theme_zip']['tmp_name'])) {
                $tmpPath = $_FILES['theme_zip']['tmp_name'];
                $result = $themeManager->installFromZip($tmpPath);
                if ($result['success']) {
                    $adminThemeSuccess = $result['message'];
                } else {
                    $adminThemeError = $result['message'];
                }
            } elseif (isset($_POST['install_from_catalog'])) {
                $repo = $_POST['repo'] ?? '';
                $tag = $_POST['tag'] ?? null;
                $name = strtolower($_POST['theme_name'] ?? '');
                if (!empty($config['allow_catalog_only'])) {
                    $catalogMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
                    $remoteCatalogRaw = @file_get_contents($catalogMirrorBase . '/catalog.json');
                    $remoteCatalog = is_string($remoteCatalogRaw) ? json_decode($remoteCatalogRaw, true) : null;
                    $catalog = is_array($remoteCatalog) ? $remoteCatalog : (json_decode(file_get_contents(__DIR__ . '/../../../data/catalog.json'), true) ?: []);
                    $catalogItem = array_filter($catalog, fn($i) => strtolower($i['name'] ?? '') === $name && strtolower($i['type'] ?? '') === 'theme');
                    $catalogItem = array_values($catalogItem);
                    if (empty($catalogItem)) {
                        $adminThemeError = 'Catalog-only mode: this entry is not present in the catalog.';
                        goto skip_catalog_install_theme;
                    }
                    if (($catalogItem[0]['author_type'] ?? '') === 'third_party') {
                        $adminThemeError = 'Catalog-only mode: third-party themes cannot be installed. Only bulletinbored team themes are allowed.';
                        goto skip_catalog_install_theme;
                    }
                }
                skip_catalog_install_theme:
                if ($repo === '' || $name === '') {
                    $adminThemeError = 'Invalid catalog item';
                } else {
                    $result = $themeManager->installFromRepo($repo, $tag, $name);
                    if ($result['success']) {
                        $adminThemeSuccess = 'Installed from catalog';
                    } else {
                        $adminThemeError = $result['message'];
                    }
                }
            } elseif (isset($_POST['activate_theme'])) {
                $themeName = $_POST['theme_name'] ?? '';
                if ($themeManager->activate($themeName)) {
                    $adminThemeSuccess = 'Theme activated';
                    log_admin_action('theme_activate', ['theme' => $themeName]);
                } else {
                    $adminThemeError = 'Theme not found';
                }
            } elseif (isset($_POST['delete_theme'])) {
                $themeName = $_POST['theme_name'] ?? '';
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
            }
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
