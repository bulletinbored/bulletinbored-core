<?php
header('Content-Type: application/json');
session_start();

if (empty($config)) {
    $configPath = __DIR__ . '/../config.json';
    $legacyPath = __DIR__ . '/../config.php';
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
        if (!is_array($config)) { $config = []; }
    } elseif (file_exists($legacyPath)) {
        $config = [];
        @include $legacyPath;
        if (!is_array($config)) { $config = []; }
    }
}

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admin required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$catalogPath = __DIR__ . '/../data/catalog.json';
$catalog = file_exists($catalogPath) ? json_decode(file_get_contents($catalogPath), true) : [];
if (!is_array($catalog)) {
    $catalog = [];
}

$installedPath = __DIR__ . '/../data/installed.json';
$installed = file_exists($installedPath) ? json_decode(file_get_contents($installedPath), true) : ['plugins' => [], 'themes' => []];
if (!isset($installed['plugins']) || !isset($installed['themes'])) {
    $installed = ['plugins' => [], 'themes' => []];
}

$name = strtolower(trim($input['name'] ?? ''));
$type = strtolower(trim($input['type'] ?? ''));

$entry = null;
foreach ($catalog as $item) {
    if (strtolower($item['name'] ?? '') === $name && strtolower($item['type'] ?? '') === $type) {
        $entry = $item;
        break;
    }
}

if (!$entry) {
    echo json_encode(['success' => false, 'message' => 'Not found in catalog']);
    exit;
}

$repo = $entry['repo'] ?? '';
if (!$repo || !preg_match('#^https?://#i', $repo)) {
    echo json_encode(['success' => false, 'message' => 'Invalid catalog repo']);
    exit;
}

$tag = $input['tag'] ?? null;

if ($type === 'plugin') {
    $pluginManager = new PluginManager(__DIR__ . '/../plugins', __DIR__ . '/../data/plugins.json');
    $result = $pluginManager->installFromRepo($repo, $tag, $name);
} elseif ($type === 'theme') {
    $themeManager = new ThemeManager(__DIR__ . '/../themes', __DIR__ . '/../data/themes.json', 'freshbored');
    $result = $themeManager->installFromRepo($repo, $tag, $name);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid type']);
    exit;
}

if ($result['success']) {
    if ($type === 'plugin') {
        $installed['plugins'][$name] = [
            'name' => $name,
            'repo' => $repo,
            'version' => $result['manifest']['version'] ?? '1.0.0',
            'installed_at' => date('c')
        ];
    } else {
        $installed['themes'][$name] = [
            'name' => $name,
            'repo' => $repo,
            'version' => $result['manifest']['version'] ?? '1.0.0',
            'installed_at' => date('c')
        ];
    }
    file_put_contents($installedPath, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode($result);
exit;
