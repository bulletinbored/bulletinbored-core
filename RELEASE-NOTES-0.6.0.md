# BulletinBored 0.6.0 — Release Notes

**Release Date:** 2026-08-30  
**Previous Version:** 0.5.1  
**Status:** Development release (pre-v1.0.0)

---

## Summary

Version 0.6.0 is a major development release that addresses all security P0/P1 issues from the roadmap, introduces a formal HTTP response layer, implements a centralized authorization system, hardens the database and migration layers, and formalizes the Plugin API v1 contract. It is the result of completing Phases 0-7 of the roadmap to v1.0.0.

---

## Security (Phase 1)

### P0 Bug Fixes
- **Fixed `PluginManager::deleteDir()`** — Replaced broken `exec()`/`cmd`-based directory deletion with pure PHP using `RecursiveIteratorIterator`. The previous implementation had inverted Windows/Unix logic that caused `cmd /c rmdir` to execute on Linux/macOS systems.
- Added 5 regression tests for nested directory deletion.

### Installer Hardening
- Added `session_regenerate_id(true)` after collecting DB credentials and admin credentials during installation.
- Removed `admin_pass` from `config.json` — no more plaintext passwords stored in configuration.
- Increased minimum password length from 6 to 12 characters.
- Updated `config-sample.json` to remove `admin_pass`.

### CSRF
- Verified 100% coverage of CSRF validation on all POST mutation handlers.
- CSRF tokens rotate on every successful validation (already present, now verified).

