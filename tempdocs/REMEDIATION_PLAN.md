# BulletinBored Remediation Plan

**Based on:** CODE_REVIEW_REPORT.md (2026-11-07)
**Status:** Draft for review

---

## Overview

This plan addresses the 20 issues identified in the code review, organized by priority (P0–P3). Each remediation includes:
- **Implementation approach** — specific code changes
- **Files affected** — precise locations
- **Dependencies** — ordering constraints
- **Verification criteria** — how to confirm the fix works
- **Risk assessment** — potential for regressions

---

## P0 — Critical Fixes (Do First)

### P0-1: Eliminate Global `$pluginManager` Variable

**Problem:** `AuthZ.php` and action handlers access `$pluginManager` via `global` keyword, creating hidden dependencies and testing difficulties.

**Implementation Approach:**
1. Add `PluginManager` as optional constructor parameter to `AuthZ`
2. Update `AuthZ::can()` to use injected instance instead of `global`
3. Update `index.php` to pass `$pluginManager` when constructing `AuthZ`
4. Update any other files using `global $pluginManager` to receive it via DI

**Files Affected:**
- `lib/AuthZ.php` — constructor, `can()` method
- `index.php` — `AuthZ` instantiation (line 98-99)
- `src/actions/posts-thread.php` — line 101
- `src/actions/posts-edit.php` — lines 61, 78, 141, 154, 213, 219, 273, 286, 347, 354
- `src/actions/posts-new.php` — check for global usage
- `src/actions/admin/moderation.php` — check for global usage
- `src/actions/admin/*.php` — audit all admin actions

**Dependencies:** None (can start immediately)

**Verification Criteria:**
- [ ] No `global $pluginManager` remains in codebase (grep verification)
- [ ] All existing tests pass
- [ ] New test: `AuthZ` works with mocked `PluginManager` in isolation
- [ ] Plugin hooks for permissions still function in integration test

**Risk:** Medium — touches authorization logic; requires careful testing of permission checks

---

### P0-2: Add Foreign Keys to Initial Schema + Enable SQLite FKs

**Problem:** Initial migration creates tables without FK constraints; SQLite FKs disabled by default.

**Implementation Approach:**
1. Create new migration `20261107_add_foreign_keys_initial.php` that:
   - Adds FK constraints to initial schema tables
   - Runs after initial schema but before any data migrations
2. Modify `BbPdo::__construct()` to enable SQLite foreign keys:
   ```php
   if (!$this->mysql) {
       $this->exec('PRAGMA foreign_keys = ON');
   }
   ```
3. Update `20260904_add_foreign_keys.php` to be idempotent (check if FKs exist before adding)

**Files Affected:**
- `migrations/20261107_add_foreign_keys_initial.php` (new)
- `lib/BbPdo.php` — constructor
- `migrations/20260904_add_foreign_keys.php` — make idempotent

**Foreign Keys to Add:**
```sql
-- threads
ALTER TABLE threads ADD CONSTRAINT fk_threads_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
ALTER TABLE threads ADD CONSTRAINT fk_threads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- posts
ALTER TABLE posts ADD CONSTRAINT fk_posts_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE;
ALTER TABLE posts ADD CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- uploads
ALTER TABLE uploads ADD CONSTRAINT fk_uploads_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE;
ALTER TABLE uploads ADD CONSTRAINT fk_uploads_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE;
ALTER TABLE uploads ADD CONSTRAINT fk_uploads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- thread_watchers
ALTER TABLE thread_watchers ADD CONSTRAINT fk_watchers_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE;
ALTER TABLE thread_watchers ADD CONSTRAINT fk_watchers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- notifications
ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- private_messages
ALTER TABLE private_messages ADD CONSTRAINT fk_pm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE private_messages ADD CONSTRAINT fk_pm_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE;

-- email_verifications
ALTER TABLE email_verifications ADD CONSTRAINT fk_email_verif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- password_resets
ALTER TABLE password_resets ADD CONSTRAINT fk_pwd_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
```

**Dependencies:** None (can start immediately)

