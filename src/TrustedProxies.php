<?php

/**
 * TrustedProxies.php — detect client IP behind reverse proxies.
 *
 * X-Forwarded-* headers are only trusted when the request comes from a known
 * proxy (load balancer, CDN, reverse proxy).
 */

function trusted_proxies_detect(): array
{
    $config = $GLOBALS['config'] ?? [];
    $trustedProxies = $config['trusted_proxies'] ?? ['127.0.0.1', '::1'];
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $isTrusted = false;

    foreach ((array)$trustedProxies as $proxy) {
        if (str_contains($proxy, '/')) {
            [$subnet, $mask] = explode('/', $proxy, 2);
            $ipLong = ip2long($remoteAddr);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);
            if ($ipLong !== false && $subnetLong !== false && ($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
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
