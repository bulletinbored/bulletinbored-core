# P3-3: Make Magic Numbers Configurable — Implementation Plan

## Overview

This plan addresses the remediation of hardcoded "magic numbers" scattered throughout the codebase by making them configurable via `config.json` with sensible defaults.

## Current Magic Numbers Identified

| Location | Magic Number | Current Value | Purpose |
|----------|--------------|---------------|---------|
| `src/helpers.php` line 25 | `POSTS_PER_PAGE` | 15 | Posts per page in thread view |
| `src/Security.php` line 143 | GC probability | 200 (1 in 200) | Rate limiter garbage collection frequency |
| `src/Security.php` line 93 | GC max age | 86400 (24 hours) | Rate limiter bucket max age |
| `lib/UpdateManager.php` line 165 | Min disk space | 50 MB | Minimum free disk space for updates |
| `src/actions/posts-thread.php` line 27 | Image max size | 5 MB | Uploaded image size limit |
| `src/actions/users.php` line 290 | Avatar max size | 2 MB | Avatar upload size limit |
| `src/actions/admin/settings.php` line 84 | Site image max size | 2 MB | Site logo/favicon upload size limit |

## Configuration Keys to Add

Add the following to `config-sample.json`:

```json
{
  "posts_per_page": 15,
  "rate_limit_gc_probability": 200,
  "rate_limit_gc_max_age": 86400,
  "update_min_disk_space_mb": 50,
  "image_max_size_mb": 5,
  "avatar_max_size_mb": 2,
  "site_image_max_size_mb": 2
}
```

## Implementation Details

### 1. Update `config-sample.json`

Add the new configuration keys with their default values. Place them logically in the file (e.g., after `avatar_max_size`).

### 2. Update `src/helpers.php` — POSTS_PER_PAGE

**Current:**
```php
if (!defined('POSTS_PER_PAGE')) {
    define('POSTS_PER_PAGE', 15);
}
```

**New:**
```php
if (!defined('POSTS_PER_PAGE')) {
    $config = App::getInstance()->config ?? [];
    define('POSTS_PER_PAGE', (int)($config['posts_per_page'] ?? 15));
}
```

**Note:** Since `helpers.php` is loaded early, we need to ensure `App::getInstance()` is available. The config is loaded in `bootstrap.php` before `helpers.php` is required, so this should work.

### 3. Update `src/Security.php` — Rate Limiter GC

**Current (line 93):**
```php
function rate_limit_gc(string $dir, int $maxAge = 86400): int
```

**New:**
```php
function rate_limit_gc(string $dir, ?int $maxAge = null): int
{
    $config = App::getInstance()->config ?? [];
    $maxAge = $maxAge ?? (int)($config['rate_limit_gc_max_age'] ?? 86400);
    // ... rest of function
}
```

**Current (line 143):**
```php
if (random_int(1, 200) === 1) {
    rate_limit_gc($dir);
}
```

**New:**
```php
$config = App::getInstance()->config ?? [];
$gcProbability = (int)($config['rate_limit_gc_probability'] ?? 200);
if (random_int(1, $gcProbability) === 1) {
    rate_limit_gc($dir);
}
```

### 4. Update `lib/UpdateManager.php` — Minimum Disk Space

**Current (line 165):**
```php
$needed = $requiredBytes > 0 ? $requiredBytes : (50 * 1024 * 1024);
```

**New:**
```php
$config = App::getInstance()->config ?? [];
$defaultMinDiskSpace = (int)($config['update_min_disk_space_mb'] ?? 50) * 1024 * 1024;
$needed = $requiredBytes > 0 ? $requiredBytes : $defaultMinDiskSpace;
```

### 5. Update `src/actions/posts-thread.php` — Image Max Size

**Current (line 27):**
```php
$maxSize = App::getInstance()->config['image_max_size'] ?? 5 * 1024 * 1024;
```

**New:**
```php
$config = App::getInstance()->config;
$maxSize = $config['image_max_size'] ?? ((int)($config['image_max_size_mb'] ?? 5) * 1024 * 1024);
```

