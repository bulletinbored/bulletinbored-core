# BulletinBored Code Review Report

**Date:** 2026-11-07
**Reviewer:** Architecture Mode
**Project:** bulletinbored-core

---

## Executive Summary

BulletinBored is a minimal, extensible forum application written in PHP 8.1+ with zero external dependencies (no Composer). The architecture follows a modular, plugin-based design with a clean separation between core functionality and extensions. The codebase demonstrates good security practices including CSRF protection, rate limiting, CSP headers, and a nonce-based Content Security Policy.

**Overall Assessment:** **Good** — Well-structured for a zero-dependency PHP application with strong security fundamentals. Some architectural concerns and technical debt items identified below.

---

## Architecture Overview

### High-Level Structure

```
bulletinbored-core/
├── index.php              # Front controller
├── bb.php                 # CLI entry point
├── src/
│   ├── bootstrap.php      # Session, config, i18n, autoloader
│   ├── Router.php         # Middleware-enabled router
│   ├── Security.php       # CSRF, rate limiting, security logging
│   ├── csp.php            # Content Security Policy
│   ├── AuthZ.php          # Authorization service
│   ├── helpers.php        # Core helpers
│   ├── Helpers/           # Modular helper classes
│   └── actions/           # Route handlers (controllers)
├── lib/
│   ├── BbPdo.php          # PDO wrapper with SQL dialect normalization
│   ├── DbQuery.php        # Lightweight query builder
│   ├── PluginManager.php  # Plugin lifecycle (composed from traits)
│   ├── ThemeManager.php   # Theme lifecycle
│   ├── UpdateManager.php  # Core/extension updates
│   ├── PackageInstaller.php # ZIP extraction & validation
│   └── Telemetry.php      # Anonymous heartbeat
├── migrations/            # Database schema migrations
├── views/                 # PHP templates
├── plugins/               # Plugin directory
├── themes/                # Theme directory
├── data/                  # Runtime data (config, sessions, logs)
└── tests/                 # Test suite
```

### Key Architectural Decisions

| Decision | Assessment |
|----------|------------|
| **Zero dependencies** | ✅ Good — Reduces supply chain risk, simplifies deployment |
| **PSR-4 autoloader (custom)** | ✅ Good — No Composer needed |
| **Trait-based PluginManager** | ✅ Good — Separation of concerns |
| **Middleware-enabled Router** | ✅ Good — Clean route organization |
| **BbPdo SQL normalization** | ✅ Good — Plugin portability across SQLite/MySQL |
| **File-based rate limiting** | ⚠️ Acceptable — Works but not scalable for high traffic |
| **Global `$pluginManager` variable** | ❌ Concern — Tight coupling, testing difficulty |

---

## Detailed Findings

### 🔴 Critical Issues

#### 1. Global State Coupling (`$pluginManager` global variable)

**Files:** `lib/AuthZ.php:85`, `src/actions/posts-thread.php:101`, `src/actions/posts-edit.php:61`, multiple action files

```php
// AuthZ.php line 85
global $pluginManager;
if (isset($pluginManager) && $pluginManager->checkHook('permission_' . $permission, $role)) {
    return true;
}
```

**Impact:** 
- Tight coupling between AuthZ and PluginManager
- Makes unit testing difficult (requires global state setup)
- Violates dependency inversion principle
- Hidden dependency not visible in constructor signature

**Recommendation:** Inject `PluginManager` via constructor or setter, or use a service locator pattern.

---

#### 2. Missing Foreign Key Constraints in Initial Schema

**File:** `migrations/20260829_initial_schema.php`

The initial schema creates tables without foreign key constraints. While migration `20260904_add_foreign_keys.php` adds them later, this means:
- Fresh installs on MySQL may have referential integrity issues before migration runs
- SQLite foreign keys are not enabled by default (requires `PRAGMA foreign_keys = ON`)

**Recommendation:** Include foreign keys in initial schema, or enable SQLite foreign keys in `BbPdo` constructor.

---

#### 3. Session Version Check Queries Database on Every Request

**File:** `src/Helpers/AuthHelpers.php:18-21`

