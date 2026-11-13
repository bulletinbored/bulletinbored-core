<?php

/**
 * Security.php — security helper functions.
 *
 * CSRF protection, rate limiting, input validation, security logging.
 */

require_once __DIR__ . '/App.php';

function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token(string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_validate_request(): bool
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token($token)) {
        return false;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

function csrf_field(): string
{
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . escape($token) . '">';
}

/**
 * Get the client IP address, respecting trusted proxies.
 */
function rate_limit_client_ip(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $config = App::getInstance()->config;
    $trusted = (array)($config['trusted_proxies'] ?? ['127.0.0.1', '::1']);

    $isTrusted = false;
    foreach ($trusted as $proxy) {
        if (str_contains($proxy, '/')) {
            if (trusted_proxies_ip_in_cidr($remoteAddr, $proxy)) {
                $isTrusted = true;
                break;
            }
        } elseif ($remoteAddr === $proxy) {
            $isTrusted = true;
            break;
        }
    }

    if (!$isTrusted || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $remoteAddr;
    }

    // Assumption: the trusted proxy records the client as the first value of
    // X-Forwarded-For (client, proxy1, proxy2). Only the first syntactically
    // valid address is used. If you chain several proxies, list every hop in
    // `trusted_proxies` and ensure the front proxy normalises/appends the
    // header as expected — see the Security Model documentation.
    foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }

    return $remoteAddr;
}

/**
 * File-based rate limiter (no dependencies).
 * Uses atomic file locking to prevent race conditions under concurrency.
 */
/**
 * Is the locked handle still the file that lives at $path?
 *
 * Guards the window between fopen() and flock(): if the garbage collector
 * unlinked (or another process replaced) the bucket in between, the handle
 * points at an orphaned inode and writing there would lose the hit.
 */
function rate_limit_bucket_is_current(string $file, $handle): bool
{
    // stat() results are cached per request: without this, a stat() performed
    // before the GC ran would make the check compare against stale data.
    clearstatcache(true, $file);
    $fpStat = @fstat($handle);
    $pathStat = @stat($file);
    if ($pathStat === false) {
        return false; // unlinked
    }
    if (!is_array($fpStat)) {
        return true; // cannot compare: assume the normal case
    }
    $fpIno = (int)($fpStat['ino'] ?? 0);
    $pathIno = (int)($pathStat['ino'] ?? 0);
    if ($fpIno > 0 && $pathIno > 0 && $fpIno !== $pathIno) {
        return false; // the name now points at a different file
    }
    return true;
}

/**
 * Delete rate-limit buckets that have not been touched for $maxAge seconds.
 * Called opportunistically by rate_limit() (one file per action+key would
 * otherwise accumulate forever). Returns the number of files removed.
 *
 * The unlink happens while holding the same lock rate_limit() uses, and the
 * age is re-checked after acquiring it: otherwise a bucket could be removed
 * while a request is writing to it, losing that hit (the writer would write to
 * an unlinked inode and the next request would start from a fresh counter).
 * A bucket that is currently locked is skipped (LOCK_NB) and retried on the
 * next pass, so the GC never stalls a request.
 *
 * $maxAge must stay well above the largest rate-limit window, otherwise
 * removing an expired-by-age bucket could drop timestamps that are still
 * inside its window.
 */
function rate_limit_gc(string $dir, int $maxAge = 86400): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $now = time();
    $removed = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        // 'r+' (not 'c+'): never recreate a bucket that disappeared meanwhile.
        $fp = @fopen($file, 'r+');
        if ($fp === false) {
            continue;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp); // in use right now: leave it for the next pass
            continue;
        }

        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAge && @unlink($file)) {
            $removed++;
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $removed;
}

/**
 * Actions that must fail closed when the rate limiter cannot operate
 * (authentication, account changes, destructive and admin operations).
 */