**Verification Criteria:**
- [ ] Fresh SQLite install: `PRAGMA foreign_key_list(threads)` shows FKs
- [ ] Fresh MySQL install: `SHOW CREATE TABLE threads` shows FKs
- [ ] Deleting a category sets `threads.category_id` to NULL
- [ ] Deleting a thread cascades to posts, uploads, watchers
- [ ] Deleting a user sets FK columns to NULL (not cascade for content ownership)
- [ ] All existing migration tests pass
- [ ] `bb.php migrate:status` shows clean state

**Risk:** Low — additive schema change; but test thoroughly with existing data

---

### P0-3: Cache Session Version to Avoid Per-Request DB Query

**Problem:** `is_logged_in()` queries `users.session_version` on every authenticated request.

**Implementation Approach:**
1. Store `session_version` in `$_SESSION` at login (already done in `handle_login()`)
2. Modify `is_logged_in()` to compare session-stored version only
3. On password change / admin forced logout, increment `session_version` in DB AND update `$_SESSION['session_version']`
4. Remove the DB query from `is_logged_in()`

**Files Affected:**
- `src/Helpers/AuthHelpers.php` — `is_logged_in()` function (lines 7-29)
- `src/actions/users.php` — `handle_reset_password()` (line 541), `handle_edit_profile()` (line 385)
- `src/actions/admin/users.php` — `handle_admin_user_edit()` (check for session_version updates)

**Dependencies:** None (can start immediately)

**Verification Criteria:**
- [ ] `is_logged_in()` no longer contains PDO query (code inspection)
- [ ] Login still works, session version stored in `$_SESSION`
- [ ] Password change invalidates other sessions (test: login in two browsers, change password in one, other gets logged out)
- [ ] Admin user edit with password change invalidates user's sessions
- [ ] Performance: measure ~1 query eliminated per authenticated request

**Risk:** Low — session version logic already exists; just moving check from DB to session

---

## P1 — Significant Fixes

### P1-1: Standardize Error Handling Pattern

**Problem:** Mixed patterns — exceptions, redirect responses, boolean returns.

**Implementation Approach:**
1. Define standard: **All route handlers throw `HttpException` subclasses** for errors
2. Router already catches `HttpException` and converts to response
3. Create/ensure exception classes exist:
   - `NotFoundException` (404)
   - `ForbiddenException` (403)
   - `ValidationException` (400) — with field details
   - `ConflictException` (409)
   - `TooManyRequestsException` (429)
4. Refactor handlers to throw instead of returning redirects/false
5. Keep `redirect()` helper for successful POST-Redirect-GET pattern

**Files Affected:**
- `src/Errors.php` — verify exception classes exist
- `src/Router.php` — verify catch logic (line 226)
- All `src/actions/*.php` files — refactor error returns to throws

**Example Refactor:**
```php
// Before
if (!$thread) {
    return redirect(url('home'));
}

// After
if (!$thread) {
    throw new \Bulletin\NotFoundException('Thread not found');
}
```

**Dependencies:** P0-1 (AuthZ DI) — some handlers use AuthZ

**Verification Criteria:**
- [ ] No handler returns `false` for errors (grep for `return false` in actions)
- [ ] No handler returns redirect for errors (only for success)
- [ ] All error paths throw `HttpException` subclass
- [ ] Integration tests: 404, 403, 400, 429 responses render correctly
- [ ] JSON API routes return JSON errors (router `wantsJson()` logic)

**Risk:** High — touches all action handlers; do incrementally with tests

---

### P1-2: Add Missing Database Indexes

**Problem:** No indexes on frequently queried FK columns and filter columns.

**Implementation Approach:**
Create migration `20261107_add_performance_indexes.php`:

