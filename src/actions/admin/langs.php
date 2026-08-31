<?php

function handle_admin_langs(string $method)
{
    global $config, $pdo;

    $langMetaPath = __DIR__ . '/../../../data/lang-meta.json';
    $langMirrorBase = !empty($config['update_mirror']) ? rtrim($config['update_mirror'], '/') : 'https://extend.bulletinbored.net';
    $langsJsonUrl = $langMirrorBase . '/langs.json';

    if (!function_exists('loadLangMeta')) {
        function loadLangMeta(string $path): array {
            if (!file_exists($path)) {
                return [];
            }
            $data = json_decode(file_get_contents($path), true);
            return is_array($data) ? $data : [];
        }
    }
    if (!function_exists('saveLangMeta')) {
        function saveLangMeta(string $code, string $sha): void {
            global $langMetaPath;
            $path = $langMetaPath ?: __DIR__ . '/../../../data/lang-meta.json';
            $meta = loadLangMeta($path);
            $meta[$code] = ['sha' => $sha, 'updated' => date('c')];
            @file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    $langMeta = loadLangMeta($langMetaPath);
    $remoteLangsRaw = @file_get_contents($langsJsonUrl);
    $remoteLangs = is_string($remoteLangsRaw) ? json_decode($remoteLangsRaw, true) : null;
    if (!is_array($remoteLangs)) {
        $remoteLangs = [];
    }

    $langSuccess = $_SESSION['lang_success'] ?? '';
    $langError = $_SESSION['lang_error'] ?? '';
    unset($_SESSION['lang_success'], $_SESSION['lang_error']);
    if ($method === 'POST' && isset($_POST['csrf_token'])) {
        if (!csrf_validate_request()) {
            $langError = 'Invalid CSRF token';
        } else {
            if (isset($_POST['save_lang_settings'])) {
                $defaultLang = trim($_POST['default_lang'] ?? $config['default_lang'] ?? 'en');
                $config['default_lang'] = $defaultLang;
                $installedLangs = [];
                foreach (glob(__DIR__ . '/../../../lang/*.json') as $file) {
                    $installedLangs[] = basename($file, '.json');
                }
                $config['available_langs'] = array_values(array_unique($installedLangs));
                file_put_contents(__DIR__ . '/../../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $_SESSION['lang_success'] = 'Language settings saved';
                return redirect(url('admin_langs'));
            } elseif (isset($_POST['upload_lang']) && !empty($_FILES['lang_file']['tmp_name'])) {
                $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                if ($langCode === '') {
                    $langError = 'Invalid language code';
                } else {
                    $dest = __DIR__ . '/../../../lang/'.$langCode.'.json';
                    if (file_exists($dest)) {
                        $langError = 'Language file already exists: '.escape($langCode);
                    } else {
                        $raw = file_get_contents($_FILES['lang_file']['tmp_name']);
                        if ($raw === false) {
                            $langError = 'Failed to read uploaded file';
                        } else {
                            $decoded = json_decode($raw, true);
                            $valid = is_array($decoded);
                            if ($valid) {
                                foreach ($decoded as $k => $v) {
                                    if (!is_string($k) || !is_string($v)) {
                                        $valid = false;
                                        break;
                                    }
                                }
                            }
                            if (!$valid) {
                                $langError = 'Language file must be a JSON array of "key": "translation" strings';
                            } elseif (move_uploaded_file($_FILES['lang_file']['tmp_name'], $dest)) {
                                $_SESSION['lang_success'] = 'Language file uploaded: '.escape($langCode);
                                return redirect(url('admin_langs'));
                            } else {
                                $langError = 'Failed to upload language file';
                            }
                        }
                    }
                }
            } elseif (isset($_POST['install_github_lang']) || isset($_POST['update_github_lang'])) {
                $isUpdate = isset($_POST['update_github_lang']);
                $langCode = preg_replace('/[^a-z_]/', '', strtolower($_POST['lang_code'] ?? ''));
                $downloadUrl = trim($_POST['download_url'] ?? '');
                $remoteSha = $_POST['remote_sha'] ?? '';
                if ($langCode === '' || $downloadUrl === '') {
                    $langError = 'Invalid language code or download URL';
                } else {
                    ob_start();
                    $prevDisplayErrors = ini_get('display_errors');
                    @ini_set('display_errors', '0');
                    $parsed = parse_url($downloadUrl);
                    $allowed = false;
                    if (
                        $parsed
                        && ($parsed['scheme'] ?? '') === 'https'
                        && !str_contains($parsed['path'], '..')
                    ) {
                        if (
                            in_array($parsed['host'], ['github.com', 'raw.githubusercontent.com'], true)
                            && str_starts_with($parsed['path'], '/bulletinbored/langs/')
                        ) {
                            $allowed = true;
                        }
                        $mirrorHost = parse_url($langMirrorBase, PHP_URL_HOST);
                        if ($mirrorHost && ($parsed['host'] ?? '') === $mirrorHost) {
                            $allowed = true;
                        }
                    }
                    if (!$allowed) {
                        $langError = 'Invalid download URL. Only URLs from the official language repository are allowed.';
                    } else {
                        $dest = __DIR__ . '/../../../lang/'.$langCode.'.json';
                        if ($isUpdate && !file_exists($dest)) {
                            $langError = 'Language file not found: '.escape($langCode);
                        } elseif (!$isUpdate && file_exists($dest)) {
                            $langError = 'Language file already exists: '.escape($langCode);
                        } else {
                            $candidateUrls = [$downloadUrl];
                            if (preg_match('#\.php$#i', $downloadUrl)) {
                                $candidateUrls[] = substr($downloadUrl, 0, -4) . '.json';
                            } else {
                                $candidateUrls[] = substr($downloadUrl, 0, -5) . '.php';
                            }

                            $data = null;
                            foreach ($candidateUrls as $tryUrl) {
                                $content = @file_get_contents($tryUrl);
                                if ($content === false) {
                                    continue;
                                }
                                if (str_ends_with($tryUrl, '.json')) {
                                    $decoded = json_decode($content, true);
                                    if (is_array($decoded)) {
                                        $data = $decoded;
                                        break;
                                    }
                                } else {
                                    $decoded = @eval('?>' . $content);
                                    if (is_array($decoded)) {
                                        $data = $decoded;
                                        break;
                                    }
                                }
                            }

                            if ($data === null) {
                                    $langError = 'Invalid language file from repository';
                                } elseif (!is_writable(dirname($dest))) {
                                    $langError = 'Language directory is not writable. Please check permissions.';
                                } else {
                                    $written = @file_put_contents($dest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                    if ($written === false) {
                                        $langError = 'Failed to save language file';
                                    } else {
                                        saveLangMeta($langCode, $remoteSha);
                                        $_SESSION['lang_success'] = ($isUpdate ? 'Language file updated: ' : 'Language file installed: ') . escape($langCode);
                                        ob_end_clean();
                                        @ini_set('display_errors', $prevDisplayErrors);
                                        return redirect(url('admin_langs'));
                                    }
                                }
                        }
                    }
                }
                @ini_set('display_errors', $prevDisplayErrors);
                ob_end_clean();
            } elseif (isset($_POST['delete_lang'])) {
                $langCode = $_POST['lang_code'] ?? '';
                $langCode = preg_replace('/[^a-z_]/', '', strtolower($langCode));
                $dest = __DIR__ . '/../../../lang/'.$langCode.'.json';
                if ($langCode === $config['default_lang']) {
                    $langError = 'Cannot delete the default language';
                } elseif (file_exists($dest)) {
                    @unlink($dest);
                    $_SESSION['lang_success'] = 'Language file deleted: '.escape($langCode);
                    return redirect(url('admin_langs'));
                } else {
                    $langError = 'Language file not found';
                }
            }
        }
    }

    $langFiles = glob(__DIR__ . '/../../../lang/*.json');
    $langOptions = [];
    foreach ($langFiles as $file) {
        $code = basename($file, '.json');
        $langOptions[] = $code;
    }
    include __DIR__ . '/../../../views/admin_langs.php';
    return true;
}
