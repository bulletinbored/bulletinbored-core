# Test Remediation Plan

**Based on:** Test run results from P2-4 Expand Test Coverage implementation
**Date:** 2026-11-07
**Status:** Draft for review

---

## Overview

This plan addresses the test failures identified after implementing P2-4 (Expand Test Coverage). The failures fall into 5 main categories:

1. **View Inclusion Conflicts** - Views included multiple times causing "Cannot redeclare function" errors
2. **Missing Helper Functions** - Test environment lacks required helper functions
3. **AuthZ Constructor Issues** - Wrong parameter order in RouterTest
4. **Upload Validation** - `is_uploaded_file()` check fails for regular files
5. **Test Isolation** - Global state and shared resources cause interference

---

## Failure Categories Analysis

### 1. View Inclusion Conflicts

**Error:** `Fatal error: Cannot redeclare function render_admin_header() (previously declared in /home/user1/Projects/bulletinbored-core/views/admin_header.php:3)`

**Root Cause:** Admin action handlers include view files directly (e.g., `include __DIR__ . '/../../../views/admin_settings.php'`). When multiple tests run, the same view file is included multiple times, causing function redeclaration errors.

**Affected Tests:**
- AdminActionsTest.php - all admin handler tests that include views
- The error occurs at the end of test runs when views are included multiple times

**Files Involved:**
- `views/admin_header.php` - contains `render_admin_header()` function
- `views/admin_settings.php` - includes admin_header.php
- `src/actions/admin/settings.php` - includes admin_settings.php
- Similar patterns in other admin handlers

### 2. Missing Helper Functions

**Errors:**
- `Call to undefined function csrf_validate_request()`
- `Call to undefined function base_url()`
- `Call to undefined function load_lang_file()`
- `Call to undefined function current_route_action()`
- `Call to undefined function setup_test_app()` (in EmailTest - wrong function name)

**Root Cause:** Test bootstrap doesn't load all required helper files. The main bootstrap.php loads minimal files for testing, but admin handlers and other code depend on helpers from `src/bootstrap.php` or `src/helpers.php`.

**Affected Tests:**
- AdminActionsTest.php - csrf_validate_request, base_url
- ThemeManagerTest.php - base_url, load_lang_file
- RouterTest.php - current_route_action (used in views)
- EmailTest.php - setup_test_app (should be emailtest_setup_app)

**Required Files:**
- `src/helpers.php` - contains base_url(), csrf_validate_request(), current_route_action()
- `src/Helpers/Text.php` - contains load_lang_file()
- `src/bootstrap.php` - full application bootstrap

### 3. AuthZ Constructor Issues

**Error:** `AuthZ::__construct(): Argument #1 ($pdo) must be of type PDO, PluginManager given`

**Root Cause:** RouterTest's `setup_test_app()` creates AuthZ with wrong parameter order:
```php
$app->authz = new AuthZ($pluginManager);  // Wrong - missing PDO
```

Should be:
```php
$app->authz = new AuthZ($pdo, $pluginManager);
```

**Affected Tests:**
- All RouterTest.php tests (32 errors)

### 4. Upload Validation

**Errors:** `validate_upload()` returns NULL for valid images

**Root Cause:** `validate_upload()` uses `is_uploaded_file($tmpPath)` which returns FALSE for regular files created in tests. The function is designed to only accept files uploaded via HTTP POST.

**Affected Tests:**
- UploadTest.php - tests for valid images, JPEG, multiple types, safe name generation, alias function

**Code in src/Helpers/Upload.php:**
```php
function validate_upload(string $tmpPath, string $origName, array $allowed, int $maxSize): ?array
{
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {  // <-- This fails
        return null;
    }
    ...
}
```

### 5. Test Isolation Issues

**Problems:**
- Global `$config` variable shared across tests
- Session state persists between tests
- Rate limiter files not cleaned up properly
- Database state not reset between tests
- View functions declared globally

**Affected Tests:**
- AdminActionsTest.php - settings test fails because global $config not updated
- Multiple tests may interfere with each other

---

## Remediation Plan

### Phase 1: Fix View Inclusion Conflicts (High Priority)

**Approach:** Modify test bootstrap to prevent view function redeclaration.

**Options:**
1. **Use `include_once` in views** - Modify view files to use `include_once` for shared partials
2. **Mock view inclusion** - In tests, override `include` behavior or mock the view rendering
3. **Separate view functions** - Move functions from views to helper files
4. **Test-level view isolation** - Track included views and prevent re-inclusion

**Recommended:** Option 1 (minimal change) + Option 4 (test harness support)
- Modify `views/admin_header.php` and similar to use `include_once`
- Add view inclusion tracking to test harness

**Files to Modify:**
- `views/admin_header.php` - change `include` to `include_once` for partials
- `views/admin_footer.php` - same
- `tests/harness.php` - add view inclusion tracking

### Phase 2: Fix Missing Helper Functions (High Priority)

**Approach:** Load required helper files in test bootstrap.