```php
class AddPerformanceIndexes
{
    public function irreversible(): bool { return false; }
    
    public function up(PDO $pdo): void {
        $indexes = [
            'idx_threads_category_id' => 'CREATE INDEX IF NOT EXISTS idx_threads_category_id ON threads(category_id)',
            'idx_threads_user_id' => 'CREATE INDEX IF NOT EXISTS idx_threads_user_id ON threads(user_id)',
            'idx_threads_status' => 'CREATE INDEX IF NOT EXISTS idx_threads_status ON threads(status)',
            'idx_threads_created_at' => 'CREATE INDEX IF NOT EXISTS idx_threads_created_at ON threads(created_at)',
            'idx_posts_thread_id' => 'CREATE INDEX IF NOT EXISTS idx_posts_thread_id ON posts(thread_id)',
            'idx_posts_user_id' => 'CREATE INDEX IF NOT EXISTS idx_posts_user_id ON posts(user_id)',
            'idx_posts_status' => 'CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status)',
            'idx_posts_created_at' => 'CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at)',
            'idx_uploads_thread_id' => 'CREATE INDEX IF NOT EXISTS idx_uploads_thread_id ON uploads(thread_id)',
            'idx_uploads_post_id' => 'CREATE INDEX IF NOT EXISTS idx_uploads_post_id ON uploads(post_id)',
            'idx_notifications_user_id' => 'CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id)',
            'idx_notifications_is_read' => 'CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read)',
            'idx_pm_sender_id' => 'CREATE INDEX IF NOT EXISTS idx_pm_sender_id ON private_messages(sender_id)',
            'idx_pm_recipient_id' => 'CREATE INDEX IF NOT EXISTS idx_pm_recipient_id ON private_messages(recipient_id)',
        ];
        
        foreach ($indexes as $name => $sql) {
            $pdo->exec($sql);
        }
    }
    
    public function down(PDO $pdo): void {
        $indexes = array_keys($indexes); // from above
        foreach ($indexes as $name) {
            $pdo->exec("DROP INDEX IF EXISTS $name");
        }
    }
}
```

**Files Affected:**
- `migrations/20261107_add_performance_indexes.php` (new)

**Dependencies:** P0-2 (FKs should exist first)

**Verification Criteria:**
- [ ] `bb.php migrate` runs new migration successfully
- [ ] SQLite: `.indexes` shows all new indexes
- [ ] MySQL: `SHOW INDEX FROM threads` shows new indexes
- [ ] Query plans: `EXPLAIN QUERY PLAN SELECT * FROM threads WHERE category_id = 1` uses index
- [ ] No performance regression on writes (indexes have cost)

**Risk:** Low — additive, reversible, standard practice

---

### P1-3: Make CSP Configurable

**Problem:** Hardcoded CSP allows many external domains; not customizable.

**Implementation Approach:**
1. Add `csp` config section to `config-sample.json`:
```json
{
  "csp": {
    "script_src": ["'self'", "https://platform.twitter.com"],
    "style_src": ["'self'", "https://cdn.jsdelivr.net"],
    "img_src": ["'self'", "data:", "https:"],
    "font_src": ["'self'", "https://fonts.gstatic.com"],
    "frame_src": ["https://www.youtube.com"],
    "connect_src": ["'self'"]
  }
}
```
2. Modify `src/csp.php` to build header from config with secure defaults
3. Validate config values (no `unsafe-inline` in script_src by default)
4. Document CSP configuration in README

**Files Affected:**
- `src/csp.php` — `send_security_headers()` function
- `config-sample.json` — add example CSP config
- `README.md` — document CSP configuration

**Dependencies:** None

**Verification Criteria:**
- [ ] Default CSP (no config) matches current behavior
- [ ] Custom CSP config overrides specific directives
- [ ] Invalid config falls back to secure defaults (logs warning)
- [ ] Nonce still works with custom CSP
- [ ] Test: configure restrictive CSP, verify inline scripts blocked

**Risk:** Low — configuration-driven, backward compatible

---

### P1-4: Secure `DbQuery::whereRaw()` Usage

**Problem:** `whereRaw()` accepts arbitrary SQL fragments; dangerous if exposed to user input.

**Implementation Approach:**
1. Add `@internal` docblock to `whereRaw()` method
2. Add runtime assertion in debug mode: `assert(!preg_match('/\$\w/', $sql), 'whereRaw SQL must not contain variables')`
3. Audit all current usages in codebase — ensure none use user input
4. Consider renaming to `whereRawInternal()` to signal intent

**Files Affected:**
- `lib/DbQuery.php` — `whereRaw()` method (lines 83-91)
- Search codebase for `whereRaw(` usages

**Dependencies:** None

**Verification Criteria:**
- [ ] All current `whereRaw()` calls use hardcoded SQL (code review)
- [ ] Documentation warns against user input
- [ ] Static analysis: no variable interpolation in `whereRaw()` calls

**Risk:** Very Low — documentation + audit only

---

### P1-5: Document Plugin/Theme Trust Model

**Problem:** No cryptographic verification; trust-on-first-use model not clearly documented for admins.

