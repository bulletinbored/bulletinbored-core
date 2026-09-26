<?php

/**
 * Migration: 20260904_add_foreign_keys.php
 *
 * Adds foreign key constraints to enforce referential integrity.
 *
 * IMPORTANT: For MySQL, this adds actual FK constraints via ALTER TABLE.
 * For SQLite, this migration enables PRAGMA foreign_keys = ON and creates
 * indexes for performance, but CANNOT add FK constraints via ALTER TABLE
 * (SQLite requires table recreation). SQLite FK enforcement depends on the
 * original schema definition and the application-level checks.
 *
 * INTEGRITY AUDIT: This migration performs an integrity check BEFORE adding
 * any constraints. If orphan records exist (referenced IDs that don't exist),
 * the migration fails with a clear error message before any constraint is
 * created.
 *
 * ATOMICITY NOTE: MySQL DDL is not transactional, so this migration cannot be
 * fully atomic. It is, however, both idempotent (constraints that already exist
 * are skipped) and self-healing: if an ALTER fails midway, every constraint
 * created during this run is dropped again before the error is rethrown, so no
 * partial state is left behind. A re-run also completes constraints left over
 * from an older partial run.
 */
class AddForeignKeys
{
    /**
     * Ordered FK definitions:
     * [table, constraint, column, refTable, refColumn, onDelete, onUpdate].
     */
    private function foreignKeySpecs(): array
    {
        return [
            ['threads', 'fk_threads_category', 'category_id', 'categories', 'id', 'SET NULL', 'CASCADE'],
            ['threads', 'fk_threads_user', 'user_id', 'users', 'id', 'SET NULL', 'CASCADE'],
            ['posts', 'fk_posts_thread', 'thread_id', 'threads', 'id', 'CASCADE', 'CASCADE'],
            ['posts', 'fk_posts_user', 'user_id', 'users', 'id', 'SET NULL', 'CASCADE'],
            ['uploads', 'fk_uploads_thread', 'thread_id', 'threads', 'id', 'CASCADE', 'CASCADE'],
            ['uploads', 'fk_uploads_post', 'post_id', 'posts', 'id', 'CASCADE', 'CASCADE'],
            ['uploads', 'fk_uploads_user', 'user_id', 'users', 'id', 'SET NULL', 'CASCADE'],
            ['thread_watchers', 'fk_watchers_thread', 'thread_id', 'threads', 'id', 'CASCADE', 'CASCADE'],
            ['thread_watchers', 'fk_watchers_user', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE'],
            ['notifications', 'fk_notifications_user', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE'],
            ['private_messages', 'fk_pm_sender', 'sender_id', 'users', 'id', 'CASCADE', 'CASCADE'],
            ['private_messages', 'fk_pm_recipient', 'recipient_id', 'users', 'id', 'CASCADE', 'CASCADE'],
            ['email_verifications', 'fk_ev_user', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE'],
            ['password_resets', 'fk_pr_user', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE'],
        ];
    }

    /**
     * Constraint names already present in the current schema. Used to make the
     * migration idempotent and to drive a safe down().
     */
    private function existingConstraintNames(PDO $pdo): array
    {
        $names = [];
        $stmt = $pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $names[(string)$name] = true;
        }
        return $names;
    }

    private function checkOrphansAndFail(PDO $pdo, string $table, string $fkCol, string $refTable, string $refCol): void
    {
        $sql = "SELECT COUNT(*) FROM {$table} t
                LEFT JOIN {$refTable} r ON t.{$fkCol} = r.{$refCol}
                WHERE t.{$fkCol} IS NOT NULL AND r.{$refCol} IS NULL";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            throw new RuntimeException(
                "Migration aborted: found {$count} orphan record(s) in `{$table}` " .
                "where `{$fkCol}` references non-existent `{$refTable}.{$refCol}`. " .
                "Clean up orphaned records before running this migration."
            );
        }
    }

    public function irreversible(): bool
    {
        return false;
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $this->checkOrphansAndFail($pdo, 'threads', 'category_id', 'categories', 'id');
            $this->checkOrphansAndFail($pdo, 'threads', 'user_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'posts', 'thread_id', 'threads', 'id');
            $this->checkOrphansAndFail($pdo, 'posts', 'user_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'uploads', 'thread_id', 'threads', 'id');
            $this->checkOrphansAndFail($pdo, 'uploads', 'post_id', 'posts', 'id');
            $this->checkOrphansAndFail($pdo, 'uploads', 'user_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'thread_watchers', 'thread_id', 'threads', 'id');
            $this->checkOrphansAndFail($pdo, 'thread_watchers', 'user_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'notifications', 'user_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'private_messages', 'sender_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'private_messages', 'recipient_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'email_verifications', 'user_id', 'users', 'id');
            $this->checkOrphansAndFail($pdo, 'password_resets', 'user_id', 'users', 'id');

            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                $existing = $this->existingConstraintNames($pdo);
                $created = [];
                foreach ($this->foreignKeySpecs() as $spec) {
                    [$table, $constraint, $column, $refTable, $refColumn, $onDelete, $onUpdate] = $spec;

                    // Idempotent: a previous partial run may already have
                    // created some of these constraints.
                    if (isset($existing[$constraint])) {
                        continue;
                    }

                    $pdo->exec("ALTER TABLE {$table}
                        ADD CONSTRAINT {$constraint}
                        FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn})
                        ON DELETE {$onDelete} ON UPDATE {$onUpdate}");
                    $created[] = [$table, $constraint];
                }
            } catch (\Throwable $e) {
                // MySQL DDL is not transactional. Undo the constraints added
                // during this run so the migration does not leave a partial
                // state behind.
                foreach ($created ?? [] as [$table, $constraint]) {
                    try {
                        $pdo->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
                    } catch (\Throwable $ignore) {
                        // Best effort; the original error is rethrown below.
                    }
                }
                throw $e;
            } finally {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        } else {
            $pdo->exec("PRAGMA foreign_keys = ON");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_threads_category ON threads(category_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_threads_user ON threads(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_thread ON posts(thread_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_user ON posts(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uploads_thread ON uploads(thread_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uploads_post ON uploads(post_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uploads_user ON uploads(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_watchers_thread ON thread_watchers(thread_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_watchers_user ON thread_watchers(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_sender ON private_messages(sender_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pm_recipient ON private_messages(recipient_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ev_user ON email_verifications(user_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pr_user ON password_resets(user_id)");
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                $existing = $this->existingConstraintNames($pdo);
                foreach ($this->foreignKeySpecs() as $spec) {
                    [$table, $constraint] = $spec;
                    if (!isset($existing[$constraint])) {
                        continue;
                    }
                    $pdo->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
                }
            } finally {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        } else {
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_category");
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_user");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_thread");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_user");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_thread");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_post");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_user");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_thread");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_user");
            $pdo->exec("DROP INDEX IF EXISTS idx_notifications_user");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_sender");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_recipient");
            $pdo->exec("DROP INDEX IF EXISTS idx_ev_user");
            $pdo->exec("DROP INDEX IF EXISTS idx_pr_user");
        }
    }
}
