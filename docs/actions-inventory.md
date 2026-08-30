# Action Files Inventory — BulletinBored

**Generated:** 2026-08-30  
**Version:** 0.5.1  

---

## Overview

| File | Lines | Functions | die()/exit() | Session | DB | Filesystem | Plugin | Mail | Renderer |
|------|-------|-----------|-------------|---------|-----|------------|--------|------|----------|
| `src/actions/posts.php` | 624 | 11 | 27 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `src/actions/users.php` | 514 | 10 | 21 | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| `src/actions/content.php` | 104 | 4 | 4 | ✗ | ✓ | ✓ | ✗ | ✗ | ✗ |
| `src/actions/misc.php` | 50 | 3 | 0 | ✗ | ✓ | ✗ | ✗ | ✗ | ✓ |
| `src/actions/admin.php` | 1552 | 31 | 38 | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| **Total** | **2844** | **59** | **90** | | | | | | |

---

## 1. src/actions/posts.php

### Functions

| Function | Line | Signature |
|----------|------|-----------|
| `handle_posts_action` | 3 | `(string $action, string $method): bool` |
| `handle_upload_image` | 36 | `(): bool` |
| `handle_thread_view` | 95 | `(): bool` |
| `handle_new_thread` | 189 | `(string $method): bool` |
| `handle_reply_post` | 272 | `(): bool` |
| `handle_edit_post` | 342 | `(string $method): bool` |
| `handle_edit_thread` | 407 | `(string $method): bool` |
| `handle_delete_post` | 477 | `(): bool` |
| `handle_delete_thread` | 536 | `(): bool` |
| `handle_watch` | 584 | `(): bool` |
| `handle_unwatch` | 606 | `(): bool` |

### die()/exit()

| Line | Condition | Output |
|------|-----------|--------|
| 127 | Thread not found | `Thread not found` |
| 193 | Not authenticated | `Login required` |
| 198 | CSRF fail | `CSRF token invalid` |
| 203 | Rate limit | `You are posting too fast...` |
| 215-221 | Unauthorized category | `t('not_authorized_category')` |
| 246 | Plugin blocks creation | `t('thread_creation_blocked')` |
| 277 | Not authenticated | `Login required` |
| 281 | CSRF fail | `CSRF token invalid` |
| 286 | Rate limit | `You are posting too fast...` |
| 304 | Thread not found | `Thread not found` |
| 308 | Thread locked | `Thread is locked` |
| 321 | Plugin blocks creation | `t('post_creation_blocked')` |
| 347 | Not authenticated | `Login required` |
| 364 | Not authorized | `Not authorized` |
| 371 | CSRF fail | `CSRF token invalid` |
| 412 | Not authenticated | `Login required` |
| 429 | Not authorized | `Not authorized` |
| 434 | CSRF fail | `CSRF token invalid` |
| 482 | Not authenticated | `Login required` |
| 486 | CSRF fail | `CSRF token invalid` |
| 503 | Not authorized | `Not authorized` |
| 509 | Plugin blocks deletion | `t('post_deletion_blocked')` |
| 541 | Not authenticated | `Login required` |
| 545 | CSRF fail | `CSRF token invalid` |
| 562 | Not authorized | `Not authorized` |
| 566 | Plugin blocks deletion | `t('thread_deletion_blocked')` |

---

## 2. src/actions/users.php

### Functions

| Function | Line | Signature |
|----------|------|-----------|
| `handle_users_action` | 3 | `(string $action, string $method): bool` |
| `handle_login` | 29 | `(string $method): bool` |
| `handle_register` | 107 | `(string $method): bool` |
| `handle_logout` | 167 | `(): bool` |
| `handle_verify_email` | 174 | `(): bool` |
| `handle_profile` | 212 | `(): bool` |
| `handle_edit_profile` | 251 | `(string $method): bool` |
| `handle_remove_avatar` | 367 | `(string $method): bool` |
| `handle_forgot_password` | 398 | `(string $method): bool` |
| `handle_reset_password` | 442 | `(string $method): bool` |

