<?php

function handle_admin_settings_post(): ?string
{
    global $config;

    if (!csrf_validate_request()) {
        $_SESSION['settings_error'] = 'CSRF token invalid';
        return redirect(url('admin_settings'));
    }

    $siteName = trim($_POST['site_name'] ?? $config['site_name']);
    $siteTagline = trim($_POST['site_tagline'] ?? $config['site_tagline']);
    $siteIcon = trim($_POST['site_icon'] ?? $config['site_icon']);
    $siteLogo = trim($_POST['site_logo'] ?? ($config['site_logo'] ?? ''));
    $siteFavicon = trim($_POST['site_favicon'] ?? ($config['site_favicon'] ?? ''));
    $timezone = trim($_POST['timezone'] ?? $config['timezone']);
    $dateFormat = trim($_POST['date_format'] ?? $config['date_format']);
    $timeFormat = trim($_POST['time_format'] ?? $config['time_format']);
    $attachmentsEnabled = !empty($_POST['attachments_enabled']) ? 1 : 0;
    $allowCatalogOnly = !empty($_POST['allow_catalog_only']) ? 1 : 0;
    $pluginVerifyFiles = !empty($_POST['plugin_verify_files']) ? 1 : 0;

    $config['site_name'] = $siteName;
    $config['site_tagline'] = $siteTagline;
    $config['site_icon'] = $siteIcon;
    $config['site_logo'] = $siteLogo;
    $config['site_favicon'] = $siteFavicon;
    $config['timezone'] = $timezone;
    $config['date_format'] = $dateFormat;
    $config['time_format'] = $timeFormat;
    $config['attachments_enabled'] = $attachmentsEnabled;
    $config['allow_catalog_only'] = $allowCatalogOnly;
    $config['plugin_verify_files'] = $pluginVerifyFiles;

    if (isset($_POST['remove_logo'])) {
        $config['site_logo'] = '';
    }
    if (isset($_POST['remove_favicon'])) {
        $config['site_favicon'] = '';
    }

    file_put_contents(__DIR__ . '/../../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    $_SESSION['settings_saved'] = true;
    header('Cache-Control: no-store');
    include __DIR__ . '/../../../views/admin_settings.php';
    return null;
}

function handle_admin_settings_get(): \Bulletin\Response|bool
{
    global $config;
    include __DIR__ . '/../../../views/admin_settings.php';
    return true;
}

function handle_admin_upload_site_image(): \Bulletin\Response|bool
{
    global $config;

    if (!csrf_validate_request()) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'CSRF token invalid'], 403);
    }
    if (empty($_FILES['site_image']['tmp_name'])) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'No file uploaded'], 400);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    $maxSize = 2 * 1024 * 1024;
    $info = validate_upload($_FILES['site_image']['tmp_name'], $_FILES['site_image']['name'] ?? '', $allowed, $maxSize);
    if ($info === null) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Invalid image. Allowed: JPG, PNG, GIF, WebP, SVG, ICO. Max 2MB.'], 400);
    }

    $uploadDir = __DIR__ . '/../../../uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $dest = $uploadDir . '/' . $info['safe_name'];
    if (!move_uploaded_file($_FILES['site_image']['tmp_name'], $dest)) {
        return \Bulletin\Response::json(['ok' => false, 'error' => 'Failed to save file'], 500);
    }

    $url = base_url() . '/uploads/' . $info['safe_name'];
    return \Bulletin\Response::json(['ok' => true, 'url' => $url, 'filename' => $info['safe_name'], 'csrf_token' => generate_csrf_token()]);
}

function handle_admin_get_images(): \Bulletin\Response|bool
{
    $images = get_uploaded_images();
    return \Bulletin\Response::json(['ok' => true, 'images' => $images]);
}

function handle_admin_smtp_get(): \Bulletin\Response|bool
{
    include __DIR__ . '/../../../views/admin_smtp.php';
    return true;
}

function handle_admin_smtp_post(): ?string
{
    global $config;

    if (!csrf_validate_request()) {
        return 'CSRF token invalid';
    }

    if (isset($_POST['send_smtp_test'])) {
        $testEmail = trim($_POST['smtp_test_to'] ?? '');
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['smtp_test_error'] = t('invalid_email_address');
        } else {
            $subject = 'Test email from ' . ($config['site_name'] ?? 'bulletinbored');
            $body = '<p>This is a test email sent from your forum\'s SMTP configuration page.</p>'
                  . '<p>If you received this, your SMTP settings are correct.</p>';
            $sent = send_email($testEmail, $subject, $body);
            if ($sent) {
                $_SESSION['smtp_test_success'] = $testEmail;
            } else {
                $err = error_get_last();
                $_SESSION['smtp_test_error'] = $err['message'] ?? 'Unknown error';
            }
        }
        return redirect(url('admin_smtp'));
    }

    $config['mail_method'] = ($_POST['mail_method'] ?? '') === 'smtp' ? 'smtp' : 'mail';
    $config['mail_host'] = trim($_POST['mail_host'] ?? ($config['mail_host'] ?? ''));
    $config['mail_port'] = (int)($_POST['mail_port'] ?? ($config['mail_port'] ?? 25));
    $config['mail_username'] = trim($_POST['mail_username'] ?? ($config['mail_username'] ?? ''));
    $newPassword = $_POST['mail_password'] ?? '';
    if ($newPassword !== '') {
        $config['mail_password'] = $newPassword;
    }
    $config['mail_secure'] = in_array($_POST['mail_secure'] ?? '', ['ssl', 'tls'], true) ? $_POST['mail_secure'] : '';
    $config['mail_timeout'] = (int)($_POST['mail_timeout'] ?? ($config['mail_timeout'] ?? 10));
    $config['mail_from'] = trim($_POST['mail_from'] ?? ($config['mail_from'] ?? ''));
    $config['mail_from_name'] = trim($_POST['mail_from_name'] ?? ($config['mail_from_name'] ?? ''));
    $config['notify_admin_email'] = trim($_POST['notify_admin_email'] ?? ($config['notify_admin_email'] ?? ''));

    file_put_contents(__DIR__ . '/../../../config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $_SESSION['smtp_saved'] = true;
    return redirect(url('admin_smtp'));

    return null;
}
