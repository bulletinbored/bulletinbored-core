<?php
global $pdo, $config;

$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalThreads = (int)$pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn();
$totalPosts = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM threads WHERE status = 'pending'")->fetchColumn();
$totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalRoles = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();

$recentUsers = $pdo->query("SELECT id, username, created_at FROM users ORDER BY id DESC LIMIT 5")->fetchAll();
$pendingThreads = $pdo->query("
    SELECT t.id, t.title, t.created_at, u.username AS author
    FROM threads t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.status = 'pending'
    ORDER BY t.created_at DESC
    LIMIT 5
")->fetchAll();

$siteName = $config['site_name'] ?? 'bulletinbored';
$today = date($config['date_format'] ?? 'Y-m-d');

$stats = [
    [
        'label' => t('users'),
        'value' => $totalUsers,
        'icon'  => 'fa-users',
        'tone'  => 'primary',
        'href'  => url('admin_users'),
        'meta'  => t('open'),
    ],
    [
        'label' => t('discussions'),
        'value' => $totalThreads,
        'icon'  => 'fa-comments',
        'tone'  => 'success',
        'href'  => url('home'),
        'meta'  => t('view_all'),
    ],
    [
        'label' => t('posts'),
        'value' => $totalPosts,
        'icon'  => 'fa-reply',
        'tone'  => 'info',
        'href'  => url('home'),
        'meta'  => t('view_all'),
    ],
    [
        'label' => t('pending_threads'),
        'value' => $pendingCount,
        'icon'  => 'fa-clock',
        'tone'  => $pendingCount > 0 ? 'warning' : 'success',
        'href'  => url('admin_moderation'),
        'meta'  => $pendingCount > 0 ? t('open') : t('moderation'),
    ],
    [
        'label' => t('roles'),
        'value' => $totalRoles,
        'icon'  => 'fa-shield-halved',
        'tone'  => 'secondary',
        'href'  => url('admin_roles'),
        'meta'  => t('open'),
    ],
    [
        'label' => t('categories'),
        'value' => $totalCategories,
        'icon'  => 'fa-folder',
        'tone'  => 'primary',
        'href'  => url('admin_categories'),
        'meta'  => t('open'),
    ],
];

$quickActions = [
    ['label' => t('add_user'), 'icon' => 'fa-user-plus', 'href' => url('admin_create_user')],
    ['label' => t('add_category'), 'icon' => 'fa-folder-plus', 'href' => url('admin_categories')],
    ['label' => t('plugins'), 'icon' => 'fa-puzzle-piece', 'href' => url('admin_plugins')],
    ['label' => t('themes'), 'icon' => 'fa-palette', 'href' => url('admin_themes')],
    ['label' => t('languages'), 'icon' => 'fa-language', 'href' => url('admin_langs')],
    ['label' => t('settings'), 'icon' => 'fa-sliders', 'href' => url('admin_settings')],
    ['label' => t('updates'), 'icon' => 'fa-arrow-up-from-bracket', 'href' => url('admin_updates')],
    ['label' => t('diagnostics'), 'icon' => 'fa-stethoscope', 'href' => url('admin_diagnostics')],
];
?>
<?php include __DIR__.'/admin_header.php'; render_admin_header(t('dashboard')); ?>
<div class="container-fluid">

    <div class="page-head">
        <div>
            <h1 class="page-title"><?= t('dashboard') ?></h1>
            <p class="page-subtitle"><?= t('dashboard_subtitle') ?></p>
        </div>
        <div class="page-actions">
            <a href="<?= url('admin_moderation') ?>" class="btn btn-outline-primary">
                <i class="fas fa-clock"></i> <?= t('pending_threads') ?>
                <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('home') ?>" class="btn btn-primary">
                <i class="fas fa-arrow-up-right-from-square"></i> <?= t('back_to_forum') ?>
            </a>
        </div>
    </div>

    <div class="card welcome-card mb-4">
        <div class="card-body p-4">
            <span class="welcome-eyebrow"><i class="fas fa-gauge-high"></i> <?= t('overview') ?> · <?= escape($today) ?></span>
            <h2><?= t('dashboard_welcome', ['name' => $_SESSION['username'] ?? '']) ?></h2>
            <p><?= t('dashboard_intro', ['site' => escape(strip_tags($siteName))]) ?></p>
            <div class="welcome-actions">
                <a href="<?= url('admin_create_user') ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-user-plus"></i> <?= t('add_user') ?>
                </a>
                <a href="<?= url('admin_categories') ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-folder-plus"></i> <?= t('add_category') ?>
                </a>
                <a href="<?= url('admin_settings') ?>" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sliders"></i> <?= t('settings') ?>
                </a>
            </div>
        </div>
    </div>

    <?php if ($pendingCount > 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                <strong><?= t('pending_banner', ['n' => $pendingCount]) ?></strong>
                <a href="<?= url('admin_moderation') ?>" class="alert-link ms-1"><?= t('view_moderation') ?></a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php foreach ($stats as $stat): ?>
            <div class="col-12 col-sm-6 col-xl-4">
                <a class="stat-card" href="<?= $stat['href'] ?>">
                    <span class="stat-icon stat-icon-<?= escape($stat['tone']) ?>">
                        <i class="fas <?= escape($stat['icon']) ?>"></i>
                    </span>
                    <span class="stat-content">
                        <span class="stat-label"><?= escape($stat['label']) ?></span>
                        <span class="stat-value"><?= (int)$stat['value'] ?></span>
                        <span class="stat-meta"><?= escape($stat['meta']) ?> <i class="fas fa-arrow-right"></i></span>
                    </span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="fas fa-bolt me-2"></i><?= t('quick_actions') ?></h2>
                </div>
                <div class="card-body">
                    <div class="quick-grid">
                        <?php foreach ($quickActions as $action): ?>
                            <a class="quick-action" href="<?= $action['href'] ?>">
                                <i class="fas <?= escape($action['icon']) ?>"></i>
                                <span><?= escape($action['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="fas fa-user-plus me-2"></i><?= t('recent_users') ?></h2>
                    <a href="<?= url('admin_users') ?>" class="btn btn-sm btn-outline-secondary"><?= t('view_all') ?></a>
                </div>
                <div class="card-body pt-2">
                    <div class="activity-list">
                        <?php if (empty($recentUsers)): ?>
                            <div class="activity-empty"><i class="fas fa-user-slash"></i> <?= t('no_users_found') ?></div>
                        <?php else: ?>
                            <?php foreach ($recentUsers as $user): ?>
                                <a class="activity-item" href="<?= url('admin_user_edit', ['id' => $user['id']]) ?>">
                                    <span class="activity-avatar"><?= render_avatar($user['username'], '', 34) ?></span>
                                    <span class="activity-body">
                                        <span class="activity-title"><?= escape($user['username']) ?></span>
                                        <span class="activity-meta"><?= escape($user['created_at'] ?? '') ?></span>
                                    </span>
                                    <i class="fas fa-chevron-right text-gray-400 small"></i>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title mb-0"><i class="fas fa-clock me-2"></i><?= t('pending_moderation') ?></h2>
            <a href="<?= url('admin_moderation') ?>" class="btn btn-sm btn-outline-secondary"><?= t('view_all') ?></a>
        </div>
        <div class="card-body pt-2">
            <div class="activity-list">
                <?php if (empty($pendingThreads)): ?>
                    <div class="activity-empty"><i class="fas fa-circle-check"></i> <?= t('no_pending_threads') ?></div>
                <?php else: ?>
                    <?php foreach ($pendingThreads as $thread): ?>
                        <a class="activity-item" href="<?= url('admin_moderation') ?>">
                            <span class="activity-avatar"><i class="fas fa-comment-dots"></i></span>
                            <span class="activity-body">
                                <span class="activity-title"><?= escape($thread['title']) ?></span>
                                <span class="activity-meta"><?= escape($thread['author'] ?? t('unknown')) ?> · <?= escape($thread['created_at']) ?></span>
                            </span>
                            <span class="badge bg-warning text-dark"><?= t('pending') ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/admin_footer.php'; ?>