**Implementation Approach:**
1. Add `SECURITY_MODEL.md` or extend README security section
2. Document:
   - Package installation = explicit trust decision
   - `plugin_verify_files` / `theme_verify_files` purpose
   - Risks of disabling verification
   - Recommended: only install from reviewed sources
   - Future: optional GPG verification for official catalog
3. Add warning log when verification is disabled

**Files Affected:**
- `README.md` — security section
- `lib/PackageInstaller.php` — log warning when verification disabled
- `lib/PluginManager/PluginPackages.php` — log warning
- `lib/ThemeManager.php` — log warning

**Dependencies:** None

**Verification Criteria:**
- [ ] Documentation clearly explains trust model
- [ ] Warning logged when `*_verify_files` set to `false`
- [ ] Admin panel shows verification status

**Risk:** None — documentation only

---

## P2 — Improvements

### P2-1: Extract Duplicate UpdateManager Logic

**Problem:** `applyCoreUpdateFromZip()` and `applyCoreUpdate()` share ~90% identical code.

**Implementation Approach:**
1. Create private method `applyCoreUpdateFromSource(string $sourceDir): bool`
2. Both public methods call this after obtaining/extracting source
3. Extract backup/copy/cleanup logic

**Files Affected:**
- `lib/UpdateManager.php` — lines 332-506

**Dependencies:** None

**Verification Criteria:**
- [ ] Both update paths (direct tag + uploaded ZIP) work
- [ ] Rollback on failure still works
- [ ] Installer scripts removed in both paths
- [ ] Version updated in config.json and VERSION file

**Risk:** Low — refactoring with existing tests

---

### P2-2: Add Return Type Hints

**Problem:** Many functions missing return type declarations.

**Implementation Approach:**
1. Identify missing types
2. Add return types to:
   - `src/helpers.php`: `base_url(): string`
   - `src/Helpers/AuthHelpers.php`: `is_admin(): bool`, `is_banned(): bool`, `is_suspended(): bool`
   - `src/Helpers/Url.php`: all functions
   - `src/Helpers/Text.php`: all functions
   - `src/Helpers/Upload.php`: all functions
   - `src/Helpers/Mail.php`: all functions
   - `src/Helpers/Notifications.php`: all functions
   - `src/Helpers/Avatar.php`: all functions
   - `src/Helpers/Data.php`: all functions

**Files Affected:** All helper files

**Dependencies:** None

**Verification Criteria:**
- [ ] No type errors introduced
- [ ] Tests pass

**Risk:** Low — additive types only

---

### P2-3: Make Rate Limiter Backend Pluggable — **SKIPPED (Out of Scope)**

**Problem:** File-based rate limiter doesn't work in clustered deployments.

**Decision:** This issue is **skipped** as it is out of scope for BulletinBored's intended deployment model. The application is designed as a lightweight, self-hosted forum primarily using SQLite on a single server node. Horizontal clustering (multi-node deployments behind a load balancer) is not a supported or intended use case for the core application.

**Future Consideration:** If the project evolves to support multi-node deployments with shared databases (e.g., MySQL), a pluggable rate limiter backend (file, database, or Redis) could be a useful addition at that time. For now, the existing file-based rate limiter in `src/Security.php` is sufficient and appropriate.

**Files Affected:** None (no changes required)

**Dependencies:** None

**Verification Criteria:** N/A — issue deferred

**Risk:** None — no implementation risk

---

### P2-4: Expand Test Coverage

**Problem:** Missing tests for admin actions, UpdateManager, ThemeManager, uploads, email, router.

**Implementation Approach:**
Create test files:
- `tests/AdminActionsTest.php` — moderation, user management, settings
- `tests/UpdateManagerTest.php` — core/plugin/theme updates (mock HTTP)
- `tests/ThemeManagerTest.php` — install/update/delete/activate
- `tests/UploadTest.php` — image upload, validation, download
- `tests/EmailTest.php` — send_email, verification, reset (mock SMTP)
- `tests/RouterTest.php` — middleware, dispatch, JSON/HTML negotiation

**Files Affected:**
- New test files in `tests/`

**Dependencies:** P1-1 (standardized error handling makes testing easier)

**Verification Criteria:**
- [ ] Each new test file has >80% coverage of target module
- [ ] All tests pass in CI
- [ ] Integration tests cover happy paths + error cases

