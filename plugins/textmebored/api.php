<?php
session_start();
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$pluginUrl = $baseUrl . '/plugins/textmebored';
$apiUrl = $pluginUrl . '/api.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

$pdo = new PDO('sqlite:' . ($config['db_path'] ?? __DIR__ . '/../../data/database.sqlite'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function textmebored_validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

if ($method === 'GET') {
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = substr($requestUri, strlen($scriptName));
    $path = ltrim($path, '/');
    $pathAction = $path ? explode('/', $path)[0] : '';
    $action = $pathAction ?: $_GET['action'] ?? 'conversations';

    if ($action === 'conversations') {
        $stmt = $pdo->prepare("
            SELECT
                CASE WHEN sender_id = :uid THEN recipient_id ELSE sender_id END as other_user_id,
                MAX(created_at) as last_message_at,
                MAX(read) as last_read,
                (SELECT content FROM private_messages pm2
                 WHERE ((pm2.sender_id = :uid AND pm2.recipient_id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END)
                     OR (pm2.recipient_id = :uid AND pm2.sender_id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END))
                 ORDER BY pm2.created_at DESC LIMIT 1) as last_message,
                (SELECT username FROM users u WHERE u.id = CASE WHEN pm.sender_id = :uid THEN pm.recipient_id ELSE pm.sender_id END) as other_username,
                SUM(CASE WHEN recipient_id = :uid AND read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM private_messages pm
            WHERE sender_id = :uid OR recipient_id = :uid
            GROUP BY other_user_id
            ORDER BY last_message_at DESC
        ");
        $stmt->execute(['uid' => $_SESSION['user_id']]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'conversations' => $conversations,
        ]);
        exit;
    }

    if ($action === 'messages') {
        $otherUserId = (int)($_GET['user_id'] ?? 0);
        if ($otherUserId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, sender_id, recipient_id, content, read, created_at
            FROM private_messages
            WHERE (sender_id = :me AND recipient_id = :other) OR (sender_id = :other AND recipient_id = :me)
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            'me' => $_SESSION['user_id'],
            'other' => $otherUserId,
        ]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE private_messages SET read = 1 WHERE recipient_id = :me AND sender_id = :other AND read = 0")
            ->execute(['me' => $_SESSION['user_id'], 'other' => $otherUserId]);

        echo json_encode([
            'success' => true,
            'messages' => $messages,
        ]);
        exit;
    }

    if ($action === 'unread_count') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE recipient_id = ? AND read = 0");
        $countStmt->execute([$_SESSION['user_id']]);
        $unreadCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
        exit;
    }

    if ($action === 'resolve_user') {
        $username = trim($_GET['username'] ?? '');
        if ($username === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $stmt->execute([$username, $_SESSION['user_id']]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'user_id' => (int)$userId,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

if ($method === 'POST') {
    if (!textmebored_validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($recipientId <= 0 || $content === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Recipient and content are required']);
            exit;
        }

        $recipientStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $recipientStmt->execute([$recipientId]);
        if (!$recipientStmt->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['error' => 'Recipient not found']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO private_messages (sender_id, recipient_id, subject, content)
            VALUES (?, ?, '', ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $recipientId, $content]);

        echo json_encode([
            'success' => true,
            'message_id' => $pdo->lastInsertId(),
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;