<?php
/**
 * Left sidebar: primary action, board navigation, categories and statistics.
 * Rendered by render_header() on every frontend page that uses the shell.
 */
$sbCategories = sidebar_categories();
$sbStats      = forum_statistics();
$sbAction     = $_GET['action'] ?? 'home';
$sbCatId      = (int)($_GET['id'] ?? 0);
$sbInfo       = $GLOBALS['layoutOptions']['info'] ?? [];
?>
<aside class="sidebar" id="forumSidebar">

    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="<?= url('new_thread') ?>" class="btn btn-brand w-100 btn-new-thread hide-on-mobile">
            <i class="fas fa-pen-to-square me-2"></i><?= t('new_thread') ?>
        </a>
    <?php else: ?>
        <a href="<?= url('login') ?>" class="btn btn-brand w-100 btn-new-thread hide-on-mobile">
            <i class="fas fa-pen-to-square me-2"></i><?= t('new_thread') ?>
        </a>
    <?php endif; ?>

    <button class="btn btn-outline-soft w-100 d-lg-none sidebar-toggle hide-on-mobile" type="button"
            data-bs-toggle="collapse" data-bs-target="#sidebarBody" aria-expanded="false" aria-controls="sidebarBody">
        <i class="fas fa-bars me-2"></i><?= t('browse') ?>
    </button>

    <div class="collapse d-lg-block sidebar-body" id="sidebarBody">

        <?php if (!empty($sbInfo)): ?>
            <section class="sidebar-block">
                <h6 class="sidebar-title"><?= t('discussion_info') ?></h6>
                <ul class="sidebar-info">
                    <?php foreach ($sbInfo as $infoLabel => $infoValue): ?>
                        <li><span><?= escape($infoLabel) ?></span><strong><?= $infoValue ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <nav class="sidebar-block">
            <ul class="sidebar-nav">
                <li>
                    <a href="<?= url('home') ?>" class="<?= ($sbAction === 'home' || $sbAction === '') ? 'active' : '' ?>">
                        <i class="fas fa-comments"></i><span><?= t('all_discussions') ?></span>
                        <span class="sidebar-count"><?= compact_number($sbStats['threads']) ?></span>
                    </a>
                </li>
                <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                    <li>
                        <a href="<?= url('profile', ['user' => $_SESSION['username'] ?? '']) ?>" class="<?= $sbAction === 'profile' ? 'active' : '' ?>">
                            <i class="fas fa-user"></i><span><?= t('my_profile') ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <section class="sidebar-block">
            <h6 class="sidebar-title"><?= t('categories') ?></h6>
            <?php if (empty($sbCategories)): ?>
                <p class="sidebar-empty"><?= t('no_categories') ?></p>
            <?php else: ?>
                <ul class="sidebar-nav">
                    <?php foreach ($sbCategories as $sbCat): ?>
                        <li>
                            <a href="<?= url('category', ['id' => $sbCat['id'], 'slug' => slugify($sbCat['name'] ?? '')]) ?>"
                               class="<?= ($sbAction === 'category' && $sbCatId === (int)$sbCat['id']) ? 'active' : '' ?>"
                               <?= !empty($sbCat['description']) ? 'title="'.escape($sbCat['description']).'"' : '' ?>>
                                <i class="fas fa-hashtag"></i><span><?= escape($sbCat['name']) ?></span>
                                <span class="sidebar-count"><?= compact_number($sbCat['thread_count'] ?? 0) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="sidebar-block">
            <h6 class="sidebar-title"><?= t('statistics') ?></h6>
            <div class="stat-grid">
                <div class="stat">
                    <span class="stat-value"><?= compact_number($sbStats['threads']) ?></span>
                    <span class="stat-label"><?= t('discussions') ?></span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?= compact_number($sbStats['posts']) ?></span>
                    <span class="stat-label"><?= t('replies') ?></span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?= compact_number($sbStats['members']) ?></span>
                    <span class="stat-label"><?= t('members') ?></span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?= compact_number($sbStats['contributors']) ?></span>
                    <span class="stat-label"><?= t('contributors') ?></span>
                </div>
            </div>
            <?php if (!empty($sbStats['newest_member'])): ?>
                <div class="stat-newest">
                    <?= render_avatar($sbStats['newest_member']['username'], $sbStats['newest_member']['avatar'] ?? '', 26) ?>
                    <span>
                        <small><?= t('newest_member') ?></small>
                        <a href="<?= url('profile', ['user' => $sbStats['newest_member']['username']]) ?>"><?= escape($sbStats['newest_member']['username']) ?></a>
                    </span>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!function_exists('is_logged_in') || !is_logged_in()): ?>
            <section class="sidebar-block sidebar-cta">
                <h6 class="sidebar-title mb-1"><?= t('join_us') ?></h6>
                <p class="sidebar-empty mb-3"><?= t('join_us_text') ?></p>
                <a href="<?= url('register') ?>" class="btn btn-brand btn-sm w-100 mb-2"><?= t('register') ?></a>
                <a href="<?= url('login') ?>" class="btn btn-outline-soft btn-sm w-100"><?= t('login') ?></a>
            </section>
        <?php endif; ?>

    </div>
</aside>
