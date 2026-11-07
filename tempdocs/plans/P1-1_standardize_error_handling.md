# P1-1: Standardize Error Handling Pattern - Detailed Task Breakdown

**Based on:** REMEDIATION_PLAN.md (P1-1) and CODE_REVIEW_REPORT.md (Issue #4)
**Status:** Ready for implementation
**Approach:** Option 1 - Keep session flash for form validation UX, throw exceptions for hard errors

---

## Overview

Refactor all action handlers to use a consistent error handling pattern:
- **All error conditions** → Throw appropriate `HttpException` subclass
- **Successful POST-Redirect-GET** → Keep using `redirect()` helper
- **Successful GET (render view)** → Return `true`
- **API endpoints** → Throw exceptions; router converts to JSON via `wantsJson()`

---

## Exception Classes Available (src/Errors.php)

| Exception | HTTP Status | Use Case |
|-----------|-------------|----------|
| `HttpException` | 500 | Base class, unexpected errors |
| `UnauthorizedException` | 401 | Not authenticated |
| `ForbiddenException` | 403 | Authenticated but not authorized, CSRF failure |
| `NotFoundException` | 404 | Resource not found |
| `ValidationException` | 422 | Validation errors with field details |
| `ConflictException` | 409 | Resource conflict (e.g., duplicate) |
| `TooManyRequestsException` | 429 | Rate limit exceeded |
| `MethodNotAllowedException` | 405 | HTTP method not allowed |

---

## Refactoring Order (Lowest Risk First)

### Phase 1: Simple Files (Already Mostly Using Exceptions)

#### Task 1.1: `src/actions/content.php`
**Functions:** `handle_search`, `handle_category`, `handle_download`
**Current patterns:** `throw NotFoundException` ✓, `return false` (dispatcher fallback)
**Changes needed:**
- [ ] `handle_search`: Change `return false` → `throw new NotFoundException('Search action not found')`
- [ ] `handle_category`: Already throws `NotFoundException` ✓
- [ ] `handle_download`: Already throws `NotFoundException` ✓
- [ ] `handle_content_action` dispatcher: Change `return false` → `throw new NotFoundException('Content action not found')`

**Verification:** Run tests, verify 404 for invalid actions

---

#### Task 1.2: `src/actions/misc.php`
**Functions:** `handle_markdown_preview`, `handle_mention_users`
**Current patterns:** `Response::html` for CSRF error, `Response::json` for success
**Changes needed:**
- [ ] `handle_markdown_preview`: Change `Response::html('<p>CSRF token invalid</p>', 403)` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] `handle_mention_users`: Already returns `Response::json` for success ✓ (no error paths)
- [ ] `handle_misc_action` dispatcher: Change `return false` → `throw new NotFoundException('Misc action not found')`

**Verification:** Test CSRF failure returns 403 HTML/JSON appropriately

---

#### Task 1.3: `src/actions/admin/categories.php`
**Functions:** `handle_admin_categories`, `handle_delete_category_post`, `handle_update_category_order_post`
**Current patterns:** Mostly throws exceptions ✓, `Response::json` for API errors
**Changes needed:**
- [ ] `handle_update_category_order_post`: Change `Response::json(['success' => false, 'message' => '...'], 403)` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] `handle_update_category_order_post`: Change `Response::json(['success' => false, 'message' => '...'], 429)` → `throw new TooManyRequestsException('...')`
- [ ] `handle_update_category_order_post`: Change `Response::json(['success' => false, 'message' => '...'], 400)` → `throw new ValidationException(['order' => 'Invalid order data'])`
- [ ] Verify all other error paths already throw exceptions

**Verification:** Test API endpoints return JSON errors via router

---

#### Task 1.4: `src/actions/admin/moderation.php`
**Functions:** All moderation handlers
**Current patterns:** Already mostly throws exceptions ✓
**Changes needed:**
- [ ] Audit all functions - verify no `return redirect` for errors
- [ ] Verify `handle_moderate_post` line 47-44: success redirects are OK
- [ ] Verify `handle_frontend_moderate_post` line 125: success redirect is OK
- [ ] Verify `handle_split_thread_post` line 202-204: success redirects are OK
- [ ] Verify `handle_merge_thread_post` line 248: success redirect is OK

**Verification:** Run moderation tests

---

### Phase 2: Core Content Handlers (Moderate Complexity)