**Risk:** Low — additive tests only

### P2-5: Fix Defects in Language Admin Handler (`src/actions/admin/langs.php`)

**Problem:** Three defects in the language administration handler:
1. **Scoping bug** (line 22): `saveLangMeta()` declares `global $langMetaPath` but `$langMetaPath` is a local variable in `handle_admin_langs()`, not global — causes fallback path to always execute
2. **Broken fallback logic** (lines 132-137): When `$downloadUrl` is a `.json` URL, fallback generates `.php` URL but loop filters for `.json` only, so fallback never tried
3. **Output buffer leak** (line 102): `ob_start()` called before validation/HTTP fetching; exceptions bypass `ob_end_clean()` cleanup

**Implementation Approach:**
1. **Fix scoping**: Change `saveLangMeta(string $code, string $sha): void` to `saveLangMeta(string $code, string $sha, string $langMetaPath): void` and pass `$langMetaPath` explicitly at call site (line 164)
2. **Fix fallback**: Generate both `.json` and `.php` variants unconditionally, try all candidates regardless of extension
3. **Fix buffer leak**: Wrap critical section in `try/finally` to guarantee `ob_end_clean()` and `ini_set('display_errors')` restoration

**Files Affected:**
- `src/actions/admin/langs.php` — lines 20-28, 102-175, 164

**Dependencies:** None (can start immediately)

**Verification Criteria:**
- [ ] `saveLangMeta()` called with explicit `$langMetaPath` parameter (no `global`)
- [ ] Language metadata correctly written to `data/lang-meta.json`
- [ ] Install/update from GitHub works for both `.json` and `.php` source URLs
- [ ] Fallback attempted when primary URL fails
- [ ] No output buffer leaks when exceptions thrown (CSRF, rate limit, validation)
- [ ] `display_errors` restored to original value on all code paths
- [ ] All existing tests pass

**Risk:** Low — isolated to single admin action; straightforward fixes with minimal regression surface

---

## P3 — Code Quality

### P3-1: Consistent Naming Conventions

**Problem:** Mixed snake_case/camelCase for functions.

**Implementation Approach:**
1. Adopt: **snake_case for functions, camelCase for methods** (PSR-12)
2. Rename functions in `src/Security.php`, `src/helpers.php`, `src/Helpers/*.php`
3. Update all call sites
4. Use automated tool (PHP-CS-Fixer) where possible

**Files Affected:** All helper files, Security.php

**Dependencies:** None (can do anytime)

**Verification Criteria:**
- [ ] PHP-CS-Fixer passes with PSR-12 config
- [ ] No broken references after rename
- [ ] Tests pass

**Risk:** Medium — many call sites; use automated refactoring

---

### P3-2: Document `clean_text()` vs `validate_input()`

**Problem:** Unclear when to use which function.

**Implementation Approach:**
1. Add detailed docblocks to both functions in `src/Helpers/Text.php`
2. `clean_text()` — for display output (trims, preserves HTML entities for later escaping)
3. `validate_input()` — for input processing (trims, stripslashes, basic sanitization)
4. Add usage examples in docblocks
5. Audit call sites for correct usage

**Files Affected:**
- `src/Helpers/Text.php`

**Dependencies:** None

**Verification Criteria:**
- [ ] Docblocks clearly explain difference
- [ ] Call sites use appropriate function
- [ ] No double-escaping or under-escaping

**Risk:** Low — documentation only

---

### P3-3: Make Magic Numbers Configurable

**Problem:** Hardcoded values scattered in code.

**Implementation Approach:**
Add to `config-sample.json`:
```json
{
  "posts_per_page": 15,
  "rate_limit_gc_probability": 200,
  "rate_limit_gc_max_age": 86400,
  "update_min_disk_space_mb": 50,
  "image_max_size_mb": 5,
  "avatar_max_size_mb": 2
}
```
Update code to read from config with defaults.

**Files Affected:**
- `src/helpers.php` — `POSTS_PER_PAGE`
- `src/Security.php` — GC probability, max age
- `lib/UpdateManager.php` — disk space
- `src/actions/posts-thread.php` — image max size
- `src/actions/users.php` — avatar max size
- `config-sample.json`

**Dependencies:** None

