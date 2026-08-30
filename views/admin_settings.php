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
                    <!-- Site Logo -->
                    <div class="col-md-6">
                        <label class="form-label"><?= t('site_logo') ?></label>
                        <div id="logoPreview" class="mb-2 <?= empty($config['site_logo']) ? 'd-none' : '' ?>">
                            <img src="<?= escape($config['site_logo'] ?? '') ?>" alt="Logo" style="max-height:60px; max-width:200px;" class="img-thumbnail">
                        </div>
                        <div id="noLogoMsg" class="text-muted small mb-2 <?= !empty($config['site_logo']) ? 'd-none' : '' ?>">
                            <?= t('no_logo_set') ?>
                        </div>
                        <input type="hidden" name="site_logo" id="siteLogoInput" value="<?= escape($config['site_logo'] ?? '') ?>">
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="uploadLogoBtn">
                                <i class="fas fa-upload me-1"></i> <?= t('upload_logo') ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="libraryLogoBtn">
                                <i class="fas fa-images me-1"></i> <?= t('choose_from_library') ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['site_logo']) ? 'd-none' : '' ?>" id="removeLogoBtn">
                                <i class="fas fa-trash me-1"></i> <?= t('remove_logo') ?>
                            </button>
                        </div>
                        <div class="form-text"><?= t('site_logo_hint') ?></div>
                    </div>

                    <!-- Favicon -->
                    <div class="col-md-6">
                        <label class="form-label"><?= t('favicon') ?></label>
                        <div id="faviconPreview" class="mb-2 <?= empty($config['site_favicon']) ? 'd-none' : '' ?>">
                            <img src="<?= escape($config['site_favicon'] ?? '') ?>" alt="Favicon" style="width:32px; height:32px;" class="img-thumbnail">
                        </div>
                        <div id="noFaviconMsg" class="text-muted small mb-2 <?= !empty($config['site_favicon']) ? 'd-none' : '' ?>">
                            <?= t('no_favicon_set') ?>
                        </div>
                        <input type="hidden" name="site_favicon" id="siteFaviconInput" value="<?= escape($config['site_favicon'] ?? '') ?>">
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="uploadFaviconBtn">
                                <i class="fas fa-upload me-1"></i> <?= t('upload_favicon') ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="libraryFaviconBtn">
                                <i class="fas fa-images me-1"></i> <?= t('choose_from_library') ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['site_favicon']) ? 'd-none' : '' ?>" id="removeFaviconBtn">
                                <i class="fas fa-trash me-1"></i> <?= t('remove_favicon') ?>
                            </button>
                        </div>
                        <div class="form-text"><?= t('favicon_hint') ?></div>
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
var currentTarget = null;
var mediaModal = null;

document.addEventListener('DOMContentLoaded', function() {
    mediaModal = new bootstrap.Modal(document.getElementById('mediaLibraryModal'));

    var uploadLogoBtn = document.getElementById('uploadLogoBtn');
    if (uploadLogoBtn) uploadLogoBtn.addEventListener('click', uploadLogo);

    var uploadFaviconBtn = document.getElementById('uploadFaviconBtn');
    if (uploadFaviconBtn) uploadFaviconBtn.addEventListener('click', uploadFavicon);

    var libraryLogoBtn = document.getElementById('libraryLogoBtn');
    if (libraryLogoBtn) libraryLogoBtn.addEventListener('click', function() { openMediaLibrary('logo'); });

    var libraryFaviconBtn = document.getElementById('libraryFaviconBtn');
    if (libraryFaviconBtn) libraryFaviconBtn.addEventListener('click', function() { openMediaLibrary('favicon'); });

    var removeLogoBtn = document.getElementById('removeLogoBtn');
    if (removeLogoBtn) removeLogoBtn.addEventListener('click', removeLogo);

    var removeFaviconBtn = document.getElementById('removeFaviconBtn');
    if (removeFaviconBtn) removeFaviconBtn.addEventListener('click', removeFavicon);
});

function uploadLogo() {
    var input = document.getElementById('logoUploadInput');
    input.onchange = function() {
        if (input.files.length > 0) {
            uploadFile(input.files[0], 'logo');
        }
    };
    input.click();
}

function uploadFavicon() {
    var input = document.getElementById('faviconUploadInput');
    input.onchange = function() {
        if (input.files.length > 0) {
            uploadFile(input.files[0], 'favicon');
        }
    };
    input.click();
}

