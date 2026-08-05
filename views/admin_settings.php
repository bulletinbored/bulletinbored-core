<?php include __DIR__.'/admin_header.php'; render_admin_header('Settings'); ?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Site Settings</h2>
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
            
            <!-- Site Tagline -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Site Tagline</label>
                    <input type="text" name="site_tagline" class="form-control" value="<?= escape($config['site_tagline'] ?? '') ?>">
                </div>
            </div>

            <!-- Site Icon -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Site Icon</label>
                    <input type="text" name="site_icon" class="form-control" value="<?= escape($config['site_icon'] ?? '') ?>">
                    <div class="form-text">Path or URL to the site icon image.</div>
                </div>
            </div>
            
            <!-- Timezone -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Timezone</label>
                    <select name="timezone" class="form-select">
                        <option value="UTC" <?= ($config['timezone'] ?? 'UTC') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                        <option value="Europe/Rome" <?= ($config['timezone'] ?? '') === 'Europe/Rome' ? 'selected' : '' ?>>Europe/Rome</option>
                        <option value="Europe/London" <?= ($config['timezone'] ?? '') === 'Europe/London' ? 'selected' : '' ?>>Europe/London</option>
                        <option value="Europe/Paris" <?= ($config['timezone'] ?? '') === 'Europe/Paris' ? 'selected' : '' ?>>Europe/Paris</option>
                        <option value="Europe/Berlin" <?= ($config['timezone'] ?? '') === 'Europe/Berlin' ? 'selected' : '' ?>>Europe/Berlin</option>
                        <option value="America/New_York" <?= ($config['timezone'] ?? '') === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                        <option value="America/Chicago" <?= ($config['timezone'] ?? '') === 'America/Chicago' ? 'selected' : '' ?>>America/Chicago</option>
                        <option value="America/Denver" <?= ($config['timezone'] ?? '') === 'America/Denver' ? 'selected' : '' ?>>America/Denver</option>
                        <option value="America/Los_Angeles" <?= ($config['timezone'] ?? '') === 'America/Los_Angeles' ? 'selected' : '' ?>>America/Los_Angeles</option>
                        <option value="Asia/Tokyo" <?= ($config['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                        <option value="Asia/Shanghai" <?= ($config['timezone'] ?? '') === 'Asia/Shanghai' ? 'selected' : '' ?>>Asia/Shanghai</option>
                        <option value="Australia/Sydney" <?= ($config['timezone'] ?? '') === 'Australia/Sydney' ? 'selected' : '' ?>>Australia/Sydney</option>
                    </select>
                </div>
            </div>

            <!-- Date Format -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Date Format</label>
                    <select name="date_format" class="form-select">
                        <option value="Y-m-d" <?= ($config['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                        <option value="d/m/Y" <?= ($config['date_format'] ?? '') === 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                        <option value="m/d/Y" <?= ($config['date_format'] ?? '') === 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                        <option value="d.m.Y" <?= ($config['date_format'] ?? '') === 'd.m.Y' ? 'selected' : '' ?>>DD.MM.YYYY</option>
                    </select>
                </div>
            </div>

            <!-- Time Format -->
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Time Format</label>
                    <select name="time_format" class="form-select">
                        <option value="H:i" <?= ($config['time_format'] ?? 'H:i') === 'H:i' ? 'selected' : '' ?>>24-hour (HH:MM)</option>
                        <option value="h:i A" <?= ($config['time_format'] ?? '') === 'h:i A' ? 'selected' : '' ?>>12-hour (hh:MM AM/PM)</option>
                    </select>
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