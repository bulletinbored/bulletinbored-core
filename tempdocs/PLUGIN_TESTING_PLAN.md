# Plugin Testing Plan After Phase 1 Remediation

## Overview
This document outlines the testing strategy to verify that plugins continue to work correctly after Phase 1 remediation changes:
1. **P0-1**: Eliminated global `$pluginManager` - AuthZ now uses dependency injection
2. **P0-2**: Added foreign keys to initial schema + enabled SQLite FKs
3. **P0-3**: Cached session version - `is_logged_in()` no longer queries DB

## Affected Plugin Integration Points

### 1. AuthZ Permission Hooks (`permission_{name}`)
**Change**: AuthZ now receives PluginManager via constructor instead of global
**Risk**: Plugins using `permission_` hooks for custom permissions
**Files**: `lib/AuthZ.php:85-88`

### 2. Authentication Hooks
- `auth_before_verify` - filters user before password verification
- `auth_login_block` - can block login after successful password check
- `auth_after_login` - runs after successful login
**Risk**: Plugins using these hooks for custom auth flows
**Files**: `src/actions/users.php:60-62, 65-69, 94-96`

### 3. Session Version Handling
**Change**: `is_logged_in()` trusts session-stored version, no DB query
**Risk**: Plugins that manually manipulate `session_version` in DB expecting immediate invalidation
**Files**: `src/Helpers/AuthHelpers.php:7-21`

### 4. Database Schema (Foreign Keys)
**Change**: FK constraints added to initial schema
**Risk**: Plugins creating tables with FK references to core tables; plugins doing bulk operations that might violate FKs
**Files**: `migrations/20261107_add_foreign_keys_initial.php`

### 5. PluginManager DI in AuthZ
**Change**: AuthZ constructor accepts optional PluginManager
**Risk**: Plugins that instantiate AuthZ directly (unlikely but possible)
**Files**: `lib/AuthZ.php:56-59`

---

## Test Scenarios

### A. Automated Unit/Integration Tests (Add to Test Suite)

#### A1. AuthZ with PluginManager - Permission Hooks
```php
// tests/PluginAuthZIntegrationTest.php
function test_authz_permission_hook_with_plugin(): Test {
    $pdo = create_test_pdo();
    create_test_schema($pdo);
    
    // Mock PluginManager with checkHook
    $mockPluginManager = new class {
        public function checkHook(string $hook, string $role): bool {
            return $hook === 'permission_custom_action' && $role === 'user';
        }
    };
    
    $authz = new AuthZ($pdo, $mockPluginManager);
    
    // User without DB permission but with plugin permission
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'test', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]')");
    
    $t = new Test('AuthZ - Plugin permission hook');
    $t->assertTrue('Plugin permission granted via hook', $authz->can(1, 'custom_action'));
    return $t;
}
```

#### A2. AuthZ without PluginManager (Backward Compatibility)
```php
function test_authz_without_pluginmanager(): Test {
    $pdo = create_test_pdo();
    create_test_schema($pdo);
    
    $authz = new AuthZ($pdo); // No PluginManager
    
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'test', 'user')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"posts.create\"]')");
    
    $t = new Test('AuthZ - Works without PluginManager');
    $t->assertTrue('Core permission works', $authz->can(1, 'posts.create'));
    $t->assertFalse('No plugin permission without PluginManager', $authz->can(1, 'custom_action'));
    return $t;
}
```

#### A3. Auth Hooks Still Execute
```php
function test_auth_hooks_execute(): Test {
    $pdo = create_test_pdo();
    create_test_schema($pdo);
    
    $pluginManager = new PluginManager(__DIR__ . '/../plugins', __DIR__ . '/../data/plugins.json');
    $pluginManager->discover();
    
    // Register test hooks
    $pluginManager->addHook('auth_before_verify', function($user, $username, $password) {
        return $user; // Pass through
    });
    $pluginManager->addHook('auth_login_block', function($user) {
        return false; // Don't block
    });
    $pluginManager->addHook('auth_after_login', function($userId, $user) {
        // Track that hook ran
        $_SESSION['auth_after_login_ran'] = true;
    });
    
    $pluginManager->loadEnabled();
    
    // Simulate login flow
    // ... test that hooks are called
}
```

#### A4. Session Version - Plugin Manual Invalidation
```php
function test_plugin_can_invalidate_sessions(): Test {
    // Plugin should update BOTH DB and session for immediate effect
    $pdo = create_test_pdo();
    create_test_schema($pdo);
    
    $userId = create_user_session($pdo, 'testuser', 'user');
    $_SESSION = ['user_id' => $userId, 'session_version' => 1];
    
    // Plugin invalidates session
    $pdo->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([$userId]);
    $_SESSION['session_version'] = 2; // Plugin MUST also update session
    
    $t = new Test('Plugin session invalidation');
    $t->assertTrue('Session still valid after manual sync', is_logged_in());
    
    // If plugin only updates DB but not session
    $pdo->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([$userId]);
    // $_SESSION['session_version'] NOT updated
    
    $t->assertTrue('Session still valid (trusts session)', is_logged_in());
    $t->assertComment('Note: Plugin MUST update both DB and session for immediate invalidation');
    return $t;
}
```

