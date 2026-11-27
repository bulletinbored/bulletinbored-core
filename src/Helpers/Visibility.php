<?php

/**
 * Thread and profile visibility primitives.
 *
 * The core owns the safe part of the social visibility model:
 *
 *   - public     everyone can see it
 *   - followers  only followers, decided by a plugin (default: deny)
 *   - private    only the author and moderators/admins
 *
 * A plugin (socialbored) supplies the follow graph through two hooks:
 *
 *   - thread_followers_visibility_sql  returns a SQL fragment for listings
 *   - thread_view_decision             returns bool for a single thread
 *   - profile_view_decision            returns bool for a single profile
 *
 * When no plugin answers, "followers" and "private" remain hidden. That is the
 * whole point of keeping these primitives in the core: disabling the social
 * plugin must never make private posts public.
 *
 * Social posts are threads without a category (the convention established by
 * socialbored). For those the author's profile visibility is combined with the
 * thread visibility; the most restrictive value wins. Classic forum threads
 * keep their own visibility and ignore the profile setting.
 */

if (!defined('BB_VISIBILITY_PUBLIC')) {
    define('BB_VISIBILITY_PUBLIC', 'public');
}
if (!defined('BB_VISIBILITY_FOLLOWERS')) {
    define('BB_VISIBILITY_FOLLOWERS', 'followers');
}
if (!defined('BB_VISIBILITY_PRIVATE')) {
    define('BB_VISIBILITY_PRIVATE', 'private');
}

/**
 * Probe for the visibility columns once per request.
 *
 * On a fully migrated installation both columns exist. On a hand-written test
 * schema (or a database whose migration failed) the helpers degrade to the
 * pre-A1 behaviour instead of turning every listing into a SQL error.
 */
function bb_visibility_column_exists(string $table, string $column): bool
{
    static $cache = null;
    if (!$cache instanceof \WeakMap) {
        $cache = new \WeakMap();
    }

    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    $pdo = App::getInstance()->pdo;

    if (!$pdo instanceof PDO || $table === '' || $column === '') {
        return false;
    }

    // Key by connection object. The test suite runs many isolated in-memory
    // schemas in one process; a WeakMap drops entries with their PDO instead of
    // letting a reused object id serve a stale answer.
    $key = $table . '.' . $column;
    $entry = $cache[$pdo] ?? [];
    if (array_key_exists($key, $entry)) {
        return $entry[$key];
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $exists = (int)$stmt->fetchColumn() > 0;
        } else {
            $stmt = $pdo->prepare("PRAGMA table_info({$table})");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            $exists = in_array($column, $columns, true);
        }
    } catch (\Throwable $e) {
        $exists = false;
    }

    $entry[$key] = $exists;
    $cache[$pdo] = $entry;
    return $exists;
}

/**
 * Select expression for the author's profile visibility that is safe on a
 * schema where the column has not been added yet (e.g. a hand-built test DB).
 */
function bb_profile_visibility_select(string $alias = 'u', string $as = 'author_profile_visibility'): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'u';
    $as = preg_replace('/[^A-Za-z0-9_]/', '', $as) ?: 'author_profile_visibility';
    return bb_visibility_column_exists('users', 'profile_visibility')
        ? "{$alias}.profile_visibility AS {$as}"
        : "'public' AS {$as}";
}

function bb_normalize_visibility(mixed $visibility): string
{
    $visibility = strtolower(trim((string)$visibility));
    return in_array($visibility, ['public', 'followers', 'private'], true)
        ? $visibility
        : 'public';
}

function bb_visibility_rank(string $visibility): int
{
    return match (bb_normalize_visibility($visibility)) {
        'private' => 2,
        'followers' => 1,
        default => 0,
    };
}

function bb_most_restrictive_visibility(string $a, string $b): string
{
    return bb_visibility_rank($a) >= bb_visibility_rank($b)
        ? bb_normalize_visibility($a)
        : bb_normalize_visibility($b);
}

function bb_thread_visibility(array $thread): string
{
    return bb_normalize_visibility($thread['visibility'] ?? 'public');
}

function bb_profile_visibility(?array $user): string
{
    return bb_normalize_visibility($user['profile_visibility'] ?? 'public');
}

