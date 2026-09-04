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
 * the migration fails with a clear error message and does NOT leave the
 * database in a partially migrated state.
 */
class AddForeignKeys
{
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

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("ALTER TABLE threads
                ADD CONSTRAINT fk_threads_category
                FOREIGN KEY (category_id) REFERENCES categories(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE threads
                ADD CONSTRAINT fk_threads_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE posts
                ADD CONSTRAINT fk_posts_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE posts
                ADD CONSTRAINT fk_posts_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_post
                FOREIGN KEY (post_id) REFERENCES posts(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE thread_watchers
                ADD CONSTRAINT fk_watchers_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE thread_watchers
                ADD CONSTRAINT fk_watchers_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE notifications
                ADD CONSTRAINT fk_notifications_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE private_messages
                ADD CONSTRAINT fk_pm_sender
                FOREIGN KEY (sender_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE private_messages
                ADD CONSTRAINT fk_pm_recipient
                FOREIGN KEY (recipient_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE email_verifications
                ADD CONSTRAINT fk_ev_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE password_resets
                ADD CONSTRAINT fk_pr_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
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
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("ALTER TABLE threads DROP FOREIGN KEY IF EXISTS fk_threads_category");
            $pdo->exec("ALTER TABLE threads DROP FOREIGN KEY IF EXISTS fk_threads_user");
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY IF EXISTS fk_posts_thread");
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY IF EXISTS fk_posts_user");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_thread");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_post");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_user");
            $pdo->exec("ALTER TABLE thread_watchers DROP FOREIGN KEY IF EXISTS fk_watchers_thread");
            $pdo->exec("ALTER TABLE thread_watchers DROP FOREIGN KEY IF EXISTS fk_watchers_user");
            $pdo->exec("ALTER TABLE notifications DROP FOREIGN KEY IF EXISTS fk_notifications_user");
            $pdo->exec("ALTER TABLE private_messages DROP FOREIGN KEY IF EXISTS fk_pm_sender");
            $pdo->exec("ALTER TABLE private_messages DROP FOREIGN KEY IF EXISTS fk_pm_recipient");
            $pdo->exec("ALTER TABLE email_verifications DROP FOREIGN KEY IF EXISTS fk_ev_user");
            $pdo->exec("ALTER TABLE password_resets DROP FOREIGN KEY IF EXISTS fk_pr_user");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_category ON threads");
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_user ON threads");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_thread ON posts");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_user ON posts");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_thread ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_post ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_user ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_thread ON thread_watchers");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_user ON thread_watchers");
            $pdo->exec("DROP INDEX IF EXISTS idx_notifications_user ON notifications");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_sender ON private_messages");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_recipient ON private_messages");
            $pdo->exec("DROP INDEX IF EXISTS idx_ev_user ON email_verifications");
            $pdo->exec("DROP INDEX IF EXISTS idx_pr_user ON password_resets");
        }
    }
}
    }

    public function irreversible(): bool
    {
        return false;
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $orphans = [];

        if ($driver === 'mysql') {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("ALTER TABLE threads
                ADD CONSTRAINT fk_threads_category
                FOREIGN KEY (category_id) REFERENCES categories(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE threads
                ADD CONSTRAINT fk_threads_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE posts
                ADD CONSTRAINT fk_posts_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE posts
                ADD CONSTRAINT fk_posts_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_post
                FOREIGN KEY (post_id) REFERENCES posts(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE thread_watchers
                ADD CONSTRAINT fk_watchers_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE thread_watchers
                ADD CONSTRAINT fk_watchers_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE notifications
                ADD CONSTRAINT fk_notifications_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE private_messages
                ADD CONSTRAINT fk_pm_sender
                FOREIGN KEY (sender_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE private_messages
                ADD CONSTRAINT fk_pm_recipient
                FOREIGN KEY (recipient_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE email_verifications
                ADD CONSTRAINT fk_ev_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE password_resets
                ADD CONSTRAINT fk_pr_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
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
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("ALTER TABLE threads DROP FOREIGN KEY IF EXISTS fk_threads_category");
            $pdo->exec("ALTER TABLE threads DROP FOREIGN KEY IF EXISTS fk_threads_user");
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY IF EXISTS fk_posts_thread");
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY IF EXISTS fk_posts_user");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_thread");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_post");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_user");
            $pdo->exec("ALTER TABLE thread_watchers DROP FOREIGN KEY IF EXISTS fk_watchers_thread");
            $pdo->exec("ALTER TABLE thread_watchers DROP FOREIGN KEY IF EXISTS fk_watchers_user");
            $pdo->exec("ALTER TABLE notifications DROP FOREIGN KEY IF EXISTS fk_notifications_user");
            $pdo->exec("ALTER TABLE private_messages DROP FOREIGN KEY IF EXISTS fk_pm_sender");
            $pdo->exec("ALTER TABLE private_messages DROP FOREIGN KEY IF EXISTS fk_pm_recipient");
            $pdo->exec("ALTER TABLE email_verifications DROP FOREIGN KEY IF EXISTS fk_ev_user");
            $pdo->exec("ALTER TABLE password_resets DROP FOREIGN KEY IF EXISTS fk_pr_user");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_category ON threads");
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_user ON threads");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_thread ON posts");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_user ON posts");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_thread ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_post ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_user ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_thread ON thread_watchers");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_user ON thread_watchers");
            $pdo->exec("DROP INDEX IF EXISTS idx_notifications_user ON notifications");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_sender ON private_messages");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_recipient ON private_messages");
            $pdo->exec("DROP INDEX IF EXISTS idx_ev_user ON email_verifications");
            $pdo->exec("DROP INDEX IF EXISTS idx_pr_user ON password_resets");
        }
    }
}

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("ALTER TABLE threads
                ADD CONSTRAINT fk_threads_category
                FOREIGN KEY (category_id) REFERENCES categories(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE threads
                ADD CONSTRAINT fk_threads_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE posts
                ADD CONSTRAINT fk_posts_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE posts
                ADD CONSTRAINT fk_posts_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_post
                FOREIGN KEY (post_id) REFERENCES posts(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE uploads
                ADD CONSTRAINT fk_uploads_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE thread_watchers
                ADD CONSTRAINT fk_watchers_thread
                FOREIGN KEY (thread_id) REFERENCES threads(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE thread_watchers
                ADD CONSTRAINT fk_watchers_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE notifications
                ADD CONSTRAINT fk_notifications_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE private_messages
                ADD CONSTRAINT fk_pm_sender
                FOREIGN KEY (sender_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE private_messages
                ADD CONSTRAINT fk_pm_recipient
                FOREIGN KEY (recipient_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE email_verifications
                ADD CONSTRAINT fk_ev_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("ALTER TABLE password_resets
                ADD CONSTRAINT fk_pr_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
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
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $pdo->exec("ALTER TABLE threads DROP FOREIGN KEY IF EXISTS fk_threads_category");
            $pdo->exec("ALTER TABLE threads DROP FOREIGN KEY IF EXISTS fk_threads_user");
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY IF EXISTS fk_posts_thread");
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY IF EXISTS fk_posts_user");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_thread");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_post");
            $pdo->exec("ALTER TABLE uploads DROP FOREIGN KEY IF EXISTS fk_uploads_user");
            $pdo->exec("ALTER TABLE thread_watchers DROP FOREIGN KEY IF EXISTS fk_watchers_thread");
            $pdo->exec("ALTER TABLE thread_watchers DROP FOREIGN KEY IF EXISTS fk_watchers_user");
            $pdo->exec("ALTER TABLE notifications DROP FOREIGN KEY IF EXISTS fk_notifications_user");
            $pdo->exec("ALTER TABLE private_messages DROP FOREIGN KEY IF EXISTS fk_pm_sender");
            $pdo->exec("ALTER TABLE private_messages DROP FOREIGN KEY IF EXISTS fk_pm_recipient");
            $pdo->exec("ALTER TABLE email_verifications DROP FOREIGN KEY IF EXISTS fk_ev_user");
            $pdo->exec("ALTER TABLE password_resets DROP FOREIGN KEY IF EXISTS fk_pr_user");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_category ON threads");
            $pdo->exec("DROP INDEX IF EXISTS idx_threads_user ON threads");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_thread ON posts");
            $pdo->exec("DROP INDEX IF EXISTS idx_posts_user ON posts");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_thread ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_post ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_uploads_user ON uploads");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_thread ON thread_watchers");
            $pdo->exec("DROP INDEX IF EXISTS idx_watchers_user ON thread_watchers");
            $pdo->exec("DROP INDEX IF EXISTS idx_notifications_user ON notifications");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_sender ON private_messages");
            $pdo->exec("DROP INDEX IF EXISTS idx_pm_recipient ON private_messages");
            $pdo->exec("DROP INDEX IF EXISTS idx_ev_user ON email_verifications");
            $pdo->exec("DROP INDEX IF EXISTS idx_pr_user ON password_resets");
        }
    }
}
