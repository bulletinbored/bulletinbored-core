<?php

function handle_misc_action(string $action, string $method): \Bulletin\Response|bool
{
    switch ($action) {
        case 'preview':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_markdown_preview();
        case 'mention_users':
            if ($method !== 'GET') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_mention_users();
        default:
            throw new \Bulletin\NotFoundException('Misc action not found');
    }
}

/**
 * Server-side Markdown preview. Reuses the exact same rendering pipeline as
 * real posts (bb_render_content), so the preview can never show something the
 * server would not also emit — no client-side parsing, no XSS surface.
 */
function handle_markdown_preview(): \Bulletin\Response|bool
{
    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $content = $_POST['content'] ?? '';
    return \Bulletin\Response::html(bb_render_content(validate_input($content)));
}

/**
 * Username autocomplete for @mentions. Returns a small JSON list of matching
 * usernames. Input is a strict \w+ query so it cannot be abused for leakage.
 */
function handle_mention_users(): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;
    $q = preg_replace('/[^\w]/', '', (string)($_GET['q'] ?? ''));
    $users = [];
    if ($q !== '' && strlen($q) <= 20) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE username LIKE ? ORDER BY username LIMIT 8");
        $stmt->execute([$q . '%']);
        $users = array_map(fn($r) => ['username' => $r['username']], $stmt->fetchAll());
    }
    return \Bulletin\Response::json(['users' => $users]);
}
