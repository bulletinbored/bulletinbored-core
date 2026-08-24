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
                    <p class="text-muted"><?= t('install_from_github') ?> <?= t('from_the') ?> <a href="https://github.com/bulletinbored/langs" target="_blank">bulletinbored/langs</a> <?= t('repository') ?> (<?= escape($langMirrorBase) ?>).</p>
                    <div id="github-langs-loading" class="text-muted d-none"><?= t('loading_available_languages') ?></div>
                    <div id="github-langs-error" class="alert alert-danger d-none"><?= t('unable_to_load_languages') ?></div>
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
<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
(function() {
    const list = document.getElementById('github-langs-list');
    const errorBox = document.getElementById('github-langs-error');
    const txtNoLanguages = <?= json_encode(t('no_languages_found')) ?>;
    const txtInstalled = <?= json_encode(t('installed')) ?>;
    const txtUpdate = <?= json_encode(t('update')) ?>;
    const txtInstall = <?= json_encode(t('install')) ?>;
    const txtCode = <?= json_encode(t('code')) ?>;
    const txtFile = <?= json_encode(t('file')) ?>;
    const txtAction = <?= json_encode(t('actions')) ?>;
    const csrf = '<?= generate_csrf_token() ?>';
    const mirrorBase = '<?= escape($langMirrorBase) ?>';

    const remoteLangs = <?= json_encode($remoteLangs ?? new stdClass()) ?>;
    const installed = <?= json_encode(array_values($langOptions)) ?>;
    const localMeta = <?= json_encode($langMeta ?? new stdClass()) ?>;

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    const codes = Object.keys(remoteLangs);
    if (codes.length === 0) {
        errorBox.textContent = txtNoLanguages;
        errorBox.classList.remove('d-none');
        return;
    }

    const table = document.createElement('table');
    table.className = 'table table-bordered';
    table.innerHTML = '<thead><tr><th>' + esc(txtCode) + '</th><th>' + esc(txtFile) + '</th><th>' + esc(txtAction) + '</th></tr></thead><tbody></tbody>';
    const tbody = table.querySelector('tbody');

    codes.forEach(function(code) {
        const info = remoteLangs[code];
        const isInstalled = installed.indexOf(code) !== -1;
        const localSha = localMeta[code] && localMeta[code].sha ? localMeta[code].sha : null;
        const changed = localSha !== null && localSha !== info.sha;
        const fullUrl = mirrorBase.replace(/\/+$/, '') + '/' + String(info.url).replace(/^\/+/, '');

        let actionCell;
        if (!isInstalled) {
            actionCell = '<form method="POST" class="d-inline">'
                + '<input type="hidden" name="csrf_token" value="' + csrf + '">'
                + '<input type="hidden" name="install_github_lang" value="1">'
                + '<input type="hidden" name="lang_code" value="' + esc(code) + '">'
                + '<input type="hidden" name="download_url" value="' + esc(fullUrl) + '">'
                + '<button type="submit" class="btn btn-sm btn-primary">' + esc(txtInstall) + '</button></form>';
        } else if (changed) {
            actionCell = '<span class="badge bg-warning text-dark me-1">' + esc(txtInstalled) + '</span>'
                + '<form method="POST" class="d-inline">'
                + '<input type="hidden" name="csrf_token" value="' + csrf + '">'
                + '<input type="hidden" name="update_github_lang" value="1">'
                + '<input type="hidden" name="lang_code" value="' + esc(code) + '">'
                + '<input type="hidden" name="download_url" value="' + esc(fullUrl) + '">'
                + '<input type="hidden" name="remote_sha" value="' + esc(info.sha) + '">'
                + '<button type="submit" class="btn btn-sm btn-warning">' + esc(txtUpdate) + '</button></form>';
        } else {
            actionCell = '<span class="badge bg-success">' + esc(txtInstalled) + '</span>';
        }

        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + esc(code) + '</td><td>' + esc(info.file) + '</td><td>' + actionCell + '</td>';
        tbody.appendChild(tr);
    });
    list.appendChild(table);
})();
</script>
<?php include __DIR__.'/admin_footer.php'; ?>
