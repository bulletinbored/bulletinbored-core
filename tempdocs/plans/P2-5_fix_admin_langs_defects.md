# Remediation Plan: Fix Defects in src/actions/admin/langs.php

## Problem Statement

The code review identified three defects in `src/actions/admin/langs.php`:

1. **Scoping Defect (Medium)**: `saveLangMeta()` declares `global $langMetaPath` but `$langMetaPath` is a local variable in `handle_admin_langs()`, not a global variable.

2. **Broken Candidate URL Fallback (Medium)**: When `$downloadUrl` doesn't end in `.php`, the fallback generates a `.php` URL, but the subsequent loop filters for `.json` URLs only, causing the fallback to be unconditionally skipped.

3. **Output Buffering Cleanup Vulnerability (Low)**: `ob_start()` is invoked before remote retrieval, but exceptions thrown during validation or HTTP fetching can leave output buffers uncleared.

## Root Cause Analysis

### Issue 1: Scoping Defect
- **Location**: Line 22 in `src/actions/admin/langs.php`
- **Cause**: The nested function `saveLangMeta()` uses `global $langMetaPath` expecting to access the `$langMetaPath` variable from the parent function `handle_admin_langs()`. However, PHP's `global` keyword only accesses variables in the global scope, not the parent function's local scope.
- **Impact**: `$langMetaPath` resolves to `null` or undefined, causing the fallback path on line 23 to always be used.

### Issue 2: Broken Candidate URL Fallback
- **Location**: Lines 132-137 in `src/actions/admin/langs.php`
- **Cause**: The logic assumes that if the URL doesn't end in `.php`, it must end in `.json`, and generates a `.php` fallback. However, the loop at line 141 filters with `if (!str_ends_with($tryUrl, '.json')) continue;`, which skips the `.php` fallback entirely.
- **Impact**: For JSON URLs (the common case), no fallback is attempted if the primary URL fails.

### Issue 3: Output Buffering Cleanup
- **Location**: Line 102 in `src/actions/admin/langs.php`
- **Cause**: `ob_start()` is called before URL validation and HTTP fetching. If an exception is thrown (e.g., CSRF validation failure, rate limit exceeded, or URL validation failure), the output buffer is never cleaned up.
- **Impact**: Potential memory leaks and corrupted output if exceptions propagate.

## Architectural Remediation Plan

### Fix 1: Pass `$langMetaPath` as Parameter (Preferred)

**Approach**: Modify `saveLangMeta()` to accept `$langMetaPath` as a parameter instead of relying on `global`.

**Rationale**: 
- Aligns with the project's pattern of explicit dependency passing (see `handle_admin_plugins()` and `handle_admin_themes()` which receive dependencies via `global` but pass them explicitly to managers)
- Eliminates scope confusion
- Makes the function testable and reusable

**Implementation**:
```php
function saveLangMeta(string $code, string $sha, string $langMetaPath): void {
    $meta = loadLangMeta($langMetaPath);
    $meta[$code] = ['sha' => $sha, 'updated' => date('c')];
    @file_put_contents($langMetaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
```

**Call site update** (line 164):
```php
saveLangMeta($langCode, $remoteSha, $langMetaPath);
```

### Fix 2: Correct Candidate URL Fallback Logic

**Approach**: Generate fallback URLs based on the actual extension of the primary URL.

**Rationale**:
- The current logic incorrectly assumes a binary choice between `.php` and `.json`
- The fix should try both extensions regardless of the primary URL's extension
- This matches the intent: try the given URL, then try the alternative extension

**Implementation**:
```php
$candidateUrls = [$downloadUrl];
$ext = pathinfo($downloadUrl, PATHINFO_EXTENSION);
if (strtolower($ext) === 'php') {
    $candidateUrls[] = substr($downloadUrl, 0, -4) . '.json';
} elseif (strtolower($ext) === 'json') {
    $candidateUrls[] = substr($downloadUrl, 0, -5) . '.php';
} else {
    // Unknown extension: try both common variants
    $candidateUrls[] = $downloadUrl . '.json';
    $candidateUrls[] = $downloadUrl . '.php';
}
```

**Alternative simpler approach** (if we only care about JSON):
```php
$candidateUrls = [$downloadUrl];
// Always try .json variant if not already .json
if (!str_ends_with(strtolower($downloadUrl), '.json')) {
    $candidateUrls[] = preg_replace('#\.php$#i', '.json', $downloadUrl);
    if (!str_ends_with(strtolower($downloadUrl), '.php')) {
        $candidateUrls[] = $downloadUrl . '.json';
    }
}
```

### Fix 3: Ensure Output Buffer Cleanup on All Paths

**Approach**: Use `try/finally` or restructure to guarantee `ob_end_clean()` is called.

**Rationale**:
- The project uses exceptions for control flow (CSRF validation, rate limiting throw exceptions)
- Current code only cleans up on success path (line 166) and error path (lines 173-174)
- Exceptions thrown before line 173 bypass cleanup

**Implementation**: Wrap the critical section in `try/finally`:
```php
ob_start();
$prevDisplayErrors = ini_get('display_errors');
@ini_set('display_errors', '0');

try {
    // ... validation and fetching logic ...
} finally {
    @ini_set('display_errors', $prevDisplayErrors);
    ob_end_clean();
}
```

## Verification Criteria

1. **Fix 1 Verification**: 
   - `saveLangMeta()` called with explicit `$langMetaPath` parameter
   - Language metadata correctly written to `data/lang-meta.json`
   - No "undefined variable" notices

2. **Fix 2 Verification**:
   - Install/update from GitHub URL works for both `.json` and `.php` source URLs
   - Fallback is attempted when primary URL fails
   - No infinite loops or duplicate attempts

3. **Fix 3 Verification**:
   - No output buffer leaks when exceptions are thrown
   - `display_errors` restored to original value on all code paths
   - No "ob_end_clean(): failed to delete buffer" warnings

## Implementation Sequence

1. **Phase 1**: Fix scoping defect (Fix 1) - lowest risk, isolated change
2. **Phase 2**: Fix candidate URL fallback (Fix 2) - medium risk, affects install/update flow
3. **Phase 3**: Fix output buffering (Fix 3) - medium risk, affects error handling

Each phase should be tested independently before proceeding.

## Architectural Implications

- **No breaking changes**: All fixes are internal to `handle_admin_langs()`
- **No API changes**: Function signatures for external callers unchanged
- **Improved testability**: `saveLangMeta()` can now be unit tested with different paths
- **Consistency**: Aligns with project pattern of explicit parameter passing over `global` for nested functions
- **Security**: No security implications; fixes improve reliability

## Risk Assessment

| Fix | Risk Level | Rollback Complexity |
|-----|------------|---------------------|
| Fix 1 (scoping) | Low | Trivial (revert parameter) |
| Fix 2 (fallback) | Medium | Trivial (revert logic) |
| Fix 3 (buffering) | Medium | Trivial (remove try/finally) |

## Dependencies

- No external dependencies
- No database schema changes
- No configuration changes required
- No migration needed