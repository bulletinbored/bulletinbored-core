# P2-1: Extract Duplicate UpdateManager Logic — Implementation Plan

**Based on:** REMEDIATION_PLAN.md (P2-1) and CODE_REVIEW_REPORT.md (Issue #9)
**Status:** Ready for implementation

---

## Problem

`UpdateManager::applyCoreUpdateFromZip()` (lines 332-406) and `UpdateManager::applyCoreUpdate()` (lines 408-506) share ~90% identical code for:
- ZIP extraction and validation
- Source directory detection (handles nested GitHub directory structure)
- Package structure validation (requires `index.php` and `VERSION`)
- Core backup creation
- Recursive file copy with rollback on failure
- Nested directory flattening (`bulletinbored-core-*`)
- Temp directory cleanup

---

## Proposed Solution

Create a private method `applyCoreUpdateFromSource(string $sourceDir, string $tag): bool` that encapsulates all shared logic. Both public methods delegate to it after obtaining/extracting their ZIP source.

### Method Signature

```php
private function applyCoreUpdateFromSource(string $sourceDir, string $tag): bool
```

**Parameters:**
- `$sourceDir` — Path to extracted source (pre-validated to contain `index.php` and `VERSION`)
- `$tag` — Version tag for logging, version tracking, and config updates

---

## Implementation Steps

### Step 1: Add Shared Private Method

Insert after `applyCoreUpdateFromZip()` (around line 406):

```php
private function applyCoreUpdateFromSource(string $sourceDir, string $tag): bool
{
    $backupPath = $this->backup->backupCore();
    if ($backupPath === null) {
        error_log('BB CORE FAIL: backup failed, aborting update');
        $this->backup->deleteRecursive($sourceDir);
        return false;
    }

    $root = rtrim(__DIR__ . '/../', '/');

    try {
        $this->backup->copyRecursive($sourceDir, $root);
    } catch (\Throwable $e) {
        if ($backupPath !== null) {
            $this->backup->restoreCoreBackup($backupPath);
        }
        $this->backup->deleteRecursive($sourceDir);
        error_log('BB CORE FAIL copy error: ' . $e->getMessage());
        return false;
    }
    $this->backup->deleteRecursive($sourceDir);

    // Flatten nested bulletinbored-core-* directories (GitHub ZIP structure)
    foreach (glob($root . '/bulletinbored-core-*', GLOB_ONLYDIR) as $nested) {
        foreach (glob($nested . '/*') as $item) {
            $base = basename($item);
            $dest = $root . '/' . $base;
            if (is_dir($item)) {
                $this->backup->copyRecursive($item, $dest);
            } elseif (is_file($item)) {
                if (is_dir($dest)) {
                    $this->backup->deleteRecursive($dest);
                }
                copy($item, $dest);
            }
        }
        $this->backup->deleteRecursive($nested);
    }

    $this->fetcher->clearCache();
    return true;
}
```

### Step 2: Refactor `applyCoreUpdateFromZip()`

Replace lines 367-405 with delegation:

```php
// ... existing code up to line 365 (structure validation) ...

$sourceDir = (count($topDirs) === 1) ? $topDirs[0] : $tmpExtract;
if (!file_exists($sourceDir . '/index.php') || !file_exists($sourceDir . '/VERSION')) {
    $this->backup->deleteRecursive($tmpExtract);
    error_log('BB CORE FAIL invalid package structure');
    return false;
}

// DELEGATE TO SHARED METHOD
if (!$this->applyCoreUpdateFromSource($sourceDir, 'uploaded')) {
    return false;
}

$this->fetcher->clearCache();
return true;
```

### Step 3: Refactor `applyCoreUpdate()`

Replace lines 456-491 with delegation:

```php
// ... existing code up to line 454 (structure validation) ...

$sourceDir = (count($topDirs) === 1) ? $topDirs[0] : $tmpExtract;
if (!file_exists($sourceDir . '/index.php') || !file_exists($sourceDir . '/VERSION')) {
    $this->backup->deleteRecursive($tmpExtract);
    error_log('BB CORE FAIL invalid package structure');
    return false;
}

// DELEGATE TO SHARED METHOD
if (!$this->applyCoreUpdateFromSource($sourceDir, $tag)) {
    return false;
}

// POST-PROCESSING SPECIFIC TO GITHUB TAG UPDATE
$this->removeInstallerScripts($root);
error_log('BB CORE extracted');

$this->setVersion('core', 'core', $tag);
$versionFile = __DIR__ . '/../VERSION';
if (is_writable($versionFile)) {
    file_put_contents($versionFile, $tag);
}

$this->updateVersionInConfig($tag);

$this->fetcher->clearCache();
return true;
```

---

## Verification Criteria

| Criterion | Verification Method |
|-----------|---------------------|
| Both update paths work | Run existing tests; manual test with uploaded ZIP and GitHub tag |
| Rollback on failure works | Test with corrupted ZIP / failed copy (existing tests cover this) |
| Installer scripts removed | Verify `install.php`, `install2.php`, `install3.php`, `api/install.php` deleted after `applyCoreUpdate()` |
| Version updated in config.json and VERSION file | Check files after `applyCoreUpdate()` |
| No duplicate code remains | Code inspection — shared logic only in `applyCoreUpdateFromSource()` |
| All existing tests pass | `php tests/run.php UpdateManagerTest` |

---

## Risk Assessment

**Risk: Low** — Pure refactoring with existing test coverage:
- `test_backup_creates_copy` / `test_restore_from_backup` (backup/restore)
- `test_nested_github_directory_flattened` (nested directory handling)
- `UpdateFailureModeTest.php` (failure paths)

**Mitigation:** Run full test suite after refactoring.

---

## Files Affected

- **`lib/UpdateManager.php`** — Only file modified (lines 332-506)

---

## Testing

```bash
# Run UpdateManager tests
php tests/run.php UpdateManagerTest

# Run all tests to ensure no regressions
php tests/run.php
```

---

## Open Questions

1. **Version tag for uploaded ZIPs**: Should `applyCoreUpdateFromZip()` accept an optional version parameter for tracking, or is it intentionally untracked?

2. **Error message consistency**: Should the shared method use a unified log prefix or preserve current distinction?

3. **New test for shared method**: Add a unit test specifically for `applyCoreUpdateFromSource()`, or are existing integration tests sufficient?

---

*Plan prepared for implementation — ready for Code mode handoff*