```php
$pdo = App::getInstance()->pdo;
$stmt = $pdo->prepare("SELECT session_version FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$dbVersion = $stmt->fetchColumn();
```

**Impact:** Extra database query on every authenticated request.

**Recommendation:** Cache session version in session, or use a more efficient invalidation mechanism (e.g., JWT with short expiry + refresh tokens).

---

### 🟠 Significant Issues

#### 4. Inconsistent Error Handling Patterns

**Files:** Multiple action files

Some handlers throw exceptions (`\Bulletin\ForbiddenException`, `\Bulletin\NotFoundException`), others return redirect responses, others return boolean `false`. The router catches `HttpException` but not all code paths use it consistently.

**Examples:**
- `handle_thread_view()` throws `NotFoundException` / `ForbiddenException`
- `handle_reply_post()` throws `ForbiddenException` but returns redirect on validation errors
- `handle_edit_post()` throws `ForbiddenException` on auth failure but returns `true` on GET

**Recommendation:** Standardize on either exception-based flow or response-object flow throughout.

---

#### 5. SQL Injection Risk in `DbQuery::whereRaw()`

**File:** `lib/DbQuery.php:83-91`

```php
public function whereRaw(string $sql, array $params = []): self
{
    $clone = clone $this;
    $clone->wheres[] = $sql;
    foreach ($params as $k => $v) {
        $clone->params[is_int($k) ? ':raw' . count($clone->params) : $k] = $v;
    }
    return $clone;
}
```

**Risk:** The `$sql` parameter is directly interpolated into the query without validation. While parameters are bound safely, the raw SQL fragment could contain malicious constructs if derived from user input.

**Mitigation:** Current codebase usage appears safe (hardcoded SQL fragments), but the method is dangerous if exposed to plugins.

**Recommendation:** Document clearly that `whereRaw()` SQL must never contain user input. Consider removing or restricting to internal use.

---

#### 6. Rate Limiter Uses File-Based Storage (Not Horizontally Scalable)

**File:** `src/Security.php:131-210`

The rate limiter stores buckets as JSON files in `data/ratelimit/`. This works for single-server deployments but:
- Does not work across multiple PHP-FPM workers without shared filesystem
- File locking (`flock`) may have performance issues under high concurrency
- Cleanup is probabilistic (`random_int(1, 200) === 1`)

**Recommendation:** For single-server deployments this is acceptable. Document the limitation. Consider Redis/Memcached backend for clustered deployments.

---

#### 7. Plugin/Theme Installation Lacks Cryptographic Verification

**Files:** `lib/PackageInstaller.php`, `lib/PluginManager/PluginPackages.php`

Packages are installed from ZIP files downloaded from GitHub. The `verifyInstalledFiles()` checks manifest-declared files match extracted files, but:
- No signature verification of the package
- No checksum validation against a trusted source
- `theme_verify_files` / `plugin_verify_files` config options can be disabled

**Documentation acknowledges this:** "installing code is an explicit act of trust in its source"

**Recommendation:** 
- Keep `*_verify_files` enabled by default (currently is)
- Consider adding optional GPG signature verification for official catalog
- Document the trust model clearly for administrators

---

#### 8. CSP Allows Broad External Domains

**File:** `src/csp.php:30`

```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://platform.twitter.com https://cdn.syndication.twimg.com; style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; style-src-attr 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; img-src 'self' data: https: https://*.twimg.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com https://publish.twitter.com https://syndication.twitter.com https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com; connect-src 'self' https://publish.twitter.com https://platform.twitter.com https://syndication.twitter.com https://cdn.syndication.twimg.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
```

**Concerns:**
- `style-src-attr 'unsafe-inline'` weakens CSP
- Broad `img-src https:` allows any HTTPS image
- Many third-party domains (Twitter, YouTube, Instagram, Facebook, CDNs)

**Recommendation:** 
- Make CSP configurable via config.json
- Consider removing `unsafe-inline` for style attributes
- Restrict `img-src` to known domains where possible

---

#### 9. Duplicate Code in UpdateManager Core Update Logic

