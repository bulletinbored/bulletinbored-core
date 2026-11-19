<?php

/**
 * DefaultRoles — the single source of the default role permissions.
 *
 * Permissions use the canonical "resource.action" notation checked by AuthZ
 * (ownership is expressed as "resource.action_own").
 *
 * This list used to be duplicated: the migrations seeded the canonical names
 * while the web installer seeded the old "can_*" names, so an installation
 * created by the installer gave regular users no usable permission at all —
 * posting threw Forbidden and (with Errors.php not loaded) a 500. Both paths
 * now read this file, and 20261120_normalize_role_permissions.php repairs the
 * installations created before the fix.
 */

/**
 * @return array<string, string[]> role name => permissions
 */
function bb_default_role_permissions(): array
{
    return [
        'admin' => [
            'admin.access',
            'threads.approve', 'threads.delete', 'threads.edit', 'threads.lock',
            'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy',
            'posts.delete', 'posts.edit',
            'users.ban', 'users.create', 'users.delete', 'users.edit',
            'roles.manage', 'categories.manage', 'settings.manage',
            'plugins.manage', 'themes.manage', 'langs.manage',
        ],
        'moderator' => [
            'threads.approve', 'threads.delete', 'threads.edit', 'threads.lock',
            'threads.sticky', 'threads.move', 'threads.split', 'threads.merge', 'threads.copy',
            'posts.delete', 'posts.edit',
        ],
        'user' => [
            'threads.create', 'posts.create', 'posts.edit_own', 'posts.delete_own',
        ],
    ];
}

/**
 * Old "can_*" permission names => canonical ones.
 * @return array<string, string>
 */
function bb_legacy_permission_map(): array
{
    return [
        'can_create_threads' => 'threads.create',
        'can_create_posts' => 'posts.create',
        'can_edit_threads' => 'threads.edit',
        'can_delete_threads' => 'threads.delete',
        'can_approve_threads' => 'threads.approve',
        'can_lock_threads' => 'threads.lock',
        'can_sticky_threads' => 'threads.sticky',
        'can_move_threads' => 'threads.move',
        'can_split_threads' => 'threads.split',
        'can_merge_threads' => 'threads.merge',
        'can_copy_threads' => 'threads.copy',
        'can_edit_posts' => 'posts.edit',
        'can_delete_posts' => 'posts.delete',
        'can_edit_own_posts' => 'posts.edit_own',
        'can_delete_own_posts' => 'posts.delete_own',
        'can_ban_users' => 'users.ban',
        'can_suspend_users' => 'users.suspend',
        'can_create_users' => 'users.create',
        'can_delete_users' => 'users.delete',
        'can_edit_users' => 'users.edit',
        'can_manage_roles' => 'roles.manage',
        'can_manage_categories' => 'categories.manage',
        'can_manage_settings' => 'settings.manage',
        'can_manage_plugins' => 'plugins.manage',
        'can_manage_themes' => 'themes.manage',
        'can_manage_langs' => 'langs.manage',
    ];
}
