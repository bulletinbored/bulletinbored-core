# Test Remediation Plan

**Based on:** Test run results from 2026-11-08
**Date:** 2026-11-08
**Status:** Draft for review

---

## Overview

This plan addresses the test failures identified in the current test suite run (1573 passed, 38 failed, 1 error). The failures fall into several categories:

1. **Router Tests** - Multiple middleware and routing failures (13 failures)
2. **Admin Actions Tests** - Categories CRUD, Settings, Plugins, Themes (8 failures)
3. **Upload Tests** - File sorting, safe name generation, MIME type detection (4 failures)
4. **ThemeManager Tests** - Active theme fallback, install/update messages, rollback, ghost theme removal (10 failures)
5. **Content Hardening** - Request parsing edge case (1 failure)
6. **BB-001 can_view_thread** - Guest access to hidden/pending threads (2 failures)

---

## Failure Categories Analysis

### 1. Router Tests (13 failures)

**Root Cause:** The Router test setup in `tests/RouterTest.php` has issues with:
- AuthZ constructor receiving wrong parameters (PluginManager instead of PDO)
- Middleware not properly redirecting/returning correct status codes
- Request parsing not handling POST data correctly
- JSON content-type detection issues

**Affected Tests:**
- `Test: Router - POST Method` - POST data in params (expected 'test', got '')
- `Test: Router - Method Mismatch Returns 404` - Returns 404 for wrong method
- `Test: Router - 404 Not Found` - Returns 404
- `Test: Router - Guest Middleware` - Redirects logged-in user
- `Test: Router - Auth Middleware` - Redirects to login
- `Test: Router - Admin Middleware` - Redirects to login
- `Test: Router - CSRF Middleware` - Returns 404 for GET
- `Test: Router - Middleware Chain` - Multiple failures (3)
- `Test: Router - API Group` - Content-Type is JSON
- `Test: Router - Wants JSON False for HTML` - Does not want JSON for HTML accept
- `Test: Router - Exception Handling` - Returns 404
- `Test: Router - Response Object` - Status 201
- `Test: Router - Middleware Can Block Request` - Status 403

**Files Involved:**
- `tests/RouterTest.php` - Test setup and test cases
- `src/Router.php` - Router implementation
- `src/AuthZ.php` - Authorization class

---

### 2. Admin Actions Tests (8 failures)

**Root Cause:** Multiple issues in admin action handlers and test setup:

**a) Admin Categories CRUD** - UNIQUE constraint violation on categories.name
- Test tries to create duplicate category names
- `src/actions/admin/categories.php:28` - INSERT fails

**b) Admin Settings** - Multiple warnings:
- Undefined array key "site_icon" in `src/actions/admin/settings.php:16`
- Deprecated trim() with null parameter
- Headers already sent (output started in test runner)

**c) Admin Plugins - Install/Update/Uninstall** - Version not updated, plugin not removed from installed.json
- Update process not properly updating version in installed.json
- Uninstall not cleaning up installed.json

**d) Admin Themes - Install/Activate/Delete** - Active theme not switching, theme directory not removed
- Theme activation not persisting
- Delete not removing theme directory

**Files Involved:**
- `src/actions/admin/categories.php`
- `src/actions/admin/settings.php`
- `src/actions/admin/plugins.php`
- `src/actions/admin/themes.php`
- `lib/PluginManager.php`
- `lib/ThemeManager.php`

---

### 3. Upload Tests (4 failures)

**Root Cause:** Issues with file handling and MIME type detection:

**a) Get Uploaded Images** - Sorting by mtime desc fails (expected 'image3.gif', got 'image1.png')
- File creation order in test doesn't match expected mtime order

**b) Safe Name Generation** - Safe name format validation fails
- Generated names don't match expected hex + extension pattern

**c) Multiple Allowed Types** - JPEG returns 'png' extension, GIF returns 'png' extension
- MIME type to extension mapping incorrect for JPEG/GIF

**Files Involved:**
- `src/Helpers/Upload.php`
- `tests/UploadTest.php`

---

### 4. ThemeManager Tests (10 failures)

**Root Cause:** ThemeManager implementation issues:

**a) Get Active Theme** - Fallback returns 'darkmode' instead of 'freshbored'
- Fallback logic incorrect when persisted theme not found

**b) Install From ZIP** - Success message says 'Package installed' instead of 'Theme installed'
- Error message for duplicate install not matching expected

**c) Update Rollback on Failure** - Multiple failures:
- Update doesn't fail with invalid package
- Original files not restored
- Version not rolled back

**d) Remove Missing Themes** - Ghost theme not detected/removed
- Cleanup logic not finding orphaned theme directories

**Files Involved:**
- `lib/ThemeManager.php`
- `tests/ThemeManagerTest.php`

---

### 5. Content Hardening - Request Parsing (1 failure)

**Root Cause:** Request::string() returns wrong default value
- Expected 'default', got 'Updated Category 6aefbbc196b58'
- Test pollution from previous test modifying global state

**Files Involved:**
- `src/Request.php`
- `tests/ContentHardeningTest.php`

---

### 6. BB-001 can_view_thread (2 failures)

**Root Cause:** Guest users can view hidden/pending threads when they shouldn't
- Authorization logic in `can_view_thread()` helper not properly checking permissions

**Files Involved:**
- `src/Helpers/AuthHelpers.php` - `can_view_thread()` function
- `tests/AuthHelpersTest.php` (or similar)

---

## Remediation Plan

### Phase 1: Fix Router Tests (High Priority - 13 failures)

**Approach:** Fix the test setup in `RouterTest.php` and verify Router implementation.

