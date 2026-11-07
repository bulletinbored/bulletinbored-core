# P1-3: Make CSP Configurable — Implementation Plan

**Based on:** REMEDIATION_PLAN.md (P1-3) and CODE_REVIEW_REPORT.md (Issue #8)
**Status:** Ready for implementation
**Priority:** P1 — Significant Fix

---

## Problem Statement

The current CSP implementation in `src/csp.php` has a hardcoded Content-Security-Policy header that:
- Allows many external domains (Twitter, YouTube, Instagram, Facebook, CDNs)
- Includes `style-src-attr 'unsafe-inline'` which weakens CSP
- Is not customizable by administrators
- Cannot be adapted for different deployment requirements

---

## Current Implementation Analysis

### `src/csp.php` (lines 17-32)
```php
function send_security_headers(string $nonce): void {
    // ... other security headers ...
    if (!headers_sent()) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://platform.twitter.com https://cdn.syndication.twimg.com; style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; style-src-attr 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; img-src 'self' data: https: https://*.twimg.com; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://platform.twitter.com https://publish.twitter.com https://syndication.twitter.com https://www.instagram.com https://connect.facebook.net https://www.facebook.com https://facebook.com; connect-src 'self' https://publish.twitter.com https://platform.twitter.com https://syndication.twitter.com https://cdn.syndication.twimg.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}
```

### `src/bootstrap.php` (lines 35-38)
```php
require_once __DIR__ . '/csp.php';
$cspNonce = generate_csp_nonce();
send_security_headers($cspNonce);
```
**Issue:** CSP headers sent BEFORE config is loaded (config loads at line 148-164)

### Tests: `tests/SecurityHardeningTest.php`
- Verifies CSP headers present
- Verifies nonce generation
- Verifies no `unsafe-inline` in script-src

---

## Implementation Design

### 1. Configuration Structure

Add to `config-sample.json`:

```json
{
  "csp": {
    "default_src": ["'self'"],
    "script_src": ["'self'", "https://platform.twitter.com", "https://cdn.syndication.twimg.com"],
    "style_src": ["'self'", "https://cdn.jsdelivr.net", "https://fonts.googleapis.com", "https://cdnjs.cloudflare.com"],
    "style_src_attr": ["'unsafe-inline'"],
    "font_src": ["'self'", "https://fonts.gstatic.com", "https://cdn.jsdelivr.net", "https://cdnjs.cloudflare.com", "data:"],
    "img_src": ["'self'", "data:", "https:", "https://*.twimg.com"],
    "frame_src": ["https://www.youtube.com", "https://www.youtube-nocookie.com", "https://platform.twitter.com", "https://publish.twitter.com", "https://syndication.twitter.com", "https://www.instagram.com", "https://connect.facebook.net", "https://www.facebook.com", "https://facebook.com"],
    "connect_src": ["'self'", "https://publish.twitter.com", "https://platform.twitter.com", "https://syndication.twitter.com", "https://cdn.syndication.twimg.com"],
    "object_src": ["'none'"],
    "base_uri": ["'self'"],
    "form_action": ["'self'"],
    "frame_ancestors": ["'none'"]
  }
}
```

### 2. Secure Defaults (when no config provided)

All directives default to current hardcoded values. The following are **always** added automatically:
- `script_src`: `'nonce-{nonce}'` (prepended)
- `style_src`: `'nonce-{nonce}'` (prepended)

### 3. Validation Rules

| Directive | Validation |
|-----------|------------|
| `script_src` | Reject `unsafe-inline`, `unsafe-eval`, `wasm-unsafe-eval` — log warning, ignore value |
| `style_src` | Reject `unsafe-inline` — log warning, ignore value |
| All others | Accept any valid CSP source expression |

---

## Files to Modify

### 1. `config-sample.json`
- Add `csp` section with all directives matching current hardcoded values
- Include comments explaining each directive

### 2. `src/csp.php`
**New functions:**
- `build_csp_header(array $config, string $nonce): string` — builds CSP header from config
- `validate_csp_config(array $cspConfig): array` — validates and sanitizes CSP config

**Modified function:**
- `send_security_headers(string $nonce, array $config = []): void` — accepts optional config parameter

**Logic:**
```php
function send_security_headers(string $nonce, array $config = []): void {
    // ... other security headers (unchanged) ...
    
    if (!headers_sent()) {
        $cspHeader = build_csp_header($config, $nonce);
        header("Content-Security-Policy: $cspHeader");
    }
}

function build_csp_header(array $config, string $nonce): string {
    $cspConfig = $config['csp'] ?? [];
    $validated = validate_csp_config($cspConfig);
    
    // Secure defaults matching current hardcoded CSP
    $defaults = [
        'default_src' => ["'self'"],
        'script_src' => ["'self'", "https://platform.twitter.com", "https://cdn.syndication.twimg.com"],
        'style_src' => ["'self'", "https://cdn.jsdelivr.net", "https://fonts.googleapis.com", "https://cdnjs.cloudflare.com"],
        'style_src_attr' => ["'unsafe-inline'"],
        'font_src' => ["'self'", "https://fonts.gstatic.com", "https://cdn.jsdelivr.net", "https://cdnjs.cloudflare.com", "data:"],
        'img_src' => ["'self'", "data:", "https:", "https://*.twimg.com"],
        'frame_src' => ["https://www.youtube.com", "https://www.youtube-nocookie.com", "https://platform.twitter.com", "https://publish.twitter.com", "https://syndication.twitter.com", "https://www.instagram.com", "https://connect.facebook.net", "https://www.facebook.com", "https://facebook.com"],
        'connect_src' => ["'self'", "https://publish.twitter.com", "https://platform.twitter.com", "https://syndication.twitter.com", "https://cdn.syndication.twimg.com"],
        'object_src' => ["'none'"],
        'base_uri' => ["'self'"],
        'form_action' => ["'self'"],
        'frame_ancestors' => ["'none'"],
    ];
    
    // Merge config with defaults (config overrides defaults)
    $merged = array_merge_recursive($defaults, $validated);
    
    // Ensure nonce is always in script_src and style_src
    if (!in_array("'nonce-{$nonce}'", $merged['script_src'])) {
        array_unshift($merged['script_src'], "'nonce-{$nonce}'");
    }
    if (!in_array("'nonce-{$nonce}'", $merged['style_src'])) {
        array_unshift($merged['style_src'], "'nonce-{$nonce}'");
    }
    
    // Build directive strings
    $directives = [];
    foreach ($merged as $directive => $values) {
        $directiveName = str_replace('_', '-', $directive);
        $directives[] = "$directiveName " . implode(' ', $values);
    }
    
    return implode('; ', $directives);
}

function validate_csp_config(array $cspConfig): array {
    $validated = [];
    $dangerousScriptValues = ["'unsafe-inline'", "'unsafe-eval'", "'wasm-unsafe-eval'"];
    $dangerousStyleValues = ["'unsafe-inline'"];
    
    foreach ($cspConfig as $directive => $values) {
        if (!is_array($values)) {
            error_log("CSP config: directive '$directive' must be an array, ignoring");
            continue;
        }
        
        $cleanValues = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                error_log("CSP config: directive '$directive' contains non-string value, ignoring");
                continue;
            }
            
            // Validate script_src
            if ($directive === 'script_src' && in_array($value, $dangerousScriptValues, true)) {
                error_log("CSP config: '$value' not allowed in script_src for security, ignoring");
                continue;
            }
            
            // Validate style_src
            if ($directive === 'style_src' && in_array($value, $dangerousStyleValues, true)) {
                error_log("CSP config: '$value' not allowed in style_src for security, ignoring");
                continue;
            }
            
            $cleanValues[] = $value;
        }
        
        if (!empty($cleanValues)) {
            $validated[$directive] = $cleanValues;
        }
    }
    
    return $validated;
}
```

### 3. `src/bootstrap.php`
**Move CSP header sending to after config loading:**

```php
// ... existing code until after config is loaded (around line 164) ...

// Load configuration
$configPath = __DIR__ . '/../config.json';
// ... existing config loading logic ...

// --- Security headers (MOVED HERE - after config is available) ---
require_once __DIR__ . '/csp.php';
$cspNonce = generate_csp_nonce();
send_security_headers($cspNonce, $config);  // Pass config to CSP function

// --- Session setup ---
require_once __DIR__ . '/session_setup.php';
session_setup();

// ... rest of bootstrap ...
```

**Remove lines 35-38** (early CSP header sending)

### 4. `README.md`
Add CSP configuration documentation section:

```markdown
## Content Security Policy (CSP) Configuration

bulletinbored uses a restrictive Content Security Policy by default. The CSP can be customized via the `csp` section in `config.json`.

### Default CSP

The default policy allows:
- Scripts: Self + Twitter platform/syndication
- Styles: Self + jsDelivr, Google Fonts, Cloudflare CDN
- Fonts: Self + Google Fonts, jsDelivr, Cloudflare CDF, data URIs
- Images: Self + data URIs + any HTTPS + Twitter images
- Frames: YouTube, Twitter, Instagram, Facebook
- Connect: Self + Twitter APIs
- Objects: None
- Base URI: Self
- Form Action: Self
- Frame Ancestors: None

### Customizing CSP

Add a `csp` object to `config.json` with any of these directives:

```json
{
  "csp": {
    "script_src": ["'self'", "https://cdn.example.com"],
    "style_src": ["'self'", "https://fonts.googleapis.com"],
    "img_src": ["'self'", "data:", "https://cdn.example.com"],
    "frame_src": ["https://www.youtube.com"],
    "connect_src": ["'self'"]
  }
}
```

### Important Notes

1. **Nonce is automatic**: The `'nonce-{value}'` is automatically prepended to `script_src` and `style_src` — do not include it in your config.

2. **Security restrictions**: The following are **rejected** if specified in config (logged as warnings):
   - `unsafe-inline`, `unsafe-eval`, `wasm-unsafe-eval` in `script_src`
   - `unsafe-inline` in `style_src`

3. **Directive names**: Use snake_case in config (e.g., `script_src`), which maps to kebab-case in CSP header (e.g., `script-src`).

4. **Complete override**: Specifying a directive completely replaces the default for that directive. To keep defaults and add more, include the default values in your config.

### Example: Restrictive CSP

For maximum security, use a minimal CSP:

```json
{
  "csp": {
    "script_src": ["'self'"],
    "style_src": ["'self'"],
    "img_src": ["'self'", "data:"],
    "font_src": ["'self'"],
    "frame_src": [],
    "connect_src": ["'self'"]
  }
}
```

This blocks all external resources, inline scripts/styles (except those with valid nonce), and third-party frames.
```

---

## Implementation Steps

| Step | Task | Files | Verification |
|------|------|-------|--------------|
| 1 | Add CSP config to `config-sample.json` | `config-sample.json` | Config validates as JSON |
| 2 | Refactor `src/csp.php` with config-driven CSP | `src/csp.php` | Unit test: `build_csp_header()` produces expected output |
| 3 | Move CSP sending in `bootstrap.php` | `src/bootstrap.php` | Config available when CSP sent |
| 4 | Update `README.md` with CSP docs | `README.md` | Documentation renders correctly |
| 5 | Run existing tests | `tests/SecurityHardeningTest.php` | All tests pass |
| 6 | Manual verification | Browser dev tools | CSP header matches expected |

---

## Verification Criteria (from REMEDIATION_PLAN.md)

- [ ] **Default CSP (no config) matches current behavior** — Run tests, compare header output
- [ ] **Custom CSP config overrides specific directives** — Configure custom `script_src`, verify only those domains allowed
- [ ] **Invalid config falls back to secure defaults (logs warning)** — Add `unsafe-inline` to `script_src`, verify warning logged, value ignored
- [ ] **Nonce still works with custom CSP** — Verify `'nonce-{value}'` present in `script_src` and `style_src` with custom config
- [ ] **Test: configure restrictive CSP, verify inline scripts blocked** — Set minimal CSP, verify inline script without nonce fails

---

## Test Plan

### Automated Tests (extend `tests/SecurityHardeningTest.php`)

```php
function test_csp_default_matches_current(): Test {
    $t = new Test('CSP - Default matches current hardcoded');
    App::reset();
    $config = []; // No CSP config
    $nonce = generate_csp_nonce();
    $header = build_csp_header($config, $nonce);
    
    // Verify key directives present
    $t->assert('Has default-src self', str_contains($header, "default-src 'self'"));
    $t->assert('Has script-src with nonce', str_contains($header, "script-src 'self' 'nonce-{$nonce}'"));
    $t->assert('Has Twitter domains', str_contains($header, 'platform.twitter.com') && str_contains($header, 'cdn.syndication.twimg.com'));
    $t->assert('Has style-src with nonce', str_contains($header, "style-src 'self' 'nonce-{$nonce}'"));
    $t->assert('Has object-src none', str_contains($header, "object-src 'none'"));
    $t->assert('Has frame-ancestors none', str_contains($header, "frame-ancestors 'none'"));
    return $t;
}

function test_csp_custom_override(): Test {
    $t = new Test('CSP - Custom config overrides directives');
    App::reset();
    $config = ['csp' => ['script_src' => ["'self'", 'https://custom.example.com']]];
    $nonce = generate_csp_nonce();
    $header = build_csp_header($config, $nonce);
    
    $t->assert('Custom domain present', str_contains($header, 'custom.example.com'));
    $t->assert('Twitter domains absent', !str_contains($header, 'platform.twitter.com'));
    $t->assert('Nonce still present', str_contains($header, "'nonce-{$nonce}'"));
    return $t;
}

function test_csp_rejects_unsafe_inline_script(): Test {
    $t = new Test('CSP - Rejects unsafe-inline in script_src');
    App::reset();
    $config = ['csp' => ['script_src' => ["'self'", "'unsafe-inline'"]]];
    $nonce = generate_csp_nonce();
    
    // Capture error log
    $logOutput = '';
    $handler = function($level, $message) use (&$logOutput) { $logOutput .= $message; };
    set_error_handler($handler);
    
    $header = build_csp_header($config, $nonce);
    restore_error_handler();
    
    $t->assert('Warning logged', str_contains($logOutput, 'unsafe-inline') && str_contains($logOutput, 'script_src'));
    $t->assert('unsafe-inline not in header', !str_contains($header, "'unsafe-inline'"));
    $t->assert('Nonce still present', str_contains($header, "'nonce-{$nonce}'"));
    return $t;
}

function test_csp_restrictive_blocks_inline(): Test {
    $t = new Test('CSP - Restrictive config blocks inline scripts');
    App::reset();
    $config = ['csp' => [
        'script_src' => ["'self'"],
        'style_src' => ["'self'"],
        'img_src' => ["'self'", 'data:'],
        'font_src' => ["'self'"],
        'frame_src' => [],
        'connect_src' => ["'self'"],
    ]];
    $nonce = generate_csp_nonce();
    $header = build_csp_header($config, $nonce);
    
    $t->assert('Only self in script-src', str_contains($header, "script-src 'self' 'nonce-{$nonce}'") && substr_count($header, 'script-src') === 1);
    $t->assert('No external domains', !str_contains($header, 'twitter.com') && !str_contains($header, 'youtube.com'));
    $t->assert('frame-src empty', str_contains($header, "frame-src ") && !str_contains($header, "frame-src https://"));
    return $t;
}
```

### Manual Verification

1. **Default behavior**: Fresh install, no `csp` in config.json → CSP header matches current
2. **Custom config**: Add `csp.script_src` with single domain → Only that domain + nonce allowed
3. **Validation**: Add `unsafe-inline` to `script_src` → Check PHP error log for warning
4. **Nonce**: Verify nonce changes per request and is in CSP header
5. **Restrictive**: Set minimal CSP → Browser console shows blocked inline scripts

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Breaking existing CSP | Low | High | Default config matches current exactly; tests verify |
| Config validation too strict | Low | Medium | Only rejects known-dangerous values; logs warnings |
| Nonce not included | Low | High | Automatically prepended in `build_csp_header()` |
| Bootstrap timing issues | Medium | Medium | Move CSP sending after config load; test thoroughly |

**Overall Risk: Low** — Configuration-driven, backward compatible, secure defaults

---

## Dependencies

- **None** — Can be implemented independently
- **No database changes** required
- **No migration** needed

---

## Rollback Plan

1. Git revert changes to `src/csp.php`, `src/bootstrap.php`, `config-sample.json`, `README.md`
2. No database changes to roll back
3. Feature flag not needed — config is optional

---

## Success Metrics

| Metric | Target |
|--------|--------|
| Default CSP matches current | 100% header match |
| Custom directive override | Verified via test |
| Invalid config handling | Warning logged, secure default used |
| Nonce auto-inclusion | Present in all script_src/style_src |
| Test coverage | 4 new test cases passing |
| Documentation | CSP config documented in README |

---

## Implementation Order

1. **Create plan document** (this file) ✓
2. **Update `config-sample.json`** with CSP example
3. **Refactor `src/csp.php`** with `build_csp_header()`, `validate_csp_config()`, updated `send_security_headers()`
4. **Update `src/bootstrap.php`** — move CSP sending after config load
5. **Update `README.md`** with CSP configuration documentation
6. **Add tests** to `tests/SecurityHardeningTest.php`
7. **Run test suite** — verify all pass
8. **Manual verification** — browser dev tools, error log check

---

## Questions Resolved

| Question | Decision |
|----------|----------|
| Config loading timing | Move CSP sending to after config load in bootstrap.php |
| Directive completeness | Support all currently-used directives; extensible for future |
| Validation strictness | Log warning, ignore dangerous values, use secure defaults |
| Testing approach | Extend existing `SecurityHardeningTest.php` |

---