#### Task 2.1: `src/actions/posts-thread.php`
**Functions:** `handle_upload_image`, `handle_thread_view`, `handle_watch`, `handle_unwatch`

**`handle_upload_image` (API endpoint):**
- [ ] Line 9: `Response::json(['ok' => false, 'error' => 'Login required'], 401)` → `throw new UnauthorizedException('Login required')`
- [ ] Line 12: `Response::json(['ok' => false, 'error' => 'CSRF token invalid'], 403)` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 15: `Response::json(['ok' => false, 'error' => '...'], 429)` → `throw new TooManyRequestsException('...')`
- [ ] Line 18: `Response::json(['ok' => false, 'error' => 'No file uploaded'], 400)` → `throw new ValidationException(['image' => 'No file uploaded'])`
- [ ] Line 30: `Response::json(['ok' => false, 'error' => 'Invalid image'], 400)` → `throw new ValidationException(['image' => 'Invalid image'])`
- [ ] Line 36: `Response::json(['ok' => false, 'error' => 'Invalid attachment context'], 400)` → `throw new ValidationException(['context' => 'Invalid attachment context'])`
- [ ] Line 52: `Response::json(['ok' => false, 'error' => 'Thread not found'], 404)` → `throw new NotFoundException('Thread not found')`
- [ ] Line 60: `Response::json(['ok' => false, 'error' => 'Post does not belong to thread'], 400)` → `throw new ValidationException(['post_id' => 'Post does not belong to thread'])`
- [ ] Line 72: `Response::json(['ok' => false, 'error' => 'Move failed'], 500)` → `throw new HttpException('Move failed', 500)`
- [ ] Line 91: `Response::json(['ok' => false, 'error' => 'Database error'], 500)` → `throw new HttpException('Database error', 500)`
- [ ] Line 95: Success response `Response::json([...])` → Keep as-is (success)

**`handle_thread_view`:**
- [ ] Line 105: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')` (or `ValidationException` for invalid ID)
- [ ] Line 131: Already throws `NotFoundException` ✓
- [ ] Line 136: Already throws `ForbiddenException` ✓
- [ ] Line 195: `return true` ✓ (success)

**`handle_watch`:**
- [ ] Line 203: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()` (but middleware 'auth' should catch this first - verify)
- [ ] Line 207: `return redirect(url('thread', [...]))` (CSRF fail) → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 211: Already throws `TooManyRequestsException` ✓
- [ ] Line 216: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')`
- [ ] Line 224: Already throws `ForbiddenException` ✓
- [ ] Line 244: `return redirect(url('thread', [...]))` ✓ (success POST-Redirect-GET)

**`handle_unwatch`:**
- [ ] Line 252: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 256: `return redirect(url('thread', [...]))` (CSRF fail) → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 260: Already throws `TooManyRequestsException` ✓
- [ ] Line 265: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')`
- [ ] Line 273: Already throws `ForbiddenException` ✓
- [ ] Line 285: `return redirect(url('thread', [...]))` ✓ (success)

**Verification:** Test all endpoints for HTML and JSON responses

---

#### Task 2.2: `src/actions/posts-edit.php`
**Functions:** `handle_reply_post`, `handle_edit_post`, `handle_delete_post`, `handle_edit_thread`, `handle_delete_thread`