**Note:** Support both `image_max_size` (bytes) and `image_max_size_mb` (megabytes) for backward compatibility.

### 6. Update `src/actions/users.php` — Avatar Max Size

**Current (line 290):**
```php
$maxSize = $config['avatar_max_size'] ?? 2*1024*1024;
```

**New:**
```php
$maxSize = $config['avatar_max_size'] ?? ((int)($config['avatar_max_size_mb'] ?? 2) * 1024 * 1024);
```

### 7. Update `src/actions/admin/settings.php` — Site Image Max Size

**Current (line 84):**
```php
$maxSize = 2 * 1024 * 1024;
```

**New:**
```php
$config = App::getInstance()->config;
$maxSize = $config['site_image_max_size'] ?? ((int)($config['site_image_max_size_mb'] ?? 2) * 1024 * 1024);
```

### 8. Update `views/partials/thread_list.php` — POSTS_PER_PAGE Usage

The view already uses the `POSTS_PER_PAGE` constant (line 46), so no changes needed there once the constant is updated in `helpers.php`.

## Backward Compatibility Strategy

All changes support both the new `_mb` suffixed keys (megabytes) and the existing byte-based keys:
- `image_max_size` (bytes) → `image_max_size_mb` (megabytes)
- `avatar_max_size` (bytes) → `avatar_max_size_mb` (megabytes)
- `site_image_max_size` (bytes) → `site_image_max_size_mb` (megabytes)

This ensures existing `config.json` files continue to work without modification.

## Admin Settings UI (Optional Enhancement)

Add configuration fields to `views/admin_settings.php` for the new settings:

1. **Posts per page** — Number input (min: 5, max: 100)
2. **Rate limiter GC probability** — Number input (min: 50, max: 1000)
3. **Rate limiter GC max age** — Number input (seconds, min: 3600)
4. **Update min disk space** — Number input (MB, min: 10)
5. **Image max upload size** — Number input (MB, min: 1)
6. **Avatar max upload size** — Number input (MB, min: 1)
7. **Site image max upload size** — Number input (MB, min: 1)

Update `src/actions/admin/settings.php` to handle these new POST fields.

## Verification Criteria

- [ ] All magic numbers replaced with config-driven values
- [ ] Default values match original hardcoded values
- [ ] Config overrides work correctly (test with custom config.json)
- [ ] Backward compatibility: existing config.json without new keys works
- [ ] All existing tests pass
- [ ] Admin settings UI (if implemented) saves and loads values correctly
- [ ] No PHP warnings/errors when config keys are missing

## Risk Assessment

**Risk: Low** — Configuration-driven changes with backward compatibility.

**Potential Issues:**
1. `App::getInstance()` might not be available in `helpers.php` during early bootstrap — verify load order
2. Integer casting needed for config values (they come from JSON as strings/numbers)
3. Division by zero if `rate_limit_gc_probability` is set to 0 — add validation

## Implementation Order

1. Update `config-sample.json` with new keys
2. Update `src/helpers.php` (POSTS_PER_PAGE)
3. Update `src/Security.php` (rate limiter GC)
4. Update `lib/UpdateManager.php` (disk space)
5. Update `src/actions/posts-thread.php` (image max size)
6. Update `src/actions/users.php` (avatar max size)
7. Update `src/actions/admin/settings.php` (site image max size)
8. (Optional) Add admin UI fields
9. Test with custom config and defaults

## Files Affected

- `config-sample.json` — Add new configuration keys
- `src/helpers.php` — POSTS_PER_PAGE constant
- `src/Security.php` — rate_limit_gc() and rate_limit() GC probability
- `lib/UpdateManager.php` — preflight() minimum disk space
- `src/actions/posts-thread.php` — handle_upload_image() max size
- `src/actions/users.php` — avatar upload max size
- `src/actions/admin/settings.php` — site image upload max size
- `views/admin_settings.php` — (Optional) Admin UI for new settings