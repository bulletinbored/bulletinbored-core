<?php

/**
 * Security.php — security helper functions.
 *
 * CSRF protection, rate limiting, input validation, security logging.
 */

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
    $trustedProxies = $GLOBALS['config']['trusted_proxies'] ?? ['127.0.0.1', '::1'];
    foreach ((array)$trustedProxies as $proxy) {
        if (str_contains($proxy, '/')) {
            [$subnet, $mask] = explode('/', $proxy, 2);
            $ipLong = ip2long($remoteAddr);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);
            if ($ipLong !== false && $subnetLong !== false && ($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
                }
                break;
            }
        } elseif ($remoteAddr === $proxy) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }
            break;
        }
    }
    return $remoteAddr;
}

/**
 * File-based rate limiter (no dependencies).
 */
function rate_limit(string $action, int $max = 10, int $window = 300, ?string $key = null): bool
{
    $ip = rate_limit_client_ip();
    $key = $key ?? $ip;
    $bucket = preg_replace('/[^a-z0-9._-]/i', '_', $action . '_' . $key);

    $dir = __DIR__ . '/../data/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . $bucket . '.json';

    $now = time();
    $hits = [];
    if (is_file($file)) {
        $decoded = json_decode(@file_get_contents($file), true);
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, fn($ts) => is_int($ts) && ($now - $ts) < $window));
        }
    }

    if (count($hits) >= $max) {
        return false;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
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