**Files to Load in tests/bootstrap.php:**
```php
require_once __DIR__ . '/../src/helpers.php';           // base_url, csrf_validate_request, current_route_action
require_once __DIR__ . '/../src/Helpers/Text.php';      // load_lang_file
require_once __DIR__ . '/../src/Helpers/AuthHelpers.php'; // is_logged_in, is_admin, etc.
require_once __DIR__ . '/../src/Helpers/Url.php';       // url()
require_once __DIR__ . '/../src/Helpers/Data.php';      // slugify()
require_once __DIR__ . '/../src/Security.php';          // csrf_validate_request, rate_limit
```

**Also Fix:** EmailTest.php - rename `setup_test_app` calls to `emailtest_setup_app`

### Phase 3: Fix AuthZ Constructor (High Priority)

**Approach:** Fix RouterTest.php `setup_test_app()` function.

**Change in tests/RouterTest.php line ~110:**
```php
// Before (wrong)
$app->authz = new AuthZ($pluginManager);

// After (correct)
$app->authz = new AuthZ($pdo, $pluginManager);
```

### Phase 4: Fix Upload Validation (Medium Priority)

**Approach Options:**

1. **Modify validate_upload() for testing** - Add a test mode flag or parameter to skip `is_uploaded_file()` check
2. **Use actual file uploads in tests** - Use `$_FILES` with `move_uploaded_file()` simulation
3. **Create test-specific wrapper** - Add `validate_upload_for_test()` function

**Recommended:** Option 1 - Add optional parameter to skip upload check
```php
function validate_upload(string $tmpPath, string $origName, array $allowed, int $maxSize, bool $skipUploadCheck = false): ?array
{
    if (!$skipUploadCheck && ($tmpPath === '' || !is_uploaded_file($tmpPath))) {
        return null;
    }
    ...
}
```

**Files to Modify:**
- `src/Helpers/Upload.php` - add `$skipUploadCheck` parameter
- `tests/UploadTest.php` - pass `true` for test calls

### Phase 5: Improve Test Isolation (Medium Priority)

**Approach:** Enhance test bootstrap and harness for better isolation.

**Changes:**

1. **Reset global state in bootstrap:**
```php
// In tests/bootstrap.php
$GLOBALS['config'] = $config;  // Ensure fresh config
$_SESSION = [];  // Clear session
```

2. **Add test-level cleanup in harness:**
```php
// In Test::run() or TestSuite::run()
register_shutdown_function(function() {
    // Clean up rate limiter files
    // Reset global config
    // Clear session
});
```

3. **Use unique database per test** - Already done via temp directories

4. **Fix global $config in AdminActionsTest** - Ensure tests update `$GLOBALS['config']` not just local variable

---

## Implementation Order

1. **Phase 3** (AuthZ constructor) - Quick fix, unblocks 32 RouterTest tests
2. **Phase 2** (Missing helpers) - Unblocks AdminActions, ThemeManager, EmailTest
3. **Phase 1** (View conflicts) - Fixes fatal error stopping test runs
4. **Phase 4** (Upload validation) - Fixes 22 failing UploadTest tests
5. **Phase 5** (Test isolation) - Prevents flaky tests

---

## Verification Criteria

After each phase, run relevant test suites:

```bash
# Phase 3 verification
php tests/run.php --filter "Router"

# Phase 2 verification
php tests/run.php --filter "Admin"
php tests/run.php --filter "ThemeManager"
php tests/run.php --filter "Email"

# Phase 1 verification
php tests/run.php --filter "Admin"  # Should not crash with redeclare error

# Phase 4 verification
php tests/run.php --filter "Upload"

# Full suite
php tests/run.php
```

**Target:** All 495 tests pass (or at least no fatal errors, minimal failures)

---

## Risk Assessment

| Phase | Risk | Mitigation |
|-------|------|------------|
| 1 (Views) | Low | Minimal changes to view files, backward compatible |
| 2 (Helpers) | Low | Loading more files in bootstrap, no code changes |
| 3 (AuthZ) | Low | Single line fix in test file |
| 4 (Upload) | Medium | Changes production code signature, but backward compatible with default param |
| 5 (Isolation) | Medium | Bootstrap changes could affect existing tests |

---

## Dependencies

- Phase 1 should complete before full test runs (fatal error blocks everything)
- Phase 2 and 3 can be done in parallel
- Phase 4 independent
- Phase 5 benefits from 1-4 being complete

---

## Timeline Estimate

| Phase | Effort | Duration |
|-------|--------|----------|
| 1 | 2-3 hours | Day 1 |
| 2 | 1-2 hours | Day 1 |
| 3 | 0.5 hours | Day 1 |
| 4 | 1-2 hours | Day 2 |
| 5 | 2-3 hours | Day 2 |
| **Total** | **7-10 hours** | **2 days** |

---

## Notes

- The "Cannot redeclare function" fatal error is the most critical as it stops test execution entirely
- Some test failures (like admin_create_user validation) may be pre-existing issues not related to new tests
- Consider adding a test configuration flag to `validate_upload()` for production safety
- Document any production code changes in CHANGELOG if project maintains one