function bb_thread_is_social(array $thread): bool
{
    return ($thread['category_id'] ?? null) === null;
}

function bb_is_privileged_viewer(?int $viewerId = null): bool
{
    $viewerId = $viewerId ?? (int)($_SESSION['user_id'] ?? 0);
    if ($viewerId <= 0) {
        return false;
    }
    $authz = App::getInstance()->authz;
    if ($authz === null) {
        return false;
    }
    try {
        return $authz->can($viewerId, 'admin.access')
            || $authz->can($viewerId, 'moderation.manage')
            || $authz->can($viewerId, 'threads.approve');
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Read a user's profile visibility with a per-request cache.
 */
function bb_user_profile_visibility(int $userId): string
{
    static $cache = null;
    if (!$cache instanceof \WeakMap) {
        $cache = new \WeakMap();
    }
    if ($userId <= 0) {
        return 'public';
    }

    $pdo = App::getInstance()->pdo;
    if (!$pdo instanceof PDO) {
        return 'public';
    }

    $entry = $cache[$pdo] ?? [];
    if (array_key_exists($userId, $entry)) {
        return $entry[$userId];
    }

    try {
        $stmt = $pdo->prepare("SELECT profile_visibility FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        $visibility = $value === false ? 'public' : bb_normalize_visibility($value);
    } catch (\Throwable $e) {
        $visibility = 'public';
    }

    $entry[$userId] = $visibility;
    $cache[$pdo] = $entry;
    return $visibility;
}

/**
 * Effective visibility of a thread: the restrictive combination of the thread
 * visibility and, for social posts, the author's profile visibility.
 */
function bb_effective_thread_visibility(array $thread): string
{
    $visibility = bb_thread_visibility($thread);
    if (!bb_thread_is_social($thread)) {
        return $visibility;
    }

    $authorVisibility = $thread['author_profile_visibility'] ?? null;
    if ($authorVisibility === null) {
        $authorVisibility = bb_user_profile_visibility((int)($thread['user_id'] ?? 0));
    }

    return bb_most_restrictive_visibility($visibility, bb_normalize_visibility($authorVisibility));
}

/**
 * Ask a plugin for the SQL fragment that resolves "followers" visibility.
 *
 * @return array{sql:string,params:array<int,int>}|null
 */
function bb_followers_visibility_sql(string $alias, int $viewerId, ?\PluginManager $pm = null): ?array
{
    $pm = $pm ?? App::getInstance()->pluginManager;
    if (!$pm instanceof \PluginManager || $viewerId <= 0) {
        return null;
    }

    $result = $pm->filter('thread_followers_visibility_sql', null, [
        'alias' => $alias,
        'viewer_id' => $viewerId,
    ]);
    if (!is_array($result) || empty($result['sql'])) {
        return null;
    }

    return [
        'sql' => (string)$result['sql'],
        'params' => array_values(array_map('intval', $result['params'] ?? [])),
    ];
}

/**
 * SQL condition that constrains a thread listing to rows the viewer may see.
 *
 * The condition uses positional placeholders; append `params` to the query in
 * the same order. The query must join users with `$authorAlias` so the profile
 * visibility of social posts can be evaluated without a second query.
 *
 * @return array{sql:string,params:array<int,int>}
 */
function bb_thread_visibility_condition(
    string $alias = 't',
    ?int $viewerId = null,
    ?\PluginManager $pm = null,
    ?string $authorAlias = 'u'
): array {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 't';
    $authorAlias = $authorAlias !== null
        ? (preg_replace('/[^A-Za-z0-9_]/', '', $authorAlias) ?: 'u')
        : null;

    $viewerId = $viewerId ?? (int)($_SESSION['user_id'] ?? 0);

    $hasThreadVisibility = bb_visibility_column_exists('threads', 'visibility');
    $hasProfileVisibility = $authorAlias !== null && bb_visibility_column_exists('users', 'profile_visibility');

    $threadVisibility = $hasThreadVisibility
        ? "COALESCE({$alias}.visibility, 'public')"
        : "'public'";
    $profileVisibility = $hasProfileVisibility
        ? "CASE WHEN {$alias}.category_id IS NULL THEN COALESCE({$authorAlias}.profile_visibility, 'public') ELSE 'public' END"
        : "'public'";

    $branches = [
        "({$threadVisibility} = 'public' AND {$profileVisibility} = 'public')",
    ];
    $params = [];

    if ($viewerId > 0) {
        $branches[] = "{$alias}.user_id = ?";
        $params[] = $viewerId;

        if (bb_is_privileged_viewer($viewerId)) {
            $branches[] = '1=1';
        }

        $followers = bb_followers_visibility_sql($alias, $viewerId, $pm);
        if ($followers !== null) {
            $branches[] = "(
                {$threadVisibility} IN ('public', 'followers')
                AND {$profileVisibility} IN ('public', 'followers')
                AND ({$followers['sql']})
            )";
            $params = array_merge($params, $followers['params']);
        }
    }

    return [
        'sql' => '(' . implode(' OR ', $branches) . ')',
        'params' => $params,
    ];
}

/**
 * Ask a plugin for extra audience filters (blocks, mutes) for a listing.
 *
 * @return array{sql:string,params:array<int,int>}|null
 */
function bb_plugin_audience_sql(string $alias = 't', ?int $viewerId = null, ?\PluginManager $pm = null): ?array
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 't';
    $viewerId = $viewerId ?? (int)($_SESSION['user_id'] ?? 0);
    if ($viewerId <= 0) {
        return null;
    }

    $pm = $pm ?? App::getInstance()->pluginManager;
    if (!$pm instanceof \PluginManager) {
        return null;
    }

    $result = $pm->filter('thread_listing_audience_sql', null, [
        'alias' => $alias,
        'viewer_id' => $viewerId,
    ]);
    if (!is_array($result) || empty($result['sql'])) {
        return null;
    }

    return [
        'sql' => (string)$result['sql'],
        'params' => array_values(array_map('intval', $result['params'] ?? [])),
    ];
}

/**
 * Decide whether a single thread row is visible to the viewer.
 *
 * The caller should select the thread's `visibility` column. For social posts
 * the author's `profile_visibility` is looked up (or read from an
 * `author_profile_visibility` column when the query already joined users).
 *
 * A plugin can veto public content (blocks) and must explicitly allow
 * "followers". "private" is never delegated.
 */
function bb_can_view_thread(array $thread, ?int $viewerId = null, ?\PluginManager $pm = null): bool
{
    $viewerId = $viewerId ?? (int)($_SESSION['user_id'] ?? 0);
    $authorId = (int)($thread['user_id'] ?? 0);

    if ($authorId > 0 && $viewerId === $authorId) {
        return true;
    }
    if (bb_is_privileged_viewer($viewerId)) {
        return true;
    }

    $effective = bb_effective_thread_visibility($thread);
    $pm = $pm ?? App::getInstance()->pluginManager;

    if ($effective === 'public') {
        // A plugin may still veto (for example a block between the two users).
        if ($viewerId > 0 && $pm instanceof \PluginManager) {
            $decision = $pm->applyHook('thread_view_decision', $thread, $viewerId, $effective);
            if ($decision === false) {
                return false;
            }
        }
        return true;
    }

    if ($viewerId <= 0 || $effective === 'private') {
        return false;
    }

    // "followers": only a plugin knows the follow graph. Deny by default.
    if (!$pm instanceof \PluginManager) {
        return false;
    }

    $decision = $pm->applyHook('thread_view_decision', $thread, $viewerId, $effective);
    return $decision === true;
}

/**
 * Decide whether a profile itself is visible to the viewer.
 */
function bb_can_view_profile(array $user, ?int $viewerId = null, ?\PluginManager $pm = null): bool
{
    $viewerId = $viewerId ?? (int)($_SESSION['user_id'] ?? 0);
    $userId = (int)($user['id'] ?? 0);

    if ($userId > 0 && $viewerId === $userId) {
        return true;
    }
    if (bb_is_privileged_viewer($viewerId)) {
        return true;
    }

    $privacy = bb_profile_visibility($user);
    if ($privacy === 'public') {
        return true;
    }
    if ($viewerId <= 0 || $privacy === 'private') {
        return false;
    }

    $pm = $pm ?? App::getInstance()->pluginManager;
    if (!$pm instanceof \PluginManager) {
        return false;
    }

    $decision = $pm->applyHook('profile_view_decision', $user, $viewerId, $privacy);
    return $decision === true;
}
