<?php include __DIR__.'/admin_header.php'; render_admin_header('Languages'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Language Files</h2>
        <a href="<?= url('admin') ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
    </div>

    <?php if ($langSuccess): ?>
        <div class="alert alert-success"><?= escape($langSuccess) ?></div>
    <?php endif; ?>
    <?php if ($langError): ?>
        <div class="alert alert-danger"><?= escape($langError) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-5">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Upload Language File</h6>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label">Language Code</label>
                            <input type="text" name="lang_code" class="form-control" placeholder="e.g. fr, de, es" required>
                            <div class="form-text">Use lowercase letters only (e.g. fr, de, es, pt).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">PHP Translation File</label>
                            <input type="file" name="lang_file" accept=".php" required class="form-control">
                            <div class="form-text">File must return an array of key => translated strings.</div>
                        </div>
                        <button type="submit" name="upload_lang" value="1" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Upload</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Installed Languages</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($langOptions)): ?>
                        <p class="text-muted">No language files found in <code>lang/</code>.</p>
                    <?php else: ?>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>File</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($langOptions as $code): ?>
                                    <tr>
                                        <td><?= escape($code) ?></td>
                                        <td><?= escape($code) ?>.php</td>
                                        <td>
                                            <?php if ($code === ($config['default_lang'] ?? 'en')): ?>
                                                <span class="badge bg-success">Default</span>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete language file <?= escape($code) ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                    <input type="hidden" name="lang_code" value="<?= escape($code) ?>">
                                                    <button type="submit" name="delete_lang" value="1" class="btn btn-sm btn-danger">Delete</button>
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
<?php include __DIR__.'/admin_footer.php'; ?>
