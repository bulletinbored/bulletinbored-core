# Test Coverage Improvement Plan - bulletinbored-core

**Generated:** 2026-11-08  
**Current Coverage:** 88.9% (1,355 / 1,525 executable lines in `src/`)  
**Target:** 100% coverage across all source files including `lib/`, entry points, and plugins

---

## Architecture Analysis Summary

### Dependency Graph (Key Relationships)

```
Entry Points (index.php, router.php, bb.php)
    │
    ▼
src/bootstrap.php → src/setup.php → lib/Migrator.php → lib/DbQuery.php
    │                    │
    │                    ▼
    │              lib/BbPdo.php
    │                    │
    ▼                    ▼
src/App.php (Singleton) ←──────────────────┘
    │
    ├──► src/Router.php
    │
    ├──► src/actions/ (dispatchers)
    │       ├── admin.php → admin/*.php (all well-covered)
    │       ├── users.php (9.1% - CRITICAL)
    │       ├── posts.php → posts-thread.php (90.5%)
    │       │           → posts-new.php (0%)
    │       │           → posts-edit.php (0%)
    │       ├── content.php (78.9%)
    │       ├── misc.php (0%)
    │       └── admin.php (0% - dispatcher only)
    │
    ├──► src/Renderer.php (54.8%)
    │
    ├──► lib/AuthZ.php (0% - NOT MEASURED)
    │
    ├──► lib/PluginManager.php (0% - NOT MEASURED)
    │
    ├──► lib/ThemeManager.php (0% - NOT MEASURED)
    │
    ├──► lib/UpdateManager.php (0% - NOT MEASURED)
    │
    ├──► src/Helpers/* (mostly well-covered)
    │
    ├──► src/Security.php (93%)
    │
    ├──► src/Request.php (78.4%)
    │
    ├──► src/Response.php (71.4%)
    │
    ├──► src/TrustedProxies.php (69.2%)
    │
    ├──► src/csp.php (90.7%)
    │
    ├──► src/markdown.php (94.9%)
    │
    ├──► src/Errors.php (75%)
    │
    ├──► src/helpers.php (77.8%)
    │
    ├──► src/session_setup.php (0%)
    │
    └──► src/setup.php (0%)
```

### Key Observations

1. **`src/actions/admin.php`** is a pure dispatcher (0% coverage) - delegates to well-covered admin modules
2. **`src/actions/posts.php`** is a dispatcher (0%) - delegates to posts-thread/new/edit (mixed coverage)
3. **`src/actions/users.php`** (9.1%) - Critical user flows completely untested
4. **`lib/` directory** - Core business logic completely unmeasured (AuthZ, DbQuery, PluginManager, etc.)
5. **Entry points** (`index.php`, `router.php`, `bb.php`) - Not measured
6. **Bootstrap chain** (`setup.php` → `session_setup.php` → `bootstrap.php`) - Partially covered

---

## Staged Improvement Plan

### Stage 1: Foundation & Core Infrastructure (Week 1-2)
**Goal:** Establish coverage for bootstrap chain and core infrastructure  
**Target:** +5% coverage (bootstrap, setup, session, App)

#### 1.1 Bootstrap Chain Tests
| File | Current | Target | Dependencies | Test File |
|------|---------|--------|--------------|-----------|
| `src/setup.php` | 0% | 90% | lib/BbPdo.php, lib/Migrator.php, lib/DbQuery.php | `tests/SetupTest.php` (new) |
| `src/session_setup.php` | 0% | 90% | src/App.php | `tests/SessionSetupTest.php` (new) |
| `src/bootstrap.php` | 76.9% | 95% | setup.php, session_setup.php | Extend `tests/BootstrapIntegrationTest.php` |
| `src/App.php` | 66.7% | 90% | - | Extend `tests/BootstrapIntegrationTest.php` |

**Test Strategy:**
- Test directory creation, PDO initialization, migration running
- Test admin user creation logic
- Test session configuration (secure cookies, HTTPS detection)
- Test App singleton getInstance/reset/config/clientIp

**Prerequisites:** None - these are foundation files

---

### Stage 2: User Authentication & Authorization (Week 2-3)
**Goal:** Cover critical user-facing flows (login, register, profile, password reset)  
**Target:** +15% coverage (users.php, AuthZ.php)

