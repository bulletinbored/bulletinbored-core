<?php

/**
 * TrustedProxies.php — detect client IP behind reverse proxies.
 *
 * X-Forwarded-* headers are only trusted when the request comes from a known
 * proxy (load balancer, CDN, reverse proxy).
 *
 * Supports both IPv4 and IPv6 addresses and CIDR notation.
 */

require_once __DIR__ . '/App.php';

/**
 * Is the current request served over HTTPS?
 *
 * Considers the direct connection and the forwarded headers already validated
 * by trusted_proxies_detect(). The resolved value is what HSTS and the
 * HTTPS redirect must use: behind a proxy PHP sees plain HTTP even though the
 * visitor is on HTTPS, and deciding from $_SERVER['HTTPS'] alone would drop
 * HSTS on those installations.
 *
 * @param array|null $proxyInfo Result of trusted_proxies_detect(); detected
 *                              when omitted.
 */
function request_is_https(?array $proxyInfo = null): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    $info = $proxyInfo ?? trusted_proxies_detect();
    if (($info['forwarded_proto'] ?? null) === 'https') {
        return true;
    }
    return ($info['forwarded_ssl'] ?? null) === 'on';
}

function trusted_proxies_detect(): array
{
    $config = App::getInstance()->config;
    $trustedProxies = $config['trusted_proxies'] ?? ['127.0.0.1', '::1'];
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $isTrusted = false;

    foreach ((array)$trustedProxies as $proxy) {
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

    $forwardedProto = ($isTrusted && isset($_SERVER['HTTP_X_FORWARDED_PROTO']))
        ? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) : null;
    $forwardedSsl = ($isTrusted && isset($_SERVER['HTTP_X_FORWARDED_SSL']))
        ? strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) : null;
    $forwardedFor = null;

    if ($isTrusted && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'];
        if (str_contains($forwardedFor, ',')) {
            $forwardedFor = trim(explode(',', $forwardedFor)[0]);
        }
    }

    return [
        'is_trusted' => $isTrusted,
        'forwarded_proto' => $forwardedProto,
        'forwarded_ssl' => $forwardedSsl,
        'forwarded_for' => $forwardedFor,
    ];
}

/**
 * Check if an IP address is within a CIDR range.
 * Supports both IPv4 and IPv6.
 */
function trusted_proxies_ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;

    if (str_contains($ip, ':') || str_contains($subnet, ':')) {
        return trusted_proxies_ipv6_in_cidr($ip, $subnet, $mask);
    }

    return trusted_proxies_ipv4_in_cidr($ip, $subnet, $mask);
}

function trusted_proxies_ipv4_in_cidr(string $ip, string $subnet, int $mask): bool
{
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }
    $maskLong = -1 << (32 - $mask);
    return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
}

function trusted_proxies_ipv6_in_cidr(string $ip, string $subnet, int $mask): bool
{
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) {
        return false;
    }

    $bytes = intdiv($mask, 8);
    $bits = $mask % 8;

    if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }

    if ($bits === 0) {
        return true;
    }

    $ipByte = ord($ipBin[$bytes]);
    $subnetByte = ord($subnetBin[$bytes]);
    $maskByte = (0xFF << (8 - $bits)) & 0xFF;

    return ($ipByte & $maskByte) === ($subnetByte & $maskByte);
}
