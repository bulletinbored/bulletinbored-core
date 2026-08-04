<?php include __DIR__.'/header.php'; render_header(t('login'), ['sidebar' => false]); ?>
    <div class="auth-wrap">
        <section class="auth-card">
            <h1 class="auth-title"><?= t('login') ?></h1>
            <p class="auth-subtitle"><?= t('login_subtitle') ?></p>

            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= t('register_check_email') ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape(t($error)) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= url('do_login') ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="mb-3">
                    <label class="form-label"><?= t('username') ?></label>
                    <input type="text" name="username" class="form-control" autofocus required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('password') ?></label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-brand w-100"><?= t('login') ?></button>
            </form>

            <div class="auth-foot">
                <a href="<?= url('forgot_password') ?>"><?= t('password_reset_request') ?></a>
                <span><?= t('no_account') ?> <a href="<?= url('register') ?>"><?= t('register') ?></a></span>
            </div>
        </section>
    </div>
<?php render_footer(); ?>