#### 2.1 User Actions (`src/actions/users.php` - 9.1% → 90%)
| Function | Priority | Dependencies | Test File |
|----------|----------|--------------|-----------|
| `handle_login` | CRITICAL | AuthZ, Security, Helpers/AuthHelpers | `tests/UsersAuthTest.php` (new) |
| `handle_register` | CRITICAL | AuthZ, Helpers/Mail, Helpers/Notifications | `tests/UsersAuthTest.php` |
| `handle_logout` | HIGH | - | `tests/UsersAuthTest.php` |
| `handle_verify_email` | HIGH | - | `tests/UsersAuthTest.php` |
| `handle_profile` | HIGH | - | `tests/UsersProfileTest.php` (new) |
| `handle_edit_profile` | HIGH | Helpers/Upload, Helpers/Url | `tests/UsersProfileTest.php` |
| `handle_remove_avatar` | MEDIUM | - | `tests/UsersProfileTest.php` |
| `handle_forgot_password` | HIGH | Helpers/Mail | `tests/UsersPasswordTest.php` (new) |
| `handle_reset_password` | HIGH | - | `tests/UsersPasswordTest.php` |

#### 2.2 Authorization Core (`lib/AuthZ.php` - 0% → 95%)
| Method | Priority | Dependencies | Test File |
|--------|----------|--------------|-----------|
| `can()` | CRITICAL | roles table, pluginManager | `tests/AuthZTest.php` (new) |
| `canOnOwned()` | CRITICAL | - | `tests/AuthZTest.php` |
| `hasRole()` | HIGH | - | `tests/AuthZTest.php` |
| `getUserRole()` | HIGH | - | `tests/AuthZTest.php` |
| `getRolePermissions()` | HIGH | roles table | `tests/AuthZTest.php` |
| `clearCache()` | MEDIUM | - | `tests/AuthZTest.php` |

**Test Strategy:**
- Unit test AuthZ with mocked PDO and pluginManager
- Test role hierarchy (admin > moderator > user)
- Test ownership checks (canOnOwned)
- Test plugin-contributed permissions
- Integration test with real database for role permissions

**Prerequisites:** Stage 1 (App, bootstrap, database setup)

---

### Stage 3: Content Creation & Management (Week 3-4)
**Goal:** Cover post/thread creation, editing, deletion flows  
**Target:** +10% coverage (posts-new.php, posts-edit.php, posts-thread.php gaps)

#### 3.1 New Thread Creation (`src/actions/posts-new.php` - 0% → 90%)
| Function | Priority | Dependencies | Test File |
|----------|----------|--------------|-----------|
| `handle_new_thread` | CRITICAL | AuthZ, PluginManager, Helpers/Notifications | `tests/PostsNewTest.php` (new) |

#### 3.2 Post/Thread Editing (`src/actions/posts-edit.php` - 0% → 90%)
| Function | Priority | Dependencies | Test File |
|----------|----------|--------------|-----------|
| `handle_reply_post` | CRITICAL | AuthZ, PluginManager, Helpers/Notifications | `tests/PostsEditTest.php` (new) |
| `handle_edit_post` | CRITICAL | AuthZ, PluginManager | `tests/PostsEditTest.php` |
| `handle_delete_post` | CRITICAL | AuthZ, PluginManager | `tests/PostsEditTest.php` |
| `handle_edit_thread` | HIGH | AuthZ, PluginManager | `tests/PostsEditTest.php` |
| `handle_delete_thread` | HIGH | AuthZ, PluginManager | `tests/PostsEditTest.php` |

#### 3.3 Thread View & Watch (`src/actions/posts-thread.php` - 90.5% → 98%)
| Function | Priority | Test File |
|----------|----------|-----------|
| `handle_thread_view` | MEDIUM | Extend existing tests |
| `handle_watch` / `handle_unwatch` | MEDIUM | Extend existing tests |
| `handle_upload_image` | MEDIUM | Extend existing tests |

**Test Strategy:**
- Test authorization checks (can/canOnOwned)
- Test plugin hooks (before_create, after_create, before_update, etc.)
- Test notification triggers
- Test validation and error handling
- Test rate limiting integration

**Prerequisites:** Stage 2 (AuthZ, user authentication)

---

