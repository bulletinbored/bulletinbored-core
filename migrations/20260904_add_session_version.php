<?php

/**
 * Migration: 20260904_add_session_version.php
 *
 * Adds session_version column to users table for session invalidation on password reset.
 */
class AddSessionVersion
{
    public function irreversible(): bool
    {
        return false;
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("
                ALTER TABLE users
                ADD COLUMN session_version INT NOT NULL DEFAULT 1
                AFTER email_verified
            ");
        } else {
            $pdo->exec("
                ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1
            ");
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("ALTER TABLE users DROP COLUMN session_version");
        } else {
            $pdo->exec("ALTER TABLE users DROP COLUMN session_version");
        }
    }
}
