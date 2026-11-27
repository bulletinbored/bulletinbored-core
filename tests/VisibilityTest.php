<?php

/**
 * VisibilityTest — the core-level privacy primitives added for A1.
 *
 * These tests model the important safety property: "followers" and "private"
 * stay hidden when no plugin answers the follow/profile hooks. They also cover
 * the social plugin's opt-in: once its hooks are registered, a follower can see
 * followers-only content and a private profile's social posts.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../src/Errors.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../src/actions/content.php';

function vis_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            role TEXT DEFAULT 'user',
            avatar TEXT,
            status TEXT DEFAULT 'active',
            suspension_time INTEGER DEFAULT 0,
            email_verified INTEGER DEFAULT 1,
            session_version INTEGER DEFAULT 1,
            profile_visibility TEXT NOT NULL DEFAULT 'public',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            position INTEGER DEFAULT 0,
            allowed_roles TEXT DEFAULT NULL
        );
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            content TEXT,
            status TEXT DEFAULT 'visible',
            visibility TEXT NOT NULL DEFAULT 'public',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            views INTEGER DEFAULT 0
        );
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            user_id INTEGER,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER,
            post_id INTEGER,
            user_id INTEGER,
            filename TEXT,
            original_name TEXT,
            size INTEGER,
            mime_type TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            permissions TEXT DEFAULT '[]'
        );
        CREATE TABLE social_follows (
            follower_id INTEGER NOT NULL,
            following_id INTEGER NOT NULL,
            PRIMARY KEY (follower_id, following_id)
        );
        CREATE TABLE social_blocks (
            blocker_id INTEGER NOT NULL,
            blocked_id INTEGER NOT NULL,
            PRIMARY KEY (blocker_id, blocked_id)
        );
        CREATE TABLE social_mutes (
            user_id INTEGER NOT NULL,
            muted_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, muted_id)
        );
    ");

    require_once __DIR__ . '/../lib/DefaultRoles.php';
    foreach (bb_default_role_permissions() as $role => $permissions) {
        $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)")
            ->execute([$role, json_encode($permissions)]);
    }

    $pdo->exec("INSERT INTO categories (id, name) VALUES (1, 'General')");

    $users = [
        [1, 'author', 'user'],
        [2, 'follower', 'user'],
        [3, 'stranger', 'user'],
        [4, 'moderator', 'moderator'],
    ];
    foreach ($users as [$id, $username, $role]) {
        $pdo->prepare("INSERT INTO users (id, username, password, role, profile_visibility) VALUES (?, ?, ?, ?, 'public')")
            ->execute([$id, $username, password_hash('test123', PASSWORD_DEFAULT), $role]);
    }

    $threads = [
        [1, 1, 'Public forum', 'public'],
        [null, 1, 'Followers social', 'followers'],
        [null, 1, 'Private social', 'private'],
        [1, 1, 'Private forum', 'private'],
        [null, 1, 'Social public', 'public'],
    ];
    foreach ($threads as [$category, $user, $title, $visibility]) {
        $pdo->prepare("INSERT INTO threads (category_id, user_id, title, content, visibility) VALUES (?, ?, ?, 'body', ?)")
            ->execute([$category, $user, $title, $visibility]);
    }

    return $pdo;
}

function vis_boot(PDO $pdo): void
{
    App::reset();
    App::getInstance()->pdo = $pdo;
    App::getInstance()->authz = new AuthZ($pdo);
    App::getInstance()->pluginManager = null;
    $_SESSION = [];
}

function vis_ids(array $threads): array
{
    $ids = array_values(array_map(static fn(array $row): int => (int)$row['id'], $threads));
    sort($ids);
    return $ids;
}

function vis_plugin(PDO $pdo): PluginManager
{
    $pm = new PluginManager(
        sys_get_temp_dir() . '/bb_vis_plugins',
        sys_get_temp_dir() . '/bb_vis_plugins.json'
    );

    $pm->addHook('thread_followers_visibility_sql', function ($value, array $context) {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)($context['alias'] ?? 't')) ?: 't';
        $viewerId = (int)($context['viewer_id'] ?? 0);
        return [
            'sql' => "EXISTS (
                SELECT 1 FROM social_follows sf
                WHERE sf.follower_id = ? AND sf.following_id = {$alias}.user_id
            )",
            'params' => [$viewerId],
        ];
    }, 5);

    $pm->addHook('thread_listing_audience_sql', function ($value, array $context) {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)($context['alias'] ?? 't')) ?: 't';
        $viewerId = (int)($context['viewer_id'] ?? 0);
        return [
            'sql' => "NOT EXISTS (
                SELECT 1 FROM social_blocks sb
                WHERE (sb.blocker_id = ? AND sb.blocked_id = {$alias}.user_id)
                   OR (sb.blocker_id = {$alias}.user_id AND sb.blocked_id = ?)
            )
            AND NOT EXISTS (
                SELECT 1 FROM social_mutes sm
                WHERE sm.user_id = ? AND sm.muted_id = {$alias}.user_id
            )",
            'params' => [$viewerId, $viewerId, $viewerId],
        ];
    }, 5);

    $pm->addHook('thread_view_decision', function (array $thread, int $viewerId, string $visibility) use ($pdo): ?bool {
        $authorId = (int)$thread['user_id'];
        $blockStmt = $pdo->prepare("
            SELECT 1 FROM social_blocks
            WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
        ");
        $blockStmt->execute([$viewerId, $authorId, $authorId, $viewerId]);
        if ($blockStmt->fetchColumn()) {
            return false;
        }
        if ($visibility !== 'followers') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM social_follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$viewerId, $authorId]);
        return (bool)$stmt->fetchColumn();
    });

    $pm->addHook('profile_view_decision', function (array $user, int $viewerId, string $privacy) use ($pdo): ?bool {
        if ($privacy !== 'followers') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM social_follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$viewerId, (int)$user['id']]);
        return (bool)$stmt->fetchColumn();
    });

    App::getInstance()->pluginManager = $pm;
    return $pm;
}

function test_visibility_guest_and_default_deny(): Test
{
    $t = new Test('Visibility - guests and non-followers only see public content');
    $pdo = vis_pdo();
    vis_boot($pdo);

    $guest = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Guest sees only public threads', [1, 5], $guest);

    $_SESSION = ['user_id' => 3, 'user_role' => 'user', 'session_version' => 1];
    $stranger = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Stranger sees only public threads', [1, 5], $stranger);

    $_SESSION = ['user_id' => 2, 'user_role' => 'user', 'session_version' => 1];
    $followerWithoutPlugin = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Followers-only stays hidden without a plugin (default deny)', [1, 5], $followerWithoutPlugin);

    $row = $pdo->query("SELECT * FROM threads WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
    $t->assertFalse('bb_can_view_thread denies followers without a plugin', bb_can_view_thread($row, 2));

    $_SESSION = ['user_id' => 1, 'user_role' => 'user', 'session_version' => 1];
    $author = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Author sees all their threads', [1, 2, 3, 4, 5], $author);

    $_SESSION = ['user_id' => 4, 'user_role' => 'moderator', 'session_version' => 1];
    $moderator = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Moderator sees all threads', [1, 2, 3, 4, 5], $moderator);

    App::reset();
    return $t;
}

function test_visibility_followers_with_plugin(): Test
{
    $t = new Test('Visibility - plugin hook enables followers-only content');
    $pdo = vis_pdo();
    vis_boot($pdo);
    vis_plugin($pdo);

    $pdo->exec("INSERT INTO social_follows (follower_id, following_id) VALUES (2, 1)");

    $_SESSION = ['user_id' => 2, 'user_role' => 'user', 'session_version' => 1];
    $follower = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Follower sees their followee followers-only posts', [1, 2, 5], $follower);

    $_SESSION = ['user_id' => 3, 'user_role' => 'user', 'session_version' => 1];
    $stranger = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Non-follower still cannot see followers-only posts', [1, 5], $stranger);

    $row = $pdo->query("SELECT * FROM threads WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
    $t->assertTrue('bb_can_view_thread allows the follower', bb_can_view_thread($row, 2));
    $t->assertFalse('bb_can_view_thread denies a non-follower', bb_can_view_thread($row, 3));

    App::reset();
    return $t;
}

function test_visibility_profile_privacy(): Test
{
    $t = new Test('Visibility - profile privacy governs social posts');
    $pdo = vis_pdo();
    vis_boot($pdo);
    vis_plugin($pdo);

    $pdo->exec("INSERT INTO social_follows (follower_id, following_id) VALUES (2, 1)");
    $pdo->exec("UPDATE users SET profile_visibility = 'followers' WHERE id = 1");

    $_SESSION = [];
    $guest = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Guest cannot see social posts of a followers-only profile', [1], $guest);

    $_SESSION = ['user_id' => 3, 'user_role' => 'user', 'session_version' => 1];
    $stranger = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Stranger cannot see social posts of a followers-only profile', [1], $stranger);

    $_SESSION = ['user_id' => 2, 'user_role' => 'user', 'session_version' => 1];
    $follower = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Follower sees the public social post of a followers-only profile', [1, 2, 5], $follower);

    $author = $pdo->query("SELECT * FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $t->assertFalse('Profile is not viewable by a stranger', bb_can_view_profile($author, 3));
    $t->assertTrue('Profile is viewable by a follower', bb_can_view_profile($author, 2));

    App::reset();
    return $t;
}

function test_visibility_block_veto(): Test
{
    $t = new Test('Visibility - plugin can veto public content for blocked users');
    $pdo = vis_pdo();
    vis_boot($pdo);
    vis_plugin($pdo);

    $pdo->exec("INSERT INTO social_blocks (blocker_id, blocked_id) VALUES (1, 3)");

    $_SESSION = ['user_id' => 3, 'user_role' => 'user', 'session_version' => 1];
    $blocked = vis_ids(fetch_threads(['per_page' => 50])['threads']);
    $t->assertEquals('Blocked viewer sees none of the blocker threads', [], $blocked);

    $row = $pdo->query("SELECT * FROM threads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $t->assertFalse('bb_can_view_thread honours the block veto', bb_can_view_thread($row, 3));
    $t->assertTrue('An unrelated viewer still sees the public thread', bb_can_view_thread($row, 2));

    App::reset();
    return $t;
}

function test_visibility_private_download_denied(): Test
{
    $t = new Test('Visibility - private attachment download is denied');
    $pdo = vis_pdo();
    vis_boot($pdo);

    $pdo->prepare("INSERT INTO uploads (thread_id, user_id, filename, original_name, mime_type) VALUES (3, 1, 'secret.jpg', 'secret.jpg', 'image/jpeg')")
        ->execute();
    $uploadId = (int)$pdo->lastInsertId();

    $_SESSION = ['user_id' => 3, 'user_role' => 'user', 'session_version' => 1];
    $t->assertThrows(
        'A stranger cannot download an attachment from a private social post',
        static fn() => handle_download(['id' => $uploadId]),
        \Bulletin\NotFoundException::class
    );

    App::reset();
    return $t;
}

register_tests(
    'test_visibility_guest_and_default_deny',
    'test_visibility_followers_with_plugin',
    'test_visibility_profile_privacy',
    'test_visibility_block_veto',
    'test_visibility_private_download_denied'
);