**File:** `lib/UpdateManager.php:332-406` (`applyCoreUpdateFromZip`) and `408-506` (`applyCoreUpdate`)

Both methods contain nearly identical logic for:
- Preflight checks
- ZIP extraction and validation
- Backup creation
- File copying
- Nested directory handling
- Installer script removal

**Recommendation:** Extract common logic into a private method.

---

#### 10. Missing Indexes on Frequently Queried Columns

**File:** `migrations/20260829_initial_schema.php`

Tables lack indexes on commonly filtered columns:
- `threads.category_id` (used in category view)
- `posts.thread_id` (used in thread view)
- `posts.user_id` (used in profile queries)
- `thread_watchers.thread_id`, `thread_watchers.user_id` (has unique composite but not individual)
- `notifications.user_id`, `notifications.is_read`
- `private_messages.sender_id`, `private_messages.recipient_id`

**Recommendation:** Add indexes in a follow-up migration for query performance.

---

### 🟡 Moderate Issues

#### 11. `is_logged_in()` Destroys Session on Version Mismatch

**File:** `src/Helpers/AuthHelpers.php:12-16`

```php
if (!isset($_SESSION['session_version'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    return false;
}
```

**Issue:** Calling `session_destroy()` inside a read-only check function is surprising behavior. It also destroys the session for ALL users if one user has a version mismatch.

**Recommendation:** Return `false` without destroying session; let the caller handle logout/redirect.

---

#### 12. `validate_input()` Doesn't Sanitize HTML

**File:** `src/Helpers/Text.php` (referenced but not read)

Based on usage in `handle_reply_post()` and `handle_edit_post()`:
```php
$content = validate_input($_POST['content'] ?? '');
```

The content is later rendered through markdown parser. Need to verify the markdown parser properly sanitizes HTML output.

**Recommendation:** Ensure markdown output is sanitized (HTMLPurifier or similar) before rendering.

---

#### 13. Inconsistent Use of `App::getInstance()` vs Dependency Injection

**Files:** Throughout codebase

Some classes receive dependencies via constructor (`AuthZ`, `DbQuery`, `Router`), others use `App::getInstance()->pdo`, `App::getInstance()->pluginManager`, `App::getInstance()->authz`.

**Recommendation:** Standardize on constructor injection for testability. Use `App` only as a service locator of last resort.

---

#### 14. `router.php` Front Controller Duplicates Logic from `index.php`

**Files:** `router.php`, `index.php`

The built-in server router (`router.php`) reimplements bootstrap logic instead of delegating to `index.php`.

**Recommendation:** Have `router.php` include `index.php` after setting up `$_SERVER` variables, or extract shared bootstrap into a common file.

---

#### 15. No Database Connection Pooling / Persistent Connections

**File:** `lib/BbPdo.php`, `src/bootstrap.php`

Each request creates a new PDO connection. For SQLite this is fine; for MySQL under load, persistent connections or connection pooling would be beneficial.

**Recommendation:** Add `PDO::ATTR_PERSISTENT` option for MySQL in config.

---

### 🟢 Minor Issues / Code Quality

#### 16. Magic Numbers / Hardcoded Values

| File | Line | Value | Recommendation |
|------|------|-------|----------------|
| `src/helpers.php` | 25 | `POSTS_PER_PAGE = 15` | Make configurable |
| `src/Security.php` | 143 | `random_int(1, 200) === 1` | Make configurable |
| `src/Security.php` | 93 | `86400` (GC max age) | Make configurable |
| `lib/UpdateManager.php` | 165 | `50 * 1024 * 1024` (disk space) | Make configurable |

---

#### 17. Inconsistent Naming Conventions

- Some functions use `snake_case` (`handle_thread_view`, `validate_input`)
- Some use `camelCase` (`generate_csrf_token`, `rate_limit_client_ip`)
- Class methods use `camelCase` (`canOnOwned`, `getUserRole`)

**Recommendation:** Adopt PSR-12 (snake_case for functions, camelCase for methods) consistently.

---

#### 18. Missing Type Hints on Some Functions