**`handle_reply_post`:**
- [ ] Line 10: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()` (middleware should catch)
- [ ] Line 15: Already throws `ForbiddenException` ✓
- [ ] Line 20: `$_SESSION['reply_error'] = 'CSRF token invalid'; return redirect(...)` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 25: `$_SESSION['reply_error'] = '...'; return redirect(...)` → `throw new TooManyRequestsException('...')`
- [ ] Line 34: `$_SESSION['reply_error'] = 'Content is required'; $_SESSION['reply_content'] = ...; return redirect(...)` → `throw new ValidationException(['content' => 'Content is required'])`
- [ ] Line 42: Already throws `NotFoundException` ✓
- [ ] Line 47: `$_SESSION['reply_error'] = 'Thread is locked'; return redirect(...)` → `throw new ForbiddenException('Thread is locked')` (or `ConflictException`)
- [ ] Line 51: Already throws `ForbiddenException` ✓
- [ ] Line 92: `return redirect(...)` ✓ (success POST-Redirect-GET)

**`handle_edit_post`:**
- [ ] Line 102: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 107: `return redirect(url('home'))` → `throw new NotFoundException('Post not found')`
- [ ] Line 111: Already throws `TooManyRequestsException` ✓
- [ ] Line 119: `return redirect(url('home'))` → `throw new NotFoundException('Post not found')`
- [ ] Line 129: Already throws `ForbiddenException` ✓
- [ ] Line 134: Already throws `ForbiddenException` ✓
- [ ] Line 163: `return redirect(...)` ✓ (success)
- [ ] Line 168: `return true` ✓ (GET success)

**`handle_delete_post`:**
- [ ] Line 178: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 182: Already throws `ForbiddenException` ✓
- [ ] Line 186: Already throws `TooManyRequestsException` ✓
- [ ] Line 191: `return redirect(url('home'))` → `throw new NotFoundException('Post not found')`
- [ ] Line 199: `return redirect(url('home'))` → `throw new NotFoundException('Post not found')`
- [ ] Line 204: Already throws `ForbiddenException` ✓
- [ ] Line 210: Already throws `ForbiddenException` ✓
- [ ] Line 223: `return redirect(...)` ✓ (success)

**`handle_edit_thread`:**
- [ ] Line 233: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 238: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')`
- [ ] Line 242: Already throws `TooManyRequestsException` ✓
- [ ] Line 250: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')`
- [ ] Line 255: Already throws `ForbiddenException` ✓
- [ ] Line 260: Already throws `ForbiddenException` ✓
- [ ] Line 295: `return redirect(...)` ✓ (success)
- [ ] Line 304: `return true` ✓ (GET success)

**`handle_delete_thread`:**
- [ ] Line 314: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 318: Already throws `ForbiddenException` ✓
- [ ] Line 322: Already throws `TooManyRequestsException` ✓
- [ ] Line 327: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')`
- [ ] Line 335: `return redirect(url('home'))` → `throw new NotFoundException('Thread not found')`
- [ ] Line 340: Already throws `ForbiddenException` ✓
- [ ] Line 344: Already throws `ForbiddenException` ✓
- [ ] Line 358: `return redirect(...)` ✓ (success)

**Verification:** Test all CRUD operations, verify form validation UX preserved (Option 1: session flash for validation errors)

---

#### Task 2.3: `src/actions/posts-new.php`
**Functions:** `handle_new_thread`

**`handle_new_thread`:**
- [ ] Line 11: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 16: Already throws `ForbiddenException` ✓
- [ ] Line 23: `$_SESSION['new_thread_error'] = 'CSRF token invalid'; include view; return true` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 28: `$_SESSION['new_thread_error'] = '...'; include view; return true` → `throw new TooManyRequestsException('...')`
- [ ] Line 42: Already throws `ForbiddenException` ✓
- [ ] Line 44: Already throws `ForbiddenException` ✓
- [ ] Line 48: Already throws `ForbiddenException` ✓
- [ ] Line 54: `$_SESSION['new_thread_error'] = 'Title and content are required'; $_SESSION['new_thread_title'] = ...; include view; return true` → `throw new ValidationException(['title' => 'Title is required', 'content' => 'Content is required'])`
- [ ] Line 75: `$_SESSION['new_thread_error'] = '...'; include view; return true` → `throw new ForbiddenException('Thread creation blocked')`
- [ ] Line 99: `return redirect(...)` ✓ (success)

**Note:** This handler re-renders the view on validation errors with form data preserved in session. With Option 1, we keep this pattern for validation errors but throw exceptions for hard errors.

**Verification:** Test thread creation with validation errors

---

### Phase 3: User Authentication Handlers (High Complexity - Session Flash Pattern)

#### Task 3.1: `src/actions/users.php`
**Functions:** `handle_login`, `handle_register`, `handle_logout`, `handle_verify_email`, `handle_profile`, `handle_edit_profile`, `handle_remove_avatar`, `handle_forgot_password`, `handle_reset_password`

**Strategy for Option 1:** Keep session flash for form validation errors (preserves UX of re-rendering form with user input), throw exceptions for hard errors.

**`handle_login`:**
- [ ] Line 33: `return redirect(url('home'))` ✓ (already logged in - redirect)
- [ ] Line 40: `$error = 'CSRF token invalid'; include view; return true` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 48: `$error = 'Too many login attempts...'; include view; return true` → `throw new TooManyRequestsException('...')`
- [ ] Line 72: `$error = 'user_banned'; include view; return true` → `throw new ForbiddenException('Account banned')`
- [ ] Line 75: `$error = 'user_suspended'; include view; return true` → `throw new ForbiddenException('Account suspended')`
- [ ] Line 77: `$error = 'email_not_verified'; include view; return true` → `throw new ForbiddenException('Email not verified')`
- [ ] Line 101: `$error = 'Invalid credentials'; include view; return true` → **Keep as session flash** (validation error - Option 1)
- [ ] Line 99: `return redirect(url('home'))` ✓ (success)

