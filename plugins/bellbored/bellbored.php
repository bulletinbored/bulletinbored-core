<?php
/**
 * Plugin Name: bellbored
 * Version: 1.0.0
 * Author: mlzog
 * Description: Notification center for the forum
 * License: MIT License
 */

function bellbored_init() {
    global $pluginManager, $config, $pdo;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $pluginUrl = $baseUrl . '/plugins/bellbored';
    $apiUrl = $pluginUrl . '/api.php';

    if (isset($pdo)) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                title TEXT NOT NULL,
                message TEXT,
                link TEXT,
                read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(read)");
    }

    $pluginManager->addHook('after_thread', function($threadId) use ($pdo, $baseUrl) {
        $threadStmt = $pdo->prepare("
            SELECT t.title, t.user_id, u.email, u.username
            FROM threads t
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $threadStmt->execute([$threadId]);
        $thread = $threadStmt->fetch();

        if (!$thread) {
            return;
        }

        $watchersStmt = $pdo->prepare("
            SELECT user_id, email, username
            FROM thread_watchers
            WHERE thread_id = ? AND user_id <> ?
        ");
        $watchersStmt->execute([$threadId, $thread['user_id']]);
        $watchers = $watchersStmt->fetchAll();

        foreach ($watchers as $watcher) {
            if (!empty($watcher['email'])) {
                $link = url('thread', ['id' => $threadId]);
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link)
                    VALUES (?, 'thread', ?, ?, ?)
                ")->execute([$watcher['user_id'], 'New thread: ' . $thread['title'], $thread['username'] . ' created a new thread', $link]);
            }
        }
    });

    $pluginManager->addHook('after_post', function($threadId, $postId) use ($pdo, $baseUrl) {
        $postStmt = $pdo->prepare("
            SELECT p.user_id as post_user_id, t.user_id as thread_user_id, t.title, u.username
            FROM posts p
            JOIN threads t ON p.thread_id = t.id
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $postStmt->execute([$postId]);
        $post = $postStmt->fetch();

        if (!$post) {
            return;
        }

        $watchersStmt = $pdo->prepare("
            SELECT user_id, email, username
            FROM thread_watchers
            WHERE thread_id = ? AND user_id <> ?
        ");
        $watchersStmt->execute([$threadId, $post['post_user_id']]);
        $watchers = $watchersStmt->fetchAll();

        foreach ($watchers as $watcher) {
            if (!empty($watcher['email'])) {
                $link = url('thread', ['id' => $threadId]);
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link)
                    VALUES (?, 'reply', ?, ?, ?)
                ")->execute([$watcher['user_id'], 'New reply: ' . $post['title'], $post['username'] . ' replied to a thread', $link]);
            }
        }
    });

    $pluginManager->addHook('user_registered', function($userId, $username) use ($pdo) {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message)
            VALUES (?, 'welcome', 'Welcome!', ?)
        ")->execute([$userId, 'Welcome to the forum, ' . $username . '!']);
    });

    $cssUrl = $pluginUrl . '/assets/css/bellbored.css';
    $jsUrl = $pluginUrl . '/assets/js/bellbored.js';
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script>window.bellbored = window.bellbored || {};window.bellbored.apiUrl = ' . json_encode($apiUrl) . ';window.bellbored.baseUrl = ' . json_encode($baseUrl) . ';window.bellbored.csrfToken = ' . json_encode($csrfToken) . ';</script>' . "\n";

    $footer = '<script src="' . $jsUrl . '"></script>' . "\n";
    $footer .= '<script>setTimeout(function(){window.bellbored = window.bellbored || {};window.bellbored.init && window.bellbored.init();}, 0);</script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });

    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });
}