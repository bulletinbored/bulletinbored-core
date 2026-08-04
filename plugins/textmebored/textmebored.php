<?php
/**
 * Plugin Name: textmebored
 * Version: 1.0.0
 * Author: mlzog
 * Description: Private messaging and chat system
 * License: MIT License
 */

function textmebored_init() {
    global $pluginManager, $config, $pdo;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/textmebored';
    $apiUrl = $pluginUrl . '/api.php';

    if (isset($pdo)) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS private_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                subject TEXT DEFAULT '',
                content TEXT NOT NULL,
                read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_recipient ON private_messages(recipient_id, read, created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_sender ON private_messages(sender_id, created_at)");
    }

    $tmVer = function($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        return $pluginUrl . '/' . $rel . '?v=' . (file_exists($f) ? filemtime($f) : time());
    };
    $cssUrl = $tmVer('assets/css/textmebored.css');
    $jsUrl = $tmVer('assets/js/textmebored.js');
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script>window.textmebored = window.textmebored || {};window.textmebored.apiUrl = ' . json_encode($apiUrl) . ';window.textmebored.baseUrl = ' . json_encode($baseUrl) . ';window.textmebored.csrfToken = ' . json_encode($csrfToken) . ';window.textmebored.currentUserId = ' . json_encode($_SESSION['user_id'] ?? 0) . ';</script>' . "\n";

    $footer = '<script src="' . $jsUrl . '"></script>' . "\n";
    $footer .= '<script>setTimeout(function(){window.textmebored = window.textmebored || {};window.textmebored.init && window.textmebored.init();}, 0);</script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });

    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });
}