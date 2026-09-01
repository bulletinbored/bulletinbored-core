<?php include __DIR__.'/header.php'; render_header(t('password_reset_success'), ['sidebar' => false]); ?>
    <div class="auth-wrap">
        <section class="auth-card">
            <h1 class="auth-title"><?= t('password_reset_success') ?></h1>
            <p class="auth-subtitle"><?= t('reset_subtitle') ?></p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= url('do_reset_password') ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="token" value="<?= escape($_GET['token'] ?? '') ?>">
                <div class="mb-3">
                    <label class="form-label"><?= t('new_password') ?></label>
                    <input type="password" name="password" class="form-control" required minlength="10">
                    <div class="form-text"><?= t('password_min') ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('confirm_password') ?></label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-brand w-100"><?= t('save_changes') ?></button>
            </form>
        </section>
    </div>
<?php render_footer(); ?>
