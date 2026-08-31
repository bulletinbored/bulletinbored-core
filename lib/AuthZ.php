<?php

/**
 * AuthZ.php — authorization service.
 *
 * Centralizes permission checks so that no handler needs to know
 * how roles/permissions are stored. Supports:
 *   - Role-based permissions (from the roles table)
 *   - Plugin-contributed permissions (via permission_{name} hook)
 *   - Ownership checks (does the user own this resource?)
 *
 * Canonical permission registry (resource.action):
 *
 *   admin.access          — access admin panel (admin only)
 *   threads.create        — create new threads
 *   threads.edit          — edit any thread
 *   threads.edit_own      — edit own thread
 *   threads.delete        — delete any thread
 *   threads.delete_own    — delete own thread
 *   threads.lock          — lock/unlock threads
 *   threads.sticky        — sticky/unsticky threads
 *   threads.approve       — approve pending threads
 *   threads.move          — move thread to another category
 *   threads.split         — split thread
 *   threads.merge         — merge threads
 *   threads.copy          — copy thread
 *   posts.create          — create replies
 *   posts.edit            — edit any post
 *   posts.edit_own        — edit own post
 *   posts.delete          — delete any post
 *   posts.delete_own      — delete own post
 *   users.create          — create users (admin)
 *   users.edit            — edit any user
 *   users.delete          — delete users
 *   users.ban             — ban/unban users
 *   users.suspend         — suspend users
 *   roles.manage          — manage roles and permissions
 *   categories.manage     — create/edit/delete categories
 *   settings.manage       — modify site settings
 *   plugins.manage        — install/enable/disable plugins
 *   themes.manage         — install/activate/delete themes
 *   langs.manage          — manage language files
 *
 * Usage:
 *   $authz = new AuthZ($pdo);
 *   $authz->can($userId, 'posts.edit');
 *   $authz->canOnOwned($userId, 'posts.edit', $postAuthorId);
 *   $authz->hasRole($userId, 'admin');
 */

class AuthZ
{
    private PDO $pdo;
    private array $roleCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check if a user has a permission.
     * Optionally checks ownership when $ownerId is provided.
     */
    public function can(int $userId, string $permission, ?int $ownerId = null): bool
    {
        $role = $this->getUserRole($userId);

        // Admin always has all permissions.
        if ($role === 'admin') {
            return true;
        }

        $perms = $this->getRolePermissions($role);

        if (in_array($permission, $perms, true)) {
            // If ownership is relevant, owner can act on their own resource.
            if ($ownerId !== null) {
                return $userId === $ownerId || in_array($permission, $perms, true);
            }
            return true;
        }

        // Plugin-contributed permissions.
        global $pluginManager;
        if (isset($pluginManager) && $pluginManager->checkHook('permission_' . $permission, $role)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a user can perform an action on a specific resource owned by someone.
     * Returns true if:
     *   - User has the permission generally, OR
     *   - User is the owner and has the permission scoped to owners
     */
    public function canOnOwned(int $userId, string $permission, int $ownerId): bool
    {
        if ($userId === $ownerId) {
            return $this->can($userId, $permission . '_own') || $this->can($userId, $permission);
        }
        return $this->can($userId, $permission);
    }

    /**
     * Check if a user has a specific role.
     */
    public function hasRole(int $userId, string $role): bool
    {
        return $this->getUserRole($userId) === $role;
    }

    /**
     * Get a user's role name.
     */
    public function getUserRole(int $userId): string
    {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: 'user';
    }

    /**
     * Get all permissions for a role.
     */
    public function getRolePermissions(string $roleName): array
    {
        if (isset($this->roleCache[$roleName])) {
            return $this->roleCache[$roleName];
        }

        $stmt = $this->pdo->prepare("SELECT permissions FROM roles WHERE name = ?");
        $stmt->execute([$roleName]);
        $perms = json_decode($stmt->fetchColumn() ?: '[]', true) ?: [];

        $this->roleCache[$roleName] = $perms;
        return $perms;
    }

    /**
     * Clear the role permission cache.
     */
    public function clearCache(): void
    {
        $this->roleCache = [];
    }
}