### Stage 4: Dispatcher & Miscellaneous Actions (Week 4-5)
**Goal:** Cover action dispatchers and utility endpoints  
**Target:** +5% coverage (admin.php, posts.php, misc.php, content.php)

#### 4.1 Action Dispatchers
| File | Current | Target | Test File |
|------|---------|--------|-----------|
| `src/actions/admin.php` | 0% | 85% | `tests/AdminDispatcherTest.php` (new) |
| `src/actions/posts.php` | 0% | 85% | `tests/PostsDispatcherTest.php` (new) |
| `src/actions/misc.php` | 0% | 90% | `tests/MiscActionsTest.php` (new) |
| `src/actions/content.php` | 78.9% | 95% | Extend existing tests |

**Test Strategy:**
- Test dispatcher routing (switch statements)
- Test method validation (GET vs POST)
- Test 404/405 error handling
- Test CSRF validation delegation
- For misc.php: test markdown preview, mention autocomplete

**Prerequisites:** Stages 2-3 (underlying handlers must work)

---

### Stage 5: Rendering & View Layer (Week 5-6)
**Goal:** Cover template rendering engine  
**Target:** +8% coverage (Renderer.php 54.8% → 95%)

#### 5.1 Renderer Core (`src/Renderer.php` - 54.8% → 95%)
| Method | Priority | Test File |
|--------|----------|-----------|
| `render()` / `display()` | CRITICAL | `tests/RendererCoreTest.php` (extend) |
| `composer()` / `composeAll()` | HIGH | `tests/RendererCoreTest.php` |
| `addGlobal()` | HIGH | `tests/RendererCoreTest.php` |
| `partial()` / `renderPartial()` | HIGH | `tests/RendererPartialTest.php` (new) |
| `slot()` / `endSlot()` / `hasSlot()` / `slotContent()` / `renderSlot()` / `yield()` | HIGH | `tests/RendererSlotTest.php` (new) |
| `layout()` / `extend()` | MEDIUM | `tests/RendererLayoutTest.php` (new) |
| `e()` / `raw()` / `escapeAttr()` | HIGH | `tests/RendererEscapeTest.php` (new) |
| `csrfField()` | HIGH | `tests/RendererCsrfTest.php` (new) |
| `url()` / `t()` | MEDIUM | `tests/RendererHelperTest.php` (new) |
| `renderComponent()` / `displayComponent()` | MEDIUM | `tests/RendererComponentTest.php` (new) |
| `when()` / `each()` | LOW | `tests/RendererUtilityTest.php` (new) |

**Test Strategy:**
- Test template rendering with various data
- Test composer callbacks execution order
- Test slot capture and rendering
- Test layout extension
- Test escaping functions (XSS prevention)
- Test component rendering
- Test error handling (missing templates)

**Prerequisites:** Stage 1 (bootstrap for view paths)

---

### Stage 6: Core Library Coverage (Week 6-8)
**Goal:** Extend coverage collection to `lib/` directory - core business logic  
**Target:** Measure and achieve 90%+ on all lib files

#### 6.1 Update Coverage Collection
Modify `tests/run.php` to include `lib/` directory in coverage scanning.

#### 6.2 Library Test Files (Priority Order)
| File | Current (est.) | Target | Test File |
|------|----------------|--------|-----------|
| `lib/AuthZ.php` | 0% | 95% | `tests/AuthZTest.php` (Stage 2) |
| `lib/DbQuery.php` | 0% | 95% | `tests/DbQueryTest.php` (extend) |
| `lib/BbPdo.php` | 0% | 90% | `tests/BbPdoTest.php` (new) |
| `lib/Migrator.php` | 0% | 90% | `tests/MigratorTest.php` (extend) |
| `lib/PluginManager.php` | 0% | 85% | `tests/PluginManagerTest.php` (extend) |
| `lib/PluginManager/PluginManifest.php` | 0% | 90% | `tests/PluginManifestTest.php` (new) |
| `lib/PluginManager/PluginHooks.php` | 0% | 85% | `tests/PluginHooksTest.php` (new) |
| `lib/PluginManager/PluginDependencies.php` | 0% | 85% | `tests/PluginDependenciesTest.php` (new) |
| `lib/PluginManager/PluginPackages.php` | 0% | 85% | `tests/PluginPackagesTest.php` (new) |
| `lib/ThemeManager.php` | 0% | 85% | `tests/ThemeManagerTest.php` (extend) |
| `lib/UpdateManager.php` | 0% | 85% | `tests/UpdateManagerTest.php` (extend) |
| `lib/UpdateFetcher.php` | 0% | 80% | `tests/UpdateFetcherTest.php` (new) |
| `lib/UpdateBackup.php` | 0% | 80% | `tests/UpdateBackupTest.php` (new) |
| `lib/PackageInstaller.php` | 0% | 80% | `tests/PackageInstallerTest.php` (new) |
| `lib/PluginDiscovery.php` | 0% | 80% | `tests/PluginDiscoveryTest.php` (new) |
| `lib/Telemetry.php` | 0% | 80% | `tests/TelemetryTest.php` (extend) |

