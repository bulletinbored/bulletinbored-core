<?php
// Analytics Plugin Example
// Place in plugins/analytics.php to enable

function analytics_init() {
    // Log page visits on every request
    $logFile = __DIR__ . '/../data/analytics.log';
    $log = [
        'time' => date('c'),
        'url' => $_SERVER['REQUEST_URI'] ?? '',
        'user' => $_SESSION['username'] ?? 'guest',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    file_put_contents($logFile, json_encode($log) . "\n", FILE_APPEND);
}