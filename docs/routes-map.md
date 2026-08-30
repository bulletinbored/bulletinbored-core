# Route Map — BulletinBored

**Generated:** 2026-08-30  
**Version:** 0.5.1  
**Total routes:** 88

---

## Middleware Reference

| Middleware | Behavior |
|------------|----------|
| `guest` | Redirects to `/home` if already logged in |
| `auth` | Redirects to `/login` if not authenticated |
| `admin` | 403 if logged in but not admin; redirects to `/login` if anonymous |
| `csrf` | Validates CSRF token on POST (defined but not used in route groups) |

**Note:** CSRF is validated inline in handlers (`csrf_validate_request()`), not via group middleware.

---

## 1. Frontend (public)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 1 | GET | `/` | closure (include views/home.php) | `index.php:66-78` |
| 2 | GET | `/thread/{id:\d+}` | `handle_thread_view()` | `index.php:80` |
| 3 | GET | `/thread/{id:\d+}-{slug}` | `handle_thread_view()` | `index.php:81` |
| 4 | GET | `/category/{id:\d+}` | `handle_category()` | `index.php:82` |
| 5 | GET | `/category/{id:\d+}-{slug}` | `handle_category()` | `index.php:83` |
| 6 | GET | `/u/{user}` | `handle_profile()` | `index.php:84` |
| 7 | GET | `/search` | `handle_search()` | `index.php:85` |
| 8 | GET | `/download/{id:\d+}` | `handle_download()` | `index.php:86` |

---

## 2. Auth (middleware: guest)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 9 | GET | `/login` | `handle_login('GET')` | `index.php:90` |
| 10 | POST | `/login` | `handle_login('POST')` | `index.php:91` |
| 11 | GET | `/register` | `handle_register('GET')` | `index.php:92` |
| 12 | POST | `/register` | `handle_register('POST')` | `index.php:93` |
| 13 | GET | `/forgot-password` | `handle_forgot_password('GET')` | `index.php:94` |
| 14 | POST | `/forgot-password` | `handle_forgot_password('POST')` | `index.php:95` |
| 15 | GET | `/reset-password` | `handle_reset_password('GET')` | `index.php:96` |
| 16 | POST | `/reset-password` | `handle_reset_password('POST')` | `index.php:97` |

---

## 3. Email Verification (no middleware)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 17 | GET | `/verify-email` | `handle_verify_email()` | `index.php:101` |

---

## 4. Authenticated User (middleware: auth)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 18 | GET | `/new-thread` | `handle_new_thread('GET')` | `index.php:105` |
| 19 | POST | `/new-thread` | `handle_new_thread('POST')` | `index.php:106` |
| 20 | POST | `/reply` | `handle_reply_post()` | `index.php:107` |
| 21 | GET | `/edit-post/{id:\d+}` | `handle_edit_post('GET')` | `index.php:108` |
| 22 | POST | `/edit-post/{id:\d+}` | `handle_edit_post('POST')` | `index.php:109` |
| 23 | POST | `/delete-post/{id:\d+}` | `handle_delete_post()` | `index.php:110` |
| 24 | GET | `/edit-thread/{id:\d+}` | `handle_edit_thread('GET')` | `index.php:111` |
| 25 | POST | `/edit-thread/{id:\d+}` | `handle_edit_thread('POST')` | `index.php:112` |
| 26 | POST | `/delete-thread/{id:\d+}` | `handle_delete_thread()` | `index.php:113` |
| 27 | GET | `/watch` | `handle_watch()` | `index.php:114` |
| 28 | GET | `/unwatch` | `handle_unwatch()` | `index.php:115` |
| 29 | POST | `/upload-image` | `handle_upload_image()` | `index.php:116` |
| 30 | GET | `/logout` | `handle_logout()` | `index.php:117` |
| 31 | GET | `/edit-profile` | `handle_edit_profile('GET')` | `index.php:118` |
| 32 | POST | `/edit-profile` | `handle_edit_profile('POST')` | `index.php:119` |
| 33 | POST | `/remove-avatar` | `handle_remove_avatar('POST')` | `index.php:120` |
| 34 | POST | `/preview` | `handle_markdown_preview()` | `index.php:121` |
| 35 | GET | `/mention-users` | `handle_mention_users()` | `index.php:122` |

---