**Test Strategy:**
- Unit test each class with mocked dependencies
- Test SQLite and MySQL paths for BbPdo/DbQuery
- Test migration up/down/rollback
- Test plugin lifecycle (install, enable, disable, update)
- Test theme activation, discovery, validation
- Test update fetching, backup, rollback

**Prerequisites:** Stage 1 (database setup), Stage 2 (AuthZ for PluginManager)

---

### Stage 7: Entry Points & Integration (Week 8-9)
**Goal:** Cover HTTP entry points and end-to-end flows  
**Target:** Measure and achieve 80%+ on entry points

#### 7.1 Entry Points
| File | Current | Target | Test File |
|------|---------|--------|-----------|
| `index.php` | Not measured | 80% | `tests/EntryPointTest.php` (new) |
| `router.php` | Not measured | 80% | `tests/EntryPointTest.php` |
| `bb.php` | Not measured | 80% | `tests/EntryPointTest.php` |
| `api/install.php` | Not measured | 70% | `tests/ApiInstallTest.php` (new) |

#### 7.2 Install Scripts
| File | Current | Target | Test File |
|------|---------|--------|-----------|
| `install.php` | Not measured | 70% | `tests/InstallScriptTest.php` (new) |
| `install2.php` | Not measured | 70% | `tests/InstallScriptTest.php` |
| `install3.php` | Not measured | 70% | `tests/InstallScriptTest.php` |

**Test Strategy:**
- Test routing dispatch
- Test middleware chain (TrustedProxies, CSP, Security headers)
- Test API endpoint handling
- Test installation flow (database setup, admin creation)
- Integration tests with real HTTP requests

**Prerequisites:** All previous stages

---

### Stage 8: Plugin & Theme Coverage (Week 9-10)
**Goal:** Cover plugin system and theme management  
**Target:** 85%+ on plugin/theme related code

#### 8.1 Plugin System
- Plugin installation/activation/deactivation
- Hook system (filters, actions)
- Dependency resolution
- Manifest validation

#### 8.2 Theme System
- Theme discovery/activation
- Template override resolution
- Asset handling
- CSS-only themes

**Prerequisites:** Stage 6 (lib/PluginManager, lib/ThemeManager)

---

### Stage 9: Remaining Gaps & Polish (Week 10-11)
**Goal:** Achieve 100% coverage on all measured files  
**Target:** 100% on src/, 95%+ on lib/, 80%+ on entry points

#### 9.1 Low Coverage Files in src/
| File | Current | Gap | Action |
|------|---------|-----|--------|
| `src/Renderer.php` | 54.8% | 19 lines | Stage 5 |
| `src/Request.php` | 78.4% | ~11 lines | Add edge case tests |
| `src/Response.php` | 71.4% | ~8 lines | Add header/cookie tests |
| `src/TrustedProxies.php` | 69.2% | ~4 lines | Add proxy header tests |
| `src/Errors.php` | 75% | ~4 lines | Add exception tests |
| `src/helpers.php` | 77.8% | ~2 lines | Add helper function tests |
| `src/csp.php` | 90.7% | ~4 lines | Add nonce/header tests |

#### 9.2 Helper Classes (Already High - Polish)
| File | Current | Target |
|------|---------|--------|
| `src/Helpers/Url.php` | 96.6% | 100% |
| `src/Helpers/Mail.php` | 94.1% | 100% |
| `src/Helpers/AuthHelpers.php` | 88.9% | 100% |
| `src/Helpers/Upload.php` | 86.2% | 100% |
| `src/Helpers/Text.php` | 80.5% | 100% |
| `src/Helpers/Data.php` | 71.4% | 95% |
| `src/Helpers/Notifications.php` | 76.9% | 95% |
| `src/Helpers/Avatar.php` | 82.4% | 95% |

