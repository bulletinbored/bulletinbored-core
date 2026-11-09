# Test Coverage Report - bulletinbored-core

**Generated:** 2026-11-08  
**Test Suite:** 41 test files, 495 tests, 1629 assertions  
**All Tests:** ✅ PASSING (1629 passed, 0 failed)

---

## Overall Coverage Summary

| Metric | Value |
|--------|-------|
| **Overall Line Coverage** | **88.9%** (1,355 / 1,525 executable lines) |
| **Files Analyzed** | 40 files in `src/` directory |
| **Files with 100% Coverage** | 1 file |
| **Files with 0% Coverage** | 7 files |
| **Files Below 70% Coverage** | 3 files |

---

## Coverage by File (src/ directory)

### ✅ 100% Coverage
| File | Coverage | Lines Covered/Total |
|------|----------|---------------------|
| `src/actions/admin/themes.php` | **100.0%** | 45/45 |

### 🟢 High Coverage (≥90%)
| File | Coverage | Lines Covered/Total |
|------|----------|---------------------|
| `src/Helpers/Url.php` | 96.6% | 86/89 |
| `src/actions/admin/plugins.php` | 97.4% | 38/39 |
| `src/actions/admin/diagnostics.php` | 97.5% | 39/40 |
| `src/actions/admin/catalog.php` | 97.3% | 36/37 |
| `src/actions/admin/langs.php` | 96.4% | 27/28 |
| `src/actions/admin/moderation.php` | 96.3% | 131/136 |
| `src/actions/admin/users.php` | 93.5% | 130/139 |
| `src/Security.php` | 93.0% | 107/115 |
| `src/markdown.php` | 94.9% | 94/99 |
| `src/Helpers/Mail.php` | 94.1% | 32/34 |
| `src/actions/admin/categories.php` | 91.9% | 34/37 |
| `src/csp.php` | 90.7% | 39/43 |
| `src/actions/posts-thread.php` | 90.5% | 38/42 |
| `src/Router.php` | 88.4% | 152/172 |
| `src/Helpers/AuthHelpers.php` | 88.9% | 32/36 |

### 🟡 Medium Coverage (70-89%)
| File | Coverage | Lines Covered/Total |
|------|----------|---------------------|
| `src/actions/admin/settings.php` | 87.5% | 49/56 |
| `src/Helpers/Upload.php` | 86.2% | 25/29 |
| `src/actions/admin/updates.php` | 84.6% | 11/13 |
| `src/Helpers/Avatar.php` | 82.4% | 14/17 |
| `src/Helpers/Text.php` | 80.5% | 33/41 |
| `src/actions/content.php` | 78.9% | 15/19 |
| `src/Request.php` | 78.4% | 40/51 |
| `src/helpers.php` | 77.8% | 7/9 |
| `src/Helpers/Notifications.php` | 76.9% | 10/13 |
| `src/bootstrap.php` | 76.9% | 10/13 |
| `src/Errors.php` | 75.0% | 12/16 |
| `src/Response.php` | 71.4% | 20/28 |
| `src/Helpers/Data.php` | 71.4% | 10/14 |
| `src/TrustedProxies.php` | 69.2% | 9/13 |

### 🔴 Low Coverage (<70%)
| File | Coverage | Lines Covered/Total | Gap |
|------|----------|---------------------|-----|
| `src/App.php` | 66.7% | 6/9 | 3 lines |
| `src/Renderer.php` | **54.8%** | 23/42 | **19 lines** |
| `src/actions/users.php` | **9.1%** | 1/11 | **10 lines** |

### ⚫ Zero Coverage (Not Loaded)
| File | Coverage | Status |
|------|----------|--------|
| `src/actions/admin.php` | 0.0% | Not loaded |
| `src/actions/misc.php` | 0.0% | Not loaded |
| `src/actions/posts-edit.php` | 0.0% | Not loaded |
| `src/actions/posts-new.php` | 0.0% | Not loaded |
| `src/actions/posts.php` | 0.0% | Not loaded |
| `src/session_setup.php` | 0.0% | Not loaded |
| `src/setup.php` | 0.0% | Not loaded |

---

## Coverage Gaps Analysis

### Critical Gaps (Zero Coverage - 7 files)

These files are **never executed** during the test suite:

1. **`src/actions/admin.php`** - Admin action dispatcher
2. **`src/actions/misc.php`** - Miscellaneous actions
3. **`src/actions/posts-edit.php`** - Post editing actions
4. **`src/actions/posts-new.php`** - New post creation actions
5. **`src/actions/posts.php`** - Post listing/display actions
6. **`src/session_setup.php`** - Session configuration
7. **`src/setup.php`** - Application bootstrap/setup

### High-Priority Gaps (Low Coverage)

1. **`src/actions/users.php` (9.1%)** - User-facing actions (login, register, profile, etc.)
   - Only 1 of 11 executable lines covered
   - Critical user flows likely untested

