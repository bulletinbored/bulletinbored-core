<?php include __DIR__.'/admin_header.php'; render_admin_header(t('settings')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('site_settings') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('settings') ?></p>
        </div>
    </div>

    <?php if (!empty($_SESSION['settings_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= t('settings_saved') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['email_test_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= t('email_test_success', ['email' => escape($_SESSION['email_test_success'])]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['email_test_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['email_test_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?= t('email_test_error', ['error' => escape($_SESSION['email_test_error'])]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['email_test_error']); ?>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="save_settings" value="1">

        <!-- General -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-sliders me-2"></i> <?= t('general_settings') ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= t('site_name') ?></label>
                        <input type="text" name="site_name" class="form-control" value="<?= escape($config['site_name'] ?? 'bulletinbored') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('site_tagline') ?></label>
                        <input type="text" name="site_tagline" class="form-control" value="<?= escape($config['site_tagline'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="hidden" name="attachments_enabled" value="0">
                            <input type="checkbox" name="attachments_enabled" value="1" class="form-check-input" id="attachments_enabled" <?= !empty($config['attachments_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="attachments_enabled"><?= t('attachments_enabled') ?></label>
                            <div class="form-text"><?= t('attachments_enabled_hint') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-envelope me-2"></i> <?= t('email_settings') ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= t('admin_email') ?></label>
                        <input type="email" name="mail_from" class="form-control" value="<?= escape($config['mail_from'] ?? '') ?>" placeholder="noreply@tuodominio.it">
                        <div class="form-text"><?= t('admin_email_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('mail_from_name') ?></label>
                        <input type="text" name="mail_from_name" class="form-control" value="<?= escape($config['mail_from_name'] ?? '') ?>" placeholder="<?= escape($config['site_name'] ?? 'bulletinbored') ?>">
                        <div class="form-text"><?= t('mail_from_name_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('notify_admin_email') ?></label>
                        <input type="email" name="notify_admin_email" class="form-control" value="<?= escape($config['notify_admin_email'] ?? '') ?>" placeholder="<?= escape($config['mail_from'] ?? '') ?>">
                        <div class="form-text"><?= t('notify_admin_email_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('test_email_address') ?></label>
                        <input type="email" name="test_email_address" class="form-control" value="<?= escape($config['notify_admin_email'] ?? $config['mail_from'] ?? '') ?>" placeholder="test@example.com">
                        <div class="form-text"><?= t('test_email_hint') ?></div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" name="send_test_email" value="1" class="btn btn-outline-primary">
                            <i class="fas fa-paper-plane me-1"></i> <?= t('send_test_email') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Appearance -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-palette me-2"></i> <?= t('appearance_settings') ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label"><?= t('site_icon') ?></label>
                        <input type="text" name="site_icon" class="form-control" value="<?= escape($config['site_icon'] ?? '') ?>">
                        <div class="form-text"><?= t('site_icon_hint') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Regional -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-globe me-2"></i> <?= t('regional_settings') ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= t('timezone') ?></label>
                        <select name="timezone" class="form-select">
                            <option value="UTC" <?= ($config['timezone'] ?? 'UTC') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                            <option value="Europe/Rome" <?= ($config['timezone'] ?? '') === 'Europe/Rome' ? 'selected' : '' ?>>Europe/Rome</option>
                            <option value="Europe/London" <?= ($config['timezone'] ?? '') === 'Europe/London' ? 'selected' : '' ?>>Europe/London</option>
                            <option value="Europe/Paris" <?= ($config['timezone'] ?? '') === 'Europe/Paris' ? 'selected' : '' ?>>Europe/Paris</option>
                            <option value="Europe/Berlin" <?= ($config['timezone'] ?? '') === 'Europe/Berlin' ? 'selected' : '' ?>>Europe/Berlin</option>
                            <option value="America/New_York" <?= ($config['timezone'] ?? '') === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                            <option value="America/Chicago" <?= ($config['timezone'] ?? '') === 'America/Chicago' ? 'selected' : '' ?>>America/Chicago</option>
                            <option value="America/Denver" <?= ($config['timezone'] ?? '') === 'America/Denver' ? 'selected' : '' ?>>America/Denver</option>
                            <option value="America/Los_Angeles" <?= ($config['timezone'] ?? '') === 'America/Los_Angeles' ? 'selected' : '' ?>>America/Los_Angeles</option>
                            <option value="Asia/Tokyo" <?= ($config['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                            <option value="Asia/Shanghai" <?= ($config['timezone'] ?? '') === 'Asia/Shanghai' ? 'selected' : '' ?>>Asia/Shanghai</option>
                            <option value="Australia/Sydney" <?= ($config['timezone'] ?? '') === 'Australia/Sydney' ? 'selected' : '' ?>>Australia/Sydney</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('date_format') ?></label>
                        <select name="date_format" class="form-select">
                            <option value="Y-m-d" <?= ($config['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                            <option value="d/m/Y" <?= ($config['date_format'] ?? '') === 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                            <option value="m/d/Y" <?= ($config['date_format'] ?? '') === 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                            <option value="d.m.Y" <?= ($config['date_format'] ?? '') === 'd.m.Y' ? 'selected' : '' ?>>DD.MM.YYYY</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('time_format') ?></label>
                        <select name="time_format" class="form-select">
                            <option value="H:i" <?= ($config['time_format'] ?? 'H:i') === 'H:i' ? 'selected' : '' ?>>24-hour (HH:MM)</option>
                            <option value="h:i A" <?= ($config['time_format'] ?? '') === 'h:i A' ? 'selected' : '' ?>>12-hour (hh:MM AM/PM)</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Catalog Settings -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-folder me-2"></i> <?= t('catalog_settings') ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= t('allow_catalog_only') ?></label>
                        <select name="allow_catalog_only" class="form-select">
                            <option value="1" <?= ($config['allow_catalog_only'] ?? true) ? 'selected' : '' ?>><?= t('yes') ?></option>
                            <option value="0" <?= (!($config['allow_catalog_only'] ?? true)) ? 'selected' : '' ?>><?= t('no') ?></option>
                        </select>
                        <div class="form-text"><?= t('allow_catalog_only_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-text"><?= t('plugin_verify_files_hint') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center">
        <button type="submit" name="save_settings" value="1" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> <?= t('save_settings') ?>
        </button>
    </div>
</form>