# P2-2: Add Return Type Hints - Implementation Plan

**Based on:** REMEDIATION_PLAN.md (P2-2) and CODE_REVIEW_REPORT.md (Issue #18)
**Status:** Ready for implementation

---

## Overview

Add missing return type declarations to helper functions across the codebase to improve static analysis (PHPStan) and code quality.

---

## Files to Modify & Return Types to Add

| File | Function | Current Signature | Proposed Return Type |
|------|----------|-------------------|---------------------|
| **src/helpers.php** | `base_url()` | `function base_url()` | `string` |
| **src/Helpers/AuthHelpers.php** | `is_admin()` | `function is_admin()` | `bool` |
| | `is_banned()` | `function is_banned()` | `bool` |
| | `is_suspended()` | `function is_suspended()` | `bool` |
| **src/Helpers/Url.php** | `slugify($text)` | `function slugify($text)` | `string` |
| | `url($action, $params = [], $absolute = false)` | `function url($action, $params = [], $absolute = false)` | `string` |
| | `current_route_action()` | `function current_route_action()` | `string` |
| **src/Helpers/Text.php** | `escape($s)` | `function escape($s)` | `string` |
| | `validate_input($data)` | `function validate_input($data)` | `mixed` (returns same type as input) |
| | `clean_text($data)` | `function clean_text($data)` | `string` |
| | `marked_parse($text)` | `function marked_parse($text)` | `string` |
| | `time_ago($datetime)` | `function time_ago($datetime)` | `string` |
| | `compact_number($n)` | `function compact_number($n)` | `string` |
| | `excerpt($text, $length = 110)` | `function excerpt($text, $length = 110)` | `string` |
| **src/Helpers/Upload.php** | `ensure_private_uploads_dir()` | `function ensure_private_uploads_dir(): string` | Already has `string` ✓ |
| | `validate_upload(...)` | `function validate_upload(...): ?array` | Already has `?array` ✓ |
| | `validate_uploaded_file(...)` | `function validate_uploaded_file(...): ?array` | Already has `?array` ✓ |
| | `get_uploaded_images()` | `function get_uploaded_images(): array` | Already has `array` ✓ |
| **src/Helpers/Mail.php** | `send_email($to, $subject, $body)` | `function send_email($to, $subject, $body)` | `bool` |
| **src/Helpers/Notifications.php** | `notify_thread_reply(...)` | `function notify_thread_reply(...): void` | Already has `void` ✓ |
| | `notify_admin_new_user(...)` | `function notify_admin_new_user($username, $email = '')` | `bool` |
| | `notify_mentioned_users(...)` | `function notify_mentioned_users(...): int` | Already has `int` ✓ |
| | `ensure_private_messages_table(...)` | `function ensure_private_messages_table($pdo)` | `void` |
| | `create_notification(...)` | `function create_notification(...): void` | Already has `void` ✓ |
| | `notification_label(...)` | `function notification_label(array $n): string` | Already has `string` ✓ |
| **src/Helpers/Avatar.php** | `avatar_initial($name)` | `function avatar_initial($name)` | `string` |
| | `avatar_color($name)` | `function avatar_color($name)` | `string` |
| | `render_avatar(...)` | `function render_avatar($username, $avatar = '', $size = 44, $class = '')` | `string` |
| **src/Helpers/Data.php** | `sidebar_categories()` | `function sidebar_categories()` | `array` |
| | `forum_statistics()` | `function forum_statistics()` | `array` |
| | `thread_sort_options()` | `function thread_sort_options()` | `array` |
| | `fetch_threads(array $opts = [])` | `function fetch_threads(array $opts = [])` | `array` |

---

## Key Observations

1. **Upload.php** - Already has return type hints on all functions ✓
2. **Notifications.php** - Most functions already have return types; only `notify_admin_new_user` and `ensure_private_messages_table` need them
3. **AuthHelpers.php** - `is_logged_in()` already has `bool` return type; `is_admin`, `is_banned`, `is_suspended` need them
4. **Text.php** - `validate_input()` returns `mixed` since it handles both arrays and strings recursively

---

## Implementation Order (Dependency-Aware)

1. **src/helpers.php** - `base_url()` (used by many other helpers)
2. **src/Helpers/Url.php** - `slugify()`, `url()`, `current_route_action()` (used by other helpers)
3. **src/Helpers/AuthHelpers.php** - `is_admin()`, `is_banned()`, `is_suspended()`
4. **src/Helpers/Text.php** - All functions
5. **src/Helpers/Avatar.php** - All functions
6. **src/Helpers/Mail.php** - `send_email()`
7. **src/Helpers/Notifications.php** - `notify_admin_new_user()`, `ensure_private_messages_table()`
8. **src/Helpers/Data.php** - All functions

---

## Verification Criteria (from REMEDIATION_PLAN.md)

- [ ] No type errors introduced
- [ ] Tests pass

---

## Risk Assessment

**Risk: Low** — This is purely additive type declarations. No logic changes. The main risk is accidentally adding incorrect types that would cause PHPStan errors, but since we're inferring types from actual return statements, this is minimal.

---

## Implementation Notes

### Parameter Types (Required)
Add parameter types to ALL function signatures for better static analysis:
- `base_url(): string` (no parameters)
- `is_admin(): bool` (no parameters)
- `is_banned(): bool` (no parameters)
- `is_suspended(): bool` (no parameters)
- `slugify(string $text): string`
- `url(string $action, array $params = [], bool $absolute = false): string`
- `current_route_action(): string` (no parameters)
- `escape(string $s): string`
- `validate_input(mixed $data): mixed` (recursive, returns same type as input)
- `clean_text(string $data): string`
- `marked_parse(string $text): string`
- `time_ago(string $datetime): string`
- `compact_number(int $n): string`
- `excerpt(string $text, int $length = 110): string`
- `send_email(string $to, string $subject, string $body): bool`
- `notify_admin_new_user(string $username, string $email = ''): bool`
- `ensure_private_messages_table(PDO $pdo): void`
- `avatar_initial(string $name): string`
- `avatar_color(string $name): string`
- `render_avatar(string $username, string $avatar = '', int $size = 44, string $class = ''): string`
- `sidebar_categories(): array` (no parameters)
- `forum_statistics(): array` (no parameters)
- `thread_sort_options(): array` (no parameters)
- `fetch_threads(array $opts = []): array`

### Special Cases
- `validate_input(mixed $data): mixed` - Returns `mixed` because it recursively processes arrays and returns the same type as input
- `base_url(): string` - Returns `string` (empty string if no base URL)
- Functions with no parameters: `base_url()`, `is_admin()`, `is_banned()`, `is_suspended()`, `current_route_action()`, `sidebar_categories()`, `forum_statistics()`, `thread_sort_options()`

---

## Testing Strategy

1. Run PHPStan at the project's configured level (or level 5) to verify no type errors
2. Run the full test suite (`php tests/run.php`) to ensure no regressions
3. Manual verification: check that all modified functions still work as expected

---

## Rollback Plan

If issues arise:
- Git revert the changes
- Each file can be reverted independently

---

*Plan prepared by Architecture Mode — ready for Code Mode implementation*