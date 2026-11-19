<?php

/**
 * Migration: 20261120_normalize_role_permissions.php
 *
 * Repairs installations whose roles still carry the old "can_*" permission
 * names.
 *
 * The web installer seeded `can_create_threads`, `can_edit_posts`, … while the
 * application checks the canonical `resource.action` names, so on those
 * installations every permission check failed for non-admin users: creating a
 * thread or replying threw Forbidden (and, before Errors.php was loaded at
 * runtime, a 500 with "Class not found").
 *
 * The migration maps the legacy names, and for the three default roles it adds
 * the canonical defaults — but only when a legacy name is actually present, so
 * a role an administrator deliberately trimmed is not silently widened.
 */
class NormalizeRolePermissions
{
    public function irreversible(): bool
    {
        return false;
    }

    public function up(PDO $pdo): void
    {
        require_once dirname(__DIR__) . '/lib/DefaultRoles.php';

        $map = bb_legacy_permission_map();
        $defaults = bb_default_role_permissions();

        $rows = $pdo->query('SELECT id, name, permissions FROM roles')->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return;
        }

        $update = $pdo->prepare('UPDATE roles SET permissions = ? WHERE id = ?');

        foreach ($rows as $row) {
            $current = json_decode((string)($row['permissions'] ?? ''), true);
            if (!is_array($current)) {
                $current = [];
            }
            $current = array_values(array_filter($current, 'is_string'));

            $legacyFound = false;
            $mapped = [];
            foreach ($current as $permission) {
                if (isset($map[$permission])) {
                    $legacyFound = true;
                    $mapped[] = $map[$permission];
                    continue;
                }
                $mapped[] = $permission;
            }

            $roleName = (string)$row['name'];
            if ($legacyFound && isset($defaults[$roleName])) {
                // The canonical defaults are the contract the code checks;
                // whatever an administrator added is kept.
                $mapped = array_merge($defaults[$roleName], $mapped);
            }

            $mapped = array_values(array_unique($mapped));
            if ($mapped !== $current) {
                $update->execute([json_encode($mapped), $row['id']]);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        // Irreversible: the legacy names carried no information that could not
        // be mapped back, and downgrading to them would break the forum again.
    }
}
