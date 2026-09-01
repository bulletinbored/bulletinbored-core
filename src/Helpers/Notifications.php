<?php

/**
 * Notification and email functions.
 */

function notify_thread_reply($thread, int $authorId, string $content): void
{
    $pdo = App::getInstance()->pdo;
    if (!isset($pdo) || !$pdo) {
        return;
    }
    $threadId = (int)($thread['id'] ?? 0);
    if ($threadId <= 0) {
        return;
    }
    $title = $thread['title'] ?? '';
    $authorName = '';
    $authorStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $authorStmt->execute([$authorId]);
    $authorName = (string)($authorStmt->fetchColumn() ?: '');

    $subject = t('reply_notification_subject', ['title' => $title]);
    $body = t('reply_notification_body', [
        'username' => $authorName,
        'author' => $authorName,
        'title' => $title,
        'link' => url('thread', ['id' => $threadId, 'slug' => slugify($title)], true),
    ]);

    $recipients = [];
    if (!empty($thread['user_id']) && (int)$thread['user_id'] !== $authorId) {
        $recipients[(int)$thread['user_id']] = true;
    }
    try {
        $wStmt = $pdo->prepare("SELECT user_id FROM thread_watchers WHERE thread_id = ?");
        $wStmt->execute([$threadId]);
        foreach ($wStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $uid = (int)$uid;
            if ($uid !== $authorId) {
                $recipients[$uid] = true;
            }
        }
    } catch (Throwable $e) {}

    $now = date('Y-m-d H:i:s');
    $link = url('thread', ['id' => $threadId, 'slug' => slugify($title)], true);
    $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES (?, 'reply', ?, ?, ?, 0, ?)");
    foreach (array_keys($recipients) as $uid) {
        try {
            $ins->execute([$uid, $subject, $body, $link, $now]);
        } catch (Throwable $e) {}
    }
}

function notify_admin_new_user($username, $email = '') {
    $config = App::getInstance()->config;
    $recipient = !empty($config['notify_admin_email']) ? $config['notify_admin_email'] : ($config['mail_from'] ?? '');
    if (empty($recipient)) {
        return false;
    }
    $subject = t('new_user_subject', ['username' => $username]);
    $body = t('new_user_body', [
        'site' => $config['site_name'] ?? 'bulletinbored',
        'username' => escape($username),
        'email' => $email !== '' ? escape($email) : '-',
        'link' => url('admin_users', [], true),
    ]);
    return send_email($recipient, $subject, $body);
}

function notify_mentioned_users($pdo, $content, $threadId, $threadTitle, $authorName) {
    if (!preg_match_all('/@([a-zA-Z0-9_]+)/', $content, $matches)) {
        return 0;
    }
    $usernames = array_unique($matches[1]);
    $sent = 0;
    $threadLink = url('thread', ['id' => $threadId, 'slug' => slugify($threadTitle)], true);
    foreach ($usernames as $username) {
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE username = ? AND email IS NOT NULL AND email <> ''");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user) {
            continue;
        }
        $subject = t('mentioned_subject', ['title' => $threadTitle]);
        $body = t('mentioned_body', [
            'username' => escape($user['username'] ?? $username),
            'author' => escape($authorName),
            'title' => escape($threadTitle),
            'link' => $threadLink,
        ]);
        if (send_email($user['email'], $subject, $body)) {
            $sent++;
        }
    }
    return $sent;
}

function ensure_private_messages_table($pdo) {
    $cfg = App::getInstance()->config;
    $driver = ($cfg['db_driver'] ?? 'sqlite');
    if ($driver === 'mysql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS private_messages (
                id INT PRIMARY KEY AUTO_INCREMENT,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                subject TEXT,
                content TEXT NOT NULL,
                is_read INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        try { $pdo->exec("CREATE INDEX idx_pm_recipient ON private_messages(recipient_id, is_read, created_at)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_pm_sender ON private_messages(sender_id, created_at)"); } catch (Throwable $e) {}
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS private_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                subject TEXT DEFAULT '',
                content TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_recipient ON private_messages(recipient_id, is_read, created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_sender ON private_messages(sender_id, created_at)");
    }
}

function create_notification(PDO $pdo, int $userId, string $type, string $title, string $message, string $link = ''): void
{
    $cfg = App::getInstance()->config;
    $driver = ($cfg['db_driver'] ?? 'sqlite');
    if ($driver === 'mysql') {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $title, $message, $link]);
    } else {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $title, $message, $link]);
    }
}

function notification_label(array $n): string
{
    $type = $n['type'] ?? '';
    $msg = $n['message'] ?? '';
    $map = [
        'pm'             => 'pm_notification',
        'pm_notification'=> 'pm_notification',
        'vote'           => 'vote_notification',
        'reply'          => 'reply_notification',
        'mention'        => 'mentioned_notification',
        'follow'         => 'new_follower_notification',
        'role'           => 'role_updated_notification',
        'note'           => 'note_notification',
        'note_notification' => 'note_notification',
    ];
    if (isset($map[$type])) {
        return t($map[$type]);
    }
    if (preg_match('/^[a-z_]+$/', $msg)) {
        $translated = t($msg);
        if ($translated !== $msg) {
            return $translated;
        }
    }
    return $msg;
}