function uploadFile(file, target) {
    var formData = new FormData();
    formData.append('site_image', file);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

    fetch('<?= base_url() ?>/admin/upload-site-image', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => { throw new Error('HTTP ' + r.status + ': ' + text.substring(0, 200)); });
        }
        return r.json().catch(e => { throw new Error('Invalid JSON response'); });
    })
    .then(data => {
        if (data.ok) {
            if (data.csrf_token) {
                var csrfInput = document.querySelector('input[name="csrf_token"]');
                if (csrfInput) csrfInput.value = data.csrf_token;
            }
            if (target === 'logo') {
                setLogo(data.url);
            } else {
                setFavicon(data.url);
            }
        } else {
            alert(data.error || 'Upload failed');
        }
    })
    .catch(err => alert('Upload error: ' + err));
}

function setLogo(url) {
    document.getElementById('siteLogoInput').value = url;
    var preview = document.getElementById('logoPreview');
    preview.querySelector('img').src = url;
    preview.classList.remove('d-none');
    document.getElementById('noLogoMsg').classList.add('d-none');
    document.getElementById('removeLogoBtn').classList.remove('d-none');
}

function setFavicon(url) {
    document.getElementById('siteFaviconInput').value = url;
    var preview = document.getElementById('faviconPreview');
    preview.querySelector('img').src = url;
    preview.classList.remove('d-none');
    document.getElementById('noFaviconMsg').classList.add('d-none');
    document.getElementById('removeFaviconBtn').classList.remove('d-none');
}

function removeLogo() {
    document.getElementById('siteLogoInput').value = '';
    document.getElementById('logoPreview').classList.add('d-none');
    document.getElementById('noLogoMsg').classList.remove('d-none');
    document.getElementById('removeLogoBtn').classList.add('d-none');
}

function removeFavicon() {
    document.getElementById('siteFaviconInput').value = '';
    document.getElementById('faviconPreview').classList.add('d-none');
    document.getElementById('noFaviconMsg').classList.remove('d-none');
    document.getElementById('removeFaviconBtn').classList.add('d-none');
}

function openMediaLibrary(target) {
    currentTarget = target;
    mediaModal.show();
    loadImages();
}

function loadImages() {
    fetch('<?= base_url() ?>/admin/get-images')
    .then(r => r.json())
    .then(data => {
        document.getElementById('mediaLibraryLoading').classList.add('d-none');
        var grid = document.getElementById('mediaLibraryGrid');
        var empty = document.getElementById('mediaLibraryEmpty');

        if (!data.ok || !data.images || data.images.length === 0) {
            empty.classList.remove('d-none');
            grid.classList.add('d-none');
            return;
        }

        empty.classList.add('d-none');
        grid.classList.remove('d-none');
        grid.innerHTML = '';

        data.images.forEach(function(img) {
            var col = document.createElement('div');
            col.className = 'col-4 col-md-3 col-lg-2';
            col.innerHTML = '<div class="media-library-item" data-url="' + img.url + '">' +
                '<div class="media-library-thumb">' +
                    '<img src="' + img.url + '" alt="' + img.filename + '" loading="lazy">' +
                '</div>' +
                '<div class="media-library-select">' +
                    '<button type="button" class="btn btn-sm btn-primary w-100">' + '<?= t('select_image') ?>' + '</button>' +
                '</div>' +
            '</div>';
            grid.appendChild(col);
        });

        grid.querySelectorAll('.media-library-item').forEach(function(item) {
            item.style.cursor = 'pointer';
            item.addEventListener('click', function() {
                var url = this.dataset.url;
                if (currentTarget === 'logo') {
                    setLogo(url);
                } else {
                    setFavicon(url);
                }
                mediaModal.hide();
            });
        });
    })
    .catch(err => {
        document.getElementById('mediaLibraryLoading').classList.add('d-none');
        document.getElementById('mediaLibraryEmpty').classList.remove('d-none');
    });
}
</script>

<style>
.media-library-item {
    cursor: pointer;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
    transition: border-color 0.2s;
}
.media-library-item:hover {
    border-color: #550296;
}
.media-library-thumb {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fc;
    padding: 8px;
}
.media-library-thumb img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.media-library-select {
    padding: 4px;
}
</style>

<?php include __DIR__.'/admin_footer.php'; ?>