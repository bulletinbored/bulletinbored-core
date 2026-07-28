<?php include __DIR__.'/header.php'; render_header('Edit Profile'); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('home') ?>"><?= t('home') ?></a></li>
            <li class="breadcrumb-item"><a href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>"><?= t('profile') ?></a></li>
            <li class="breadcrumb-item active"><?= t('edit_profile') ?></li>
        </ol>
    </nav>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-user-edit me-2"></i><?= t('edit_profile') ?></div>
                <div class="card-body">
                    <?php if (!empty($_SESSION['avatar_upload_success'] ?? '')): ?>
                        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= escape($_SESSION['avatar_upload_success']) ?></div>
                        <?php unset($_SESSION['avatar_upload_success']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['avatar_upload_error'] ?? '')): ?>
                        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= escape($_SESSION['avatar_upload_error']) ?></div>
                        <?php unset($_SESSION['avatar_upload_error']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['avatar'] ?? '')): ?>
                        <div class="text-center mb-3">
                            <img src="<?= base_url() ?>/uploads/avatars/<?= escape($_SESSION['avatar'] ?? '') ?>" alt="Avatar" style="width:120px;height:120px;object-fit:cover;border-radius:50%;">
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="<?= url('update_profile') ?>">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= t('username') ?></label>
                            <input type="text" name="username" class="form-control" value="<?= escape($_SESSION['username'] ?? '') ?>">
                            <div class="form-text"><?= t('leave_blank_keep') ?>.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('email') ?></label>
                            <input type="email" name="email" class="form-control" value="<?= escape($_SESSION['email'] ?? '') ?>">
                            <div class="form-text">Used for password recovery.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('password') ?></label>
                            <input type="password" name="password" class="form-control" placeholder="<?= t('leave_blank_keep') ?>">
                        </div>
                        <button type="submit" class="btn btn-forum w-100"><i class="fas fa-save me-1"></i><?= t('save_changes') ?></button>
                    </form>
                    <hr>
                    <form method="POST" action="<?= url('upload_avatar') ?>" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label">Avatar</label>
                            <input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">JPG, PNG, GIF, WebP. Max 2MB.</div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-upload me-1"></i>Upload Avatar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>