**Tasks:**
1. Fix `setup_test_app()` in `RouterTest.php` - AuthZ constructor needs PDO first, then PluginManager
2. Verify middleware redirect logic returns correct status codes (302 for redirects, 403 for forbidden)
3. Fix POST data parsing in Router
4. Fix JSON content-type detection for API routes
5. Fix exception handling to return proper 404/400 status codes

**Files to Modify:**
- `tests/RouterTest.php` - Fix test setup
- `src/Router.php` - Fix middleware and routing logic if needed

---

### Phase 2: Fix Admin Actions Tests (High Priority - 8 failures)

**Approach:** Fix each admin action handler and test data setup.

**Tasks:**

**2a) Admin Categories CRUD:**
- Ensure test uses unique category names
- Add cleanup between test cases

**2b) Admin Settings:**
- Fix undefined "site_icon" key - add null coalescing or default
- Fix deprecated trim(null) - add null check
- Fix headers already sent - ensure no output before headers

**2c) Admin Plugins:**
- Fix version update in installed.json during plugin update
- Fix uninstall to remove from installed.json

**2d) Admin Themes:**
- Fix theme activation persistence
- Fix theme directory removal on delete

**Files to Modify:**
- `src/actions/admin/categories.php`
- `src/actions/admin/settings.php`
- `src/actions/admin/plugins.php`
- `src/actions/admin/themes.php`
- `lib/PluginManager.php`
- `lib/ThemeManager.php`

---

### Phase 3: Fix Upload Tests (Medium Priority - 4 failures)

**Approach:** Fix file handling and MIME type detection in Upload helper.

**Tasks:**
1. Fix test to create files in correct order for mtime sorting
2. Fix safe name generation to match expected hex + extension pattern
3. Fix MIME type to extension mapping for JPEG (jpg) and GIF (gif)

**Files to Modify:**
- `src/Helpers/Upload.php` - Fix `get_extension_from_mime()` function
- `tests/UploadTest.php` - Fix test file creation order

---

### Phase 4: Fix ThemeManager Tests (Medium Priority - 10 failures)

**Approach:** Fix ThemeManager implementation.

**Tasks:**
1. Fix fallback logic in `getActiveTheme()` to return 'freshbored' as default
2. Fix success message in `installFromZip()` to say 'Theme installed'
3. Fix duplicate install error message
4. Fix update rollback on failure - ensure original files restored, version rolled back
5. Fix ghost theme detection in `removeMissingThemes()`

**Files to Modify:**
- `lib/ThemeManager.php`
- `tests/ThemeManagerTest.php` - Update expected messages

---

### Phase 5: Fix Content Hardening & BB-001 (Low Priority - 3 failures)

**Approach:** Fix test isolation and authorization logic.

**Tasks:**
1. Fix Request::string() default value - ensure test isolation
2. Fix `can_view_thread()` to properly deny guest access to hidden/pending threads

**Files to Modify:**
- `src/Request.php` - If bug in default handling
- `src/Helpers/AuthHelpers.php` - Fix `can_view_thread()`
- `tests/ContentHardeningTest.php` - Ensure test isolation
- `tests/AuthHelpersTest.php` - Fix test expectations

---

## Implementation Order

1. **Phase 1** (Router) - Unblocks 13 tests, foundation for other tests
2. **Phase 2** (Admin Actions) - Unblocks 8 tests, core admin functionality
3. **Phase 3** (Upload) - Unblocks 4 tests, file handling
4. **Phase 4** (ThemeManager) - Unblocks 10 tests, theme system
5. **Phase 5** (Content Hardening & BB-001) - Unblocks 3 tests, security helpers

---

## Verification Criteria

After each phase, run relevant test suites:

```bash
# Phase 1 verification
php tests/run.php --filter "Router"

# Phase 2 verification
php tests/run.php --filter "Admin"

# Phase 3 verification
php tests/run.php --filter "Upload"

# Phase 4 verification
php tests/run.php --filter "ThemeManager"

# Phase 5 verification
php tests/run.php --filter "ContentHardening"
php tests/run.php --filter "BB-001"

# Full suite
php tests/run.php
```

**Target:** All 495 tests pass (0 failed, 0 errors)

---

## Risk Assessment

| Phase | Risk | Mitigation |
|-------|------|------------|
| 1 (Router) | Medium | Core routing changes could affect application; test thoroughly |
| 2 (Admin) | Low | Admin-only changes, well-isolated |
| 3 (Upload) | Low | Helper function changes, backward compatible |
| 4 (ThemeManager) | Medium | Theme system changes; verify install/update/delete flows |
| 5 (AuthHelpers) | Low | Security helper fix; verify authorization still works |

---

## Dependencies

- Phase 1 should complete first (Router is used by many tests)
- Phase 2 and 3 can be done in parallel
- Phase 4 depends on PluginManager (shared code)
- Phase 5 independent

---

## Timeline Estimate

| Phase | Effort | Duration |
|-------|--------|----------|
| 1 | 3-4 hours | Day 1 |
| 2 | 3-4 hours | Day 1-2 |
| 3 | 1-2 hours | Day 2 |
| 4 | 2-3 hours | Day 2-3 |
| 5 | 1-2 hours | Day 3 |
| **Total** | **10-15 hours** | **3 days** |

---

## Notes

- The "headers already sent" warning in Admin Settings is caused by test runner output starting before the action handler sends headers
- Some test failures may be due to test pollution (shared global state between tests)
- Consider adding test isolation improvements in a follow-up phase
- Document any production code changes in CHANGELOG if project maintains one