**Verification Criteria:**
- [ ] Config values override defaults
- [ ] Missing config falls back to defaults
- [ ] Admin settings page exposes these (optional)

**Risk:** Low — configuration-driven

---

## Implementation Sequence

```
Phase 1 (Week 1): P0 Critical Fixes
├── P0-1: Eliminate global $pluginManager
├── P0-2: Add FKs to initial schema + SQLite PRAGMA
└── P0-3: Cache session version

Phase 2 (Week 2): P1 Significant Fixes
├── P1-1: Standardize error handling (incremental)
├── P1-2: Add performance indexes
├── P1-3: Make CSP configurable
├── P1-4: Secure whereRaw() documentation
└── P1-5: Document trust model

Phase 3 (Week 3): P2 Improvements
├── P2-1: Extract UpdateManager duplicate logic
├── P2-2: Add return type hints
├── P2-3: **SKIPPED** — Rate limiter pluggable backend (out of scope)
└── P2-4: Expand test coverage (start with AdminActions)

Phase 4 (Week 4): P3 Code Quality
├── P3-1: Consistent naming (automated)
├── P3-2: Document clean_text vs validate_input
└── P3-3: Make magic numbers configurable
```

**Parallelization Notes:**
- P0-1, P0-2, P0-3 are independent — can be done in parallel
- P1-1 depends on P0-1 (AuthZ DI)
- P1-2 depends on P0-2 (FKs first)
- P2-4 depends on P1-1 (easier to test with standardized errors)
- P3 items can be done anytime

---

## Verification Gates

### Gate 1: After Phase 1 (P0 Complete)
- [ ] `grep -r "global \$pluginManager" src/ lib/` returns nothing
- [ ] Fresh install on SQLite + MySQL: FKs present and enforced
- [ ] `is_logged_in()` has no DB query (code inspection)
- [ ] All existing tests pass
- [ ] New unit test: AuthZ with mocked PluginManager

### Gate 2: After Phase 2 (P1 Complete)
- [ ] All action handlers throw HttpException for errors
- [ ] Migration `20261107_add_performance_indexes` runs cleanly
- [ ] CSP configurable via config.json
- [ ] `whereRaw()` audited and documented
- [ ] Trust model documented, warnings logged

### Gate 3: After Phase 3 (P2 Complete)
- [ ] UpdateManager duplicate logic extracted
- [ ] **SKIPPED** — Rate limiter pluggable backend (out of scope)
- [ ] AdminActionsTest.php covers >80% of admin handlers
- [ ] UpdateManagerTest.php covers >80% of UpdateManager (P2-4)
- [ ] ThemeManagerTest.php covers >80% of ThemeManager (P2-4)
- [ ] UploadTest.php covers >80% of Upload helper (P2-4)
- [ ] EmailTest.php covers >80% of Email functions (P2-4)
- [ ] RouterTest.php covers >80% of Router (P2-4)
- [ ] All existing tests pass
- [ ] P2-5: `saveLangMeta()` called with explicit `$langMetaPath` parameter (no `global`)
- [ ] P2-5: Language metadata correctly written to `data/lang-meta.json`
- [ ] P2-5: Install/update from GitHub works for both `.json` and `.php` source URLs
- [ ] P2-5: Fallback attempted when primary URL fails
- [ ] P2-5: No output buffer leaks when exceptions thrown (CSRF, rate limit, validation)
- [ ] P2-5: `display_errors` restored to original value on all code paths

### Gate 4: After Phase 4 (P3 Complete)
- [ ] PHP-CS-Fixer passes
- [ ] clean_text/validate_input documented and audited
- [ ] All magic numbers configurable
- [ ] Full test suite passes

---

## Rollback Plan

Each migration is reversible (`down()` method). For code changes:
- Feature flags for CSP config, rate limiter backend
- Git branches per phase for easy revert
- Database migrations tested on staging before production

---

## Success Metrics

| Metric | Target |
|--------|--------|
| Global state eliminated | 0 `global $pluginManager` |
| DB queries per auth request | -1 (session version cached) |
| FK constraints | 100% of relationships |
| Index coverage | All FK/filter columns indexed |
| Test coverage (new modules) | >80% |
| CSP configurable | Yes, with secure defaults |

---

*Plan prepared by Architecture Mode — ready for team review and sprint planning*