### die()/exit()

| Line | Condition | Output |
|------|-----------|--------|
| 38 | CSRF fail | `CSRF token invalid` |
| 44 | Rate limit | `Too many login attempts...` |
| 112 | CSRF fail | `CSRF token invalid` |
| 117 | Rate limit | `Too many registration attempts...` |
| 124 | Empty input | `Username and password are required` |
| 131 | Duplicate username | `Username already taken` |
| 184 | Empty token | exit |
| 200 | Invalid token | exit |
| 208 | Verification OK | exit |
| 223 | User not found | `User not found` |
| 257 | Not authenticated | `Login required` |
| 262 | CSRF fail | `CSRF token invalid` |
| 332 | Duplicate username | `Username already taken` |
| 372 | Not authenticated | `Login required` |
| 377 | CSRF fail | `CSRF token invalid` |
| 405 | CSRF fail | `CSRF token invalid` |
| 410 | Rate limit | `Too many requests...` |
| 449 | CSRF fail | `CSRF token invalid` |
| 454 | Rate limit | `Too many attempts...` |
| 464 | Passwords don't match | exit |
| 469 | Password too short | exit |
| 485 | Expired/invalid token | exit |
| 509 | Missing token in GET | `Page not found` |

---

## 3. src/actions/content.php

### Functions

| Function | Line | Signature |
|----------|------|-----------|
| `handle_content_action` | 3 | `(string $action, string $method): bool` |
| `handle_search` | 17 | `(): bool` |
| `handle_category` | 38 | `(): bool` |
| `handle_download` | 67 | `(): bool` |

### die()/exit()

| Line | Condition | Output |
|------|-----------|--------|
| 49 | Category not found | `Category not found` |
| 79 | Upload not found | `File not found` |
| 85 | File missing on disk | `File not found` |
| 102 | After readfile | exit |

---

## 4. src/actions/misc.php

### Functions

| Function | Line | Signature |
|----------|------|-----------|
| `handle_misc_action` | 3 | `(string $action, string $method): bool` |
| `handle_markdown_preview` | 20 | `(): bool` |
| `handle_mention_users` | 37 | `(): bool` |

### die()/exit()

None.

---

## 5. src/actions/admin.php

### Functions

| Function | Line | Signature |
|----------|------|-----------|
| `handle_admin_action` | 3 | `(string $action, string $method): bool` |
| `handle_admin_dashboard` | 86 | `(): bool` |
| `handle_admin_settings_post` | 130 | `(): ?string` |
| `handle_admin_settings_get` | 204 | `(): bool` |
| `handle_admin_upload_site_image` | 211 | `(): bool` |
| `handle_admin_get_images` | 265 | `(): bool` |
| `handle_admin_smtp_get` | 281 | `(): bool` |
| `handle_admin_smtp_post` | 287 | `(): ?string` |
| `handle_admin_moderation_get` | 334 | `(): bool` |
| `handle_moderate_post` | 340 | `(): bool` |
| `handle_frontend_moderate_post` | 374 | `(): bool` |
| `handle_split_thread_post` | 449 | `(): bool` |
| `handle_merge_thread_post` | 516 | `(): bool` |
| `handle_admin_roles_get` | 548 | `(): bool` |
| `handle_admin_roles_action_post` | 554 | `(): bool` |
| `handle_admin_users_get` | 589 | `(): bool` |
| `handle_admin_user_edit` | 595 | `(string $method): bool` |
| `handle_admin_create_user_post` | 631 | `(): bool` |
| `handle_admin_categories` | 689 | `(string $method): bool` |
| `handle_delete_category_post` | 724 | `(): bool` |
| `handle_update_category_order_post` | 740 | `(): bool` |
| `handle_admin_langs` | 770 | `(string $method): bool` |
| `handle_admin_diagnostics_get` | 984 | `(): bool` |
| `handle_admin_plugins` | 1057 | `(string $method): bool` |
| `handle_admin_themes` | 1158 | `(string $method): bool` |
| `handle_admin_catalog` | 1243 | `(string $method): bool` |
| `handle_admin_updates` | 1378 | `(string $method): bool` |
| `handle_delete_user_post` | 1481 | `(): bool` |
| `handle_unban_user_post` | 1504 | `(): bool` |
| `handle_ban_user_post` | 1520 | `(): bool` |
| `handle_suspend_user_post` | 1536 | `(): bool` |

