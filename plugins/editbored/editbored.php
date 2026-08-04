<?php
/**
 * Plugin Name: editbored
 * Version: 1.0.0
 * Author: mlzog
 * Description: WYSIWYG Markdown editor with mentions and image upload
 * License: MIT License
 */

function editbored_init() {
    global $pluginManager, $config;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/editbored';

    $users = [];
    if (isset($GLOBALS['pdo'])) {
        $stmt = $GLOBALS['pdo']->query("SELECT id, username FROM users ORDER BY username ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $usersJson = json_encode($users);
    $uploadUrl = $pluginUrl . '/upload.php';
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    $ebVer = function($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        return $pluginUrl . '/' . $rel . '?v=' . (file_exists($f) ? filemtime($f) : time());
    };
    $cssUrl = $ebVer('assets/css/editbored.css');
    $mentionsUrl = $ebVer('assets/js/mentions.js');
    $editorUrl = $ebVer('assets/js/editbored.js');

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script>window.editbored = window.editbored || {};window.editbored.users = ' . $usersJson . ';window.editbored.uploadUrl = ' . json_encode($uploadUrl) . ';window.editbored.csrfToken = ' . json_encode($csrfToken) . ';</script>' . "\n";

    $footer = '<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>' . "\n";
    $footer .= '<script async src="https://www.instagram.com/embed.js"></script>' . "\n";
    $footer .= '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v21.0"></script>' . "\n";
    $footer .= '<script src="' . $mentionsUrl . '"></script>' . "\n";
    $footer .= '<script src="' . $editorUrl . '"></script>' . "\n";
    $footer .= '<script>window.editbored = window.editbored || {};window.editbored.init && window.editbored.init();</script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });
    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });
}
