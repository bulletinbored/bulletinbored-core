<?php include __DIR__.'/admin_header.php'; render_admin_header(t('settings')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('site_settings') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('settings') ?></p>
        </div>
        <button type="submit" form="settingsForm" name="save_settings" value="1" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> <?= t('save_settings') ?>
        </button>
    </div>

    <?php if (!empty($_SESSION['settings_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= t('settings_saved') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="POST" id="settingsForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="save_settings" value="1">

        <!-- Row 1: General + Regional -->
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-sliders-h me-2"></i><?= t('general_settings') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><?= t('site_name') ?></label>
                            <input type="text" name="site_name" class="form-control" value="<?= escape($config['site_name'] ?? 'bulletinbored') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('site_tagline') ?></label>
                            <input type="text" name="site_tagline" class="form-control" value="<?= escape($config['site_tagline'] ?? '') ?>">
                        </div>
                        <div class="form-check form-switch">
                            <input type="hidden" name="attachments_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="attachments_enabled" value="1" id="attachments_enabled" <?= !empty($config['attachments_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="attachments_enabled"><?= t('attachments_enabled') ?></label>
                        </div>
                        <div class="form-text"><?= t('attachments_enabled_hint') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-globe me-2"></i><?= t('regional_settings') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
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
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label"><?= t('date_format') ?></label>
                                <select name="date_format" class="form-select">
                                    <option value="Y-m-d" <?= ($config['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                    <option value="d/m/Y" <?= ($config['date_format'] ?? '') === 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                    <option value="m/d/Y" <?= ($config['date_format'] ?? '') === 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                    <option value="d.m.Y" <?= ($config['date_format'] ?? '') === 'd.m.Y' ? 'selected' : '' ?>>DD.MM.YYYY</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label"><?= t('time_format') ?></label>
                                <select name="time_format" class="form-select">
                                    <option value="H:i" <?= ($config['time_format'] ?? 'H:i') === 'H:i' ? 'selected' : '' ?>>24-hour (HH:MM)</option>
                                    <option value="h:i A" <?= ($config['time_format'] ?? '') === 'h:i A' ? 'selected' : '' ?>>12-hour (hh:MM AM/PM)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Appearance + Catalog -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-palette me-2"></i><?= t('appearance_settings') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Site Logo -->
                            <div class="col-6 text-center">
                                <h6 class="fw-bold mb-2"><?= t('site_logo') ?></h6>
                                <div id="logoPreview" class="mb-2 <?= empty($config['site_logo']) ? 'd-none' : '' ?>">
                                    <img src="<?= escape($config['site_logo'] ?? '') ?>" alt="Logo" class="img-thumbnail" style="width:104px; height:104px; object-fit:contain;">
                                </div>
                                <div id="noLogoMsg" class="text-muted small mb-2 <?= !empty($config['site_logo']) ? 'd-none' : '' ?>">
                                    <?= t('no_logo_set') ?>
                                </div>
                                <input type="hidden" name="site_logo" id="siteLogoInput" value="<?= escape($config['site_logo'] ?? '') ?>">
                                <div class="mb-2 d-flex justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="uploadLogoBtn">
                                        <i class="fas fa-upload"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="libraryLogoBtn">
                                        <i class="fas fa-images"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['site_logo']) ? 'd-none' : '' ?>" id="removeLogoBtn">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Favicon -->
                            <div class="col-6 text-center">
                                <h6 class="fw-bold mb-2"><?= t('favicon') ?></h6>
                                <div id="faviconPreview" class="mb-2 <?= empty($config['site_favicon']) ? 'd-none' : '' ?>">
                                    <img src="<?= escape($config['site_favicon'] ?? '') ?>" alt="Favicon" class="img-thumbnail" style="width:104px; height:104px; object-fit:contain; image-rendering:pixelated;">
                                </div>
                                <div id="noFaviconMsg" class="text-muted small mb-2 <?= !empty($config['site_favicon']) ? 'd-none' : '' ?>">
                                    <?= t('no_favicon_set') ?>
                                </div>
                                <input type="hidden" name="site_favicon" id="siteFaviconInput" value="<?= escape($config['site_favicon'] ?? '') ?>">
                                <div class="mb-2 d-flex justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="uploadFaviconBtn">
                                        <i class="fas fa-upload"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="libraryFaviconBtn">
                                        <i class="fas fa-images"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['site_favicon']) ? 'd-none' : '' ?>" id="removeFaviconBtn">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p class="form-text text-muted text-center mt-3 mb-0" style="font-size:0.75rem;"><?= t('image_formats_hint') ?></p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-folder me-2"></i><?= t('catalog_settings') ?></h5>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <label class="form-label mb-0"><?= t('allow_catalog_only') ?></label>
                                <div class="form-text mb-0"><?= t('allow_catalog_only_hint') ?></div>
                            </div>
                            <div class="form-check form-switch">
                                <input type="hidden" name="allow_catalog_only" value="0">
                                <input class="form-check-input" type="checkbox" name="allow_catalog_only" value="1" id="catalogOnlySwitch" <?= ($config['allow_catalog_only'] ?? true) ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Hidden file inputs -->
<input type="file" id="logoUploadInput" accept="image/*" class="d-none">
<input type="file" id="faviconUploadInput" accept="image/*,.ico,.svg" class="d-none">

<!-- Media Library Modal -->
<div class="modal fade" id="mediaLibraryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= t('media_library') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('close') ?>"></button>
            </div>
            <div class="modal-body">
                <div id="mediaLibraryLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2"><?= t('loading_available_languages') ?></p>
                </div>
                <div id="mediaLibraryEmpty" class="text-center py-4 d-none">
                    <i class="fas fa-images fa-2x text-muted mb-2"></i>
                    <p class="text-muted"><?= t('media_library_empty') ?></p>
                </div>
                <div id="mediaLibraryGrid" class="row g-3 d-none"></div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
var BASE_URL = '<?= base_url() ?>';
var SELECT_IMAGE_TEXT = '<?= t('select_image') ?>';
</script>
<script src="<?= htmlspecialchars(base_url() . '/assets/js/admin-media-library.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>

<?php include __DIR__.'/admin_footer.php'; ?>
