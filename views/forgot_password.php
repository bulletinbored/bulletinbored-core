<?php include __DIR__.'/header.php'; render_header('Forgot Password'); ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="auth-form">
                <div class="card">
                    <div class="card-header text-center py-3 bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-key me-2"></i>Forgot Password</h5>
                    </div>
                    <div class="card-body p-4">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
                        <?php endif; ?>
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape($success) ?></div>
                        <?php endif; ?>
                        <form method="POST" action="<?= base_url() ?>/?action=do_forgot_password">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                                <div class="form-text">A password reset link will be sent to this email.</div>
                            </div>
                            <button type="submit" class="btn btn-forum w-100"><i class="fas fa-paper-plane me-1"></i>Send Reset Link</button>
                        </form>
                        <hr>
                        <div class="text-center">
                            <span class="text-muted small">Remember your password?</span>
                            <a href="<?= base_url() ?>/?action=login" class="text-decoration-none small">Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>