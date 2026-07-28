<?php include __DIR__.'/header.php'; render_header('Register'); ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="auth-form">
                <div class="card">
                    <div class="card-header text-center py-3 bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Register</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="<?= base_url() ?>/?action=do_register">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                                <div class="form-text">Used for password recovery.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-forum w-100"><i class="fas fa-user-plus me-1"></i>Register</button>
                        </form>
                        <hr>
                        <div class="text-center">
                            <span class="text-muted small">Already have an account?</span>
                            <a href="<?= base_url() ?>/?action=login" class="text-decoration-none small">Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>