---

## Dependency Ordering Summary

```
Stage 1: Foundation (setup, session, bootstrap, App)
    │
    ├──► Stage 2: AuthZ + User Actions (depends on Stage 1)
    │       │
    │       ├──► Stage 3: Posts/Threads (depends on Stage 2 AuthZ)
    │       │
    │       ├──► Stage 4: Dispatchers (depends on Stages 2-3 handlers)
    │       │
    │       ├──► Stage 5: Renderer (depends on Stage 1 bootstrap)
    │       │
    │       └──► Stage 6: lib/ Core (depends on Stage 1 DB, Stage 2 AuthZ)
    │               │
    │               ├──► Stage 7: Entry Points (depends on all above)
    │               │
    │               ├──► Stage 8: Plugins/Themes (depends on Stage 6)
    │               │
    │               └──► Stage 9: Polish (depends on all)
```

---

## Test Infrastructure Improvements Needed

### 1. Extend Coverage Collection (`tests/run.php`)
```php
// Add lib/ directory to coverage scanning
if (is_dir($root . '/lib')) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $real = str_replace('\\', '/', $file->getRealPath());
            $srcFiles[$real] = str_replace(str_replace('\\', '/', $root) . '/', '', $real);
        }
    }
}
```

### 2. Add Entry Point Coverage
```php
// Add root PHP files
$rootFiles = ['index.php', 'router.php', 'bb.php', 'api/install.php'];
foreach ($rootFiles as $file) {
    $path = $root . '/' . $file;
    if (file_exists($path)) {
        $real = str_replace('\\', '/', realpath($path));
        $srcFiles[$real] = $file;
    }
}
```

### 3. Test Helpers for Common Patterns
Create shared test utilities in `tests/helpers/`:
- `TestDatabase.php` - Fresh DB per test
- `TestApp.php` - App singleton with test config
- `TestAuth.php` - Pre-authenticated user sessions
- `TestPluginManager.php` - Mock plugin manager
- `TestRenderer.php` - Renderer with test views

### 4. CI/CD Integration
- Add coverage threshold gates (start at 85%, increase by 5% per stage)
- Fail build on coverage regression
- Generate coverage badges

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Tests become brittle with implementation changes | Test behavior, not implementation; use integration tests for critical flows |
| Database tests slow down suite | Use SQLite in-memory; parallelize test execution |
| Plugin/theme tests require filesystem | Use temp directories; cleanup in tearDown |
| Coverage tool misses dynamic includes | Ensure all files are required during test bootstrap |
| Legacy code hard to test | Add characterization tests first, then refactor |

---

## Success Metrics

| Milestone | Coverage Target | Files at 100% | Zero-Coverage Files |
|-----------|-----------------|---------------|---------------------|
| Current | 88.9% (src only) | 1 | 7 |
| Stage 1-2 Complete | 95% (src) | 5 | 0 |
| Stage 3-4 Complete | 97% (src) | 10 | 0 |
| Stage 5-6 Complete | 95% (src+lib) | 20 | 0 |
| Stage 7-8 Complete | 90% (all) | 30 | 0 |
| **Final Target** | **100% (src), 95% (lib), 80% (entry)** | **All critical** | **0** |

---

## Estimated Effort

| Stage | New Test Files | Existing Files Extended | Est. Tests Added |
|-------|----------------|------------------------|------------------|
| 1 | 2 | 1 | ~30 |
| 2 | 4 | 0 | ~80 |
| 3 | 2 | 1 | ~60 |
| 4 | 3 | 1 | ~40 |
| 5 | 6 | 1 | ~50 |
| 6 | 12 | 3 | ~150 |
| 7 | 2 | 0 | ~30 |
| 8 | 0 | 0 | ~40 |
| 9 | 0 | 10 | ~50 |
| **Total** | **~31** | **~17** | **~530** |

---

*This plan should be executed sequentially. Each stage builds on the previous. Adjust timelines based on team capacity and discovery during implementation.*