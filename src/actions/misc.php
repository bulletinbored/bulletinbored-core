<?php

function handle_misc_action(string $action, string $method): bool
{
    switch ($action) {
        case 'notifications':
            return handle_notifications($method);
        case 'messages':
            return handle_messages($method);
        case 'preview':
            return $method === 'POST' ? handle_markdown_preview() : false;
        case 'mention_users':
            return $method === 'GET' ? handle_mention_users() : false;
        default:
            return false;
    }
}

/**
 * Server-side Markdown preview. Reuses the exact same rendering pipeline as
 * real posts (bb_render_content), so the preview can never show something the
 * server would not also emit — no client-side parsing, no XSS surface.
 */
function handle_markdown_preview(): bool
{
    if (!csrf_validate_request()) {
        http_response_code(403);
        echo '<p class="text-danger">CSRF token invalid</p>';
        return true;
    }
    $content = $_POST['content'] ?? '';
    header('Content-Type: text/html; charset=utf-8');
    echo bb_render_content(validate_input($content));
    return true;
}

/**
 * Username autocomplete for @mentions. Returns a small JSON list of matching
 * usernames. Input is a strict \w+ query so it cannot be abused for leakage.
 */
function handle_mention_users(): bool
{
    global $pdo;
    $q = preg_replace('/[^\w]/', '', (string)($_GET['q'] ?? ''));
    $users = [];
    if ($q !== '' && strlen($q) <= 20) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE username LIKE ? ORDER BY username LIMIT 8");
        $stmt->execute([$q . '%']);
        $users = array_map(fn($r) => ['username' => $r['username']], $stmt->fetchAll());
    }
    header('Content-Type: application/json');
    echo json_encode(['users' => $users]);
    return true;
}


function handle_notifications(string $method): bool
{
    global $pdo;
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }
    if ($method === 'POST' && csrf_validate_request()) {
        if (isset($_POST['do']) && $_POST['do'] === 'mark_read' && isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            if ($id > 0) {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                    ->execute([$id, $_SESSION['user_id']]);
            }
        }
        if (isset($_POST['do']) && $_POST['do'] === 'mark_all_read') {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
                ->execute([$_SESSION['user_id']]);
        }
        redirect(url('notifications'));
    }
    $notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
    $notifications->execute([$_SESSION['user_id']]);
    $notifications = $notifications->fetchAll();
    include __DIR__ . '/../../views/notifications.php';
    return true;
}

function handle_messages(string $method): bool
{
    global $pdo;
    global $pdo;

    if (!is_logged_in()) {
        die('Login required');
    }

    try {
        ensure_private_messages_table($pdo);
    } catch (Throwable $e) {
        error_log('ensure_private_messages_table failed: ' . $e->getMessage());
    }

    if ($method === 'POST' && isset($_POST['content']) && csrf_validate_request()) {
        $recipientId = (int)($_GET['conversation'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if ($recipientId > 0 && $content !== '') {
            $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, recipient_id, subject, content) VALUES (?, ?, '', ?)");
            $stmt->execute([$_SESSION['user_id'], $recipientId, $content]);
        }
        redirect(url('messages', ['conversation' => $recipientId]));
    }

    $conversationUserId = (int)($_GET['conversation'] ?? 0);
    if ($conversationUserId > 0) {
        $messages = $pdo->prepare("
            SELECT pm.*, u.username as sender_name
            FROM private_messages pm
            JOIN users u ON pm.sender_id = u.id
            WHERE (pm.sender_id = :me1 AND pm.recipient_id = :other1) OR (pm.sender_id = :other2 AND pm.recipient_id = :me2)
            ORDER BY pm.created_at ASC
        ");
        $messages->execute(['me1' => $_SESSION['user_id'], 'me2' => $_SESSION['user_id'], 'other1' => $conversationUserId, 'other2' => $conversationUserId]);
        $messages = $messages->fetchAll();

        $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE recipient_id = :me AND sender_id = :other AND is_read = 0")
            ->execute(['me' => $_SESSION['user_id'], 'other' => $conversationUserId]);

        $otherUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $otherUser->execute([$conversationUserId]);
        $otherUsername = $otherUser->fetchColumn();

        include __DIR__ . '/../../views/messages.php';
    } else {
        $convStmt = $pdo->prepare("
            SELECT
                CASE WHEN sender_id = :uid1 THEN recipient_id ELSE sender_id END as other_user_id,
                MAX(created_at) as last_message_at,
                MAX(is_read) as last_read,
                SUM(CASE WHEN recipient_id = :uid2 AND is_read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM private_messages
            WHERE sender_id = :uid3 OR recipient_id = :uid4
            GROUP BY other_user_id
            ORDER BY last_message_at DESC
        ");
        $convStmt->execute([
            'uid1' => $_SESSION['user_id'],
            'uid2' => $_SESSION['user_id'],
            'uid3' => $_SESSION['user_id'],
            'uid4' => $_SESSION['user_id'],
        ]);
        $conversations = $convStmt->fetchAll();

        $msgStmt = $pdo->prepare("
            SELECT content FROM private_messages
            WHERE (sender_id = :uid1 AND recipient_id = :other1) OR (recipient_id = :uid2 AND sender_id = :other2)
            ORDER BY created_at DESC LIMIT 1
        ");
        $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        foreach ($conversations as &$conv) {
            $otherId = (int)$conv['other_user_id'];
            $msgStmt->execute(['uid1' => $_SESSION['user_id'], 'uid2' => $_SESSION['user_id'], 'other1' => $otherId, 'other2' => $otherId]);
            $conv['last_message'] = $msgStmt->fetchColumn();
            $userStmt->execute(['id' => $otherId]);
            $conv['other_username'] = $userStmt->fetchColumn();
        }
        unset($conv);
        include __DIR__ . '/../../views/messages.php';
    }

    return true;
}
