<?php include __DIR__.'/admin_header.php'; render_admin_header(t('language_files')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= t('language_files') ?></h2>
    </div>

    <?php if ($langSuccess): ?>
        <div class="alert alert-success"><?= escape($langSuccess) ?></div>
    <?php endif; ?>
    <?php if ($langError): ?>
        <div class="alert alert-danger"><?= escape($langError) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('site_language_settings') ?></h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?= t('default_language') ?></label>
                                <select name="default_lang" class="form-select">
                                    <?php foreach ($langOptions as $code): ?>
                                        <option value="<?= escape($code) ?>" <?= ($config['default_lang'] ?? 'en') === $code ? 'selected' : '' ?>><?= strtoupper(escape($code)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" name="save_lang_settings" value="1" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= t('save_language_settings') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('install_from_github') ?></h6>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?= t('install_from_github') ?> <?= t('from_the') ?> <a href="https://github.com/bulletinbored/langs" target="_blank">bulletinbored/langs</a> <?= t('repository') ?>.</p>
                    <div id="github-langs-loading" class="text-muted"><?= t('loading_available_languages') ?></div>
                    <div id="github-langs-error" class="alert alert-danger d-none"></div>
                    <div id="github-langs-list"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('upload_language_file') ?></h6>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('language_code') ?></label>
                            <input type="text" name="lang_code" class="form-control" placeholder="<?= t('lang_code_example') ?>" required>
                            <div class="form-text"><?= t('use_lowercase_letters') ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('php_translation_file') ?></label>
                            <input type="file" name="lang_file" accept=".php" required class="form-control">
                            <div class="form-text"><?= t('file_must_return_array') ?></div>
                        </div>
                        <button type="submit" name="upload_lang" value="1" class="btn btn-primary"><i class="fas fa-upload me-1"></i><?= t('upload_language_file') ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><?= t('installed_languages') ?></h6>
                </div>
                <div class="card-body">
                    <?php if (empty($langOptions)): ?>
                        <p class="text-muted"><?= t('no_language_files_found') ?></p>
                    <?php else: ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th><?= t('code') ?></th>
                                    <th><?= t('file') ?></th>
                                    <th><?= t('actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($langOptions as $code): ?>
                                    <tr>
                                        <td><?= escape($code) ?></td>
                                        <td><?= escape($code) ?>.php</td>
                                        <td>
                                            <?php if ($code === ($config['default_lang'] ?? 'en')): ?>
                                                <span class="badge bg-success"><?= t('default') ?></span>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('<?= t('delete_confirm') ?> <?= escape($code) ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                    <input type="hidden" name="lang_code" value="<?= escape($code) ?>">
                                                    <button type="submit" name="delete_lang" value="1" class="btn btn-sm btn-danger"><?= t('delete') ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const repoOwner = 'bulletinbored';
    const repoName = 'langs';
    const apiUrl = 'https://api.github.com/repos/' + repoOwner + '/' + repoName + '/contents/';
    const loading = document.getElementById('github-langs-loading');
    const errorBox = document.getElementById('github-langs-error');
    const list = document.getElementById('github-langs-list');
    const txtNoLanguages = <?= json_encode(t('no_languages_found')) ?>;
    const txtUnable = <?= json_encode(t('unable_to_load_languages')) ?>;
    const txtFailed = <?= json_encode(t('failed_to_load_languages')) ?>;
    const txtInstalled = <?= json_encode(t('installed')) ?>;
    const txtInstall = <?= json_encode(t('install')) ?>;
    const txtCode = <?= json_encode(t('code')) ?>;
    const txtFile = <?= json_encode(t('file')) ?>;
    const txtAction = <?= json_encode(t('actions')) ?>;

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    fetch(apiUrl)
        .then(function(res) { return res.json(); })
        .then(function(files) {
            loading.classList.add('d-none');
            if (!Array.isArray(files)) {
                errorBox.textContent = txtUnable;
                errorBox.classList.remove('d-none');
                return;
            }
            const langFiles = files.filter(function(f) {
                return f.type === 'file' && f.name.endsWith('.php') && f.name !== 'README.md';
            });
            if (langFiles.length === 0) {
                list.innerHTML = '<p class="text-muted">' + esc(txtNoLanguages) + '</p>';
                return;
            }
            const installed = <?= json_encode(array_values($langOptions)) ?>;
            const table = document.createElement('table');
            table.className = 'table table-bordered';
            table.innerHTML = '<thead><tr><th>' + esc(txtCode) + '</th><th>' + esc(txtFile) + '</th><th>' + esc(txtAction) + '</th></tr></thead><tbody></tbody>';
            const tbody = table.querySelector('tbody');
            langFiles.forEach(function(file) {
                const code = file.name.replace(/\.php$/i, '');
                const tr = document.createElement('tr');
                const isInstalled = installed.indexOf(code) !== -1;
                const actionCell = isInstalled
                    ? '<span class="badge bg-success">' + esc(txtInstalled) + '</span>'
                    : '<form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>"><input type="hidden" name="install_github_lang" value="1"><input type="hidden" name="lang_code" value="' + esc(code) + '"><input type="hidden" name="download_url" value="' + esc(file.download_url) + '"><button type="submit" class="btn btn-sm btn-primary">' + esc(txtInstall) + '</button></form>';
                tr.innerHTML = '<td>' + esc(code) + '</td><td>' + esc(file.name) + '</td><td>' + actionCell + '</td>';
                tbody.appendChild(tr);
            });
            list.appendChild(table);
        })
        .catch(function() {
            loading.classList.add('d-none');
            errorBox.textContent = txtFailed;
            errorBox.classList.remove('d-none');
        });
})();
</script>
<?php include __DIR__.'/admin_footer.php'; ?>
