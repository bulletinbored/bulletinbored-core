<?php
// Plugin Example: Analytics Plugin
// Put this file in plugins/analytics.php to enable

class AnalyticsPlugin {
    public static function onPageLoad($app) {
        // Log page visits
        $logFile = __DIR__ . '/storage/analytics.log';
        $log = [
            'time' => date('c'),
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'user' => $_SESSION['username'] ?? 'guest',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        file_put_contents($logFile, json_encode($log) . "\n", FILE_APPEND);
    }

    public static function onThreadCreate($threadId) {
        // Track thread creation
        error_log("Thread created: $threadId");
    }
}

// Register hooks
$container = $app->getContainer();
$router = $container->get('router');

// Add analytics tracking on all pages
$originalDispatch = [$router, 'dispatch'];
$router->addRoute('*', '/analytics', [AnalyticsPlugin::class, 'onPageLoad']);

// Hook into thread creation
// This would be added to the ForumController after thread creation