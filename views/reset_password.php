<?php include __DIR__.'/header.php'; render_header(t('password_reset_success')); ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="auth-form">
                <div class="card">
                    <div class="card-header text-center py-3 bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-key me-2"></i><?= t('password_reset_success') ?></h5>
                    </div>
                    <div class="card-body p-4">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
                        <?php endif; ?>
                        <form method="POST" action="<?= url('do_reset_password') ?>">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="token" value="<?= escape($_GET['token'] ?? '') ?>">
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="password" class="form-control" required minlength="6">
                                <div class="form-text">Minimum 6 characters.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-forum w-100"><i class="fas fa-save me-1"></i>Reset Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>