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
    $trustedProxies = $config['trusted_proxies'] ?? ['127.0.0.1', '::1'];
    foreach ((array)$trustedProxies as $proxy) {
        if (str_contains($proxy, '/')) {
            if (trusted_proxies_ip_in_cidr($remoteAddr, $proxy)) {
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
 * Uses atomic file locking to prevent race conditions under concurrency.
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

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return true;
    }
    if (flock($fp, LOCK_EX)) {
        $content = '';
        rewind($fp);
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, fn($ts) => is_int($ts) && ($now - $ts) < $window));
        }

        if (count($hits) >= $max) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        $hits[] = $now;
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($hits));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
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
