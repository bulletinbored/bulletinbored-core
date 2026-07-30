<?php
/**
 * Plugin Name: Editbored
 * Version: 1.0.0
 * Author: mlzog
 * Description: WYSIWYG Markdown editor with mentions and image upload
 * License: BSD Zero Clause
 */

function editbored_init() {
    global $pluginManager, $config;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $pluginUrl = $baseUrl . '/plugins/editbored';

    $users = [];
    if (isset($GLOBALS['pdo'])) {
        $stmt = $GLOBALS['pdo']->query("SELECT id, username FROM users ORDER BY username ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $usersJson = json_encode($users);
    $uploadUrl = $pluginUrl . '/upload.php';
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    $cssUrl = $pluginUrl . '/assets/css/editbored.css';
    $mentionsUrl = $pluginUrl . '/assets/js/mentions.js?v=1';
    $editorUrl = $pluginUrl . '/assets/js/editbored.js?v=6';

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script>window.Editbored = window.Editbored || {};window.Editbored.users = ' . $usersJson . ';window.Editbored.uploadUrl = ' . json_encode($uploadUrl) . ';window.Editbored.csrfToken = ' . json_encode($csrfToken) . ';</script>' . "\n";

    $footer = '<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>' . "\n";
    $footer .= '<script async src="https://www.instagram.com/embed.js"></script>' . "\n";
    $footer .= '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v21.0"></script>' . "\n";
    $footer .= '<script src="' . $mentionsUrl . '"></script>' . "\n";
    $footer .= '<script src="' . $editorUrl . '"></script>' . "\n";
    $footer .= '<script>window.Editbored = window.Editbored || {};window.Editbored.init && window.Editbored.init();</script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });
    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });
}