## 5. Admin (middleware: admin)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 36 | GET | `/admin` | `handle_admin_dashboard()` | `index.php:127` |
| 37 | GET | `/admin/settings` | `handle_admin_settings_get()` | `index.php:128` |
| 38 | POST | `/admin/settings` | `handle_admin_settings_post()` | `index.php:129` |
| 39 | GET | `/admin/smtp` | `handle_admin_smtp_get()` | `index.php:130` |
| 40 | POST | `/admin/smtp` | `handle_admin_smtp_post()` | `index.php:131` |
| 41 | POST | `/admin/upload-site-image` | `handle_admin_upload_site_image()` | `index.php:132` |
| 42 | GET | `/admin/get-images` | `handle_admin_get_images()` | `index.php:133` |
| 43 | GET | `/admin/moderation` | `handle_admin_moderation_get()` | `index.php:134` |
| 44 | POST | `/admin/moderate` | `handle_moderate_post()` | `index.php:135` |
| 45 | POST | `/admin/front-moderate` | `handle_frontend_moderate_post()` | `index.php:136` |
| 46 | POST | `/admin/split-thread` | `handle_split_thread_post()` | `index.php:137` |
| 47 | POST | `/admin/merge-thread` | `handle_merge_thread_post()` | `index.php:138` |
| 48 | GET | `/admin/roles` | `handle_admin_roles_get()` | `index.php:139` |
| 49 | POST | `/admin/roles-action` | `handle_admin_roles_action_post()` | `index.php:140` |
| 50 | GET | `/admin/users` | `handle_admin_users_get()` | `index.php:141` |
| 51 | GET | `/admin/users/{id:\d+}/edit` | `handle_admin_user_edit('GET')` | `index.php:142` |
| 52 | POST | `/admin/users/{id:\d+}/edit` | `handle_admin_user_edit('POST')` | `index.php:143` |
| 53 | POST | `/admin/create-user` | `handle_admin_create_user_post()` | `index.php:144` |
| 54 | GET | `/admin/categories` | `handle_admin_categories('GET')` | `index.php:145` |
| 55 | POST | `/admin/categories` | `handle_admin_categories('POST')` | `index.php:146` |
| 56 | POST | `/admin/delete-category` | `handle_delete_category_post()` | `index.php:147` |
| 57 | POST | `/admin/update-category-order` | `handle_update_category_order_post()` | `index.php:148` |
| 58 | GET | `/admin/langs` | `handle_admin_langs('GET')` | `index.php:149` |
| 59 | POST | `/admin/langs` | `handle_admin_langs('POST')` | `index.php:150` |
| 60 | GET | `/admin/diagnostics` | `handle_admin_diagnostics_get()` | `index.php:151` |
| 61 | GET | `/admin/plugins` | `handle_admin_plugins('GET')` | `index.php:152` |
| 62 | POST | `/admin/plugins` | `handle_admin_plugins('POST')` | `index.php:153` |
| 63 | GET | `/admin/themes` | `handle_admin_themes('GET')` | `index.php:154` |
| 64 | POST | `/admin/themes` | `handle_admin_themes('POST')` | `index.php:155` |
| 65 | GET | `/admin/catalog` | `handle_admin_catalog('GET')` | `index.php:156` |
| 66 | POST | `/admin/catalog` | `handle_admin_catalog('POST')` | `index.php:157` |
| 67 | GET | `/admin/updates` | `handle_admin_updates('GET')` | `index.php:158` |
| 68 | POST | `/admin/updates` | `handle_admin_updates('POST')` | `index.php:159` |
| 69 | POST | `/admin/delete-user` | `handle_delete_user_post()` | `index.php:160` |
| 70 | POST | `/admin/ban-user` | `handle_ban_user_post()` | `index.php:161` |
| 71 | POST | `/admin/unban-user` | `handle_unban_user_post()` | `index.php:162` |
| 72 | POST | `/admin/suspend-user` | `handle_suspend_user_post()` | `index.php:163` |

---

## 6. Plugin via Router

### bellbored (Notifications)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 73 | GET | `/notifications` | `bellbored_handle_page('GET')` | `plugins/bellbored/bellbored.php:119-121` |
| 74 | POST | `/notifications` | `bellbored_handle_page('POST')` | `plugins/bellbored/bellbored.php:122-124` |

### textmebored (Private Messages)

| # | Method | Path | Handler | File |
|---|--------|------|---------|------|
| 75 | GET | `/messages` | `textmebored_handle_page('GET')` | `plugins/textmebored/textmebored.php:115-117` |
| 76 | POST | `/messages` | `textmebored_handle_page('POST')` | `plugins/textmebored/textmebored.php:118-120` |

---

## 7. Plugin Standalone (direct access, bypass router)

| # | Method | Path | File | Notes |
|---|--------|------|------|-------|
| 77 | GET/POST | `/plugins/bellbored/api.php` | `plugins/bellbored/api.php` | Notification count/list/mark read |
| 78 | GET/POST | `/plugins/textmebored/api.php` | `plugins/textmebored/api.php` | Conversations, messages, send |
| 79 | GET/POST | `/plugins/updownbored/api.php` | `plugins/updownbored/api.php` | Post scores, votes |
| 80 | POST | `/plugins/editbored/upload.php` | `plugins/editbored/upload.php` | Editor image upload |
| 81 | GET | `/plugins/sitemapbored/sitemap.php` | `plugins/sitemapbored/sitemap.php` | Public XML sitemap |

---

## 8. Installer (direct access)

| # | Method | Path | File |
|---|--------|------|------|
| 82 | GET/POST | `/install.php` | `install.php` |
| 83 | GET/POST | `/install2.php` | `install2.php` |
| 84 | GET/POST | `/install3.php` | `install3.php` |

---

## 9. API Endpoints (direct access)

| # | Method | Path | File | Notes |
|---|--------|------|------|-------|
| 85 | POST | `/api/install.php` | `api/install.php` | Install plugin/theme from catalog |

---

## Summary by Area

| Area | Count |
|------|-------|
| Public frontend | 8 |
| Auth | 8 |
| Email verification | 1 |
| Authenticated user | 18 |
| Admin | 37 |
| Plugin via router | 4 |
| Plugin standalone | 5 |
| Installer | 3 |
| API | 1 |
| **TOTAL** | **88** |

---

## Observations

1. **No API prefix**: The router has an `api()` method but it is never used. API endpoints are standalone files.
2. **CSRF inline**: The `csrf` middleware exists but no route group uses it. Validation is done in handlers.
3. **Plugins before core**: `$pluginManager->applyRoutes()` runs before core route registration → plugins take precedence.
4. **POST only for mutations**: PUT/DELETE/PATCH are supported by the router but unused. All mutations use POST.
5. **No Response objects**: All handlers use `echo`/`print`/`include` + `die()`/`exit()`.
