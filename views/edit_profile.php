<?php include __DIR__.'/header.php'; render_header(t('edit_profile')); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('all_discussions') ?></a></li>
            <li class="breadcrumb-item"><a href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>"><?= t('profile') ?></a></li>
            <li class="breadcrumb-item active"><?= t('edit_profile') ?></li>
        </ol>
    </nav>

    <header class="page-head">
        <div>
            <h1 class="page-title"><?= t('edit_profile') ?></h1>
            <p class="page-subtitle"><?= t('edit_profile_hint') ?></p>
        </div>
    </header>

    <?php if (!empty($_SESSION['avatar_upload_success'] ?? '')): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape($_SESSION['avatar_upload_success']) ?></div>
        <?php unset($_SESSION['avatar_upload_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['avatar_upload_error'] ?? '')): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($_SESSION['avatar_upload_error']) ?></div>
        <?php unset($_SESSION['avatar_upload_error']); ?>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="panel">
                <h2 class="panel-title"><?= t('account') ?></h2>
                <form method="POST" action="<?= url('update_profile') ?>">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= t('username') ?></label>
                        <input type="text" name="username" class="form-control" value="<?= escape($_SESSION['username'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('email') ?></label>
                        <input type="email" name="email" class="form-control" value="<?= escape($_SESSION['email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('password') ?></label>
                        <input type="password" name="password" class="form-control" placeholder="<?= t('leave_blank_keep') ?>">
                    </div>
                    <button type="submit" class="btn btn-brand"><i class="fas fa-save me-2"></i><?= t('save_changes') ?></button>
                </form>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="panel text-center">
                <h2 class="panel-title"><?= t('avatar') ?></h2>
                <div class="mb-3">
                    <?= render_avatar($_SESSION['username'] ?? '', $_SESSION['avatar'] ?? '', 110) ?>
                </div>
                <form method="POST" action="<?= url('upload_avatar') ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <div class="mb-3 text-start">
                        <input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-text"><?= t('avatar_hint') ?></div>
                    </div>
                    <button type="submit" class="btn btn-outline-soft w-100"><i class="fas fa-upload me-2"></i><?= t('upload_avatar') ?></button>
                </form>
                <?php if (!empty($_SESSION['avatar'] ?? '')): ?>
                    <form method="POST" action="<?= url('remove_avatar') ?>" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <button type="submit" class="btn btn-outline-danger w-100"><i class="fas fa-trash-alt me-2"></i><?= t('remove_avatar') ?></button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
<?php render_footer(); ?>