2. **`src/Renderer.php` (54.8%)** - View rendering engine
   - 19 of 42 lines uncovered
   - Template rendering, layout handling may have gaps

3. **`src/App.php` (66.7%)** - Application singleton/core
   - 3 of 9 lines uncovered
   - Core application lifecycle methods may be missing tests

---

## Files NOT Included in Coverage Report

The coverage tool only scans the `src/` directory. The following are **not measured**:

| Directory/File | Status |
|----------------|--------|
| `lib/` (13 files + 4 in PluginManager/) | ❌ Not scanned |
| `api/` | ❌ Not scanned |
| `index.php` | ❌ Not scanned |
| `router.php` | ❌ Not scanned |
| `bb.php` | ❌ Not scanned |
| `install.php`, `install2.php`, `install3.php` | ❌ Not scanned |
| `plugins/` | ❌ Not scanned |
| `views/` | ❌ Not scanned (templates) |

**Recommendation:** Extend coverage collection to include `lib/` directory at minimum, as it contains core business logic (AuthZ, DbQuery, PluginManager, Migrator, etc.).

---

## Test Distribution

| Test File | Tests | Focus Area |
|-----------|-------|------------|
| `AdminActionsTest.php` | Multiple | Admin panel actions |
| `AuthTest.php` / `AuthHardeningTest.php` | Multiple | Authentication |
| `ContentCrudTest.php` / `ContentHardeningTest.php` | Multiple | Content management |
| `DatabaseIntegrityTest.php` / `DatabaseMatrixTest.php` | Multiple | Database operations |
| `DbQueryTest.php` | Multiple | Query builder |
| `E2eFlowTest.php` / `E2eIntegrationTest.php` | Multiple | End-to-end flows |
| `EmailTest.php` / `EmailSecurityTest.php` | Multiple | Email functionality |
| `EndpointAuthorizationTest.php` | Multiple | Authorization |
| `HelpersTest.php` | Multiple | Helper functions |
| `InstallerTest.php` | Multiple | Installation |
| `MarkdownTest.php` | Multiple | Markdown rendering |
| `MigratorTest.php` | Multiple | Database migrations |
| `ModerationTest.php` / `ModerationHandlerTest.php` | Multiple | Moderation |
| `PluginManagerTest.php` / `PluginRouterTest.php` / `PluginThemeTest.php` / `PluginAuthZIntegrationTest.php` | Multiple | Plugin system |
| `RegistrationTest.php` | Multiple | User registration |
| `RendererTest.php` | Multiple | View rendering |
| `ResponseTest.php` | Multiple | HTTP responses |
| `RouterTest.php` | Multiple | Routing |
| `SecurityTest.php` / `SecurityHardeningTest.php` / `SecurityFixesTest.php` | Multiple | Security |
| `SessionSecurityTest.php` | Multiple | Session handling |
| `TelemetryTest.php` | Multiple | Telemetry |
| `ThemeManagerTest.php` | Multiple | Theme system |
| `UpdateManagerTest.php` / `UpdateFailureModeTest.php` | Multiple | Updates |
| `UpgradeTest.php` | Multiple | Upgrades |
| `UploadTest.php` / `UploadSecurityTest.php` | Multiple | File uploads |
| `VersionConsistencyTest.php` | Multiple | Version consistency |

---

## Recommendations

### Immediate (High Impact)
1. **Add tests for zero-coverage action files** - Create test files for:
   - `src/actions/admin.php`
   - `src/actions/misc.php`
   - `src/actions/posts-edit.php`
   - `src/actions/posts-new.php`
   - `src/actions/posts.php`
   - `src/actions/users.php` (currently only 9.1%)

2. **Add tests for bootstrap/setup files**:
   - `src/session_setup.php`
   - `src/setup.php`

3. **Improve `src/Renderer.php` coverage** - Target the 19 uncovered lines

### Medium Term
4. **Extend coverage to `lib/` directory** - Core business logic is currently unmeasured
5. **Add integration tests for `src/App.php`** - Improve from 66.7%
6. **Add tests for `src/TrustedProxies.php`** - Improve from 69.2%

### Long Term
7. **Consider adding coverage for entry points** (`index.php`, `router.php`, `bb.php`)
8. **Set up CI/CD coverage gates** - Enforce minimum coverage thresholds
9. **Add mutation testing** - Validate test quality beyond line coverage

---

## Coverage Trend Target

| Phase | Target Coverage | Focus |
|-------|----------------|-------|
| Current | 88.9% | Baseline |
| Phase 1 | 95% | Eliminate zero-coverage files |
| Phase 2 | 98% | Cover lib/ directory |
| Phase 3 | 100% | Full coverage including entry points |

---

*Report generated by running `XDEBUG_MODE=coverage php tests/run.php --coverage`*