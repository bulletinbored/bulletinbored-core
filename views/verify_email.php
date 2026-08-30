<?php include __DIR__.'/header.php'; render_header(t('verify_email'), ['sidebar' => false]); ?>
    <div class="auth-wrap">
        <section class="auth-card">
            <h1 class="auth-title"><?= t('verify_email') ?></h1>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape(t($error)) ?></div>
                <a href="<?= url('home') ?>" class="btn btn-outline-secondary w-100"><?= t('back_to_login') ?></a>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape(t($success)) ?></div>
                <a href="<?= url('login') ?>" class="btn btn-brand w-100"><?= t('login') ?></a>
            <?php endif; ?>
        </section>
    </div>
<?php render_footer(); ?>
