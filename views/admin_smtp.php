<?php include __DIR__.'/admin_header.php'; render_admin_header(t('smtp_settings')); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-heading mb-0"><?= t('smtp_configuration') ?></h2>
            <p class="text-gray-500 mb-0 small"><?= t('smtp_settings') ?></p>
        </div>
        <button type="submit" form="smtpForm" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> <?= t('save_smtp_settings') ?>
        </button>
    </div>

    <?php if (!empty($_SESSION['smtp_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= t('smtp_saved') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['smtp_saved']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['smtp_test_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?= t('smtp_test_success', ['email' => escape($_SESSION['smtp_test_success'])]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['smtp_test_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['smtp_test_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?= t('smtp_test_error', ['error' => escape($_SESSION['smtp_test_error'])]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['smtp_test_error']); ?>
    <?php endif; ?>

    <form method="POST" id="smtpForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <!-- Row 1: Mail Method + From Email -->
        <div class="row mb-4">
            <div class="col-md-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-server me-2"></i><?= t('smtp_send_method') ?></h5>
                    </div>
                    <div class="card-body">
                        <label class="form-label"><?= t('smtp_send_method') ?></label>
                        <select name="mail_method" class="form-select" id="mailMethodSelect">
                            <option value="mail" <?= ($config['mail_method'] ?? 'mail') === 'mail' ? 'selected' : '' ?>><?= t('mail_method_php') ?></option>
                            <option value="smtp" <?= ($config['mail_method'] ?? '') === 'smtp' ? 'selected' : '' ?>><?= t('mail_method_smtp') ?></option>
                        </select>
                        <div class="form-text"><?= t('smtp_send_method_hint') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-envelope me-2"></i><?= t('smtp_from_email') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><?= t('smtp_from_email') ?></label>
                            <input type="email" name="mail_from" class="form-control" value="<?= escape($config['mail_from'] ?? '') ?>" placeholder="noreply@tuodominio.it">
                            <div class="form-text"><?= t('smtp_from_email_hint') ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('smtp_from_name') ?></label>
                            <input type="text" name="mail_from_name" class="form-control" value="<?= escape($config['mail_from_name'] ?? '') ?>" placeholder="My Forum">
                            <div class="form-text"><?= t('smtp_from_name_hint') ?></div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label"><?= t('notify_admin_email') ?></label>
                            <input type="email" name="notify_admin_email" class="form-control" value="<?= escape($config['notify_admin_email'] ?? '') ?>" placeholder="<?= escape($config['mail_from'] ?? '') ?>">
                            <div class="form-text"><?= t('notify_admin_email_hint') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Test Email -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-flask me-2"></i><?= t('smtp_test_email') ?></h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label"><?= t('smtp_test_email_to') ?></label>
                        <input type="email" name="smtp_test_to" class="form-control" value="<?= escape($config['mail_from'] ?? '') ?>" placeholder="test@example.com">
                        <div class="form-text"><?= t('smtp_test_email_hint') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label d-block invisible"><?= t('smtp_test_email') ?></label>
                        <button type="submit" name="send_smtp_test" value="1" class="btn btn-outline-primary w-100">
                            <i class="fas fa-paper-plane me-1"></i> <?= t('smtp_test_email') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 3: SMTP Configuration (shown only when SMTP is selected) -->
        <div class="card shadow-sm mb-4" id="smtpConfigCard" <?= ($config['mail_method'] ?? 'mail') !== 'smtp' ? 'style="display:none;"' : '' ?>>
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-cog me-2"></i><?= t('smtp_configuration') ?></h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label"><?= t('smtp_host') ?></label>
                        <input type="text" name="mail_host" class="form-control" value="<?= escape($config['mail_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                        <div class="form-text"><?= t('smtp_host_hint') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= t('smtp_port') ?></label>
                        <input type="number" name="mail_port" class="form-control" value="<?= escape($config['mail_port'] ?? '25') ?>" placeholder="587" min="1" max="65535">
                        <div class="form-text"><?= t('smtp_port_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('smtp_username') ?></label>
                        <input type="text" name="mail_username" class="form-control" value="<?= escape($config['mail_username'] ?? '') ?>" placeholder="user@example.com">
                        <div class="form-text"><?= t('smtp_username_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('smtp_password') ?></label>
                        <input type="password" name="mail_password" class="form-control" placeholder="••••••••" autocomplete="off">
                        <div class="form-text"><?= t('smtp_password_hint') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('smtp_encryption') ?></label>
                        <select name="mail_secure" class="form-select">
                            <option value="" <?= ($config['mail_secure'] ?? '') === '' ? 'selected' : '' ?>><?= t('smtp_encryption_none') ?></option>
                            <option value="ssl" <?= ($config['mail_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>><?= t('smtp_encryption_ssl') ?></option>
                            <option value="tls" <?= ($config['mail_secure'] ?? '') === 'tls' ? 'selected' : '' ?>><?= t('smtp_encryption_tls') ?></option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= t('smtp_timeout') ?></label>
                        <input type="number" name="mail_timeout" class="form-control" value="<?= escape($config['mail_timeout'] ?? '10') ?>" placeholder="10" min="1" max="120">
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
document.getElementById('mailMethodSelect').addEventListener('change', function() {
    var card = document.getElementById('smtpConfigCard');
    card.style.display = this.value === 'smtp' ? '' : 'none';
});
</script>

<?php include __DIR__.'/admin_footer.php'; ?>
