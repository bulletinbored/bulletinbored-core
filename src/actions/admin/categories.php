<?php

function handle_admin_categories(string $method): \Bulletin\Response|bool
{
    $pdo = App::getInstance()->pdo;

    if ($method === 'POST') {
        if (!csrf_validate_request()) {
            throw new \Bulletin\ForbiddenException('CSRF token invalid');
        }
        if (!rate_limit('admin_categories', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
            throw new \Bulletin\TooManyRequestsException('You are changing categories too fast. Please try again later.');
        }
        $allowedRoles = $_POST['allowed_roles'] ?? 'all';
        $allowedRoles = in_array($allowedRoles, ['all', 'admin', 'moderator'], true) ? $allowedRoles : 'all';
        $catId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($catId > 0) {
            $name = validate_input($_POST['name'] ?? '');
            $description = validate_input($_POST['description'] ?? '');
            if ($name !== '') {
                $pdo->prepare("UPDATE categories SET name = ?, description = ?, allowed_roles = ? WHERE id = ?")->execute([$name, $description, $allowedRoles, $catId]);
                log_admin_action('category_update', ['category_id' => $catId, 'name' => $name]);
            }
        } else {
            $name = validate_input($_POST['name'] ?? '');
            $description = validate_input($_POST['description'] ?? '');
            $position = (int)($_POST['position'] ?? 0);
            if ($name !== '') {
                // Check if category name already exists
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
                $checkStmt->execute([$name]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new \Bulletin\ValidationException(['name' => 'Category name already exists']);
                }
                $stmt = $pdo->prepare("INSERT INTO categories (name, description, allowed_roles, position) VALUES (?, ?, ?, ?)");
                $result = $stmt->execute([$name, $description, $allowedRoles, $position]);
                if (!$result) {
                    throw new \Bulletin\ValidationException(['name' => 'Category name already exists']);
                }
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
    $pdo = App::getInstance()->pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    if (!rate_limit('admin_delete_category', 10, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are deleting categories too fast. Please try again later.');
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
    $pdo = App::getInstance()->pdo;

    if (!csrf_validate_request()) {
        throw new \Bulletin\ForbiddenException('CSRF token invalid');
    }
    if (!rate_limit('admin_category_order', 30, 3600, (string)($_SESSION['user_id'] ?? 0))) {
        throw new \Bulletin\TooManyRequestsException('You are reordering categories too fast. Please try again later.');
    }
    $orderRaw = $_POST['order'] ?? '';
    $order = is_string($orderRaw) ? json_decode($orderRaw, true) : $orderRaw;
    if (!is_array($order)) {
        throw new \Bulletin\ValidationException(['order' => 'Invalid order data']);
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
