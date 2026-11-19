<?php

/**
 * AuthzHookFailureTest — a plugin permission hook must not break the core.
 *
 * AuthZ::can() returns early for administrators, so the plugin hook is only
 * consulted for everybody else. Without a handler, a throwing hook produced a
 * 500 on every action a normal user attempted while the admin kept working.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function authz_hook_pdo(): PDO
{
    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)");
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[\"threads.create\"]'), ('admin', '[]')");
    $pdo->exec("INSERT INTO users (id, username, role) VALUES (1, 'admin', 'admin'), (2, 'member', 'user')");
    return $pdo;
}

function test_authz_survives_a_throwing_permission_hook(): Test
{
    $t = new Test('AuthZ - a throwing permission hook is contained');

    $pdo = authz_hook_pdo();
    $authz = new AuthZ($pdo);

    $base = sys_get_temp_dir() . '/bb_authz_' . uniqid('', true);
    @mkdir($base . '/plugins', 0755, true);
    $plugins = new PluginManager($base . '/plugins', $base . '/plugins.json');
    $plugins->addHook('permission_threads.approve', function (): bool {
        throw new RuntimeException('plugin exploded');
    });
    $GLOBALS['pluginManager'] = $plugins;

    try {
        // The normal permission still works for a regular user.
        $t->assertTrue('Ordinary permission unaffected', $authz->can(2, 'threads.create'));

        $threw = false;
        $allowed = true;
        try {
            $allowed = $authz->can(2, 'threads.approve');
        } catch (Throwable $e) {
            $threw = true;
        }
        $t->assertFalse('A throwing hook does not propagate', $threw);
        $t->assertFalse('A failing hook grants nothing (fail closed)', $allowed);

        // The administrator never reaches the hook.
        $t->assertTrue('Admin still authorized', $authz->can(1, 'threads.approve'));

        // Plugin-granted permissions still work when the hook behaves.
        $plugins2 = new PluginManager($base . '/plugins', $base . '/plugins.json');
        $plugins2->addHook('permission_posts.pin', fn(): bool => true);
        $GLOBALS['pluginManager'] = $plugins2;
        $t->assertTrue('A working hook still grants permission', $authz->can(2, 'posts.pin'));
    } finally {
        unset($GLOBALS['pluginManager']);
        @unlink($base . '/plugins.json');
        @rmdir($base . '/plugins');
        @rmdir($base);
    }

    return $t;
}

register_tests('test_authz_survives_a_throwing_permission_hook');
