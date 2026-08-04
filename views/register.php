<?php include __DIR__.'/header.php'; render_header(t('register'), ['sidebar' => false]); ?>
    <div class="auth-wrap">
        <section class="auth-card">
            <h1 class="auth-title"><?= t('register') ?></h1>
            <p class="auth-subtitle"><?= t('register_subtitle') ?></p>

            <form method="POST" action="<?= url('do_register') ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="mb-3">
                    <label class="form-label"><?= t('username') ?></label>
                    <input type="text" name="username" class="form-control" autofocus required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('email') ?></label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('password') ?></label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-brand w-100"><?= t('register') ?></button>
            </form>

            <div class="auth-foot">
                <span><?= t('have_account') ?> <a href="<?= url('login') ?>"><?= t('login') ?></a></span>
            </div>
        </section>
    </div>
<?php render_footer(); ?>