**`handle_register`:**
- [ ] Line 121: `$error = 'CSRF token invalid'; include view; return true` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 127: `$error = 'Too many registration attempts...'; include view; return true` → `throw new TooManyRequestsException('...')`
- [ ] Line 136: `$error = 'Username and password are required'; include view; return true` → **Keep as session flash** (validation)
- [ ] Line 143: `$error = t($pwErrors[0]); include view; return true` → **Keep as session flash** (validation)
- [ ] Line 152: `$error = 'Username already taken'; include view; return true` → **Keep as session flash** (validation - conflict)
- [ ] Line 183: `return redirect(url('login', ['registered' => 1]))` ✓ (success)

**`handle_logout`:**
- [ ] Line 193: `return redirect(url('home'))` ✓ (GET not allowed - redirect)
- [ ] Line 196: `return redirect(url('home'))` ✓ (CSRF fail - redirect)
- [ ] Line 200: `return redirect(url('home'))` ✓ (success)

**`handle_verify_email`:**
- [ ] Line 210: `$error = 'verify_email_invalid'; include view; return true` → `throw new NotFoundException('Invalid verification token')`
- [ ] Line 221: `$error = 'verify_email_invalid'; include view; return true` → `throw new NotFoundException('Invalid verification token')`
- [ ] Line 229: `$error = 'verify_email_invalid'; include view; return true` → `throw new NotFoundException('Invalid verification token')`
- [ ] Line 237: `include view; return true` ✓ (success)

**`handle_profile`:**
- [ ] Line 251: Already throws `NotFoundException` ✓
- [ ] Line 276: `return true` ✓ (success)

**`handle_edit_profile`:**
- [ ] Line 284: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 289: `$_SESSION['profile_error'] = 'CSRF token invalid'; return redirect(...)` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 294: `$_SESSION['profile_error'] = '...'; return redirect(...)` → `throw new TooManyRequestsException('...')`
- [ ] Line 306: `$_SESSION['avatar_upload_error'] = '...'; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 317: `$_SESSION['avatar_upload_error'] = '...'; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 322: `$_SESSION['avatar_upload_error'] = '...'; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 350: `$_SESSION['avatar_upload_error'] = '...'; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 354: `return redirect(...)` ✓ (success - avatar upload)
- [ ] Line 365: `$_SESSION['profile_error'] = 'Username already taken'; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 381: `$_SESSION['profile_error'] = t($pwErrors[0]); return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 400: `return redirect(...)` ✓ (success - profile update)

**`handle_remove_avatar`:**
- [ ] Line 413: `return redirect(url('login')) ?? true` → `throw new UnauthorizedException()`
- [ ] Line 418: `$_SESSION['avatar_upload_error'] = 'CSRF token invalid'; return redirect(...)` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 423: `$_SESSION['avatar_upload_error'] = '...'; return redirect(...)` → `throw new TooManyRequestsException('...')`
- [ ] Line 442: `return redirect(...)` ✓ (success)

**`handle_forgot_password`:**
- [ ] Line 451: `$error = 'CSRF token invalid'; include view; return true` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 457: `$error = 'Too many requests...'; include view; return true` → `throw new TooManyRequestsException('...')`
- [ ] Line 484: `include view; return true` ✓ (success - always shows success message for security)

**`handle_reset_password`:**
- [ ] Line 497: `$error = 'CSRF token invalid'; include view; return true` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 503: `$error = 'Too many attempts...'; include view; return true` → `throw new TooManyRequestsException('...')`
- [ ] Line 513: `$error = 'Passwords do not match.'; include view; return true` → **Keep as session flash** (validation)
- [ ] Line 519: `$error = t($pwErrors[0]); include view; return true` → **Keep as session flash** (validation)
- [ ] Line 530: `$error = 'Invalid or expired reset token.'; include view; return true` → `throw new NotFoundException('Invalid or expired reset token')`
- [ ] Line 538: `$error = 'Invalid or expired reset token.'; include view; return true` → `throw new NotFoundException('Invalid or expired reset token')`
- [ ] Line 562: `return redirect(url('login'))` ✓ (success)
- [ ] Line 566: Already throws `NotFoundException` ✓ (no token in GET)
- [ ] Line 570: `include view; return true` ✓ (GET with token)

