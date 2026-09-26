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
 * Delete rate-limit buckets that have not been touched for $maxAge seconds.
 * Called opportunistically by rate_limit() (one file per action+key would
 * otherwise accumulate forever). Returns the number of files removed.
 */
function rate_limit_gc(string $dir, int $maxAge = 86400): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $now = time();
    $removed = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAge) {
            if (@unlink($file)) {
                $removed++;
            }
        }
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
        'edit_profile', 'remove_avatar', 'upload_image', 'editbored_upload',
        'edit_post', 'delete_post', 'edit_thread', 'delete_thread',
        'admin_ban_user', 'admin_unban_user', 'admin_suspend_user',
        'admin_create_user', 'admin_delete_user', 'admin_user_edit', 'admin_roles_action',
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

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        log_security_event('rate_limit_file_fail', ['action' => $action, 'key' => $key]);
        return $onFailure;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        log_security_event('rate_limit_lock_fail', ['action' => $action, 'key' => $key]);
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
