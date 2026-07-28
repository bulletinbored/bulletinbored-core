<?php
global $config;
$langFiles = glob(__DIR__ . '/../lang/*.php');
$availableLangs = [];
foreach ($langFiles as $file) {
    $code = basename($file, '.php');
    $availableLangs[] = $code;
}
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header('Settings'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Site Settings</h2>
        <a href="<?= url('admin') ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="save_settings" value="1">
        
        <div class="row g-4">
            <!-- Site Name -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Site Name</label>
                    <input type="text" name="site_name" class="form-control" value="<?= escape($config['site_name'] ?? 'bulletinbored') ?>" required>
                </div>
            </div>
            
            <!-- Theme -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Theme</label>
                    <select name="theme" class="form-select">
                        <option value="default" <?= ($config['theme'] ?? 'default') === 'default' ? 'selected' : '' ?>>Default</option>
                    </select>
                </div>
            </div>
            
            <!-- Default Language -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Default Language</label>
                    <select name="default_lang" class="form-select">
                        <?php foreach ($availableLangs as $l): ?>
                            <option value="<?= $l ?>" <?= ($config['default_lang'] ?? 'en') === $l ? 'selected' : '' ?>><?= strtoupper($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Available Languages -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Available Languages (comma-separated codes)</label>
                    <input type="text" name="available_langs" class="form-control" value="<?= escape(implode(',', $config['available_langs'] ?? ['en'])) ?>">
                    <div class="form-text">Example: en,it,fr</div>
                </div>
            </div>
            
            <!-- Allow Registration -->
            <div class="col-md-6">
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="allow_registration" id="allowRegistration" <?= !empty($config['allow_registration']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allowRegistration">Allow Registration</label>
                    </div>
                </div>
            </div>
            
            <!-- Maintenance Mode -->
            <div class="col-md-6">
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenanceMode" <?= !empty($config['maintenance_mode']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="maintenanceMode">Maintenance Mode</label>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Settings</button>
        </div>
    </form>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>