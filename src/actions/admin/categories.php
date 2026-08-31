<?php

function handle_admin_categories(string $method): \Bulletin\Response|bool
{
    global $pdo;

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }
        $allowedRoles = $_POST['allowed_roles'] ?? 'all';
        $allowedRoles = in_array($allowedRoles, ['all', 'admin', 'moderator'], true) ? $allowedRoles : 'all';
        if (isset($_GET['id'])) {
            $catId = (int)$_GET['id'];
            $name = validate_input($_POST['name'] ?? '');
            $description = validate_input($_POST['description'] ?? '');
            if ($catId > 0 && $name !== '') {
                $pdo->prepare("UPDATE categories SET name = ?, description = ?, allowed_roles = ? WHERE id = ?")->execute([$name, $description, $allowedRoles, $catId]);
                log_admin_action('category_update', ['category_id' => $catId, 'name' => $name]);
            }
        } else {
            $name = validate_input($_POST['name'] ?? '');
            $description = validate_input($_POST['description'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO categories (name, description, allowed_roles) VALUES (?, ?, ?)")->execute([$name, $description, $allowedRoles]);
                log_admin_action('category_create', ['name' => $name]);
            }
        }
        return redirect(url('admin_categories'));
    }
    include __DIR__ . '/../../../views/admin_categories.php';
    return true;
}

function handle_delete_category_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    $catId = (int)($_GET['id'] ?? 0);
    if ($catId > 0) {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);
        log_admin_action('category_delete', ['category_id' => $catId]);
    }
    return redirect(url('admin_categories'));
}

function handle_update_category_order_post(): \Bulletin\Response|bool
{
    global $pdo;

    if (!csrf_validate_request()) {
        return \Bulletin\Response::json(['success' => false, 'message' => 'CSRF token invalid'], 403);
    }
    $orderRaw = $_POST['order'] ?? '';
    $order = is_string($orderRaw) ? json_decode($orderRaw, true) : $orderRaw;
    if (!is_array($order)) {
        return \Bulletin\Response::json(['success' => false, 'message' => 'Invalid order data'], 400);
    }
    $position = 1;
    $stmt = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ?");
    foreach ($order as $catId) {
        $catId = (int)$catId;
        if ($catId > 0) {
            $stmt->execute([$position, $catId]);
            $position++;
        }
    }
    return \Bulletin\Response::json(['success' => true]);
}