function rate_limit_sensitive_actions(): array
{
    return [
        'login', 'register', 'forgot_password', 'reset_password',
        'edit_profile', 'remove_avatar', 'upload_image', 'editbored_upload', 'logout_others',
        'edit_post', 'delete_post', 'edit_thread', 'delete_thread',
        'admin_ban_user', 'admin_unban_user', 'admin_suspend_user',
        'admin_create_user', 'admin_delete_user', 'admin_user_edit', 'admin_roles_action',
        'admin_disconnect_user',
        'admin_categories', 'admin_delete_category', 'admin_category_order',
        'admin_dashboard_settings', 'admin_settings', 'admin_smtp', 'admin_upload_site_image',
        'admin_catalog', 'admin_langs', 'admin_moderate', 'admin_front_moderate',
        'admin_split_thread', 'admin_merge_thread', 'admin_plugins', 'admin_themes',
        'admin_updates_apply', 'admin_updates_apply_all', 'admin_updates_check', 'admin_install',
    ];
}

function rate_limit(string $action, int $max = 10, int $window = 300, ?string $key = null): bool
{
    $ip = rate_limit_client_ip();
    $key = $key ?? $ip;
    $bucket = preg_replace('/[^a-z0-9._-]/i', '_', $action . '_' . $key);

    $dir = __DIR__ . '/../data/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Opportunistic cleanup (~0.5% of calls) so the bucket directory does not
    // grow without bound: one file per action+key accumulates indefinitely.
    if (random_int(1, 200) === 1) {
        rate_limit_gc($dir);
    }
    $file = $dir . '/' . $bucket . '.json';

    // Sensitive actions fail closed: if the limiter cannot operate, deny the
    // request instead of silently allowing it. Non-sensitive actions stay
    // available so a transient storage problem does not break the whole forum.
    $failClosed = in_array($action, rate_limit_sensitive_actions(), true);
    $onFailure = !$failClosed;

    $now = time();
    $hits = [];

    $fp = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            break;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $fp = false;
            break;
        }

        // The garbage collector unlinks expired buckets (holding this same
        // lock). If that happened between our fopen() and flock(), we are
        // holding a lock on an unlinked inode: writing there would silently
        // lose the hit. Detect it and retry on the fresh file.
        if (rate_limit_bucket_is_current($file, $fp)) {
            break;
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        $fp = false;
        clearstatcache(true, $file);
    }

    if ($fp === false) {
        log_security_event('rate_limit_file_fail', ['action' => $action, 'key' => $key]);
        return $onFailure;
    }

    $content = '';
    rewind($fp);
    while (!feof($fp)) {
        $content .= fread($fp, 8192);
    }

    $decoded = json_decode($content, true);
    if ($content !== '' && !is_array($decoded)) {
        // Corrupt bucket: self-heal for non-sensitive actions, but refuse
        // sensitive ones instead of silently resetting the counter.
        flock($fp, LOCK_UN);
        fclose($fp);
        log_security_event('rate_limit_corrupt', ['action' => $action, 'key' => $key]);
        return $onFailure;
    }
    if (is_array($decoded)) {
        $hits = array_values(array_filter($decoded, fn($ts) => is_int($ts) && ($now - $ts) < $window));
    }

    if (count($hits) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $hits[] = $now;
    $json = json_encode($hits);
    rewind($fp);
    // Persist the bucket, verifying each step. If the write fails (disk full,
    // read-only file, ...) the hit is not recorded, so fail closed for
    // sensitive actions instead of silently allowing the request.
    $written = ($json !== false)
        && ftruncate($fp, 0)
        && (fwrite($fp, $json) === strlen($json))
        && fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!$written) {
        log_security_event('rate_limit_write_fail', ['action' => $action, 'key' => $key]);
        return $onFailure;
    }
    return true;
}

function log_security_event(string $event, array $context = []): void
{
    static $logDir = null;
    if ($logDir === null) {
        $logDir = __DIR__ . '/../data/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    if (!is_dir($logDir) || !is_writable($logDir)) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $line = sprintf(
        "[%s] %s ip=%s %s\n",
        date('c'),
        $event,
        $ip,
        json_encode($context, JSON_UNESCAPED_UNICODE)
    );
    @file_put_contents($logDir . '/security.log', $line, FILE_APPEND | LOCK_EX);
}

function log_admin_action(string $action, array $context = []): void
{
    $userId = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? 'unknown';
    $ctx = array_merge(['admin_id' => $userId, 'admin_user' => $username], $context);
    log_security_event('admin_' . $action, $ctx);
}
