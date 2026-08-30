<?php include __DIR__.'/header.php'; render_header(t('password_reset_request'), ['sidebar' => false]); ?>
    <div class="auth-wrap">
        <section class="auth-card">
            <h1 class="auth-title"><?= t('password_reset_request') ?></h1>
            <p class="auth-subtitle"><?= t('forgot_subtitle') ?></p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= url('do_forgot_password') ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="mb-3">
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-brand w-100"><?= t('send_reset_link') ?></button>
            </form>

            <div class="auth-foot">
                <span><a href="<?= url('login') ?>"><?= t('back_to_login') ?></a></span>
            </div>
        </section>
    </div>
<?php render_footer(); ?>
