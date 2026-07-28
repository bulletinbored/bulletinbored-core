<?php include __DIR__.'/header.php'; render_header('Edit Profile'); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= base_url() ?>/?action=home">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= base_url() ?>/?action=profile&user=<?= escape($_SESSION['username'] ?? '') ?>">Profile</a></li>
            <li class="breadcrumb-item active">Edit Profile</li>
        </ol>
    </nav>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-user-edit me-2"></i>Edit Profile</div>
                <div class="card-body">
                    <form method="POST" action="<?= base_url() ?>/?action=update_profile">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?= escape($_SESSION['username'] ?? '') ?>">
                            <div class="form-text">Leave blank to keep current.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= escape($_SESSION['email'] ?? '') ?>">
                            <div class="form-text">Used for password recovery.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current">
                        </div>
                        <button type="submit" class="btn btn-forum w-100"><i class="fas fa-save me-1"></i>Save Changes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php render_footer(); ?>