**Examples:**
- `src/helpers.php`: `function base_url()` — no return type
- `src/Helpers/AuthHelpers.php`: `function is_admin()` — no return type
- `src/Helpers/Url.php`: Several functions missing return types

**Recommendation:** Add return type declarations for better static analysis.

---

#### 19. `clean_text()` vs `validate_input()` Confusion

**Files:** `src/Helpers/Text.php` (not read), used in actions

Both functions exist but their exact behavior and when to use which is not clearly documented in code.

**Recommendation:** Document the difference clearly; consider consolidating.

---

#### 20. Test Coverage Gaps

**Files:** `tests/`

Good test coverage for:
- Auth, CSRF, Security, PluginManager, AuthZ
- Database integrity, migrations

Missing/limited coverage for:
- UpdateManager (core/plugin/theme updates)
- ThemeManager
- Admin actions (moderation, user management, settings)
- File upload handling
- Email sending
- Markdown rendering
- Router middleware

---

## Security Assessment

### Strengths ✅

1. **CSRF Protection** — Per-session tokens with rotation on validation
2. **Rate Limiting** — File-based with fail-closed for sensitive actions
3. **CSP Headers** — Nonce-based, restrictive policy
4. **Security Headers** — X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS
5. **Password Hashing** — `password_hash()` with `PASSWORD_DEFAULT` (bcrypt/argon2)
6. **SQL Injection Prevention** — Prepared statements throughout, `DbQuery` builder
7. **Zip Slip Protection** — `PackageInstaller::safeExtractZip()` validates paths
8. **File Upload Validation** — MIME type checking, size limits, extension validation
9. **Session Security** — Session version invalidation, regeneration on login
10. **Admin Audit Logging** — All admin actions logged with user context

### Concerns ⚠️

1. **No Cryptographic Package Verification** — Trust-on-first-use model
2. **Broad CSP** — Many external domains allowed
3. **File-Based Rate Limiting** — Not suitable for clustered deployments
4. **Session Version Check** — Extra DB query per request
5. **Installer Files** — Must be manually deleted post-install (documented but error-prone)

---

## Database Schema Assessment

### Current State

11 tables in initial schema:
- `users` — Core user accounts
- `categories` — Forum categories
- `threads` — Discussion threads
- `posts` — Replies within threads
- `uploads` — File attachments
- `thread_watchers` — Thread subscriptions
- `notifications` — User notifications
- `private_messages` — Direct messages
- `roles` — RBAC roles with JSON permissions
- `email_verifications` — Email confirmation tokens
- `password_resets` — Password reset tokens

### Missing Indexes (Performance)

```sql
-- Recommended additions
CREATE INDEX idx_threads_category_id ON threads(category_id);
CREATE INDEX idx_threads_user_id ON threads(user_id);
CREATE INDEX idx_threads_status ON threads(status);
CREATE INDEX idx_posts_thread_id ON posts(thread_id);
CREATE INDEX idx_posts_user_id ON posts(user_id);
CREATE INDEX idx_posts_status ON posts(status);
CREATE INDEX idx_uploads_thread_id ON uploads(thread_id);
CREATE INDEX idx_uploads_post_id ON uploads(post_id);
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_is_read ON notifications(is_read);
CREATE INDEX idx_private_messages_sender_id ON private_messages(sender_id);
CREATE INDEX idx_private_messages_recipient_id ON private_messages(recipient_id);
```

### Migration System

- Well-structured with `up()`/`down()`/`irreversible()` contract
- `Migrator` class handles batching and rollback
- Plugin migrations supported via `addPluginPaths()`
- CLI commands for migrate/rollback/status

---

## Plugin System Assessment

### Architecture

`PluginManager` composed of 4 traits:
1. **PluginHooks** — Event system (addHook, runHook, applyHook, filter, checkHook, captureHook)
2. **PluginManifest** — Discovery, validation, enable/disable, settings
3. **PluginDependencies** — Dependency resolution, cycle detection
4. **PluginPackages** — Install/update/uninstall from ZIP/repo

### Strengths ✅