**Verification:** Test all auth flows, ensure form validation UX preserved

---

### Phase 4: Admin Handlers (Moderate Complexity)

#### Task 4.1: `src/actions/admin.php`
**Functions:** `handle_admin_action` (dispatcher), `handle_admin_dashboard`

**`handle_admin_action` dispatcher:**
- [ ] Line 26: `return false` → `throw new NotFoundException('Admin action not found')`

**`handle_admin_dashboard`:**
- [ ] Line 111: `$adminError = 'Invalid CSRF token'` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 113: `$adminError = 'You are changing settings too fast...'` → `throw new TooManyRequestsException('...')`
- [ ] Line 126: `$adminError = 'Failed to save settings'` → `throw new HttpException('Failed to save settings', 500)`
- [ ] Line 130: `return redirect(url('admin_settings'))` ✓ (success POST-Redirect-GET)
- [ ] Line 134: `return true` ✓ (GET success)

**Verification:** Test admin dashboard

---

#### Task 4.2: `src/actions/admin/users.php`
**Functions:** All admin user management handlers

**`handle_admin_roles_action_post`:**
- [ ] Line 14: Already throws `ForbiddenException` ✓
- [ ] Line 17: Already throws `TooManyRequestsException` ✓
- [ ] Line 43: `return redirect(url('admin_roles'))` ✓ (success)

