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
 * Usage:
 *   $authz = new AuthZ($pdo);
 *   $authz->can($userId, 'posts.edit');
 *   $authz->can($userId, 'posts.edit', $postAuthorId);
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
