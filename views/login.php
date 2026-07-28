<?php include __DIR__.'/header.php'; render_header(t('login')); ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="auth-form">
                <div class="card">
                    <div class="card-header text-center py-3 bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-sign-in-alt me-2"></i><?= t('login') ?></h5>
                    </div>
                    <div class="card-body p-4">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
                        <?php endif; ?>
                        <form method="POST" action="<?= url('do_login') ?>">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="mb-3">
                                <label class="form-label"><?= t('username') ?></label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?= t('password') ?></label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-forum w-100"><i class="fas fa-sign-in-alt me-1"></i><?= t('login') ?></button>
                        </form>
                        <hr>
                        <div class="text-center">
                            <a href="<?= url('forgot_password') ?>" class="text-decoration-none small"><i class="fas fa-key me-1"></i><?= t('password_reset_request') ?></a>
                            <br>
                            <span class="text-muted small">Don't have an account?</span>
                            <a href="<?= url('register') ?>" class="text-decoration-none small"><?= t('register') ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>