### die()/exit()

| Line | Condition | Output |
|------|-----------|--------|
| 21 | Not admin | `Admin required` |
| 138 | Redirect after settings POST | exit |
| 218 | Not admin (upload) | JSON `{"ok":false,"error":"Admin required"}` |
| 223 | CSRF fail (upload) | JSON `{"ok":false,"error":"CSRF token invalid"}` |
| 228 | No file uploaded | JSON `{"ok":false,"error":"No file uploaded"}` |
| 245 | Invalid image | JSON `{"ok":false,"error":"Invalid image..."}` |
| 256 | Failed to save file | JSON `{"ok":false,"error":"Failed to save file"}` |
| 262 | Upload OK | JSON `{"ok":true,"url":...}` |
| 272 | Not admin (get images) | JSON `{"ok":false,"error":"Admin required"}` |
| 278 | Image list OK | JSON `{"ok":true,"images":[...]}` |
| 345 | CSRF fail (moderate) | `CSRF token invalid` |
| 352 | Invalid thread ID | `Invalid thread ID` |
| 379 | CSRF fail (frontend mod) | `CSRF token invalid` |
| 384 | Invalid thread ID | `Invalid thread ID` |
| 388 | Not authorized | `Not authorized` |
| 454 | CSRF fail (split) | `CSRF token invalid` |
| 463 | Invalid input | `Invalid input` |
| 467 | Not authorized | `Not authorized` |
| 473 | Thread not found | `Thread not found` |
| 484 | No valid posts selected | `No valid posts selected` |
| 521 | CSRF fail (merge) | `CSRF token invalid` |
| 526 | Invalid input | `Invalid input` |
| 530 | Not authorized | `Not authorized` |
| 536 | Target not found | `Target thread not found` |
| 540 | Self-merge attempt | `Cannot merge a thread into itself` |
| 559 | CSRF fail (roles) | `CSRF token invalid` |
| 600 | Not admin (user edit) | `Admin required` |
| 614 | CSRF fail (user edit) | `CSRF token invalid` |
| 636 | CSRF fail (create user) | `CSRF token invalid` |
| 647 | Empty input | `Username and password are required` |
| 654 | Duplicate username | `Username already taken` |
| 694 | Not admin (categories) | `Admin required` |
| 698 | CSRF fail (categories) | `CSRF token invalid` |
| 729 | CSRF fail (delete cat) | `CSRF token invalid` |
| 747 | CSRF fail (order) | JSON `{"success":false,"message":"CSRF token invalid"}` |
| 754 | Invalid order data | JSON `{"success":false,"message":"Invalid order data"}` |
| 766 | Reorder OK | JSON `{"success":true}` |
| 775 | Not admin (langs) | `Admin required` |
| 1248 | Not admin (catalog) | `Admin required` |
| 1486 | CSRF fail (delete user) | `CSRF token invalid` |
| 1509 | CSRF fail (unban) | `CSRF token invalid` |
| 1525 | CSRF fail (ban) | `CSRF token invalid` |
| 1541 | CSRF fail (suspend) | `CSRF token invalid` |

---

## Identified Patterns

1. **No Response objects** — All handlers use `echo`/`print`/`include` + `die()`/`exit()`.
2. **90 die()/exit()** in action files — for auth, CSRF, rate limit, validation, missing resources.
3. **Heavy global state** — All files use `global $pdo, $config`, most use `global $pluginManager`.
4. **Mixed return types** — `bool` for routing, `?string` for some admin handlers.
5. **CSRF inline** — `csrf_validate_request()` called directly in handlers, not via middleware.