#### A5. Foreign Keys - Plugin Table References
```php
function test_plugin_can_create_fk_to_core_tables(): Test {
    $pdo = create_test_pdo();
    create_test_schema($pdo);
    
    // Plugin creates table with FK to core tables
    $pdo->exec("
        CREATE TABLE plugin_custom_data (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            thread_id INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
        )
    ");
    
    // Insert core data
    $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'user1')");
    $pdo->exec("INSERT INTO threads (id, user_id, title) VALUES (1, 1, 'Thread')");
    
    // Insert plugin data
    $pdo->exec("INSERT INTO plugin_custom_data (user_id, thread_id) VALUES (1, 1)");
    
    // Delete core - should cascade
    $pdo->exec("DELETE FROM users WHERE id = 1");
    
    $count = $pdo->query("SELECT COUNT(*) FROM plugin_custom_data")->fetchColumn();
    
    $t = new Test('Plugin FK to core tables');
    $t->assertEquals('Plugin data cascaded on user delete', 0, $count);
    return $t;
}
```

### B. Manual Testing Procedures

#### B1. Core Plugin Installation/Update
1. Install official plugins from catalog:
   - `editbored` (editor)
   - `bellbored` (notifications)
   - `hellobored` (example)
   - `textmebored` (private messages)
   - `updownbored` (voting)
   - `sitemapbored` (sitemap)
2. Verify each installs without error
3. Verify each enables/disables correctly
4. Verify each uninstalls cleanly

#### B2. Plugin Permission System
1. Install a plugin that adds custom permissions (if any exist)
2. Create test users with different roles
3. Verify permission checks work via `AuthZ::can()`
4. Verify `canOnOwned()` works with plugin permissions

#### B3. Authentication Flow
1. Test login with plugins enabled
2. Test registration with plugins enabled
3. Test password reset with plugins enabled
4. Verify `auth_before_verify`, `auth_login_block`, `auth_after_login` hooks fire

#### B4. Session Management
1. Login as user
2. Change password via profile → verify other sessions invalidated
3. Admin changes user password → verify user's sessions invalidated
4. Test "remember me" / long-lived sessions (if applicable)

#### B5. Plugin Lifecycle Hooks
For each plugin, verify:
- `on_install` runs on install
- `on_update` runs on update
- `on_uninstall` runs on uninstall
- `on_enable` / `on_disable` run correctly

#### B6. Admin Panel with Plugins
1. Access admin panel with plugins enabled
2. Verify plugin settings pages load
3. Verify plugin management (enable/disable/update) works
4. Check admin diagnostics with plugins

### C. Regression Test Checklist

| Area | Test | Expected |
|------|------|----------|
| **AuthZ DI** | AuthZ instantiated without PluginManager | Works, no errors |
| **AuthZ DI** | AuthZ instantiated with PluginManager | Works, hooks called |
| **Auth Hooks** | `auth_before_verify` filters user | Hook executes |
| **Auth Hooks** | `auth_login_block` can block login | Hook executes |
| **Auth Hooks** | `auth_after_login` runs after login | Hook executes |
| **Permissions** | Plugin `permission_` hooks work | Custom permissions granted |
| **Session** | `is_logged_in()` no DB query | Fast, trusts session |
| **Session** | Password change invalidates sessions | DB + session updated |
| **Session** | Admin password change invalidates user | DB + session updated |
| **FKs** | Plugin tables with FKs to core | FKs enforced |
| **FKs** | Core delete cascades to plugin tables | Cascade works |
| **Install** | All official plugins install | No errors |
| **Enable** | Plugins enable/disable | State persists |
| **Update** | Plugin updates work | No data loss |
| **Uninstall** | Plugins uninstall cleanly | No orphan data |

---

## Test Execution Plan

### Phase 1: Automated Tests (Immediate)
- [ ] Add `PluginAuthZIntegrationTest.php` to test suite
- [ ] Run full test suite: `php tests/run.php`
- [ ] Verify 0 failures

### Phase 2: Manual Plugin Testing (Per Plugin)
- [ ] editbored
- [ ] bellbored
- [ ] hellobored
- [ ] textmebored
- [ ] updownbored
- [ ] sitemapbored

### Phase 3: Edge Cases
- [ ] Plugin with custom permissions
- [ ] Plugin with custom tables referencing core
- [ ] Plugin using auth hooks
- [ ] Multiple plugins enabled simultaneously

### Phase 4: Performance
- [ ] Measure `is_logged_in()` performance (should be faster)
- [ ] Verify no N+1 queries introduced

---

## Rollback Criteria

If any of the following occur, halt and investigate:
1. Any existing test fails
2. Plugin install/update/uninstall fails
3. AuthZ throws errors when PluginManager not provided
4. Session invalidation doesn't work as expected
5. FK constraint violations on legitimate operations

---

## Documentation Updates Needed

1. **Plugin Development Guide**: Document that AuthZ now uses DI
2. **Session Management**: Document that plugins must update BOTH DB and session for immediate invalidation
3. **Database Schema**: Document new FK constraints for plugin developers
4. **Auth Hooks**: Document hook signatures and expected return values

---

## Success Metrics

| Metric | Target |
|--------|--------|
| Automated tests pass | 100% |
| Official plugins install/enable | 6/6 |
| Manual test scenarios pass | 100% |
| No regression in auth flow | Verified |
| Session invalidation works | Verified |
| FK constraints don't break plugins | Verified |