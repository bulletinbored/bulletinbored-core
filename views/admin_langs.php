<?php include __DIR__.'/admin_header.php'; render_admin_header(t('language_files')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('language_files') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('languages') ?></p>
        </div>
    </div>

    <?php if ($langSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= escape($langSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($langError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?= escape($langError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Settings + Install Section -->
    <div class="row mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-upload me-2"></i><?= t('upload_language_file') ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?= t('language_code') ?></label>
                                <input type="text" name="lang_code" class="form-control" placeholder="<?= t('lang_code_example') ?>" required>
                                <div class="form-text"><?= t('use_lowercase_letters') ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?= t('json_translation_file') ?></label>
                                <input type="file" name="lang_file" accept=".json" required class="form-control">
                                <div class="form-text"><?= t('file_must_be_json_array') ?></div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" name="upload_lang" value="1" class="btn btn-primary">
                                <i class="fas fa-upload me-1"></i> <?= t('upload_language_file') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-globe me-2"></i><?= t('site_language_settings') ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('default_language') ?></label>
                            <select name="default_lang" class="form-select">
                                <?php foreach ($langOptions as $code): ?>
                                    <option value="<?= escape($code) ?>" <?= ($config['default_lang'] ?? 'en') === $code ? 'selected' : '' ?>><?= strtoupper(escape($code)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="save_lang_settings" value="1" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> <?= t('save_language_settings') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- GitHub Catalog -->
    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-cloud-download-alt me-2"></i><?= t('install_from_github') ?></h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3"><?= t('install_from_github') ?> <?= t('from_the') ?> <a href="https://github.com/bulletinbored/langs" target="_blank">bulletinbored/langs</a> <?= t('repository') ?> (<?= escape($langMirrorBase) ?>).</p>
            <div id="github-langs-loading" class="text-center py-3">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2"><?= t('loading_available_languages') ?></p>
            </div>
            <div id="github-langs-error" class="alert alert-danger d-none"><?= t('unable_to_load_languages') ?></div>
            <div id="github-langs-list"></div>
        </div>
    </div>

    <!-- Installed Languages -->
    <h5 class="mb-3"><i class="fas fa-language me-2"></i><?= t('installed_languages') ?></h5>

    <?php if (empty($langOptions)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-language fa-3x text-gray-400 mb-3"></i>
                <h5 class="text-gray-600"><?= t('no_language_files_found') ?></h5>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($langOptions as $code): ?>
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="card shadow-sm h-100 lang-card">
                        <div class="card-body text-center">
                            <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-2" style="width:48px;height:48px">
                                <span class="fw-bold text-primary"><?= strtoupper(escape(substr($code, 0, 2))) ?></span>
                            </div>
                            <h6 class="mb-1"><?= escape($code) ?>.json</h6>
                            <?php if ($code === ($config['default_lang'] ?? 'en')): ?>
                                <span class="badge bg-success"><?= t('default') ?></span>
                            <?php else: ?>
                                <form method="POST" data-confirm="<?= t('delete_confirm') ?> <?= escape($code) ?>?">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="lang_code" value="<?= escape($code) ?>">
                                    <button type="submit" name="delete_lang" value="1" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
var REMOTE_LANGS = <?= json_encode($remoteLangs ?? new stdClass()) ?>;
var INSTALLED = <?= json_encode(array_values($langOptions)) ?>;
var LOCAL_META = <?= json_encode($langMeta ?? new stdClass()) ?>;
var MIRROR_BASE = '<?= escape($langMirrorBase) ?>';
var CSRF_TOKEN = '<?= generate_csrf_token() ?>';
var NO_LANGUAGES_TEXT = <?= json_encode(t('no_languages_found')) ?>;
var INSTALLED_TEXT = <?= json_encode(t('installed')) ?>;
var UPDATE_TEXT = <?= json_encode(t('update')) ?>;
var INSTALL_TEXT = <?= json_encode(t('install')) ?>;
var CODE_TEXT = <?= json_encode(t('code')) ?>;
var FILE_TEXT = <?= json_encode(t('file')) ?>;
var ACTION_TEXT = <?= json_encode(t('actions')) ?>;
</script>
<script src="<?= htmlspecialchars(base_url() . '/assets/js/admin-langs.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php include __DIR__.'/admin_footer.php'; ?>