**`handle_admin_user_edit`:**
- [ ] Line 58: `return redirect(url('admin_users'))` → `throw new NotFoundException('User not found')`
- [ ] Line 64: `return redirect(url('admin_users'))` → `throw new NotFoundException('User not found')`
- [ ] Line 68: Already throws `ForbiddenException` ✓
- [ ] Line 71: Already throws `TooManyRequestsException` ✓
- [ ] Line 99: `$_SESSION['admin_user_error'] = t($pwErrors[0]); return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 119: `return redirect(...)` ✓ (success)

**`handle_admin_create_user_post`:**
- [ ] Line 130: Already throws `ForbiddenException` ✓
- [ ] Line 133: Already throws `TooManyRequestsException` ✓
- [ ] Line 144: `$_SESSION['admin_user_error'] = '...'; $_SESSION['admin_user_old'] = ...; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 151: `$_SESSION['admin_user_error'] = t($pwErrors[0]); $_SESSION['admin_user_old'] = ...; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 160: `$_SESSION['admin_user_error'] = 'Username already taken'; $_SESSION['admin_user_old'] = ...; return redirect(...)` → **Keep as session flash** (validation)
- [ ] Line 193: `return redirect(url('admin_users'))` ✓ (success)

**`handle_delete_user_post`:**
- [ ] Line 201: Already throws `ForbiddenException` ✓
- [ ] Line 204: Already throws `TooManyRequestsException` ✓
- [ ] Line 211: `$_SESSION['admin_error'] = '...'` → **Keep as session flash** (validation - user not found or is admin)
- [ ] Line 216: `$_SESSION['admin_error'] = '...'` → **Keep as session flash** (validation)
- [ ] Line 218: `return redirect(url('admin_users'))` ✓ (success)

**`handle_unban_user_post`, `handle_ban_user_post`, `handle_suspend_user_post`:**
- [ ] All already throw `ForbiddenException` and `TooManyRequestsException` ✓
- [ ] All return `redirect(url('admin_users'))` ✓ (success)

**Verification:** Test all admin user operations

---

#### Task 4.3: `src/actions/admin/plugins.php`
**Functions:** `handle_admin_plugins`
**Current patterns:** Uses `global $config, $pluginManager, $updateManager`, inline error variables

**Changes needed:**
- [ ] Remove `global` declarations (depends on P0-1 completion)
- [ ] Line 11: `$adminPluginError = 'Invalid CSRF token'` → `throw new ForbiddenException('CSRF token invalid')`
- [ ] Line 13: `$adminPluginError = '...'` → `throw new TooManyRequestsException('...')`
- [ ] Line 28: `$adminPluginError = $result['message']` → **Keep as session flash** (plugin install result)
- [ ] Line 38: `$adminPluginError = '...'` → `throw new ForbiddenException('Catalog-only mode: ...')`
- [ ] Line 43: `$adminPluginError = '...'` → `throw new ForbiddenException('Third-party plugins not allowed')`
- [ ] Line 48: `$adminPluginError = 'Invalid catalog item'` → `throw new ValidationException(['catalog' => 'Invalid catalog item'])`
- [ ] Line 54: `$adminPluginError = $result['message']` → **Keep as session flash** (install result)
- [ ] Line 64: `$adminPluginError = $result['message']` → **Keep as session flash** (uninstall result)
- [ ] Line 73: `$adminPluginError = 'Plugin not found or dependencies not met'` → **Keep as session flash** (enable result)
- [ ] Line 79: `$adminPluginError = 'Plugin not found'` → **Keep as session flash** (disable result)
- [ ] Line 98: `return true` ✓ (success)

**Note:** This file uses global variables heavily. Refactoring depends on P0-1 (AuthZ DI) completion.

---

#### Task 4.4: Other Admin Files (Audit Required)
- [ ] `src/actions/admin/themes.php` - Audit and fix
- [ ] `src/actions/admin/catalog.php` - Audit and fix
- [ ] `src/actions/admin/updates.php` - Audit and fix
- [ ] `src/actions/admin/settings.php` - Audit and fix
- [ ] `src/actions/admin/langs.php` - Audit and fix
- [ ] `src/actions/admin/diagnostics.php` - Audit and fix

---

### Phase 5: Verification & Testing

#### Task 5.1: Automated Verification
- [ ] Run `grep -r "return false" src/actions/` - should return no results for error paths
- [ ] Run `grep -r "return redirect" src/actions/` - verify only success paths remain
- [ ] Run `grep -r "Response::json.*error" src/actions/` - verify no error responses
- [ ] Run `grep -r "Response::html.*403\|Response::html.*400" src/actions/` - verify no inline error responses

#### Task 5.2: Integration Tests
- [ ] Test 404 responses (HTML and JSON)
- [ ] Test 403 responses (HTML and JSON)
- [ ] Test 401 responses (HTML and JSON)
- [ ] Test 422 validation responses (HTML and JSON)
- [ ] Test 429 rate limit responses (HTML and JSON)
- [ ] Test 409 conflict responses
- [ ] Test 500 error responses
- [ ] Verify POST-Redirect-GET still works for all success cases
- [ ] Verify form validation UX preserved (session flash messages display)

#### Task 5.3: Run Full Test Suite
- [ ] Run existing PHPUnit/custom tests
- [ ] Verify no regressions
- [ ] Add new tests for error handling if needed

---

## Dependencies

| Task | Depends On |
|------|------------|
| All tasks | P0-1 (AuthZ DI) - for files using `App::getInstance()->authz` |
| Task 4.3 (admin/plugins.php) | P0-1 (global $pluginManager removal) |
| Task 5.x | All refactoring tasks complete |

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Breaking form validation UX | Option 1: Keep session flash for validation errors, only throw exceptions for hard errors |
| Missing error paths | Refactor incrementally, test after each file |
| Router not catching exceptions | Verify middleware returns arrays (not exceptions) - this is correct |
| Plugin compatibility | Plugins use hooks, not direct handler calls - should be unaffected |

---

## Success Criteria (from REMEDIATION_PLAN.md)

- [ ] No handler returns `false` for errors (grep verification)
- [ ] No handler returns redirect for errors (only for success)
- [ ] All error paths throw `HttpException` subclass
- [ ] Integration tests: 404, 403, 400, 429 responses render correctly
- [ ] JSON API routes return JSON errors (router `wantsJson()` logic)

---

## Estimated Effort

| Phase | Files | Estimated Hours |
|-------|-------|-----------------|
| Phase 1 | 4 | 3-4 |
| Phase 2 | 3 | 5-7 |
| Phase 3 | 1 (complex) | 4-6 |
| Phase 4 | 7+ | 6-8 |
| Phase 5 | Verification | 3-4 |
| **Total** | **~15 files** | **21-29 hours** |

---

## Notes for Implementer

1. **Start with Phase 1** - lowest risk, builds confidence
2. **Run tests after each file** - catch regressions early
3. **Preserve session flash for validation** - Option 1 keeps current UX for form errors
4. **Update views if needed** - Some views expect `$error` variable; may need to set from exception in router catch block
5. **API endpoints** - Ensure `wantsJson()` detection works for all API routes
6. **Middleware errors** - Middleware returns arrays with status codes, not exceptions - this is correct and should not change

---

*Document created: 2026-11-07*
*Status: Ready for implementation*