### Session Hardening
- Added `session_regenerate_id(true)` on logout (already present on login).
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` (from config).

### Trusted Proxies
- Added `trusted_proxies` config field supporting CIDR notation (e.g., `10.0.0.0/8`).
- `X-Forwarded-Proto` / `X-Forwarded-For` headers are only trusted when the remote address is in the trusted proxies list.
- Updated `bootstrap.php`, `rate_limit()`, and login handler to respect trusted proxies.
- Added 6 regression tests.

### Rate Limiting
- Login: 5 attempts / 15 minutes (IP + username)
- Register: 5 / hour (IP)
- Password reset: 10 / hour (IP)
- New thread: 20 / hour (user ID)
- Reply: 30 / hour (user ID)

### Password Policy
- Minimum 12 characters enforced on: registration, password reset, profile edit, admin user creation.
- Generic login error message ("Invalid credentials") — never reveals if username exists.

---

## HTTP Layer & Error Handling (Phase 2)

### Response Object
- Created `Bulletin\Response` with factory methods: `html()`, `json()`, `redirect()`, `error()`.
- Handlers now return `Response` objects instead of calling `echo`/`die`/`exit`.
- Router handles `Response` objects returned from handlers.

### Typed Errors
- `HttpException` base class with status code and details.
- `UnauthorizedException` (401), `ForbiddenException` (403), `NotFoundException` (404)
- `ValidationException` (422) with field-level errors
- `ConflictException` (409), `TooManyRequestsException` (429)

### Typed Request
- Added `Request::string()`, `Request::int()`, `Request::bool()`, `Request::email()`, `Request::enum()`.

### die() Elimination
- `redirect()` now returns a `Response` object instead of calling `exit`.
- All 57 callers updated to `return redirect(...)`.
- Auth flows (login, register, logout, profile, password reset) migrated.
- Content flows (new thread, reply) migrated.
- Legacy `die()` calls remain in admin.php — to be migrated when touched (no big-bang approach).

---

## Authorization (Phase 3)

### Permission Registry
- Created `AuthZ` service (`lib/AuthZ.php`) — centralized authorization.
- `can()`, `canOnOwned()`, `getUserRole()`, `getRolePermissions()`.
- Updated `user_has_permission()` to use AuthZ with fallback.

### Router Integration
- Added `can:xxx` middleware via `registerCanMiddleware()`.
- Dynamic permission matching in dispatch loop.
- Registered in `index.php` with `$authz` global.

### Ownership
- `canOnOwned()` — checks if user is resource owner with `_own` permission variant.

### Defense-in-Depth
- Admin routes use `admin` middleware + permission-based routes.
- `is_admin()` checks being replaced by permission-based checks.

---

## Database, Migration & Update Lifecycle (Phase 4)

### DbQuery Immutability
- `first()`, `pluck()`, `paginate()` now use clone-on-write (no mutation of original state).

### Migrations
- `runUp()` uses transactions (MySQL) with rollback on failure.
- Failed migrations are NOT recorded in the migrations table.
- `migrate()` with file-based locking (prevents concurrent runs).
- `rollback()` for last batch.

### Core Update
- `backupCore()` — creates backup before update (keeps last 3).
- `restoreCoreBackup()` — recovery from backup.
- `listBackups()` — manage backups.
- `applyCoreUpdate()` verifies package structure, creates backup, restores on failure.

---

## Plugin API v1 (Phase 5)

### Manifest Validation
- `validateManifest()` — validates id, name, version, core/PHP constraints.
- `normalizeManifest()` — derives `id` from `name` for backward compatibility.
- `satisfiesConstraint()` — version constraint parsing.
- **Backward compatible**: existing plugins with legacy manifests (name only, no id) are fully supported.

### Lifecycle States
- `getPluginState()` returns: enabled, disabled, incompatible, corrupted, failed, not_found.

### Failure Isolation
- `loadEnabled()` wraps each plugin load in try/catch.
- Failed plugins are disabled, logged, and trigger `plugin_load_failed` hook.

---

## Targeted Refactoring (Phase 6)

### helpers.php
- Security functions extracted to `src/Security.php` (CSRF, rate limiting, logging).

### bootstrap.php
- Extracted to `src/TrustedProxies.php` and `src/session_setup.php`.

### admin.php
- Organized with clear section markers for all 11 domains.

---

## Content Hardening & Tests (Phase 7)

### Markdown Security
- Added 21 security tests covering: raw HTML escaping, URL scheme validation, XSS vectors.
- Verified: `javascript:`, `data:`, event handlers, alt text breakout all properly escaped.

### Test Suite
- **290 tests total**, 0 failures.
- Coverage: Auth (48), DbQuery (40), E2E (21), Migrator (27), PluginManager (27), Security (31), Response/Request (26), Markdown (21).

---

## New Files

```
lib/AuthZ.php                    — Authorization service
src/Response.php                 — HTTP Response object
src/Errors.php                   — Typed HTTP exceptions
src/Security.php                 — Security helpers (CSRF, rate limit, logging)
src/TrustedProxies.php           — Trusted proxy detection
src/session_setup.php            — Session configuration
tests/ResponseTest.php           — Response and typed Request tests
tests/MarkdownTest.php           — Markdown security tests
```

## Modified Files

```
lib/PluginManager.php            — deleteDir fix, manifest validation, failure isolation, plugin states
lib/DbQuery.php                  — first/pluck/paginate immutability
lib/Migrator.php                 — transactional runUp, locking, migrate/rollback methods
lib/UpdateManager.php            — backupCore, restoreCoreBackup, listBackups
src/bootstrap.php                — trusted proxies extraction, session setup extraction
src/helpers.php                  — security functions extracted, user_has_permission updated
src/Router.php                   — can: middleware, Response object support
src/Request.php                  — typed getters (string/int/bool/email/enum)
src/actions/users.php            — die() elimination, password policy
src/actions/posts.php            — die() elimination in critical flows
src/actions/admin.php            — section markers, password policy
index.php                        — AuthZ registration, can: middleware
install.php / install2.php       — session_regenerate_id, password policy
src/setup.php                    — admin_pass removal fallback
config-sample.json               — trusted_proxies, admin_pass removed
```

---

## Known Remaining Work (Phase 8)

- Upgrade test from previous fixtures
- Cross-DB test matrix (SQLite + MySQL)
- Final documentation update
- RC checklist and v1.0.0 tag

---

## Upgrade Notes

From 0.5.1: upload all files. The `redirect()` helper now returns a `Response` object — all internal callers have been updated, but custom plugins/themes that call `redirect()` should update to `return redirect(...)`.