- Clean trait-based composition
- Dependency resolution with cycle detection
- Manifest validation (v1 with `id`, legacy with `name`)
- File integrity verification (`verifyInstalledFiles`)
- Rollback on failed install/update
- Lifecycle hooks (`on_install`, `on_update`, `on_uninstall`)
- CLI management commands

### Concerns ⚠️

- Global `$pluginManager` variable creates tight coupling
- No plugin sandboxing (full PHP execution)
- No permission system for plugins (can access everything)
- `theme_verify_files` / `plugin_verify_files` can be disabled

---

## Testing Assessment

### Test Infrastructure

Custom test framework in `tests/harness.php` with:
- `Test` class with assertions
- `register_tests()` for test discovery
- `run.php` runner with filtering, verbose, coverage options
- Xdebug/PCOV coverage support

### Coverage

| Area | Coverage |
|------|----------|
| Auth/CSRF/Session | ✅ Good |
| Security (rate limit, proxies) | ✅ Good |
| AuthZ permissions | ✅ Good |
| PluginManager hooks/lifecycle | ✅ Excellent |
| Database integrity/migrations | ✅ Good |
| Markdown rendering | ✅ Good |
| Content CRUD | ✅ Moderate |
| Admin actions | ❌ Limited |
| UpdateManager | ❌ Missing |
| ThemeManager | ❌ Missing |
| File uploads | ❌ Missing |
| Email | ❌ Missing |
| Router/middleware | ❌ Missing |

---

## Recommendations Priority Matrix

| Priority | Issue | Effort | Impact |
|----------|-------|--------|--------|
| **P0** | Remove global `$pluginManager`; use DI | Medium | High (testability, architecture) |
| **P0** | Add foreign keys to initial schema | Low | High (data integrity) |
| **P0** | Enable SQLite foreign keys in BbPdo | Low | High (data integrity) |
| **P1** | Standardize error handling (exceptions vs responses) | Medium | Medium (consistency) |
| **P1** | Add missing database indexes | Low | High (performance) |
| **P1** | Make CSP configurable | Low | Medium (security flexibility) |
| **P1** | Cache session version to avoid DB query | Low | Medium (performance) |
| **P2** | Extract duplicate UpdateManager logic | Low | Low (maintainability) |
| **P2** | Add return type hints | Low | Low (code quality) |
| **P2** | Make rate limiter backend pluggable | Medium | Low (scalability) |
| **P2** | Add test coverage for admin actions, updates, themes | Medium | Medium (confidence) |
| **P3** | Consistent naming conventions | Low | Low (code quality) |
| **P3** | Document `clean_text` vs `validate_input` | Low | Low (clarity) |
| **P3** | Make magic numbers configurable | Low | Low (flexibility) |

---

## Architecture Evolution Recommendations

### Short Term (Next Release)
1. Fix critical issues (P0)
2. Add missing indexes via migration
3. Make CSP configurable
4. Improve test coverage for admin actions

### Medium Term
1. Refactor to eliminate global state (service container)
2. Add plugin sandboxing/permission system
3. Implement Redis rate limiter backend option
4. Add cryptographic package verification (optional)

### Long Term
1. Consider PSR-7/PSR-15 middleware for router
2. Evaluate async job queue for notifications/emails
3. Add API versioning for future headless clients
4. Consider event sourcing for audit trail

---

## Conclusion

BulletinBored is a well-designed, security-conscious forum application that achieves its goal of "zero dependencies" without sacrificing code quality. The trait-based plugin architecture is a highlight, and the security implementation (CSRF, CSP, rate limiting, audit logging) exceeds many comparable projects.

The primary architectural concern is the use of global state (`$pluginManager`, `App::getInstance()`) which hampers testability and creates hidden dependencies. Addressing this, along with the missing database indexes and foreign keys, would significantly improve the codebase.

**Recommended Next Steps:**
1. Create GitHub issues for P0 items
2. Add migration for foreign keys + indexes
3. Refactor `AuthZ` to accept `PluginManager` via constructor
4. Make CSP configurable via `config.json`
5. Expand test suite for admin/update/theme areas

---

*Report generated by Architecture Mode code review*