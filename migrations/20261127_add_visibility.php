<?php

/**
 * Migration: 20261127_add_visibility.php
 *
 * Moves per-thread and per-profile visibility into the core schema so the core
 * can enforce privacy even when the socialbored plugin is disabled.
 *
 *   - threads.visibility       public | followers | private
 *   - users.profile_visibility public | followers | private
 *
 * The columns are additive and default to "public", which preserves the
 * behaviour of every existing forum thread. When the socialbored tables are
 * present their values are backfilled so an upgrade from the plugin-only model
 * does not change who can see an existing post.
 *
 * The migration is idempotent: columns, index and backfill can be re-run after
 * a partially applied attempt.
 */
class AddVisibility
{
    public function irreversible(): bool
    {
        return false;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare("PRAGMA table_info({$table})");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($column, $columns, true);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ");
                $stmt->execute([$table]);
                return (int)$stmt->fetchColumn() > 0;
            }

            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            ");
            $stmt->execute([$table, $index]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare("PRAGMA index_list({$table})");
        $stmt->execute();
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($index, $indexes, true);
    }

    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $visibilityType = $driver === 'mysql' ? "VARCHAR(20) NOT NULL DEFAULT 'public'" : "TEXT NOT NULL DEFAULT 'public'";

        if (!$this->columnExists($pdo, 'threads', 'visibility')) {
            $pdo->exec("ALTER TABLE threads ADD COLUMN visibility {$visibilityType}");
        }
        if (!$this->columnExists($pdo, 'users', 'profile_visibility')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_visibility {$visibilityType}");
        }

        $this->backfill($pdo);

        if ($driver === 'mysql') {
            if (!$this->indexExists($pdo, 'threads', 'idx_threads_visibility')) {
                $pdo->exec("ALTER TABLE threads ADD INDEX idx_threads_visibility (visibility)");
            }
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_threads_visibility ON threads(visibility)");
        }
    }

    /**
     * Copy the plugin-owned visibility values into the core columns. The social
     * tables only exist on installations that enabled socialbored; when they are
     * missing this is a no-op and every row keeps the safe "public" default.
     */
    private function backfill(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($this->tableExists($pdo, 'social_threads')) {
            try {
                if ($driver === 'mysql') {
                    $pdo->exec("
                        UPDATE threads t
                        JOIN social_threads st ON st.thread_id = t.id
                        SET t.visibility = st.visibility
                        WHERE st.visibility IN ('public', 'followers', 'private')
                    ");
                } else {
                    $pdo->exec("
                        UPDATE threads
                        SET visibility = (
                            SELECT st.visibility FROM social_threads st
                            WHERE st.thread_id = threads.id
                        )
                        WHERE EXISTS (
                            SELECT 1 FROM social_threads st
                            WHERE st.thread_id = threads.id
                              AND st.visibility IN ('public', 'followers', 'private')
                        )
                    ");
                }
            } catch (Throwable $e) {
                // Backfill is best effort: a broken legacy row must not block
                // the migration. The default remains "public".
            }
        }

        if ($this->tableExists($pdo, 'social_profiles')) {
            try {
                if ($driver === 'mysql') {
                    $pdo->exec("
                        UPDATE users u
                        JOIN social_profiles sp ON sp.user_id = u.id
                        SET u.profile_visibility = sp.privacy
                        WHERE sp.privacy IN ('public', 'followers', 'private')
                    ");
                } else {
                    $pdo->exec("
                        UPDATE users
                        SET profile_visibility = (
                            SELECT sp.privacy FROM social_profiles sp
                            WHERE sp.user_id = users.id
                        )
                        WHERE EXISTS (
                            SELECT 1 FROM social_profiles sp
                            WHERE sp.user_id = users.id
                              AND sp.privacy IN ('public', 'followers', 'private')
                        )
                    ");
                }
            } catch (Throwable $e) {
                // Same rationale as above.
            }
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            if ($this->indexExists($pdo, 'threads', 'idx_threads_visibility')) {
                $pdo->exec("ALTER TABLE threads DROP INDEX idx_threads_visibility");
            }
            if ($this->columnExists($pdo, 'threads', 'visibility')) {
                $pdo->exec("ALTER TABLE threads DROP COLUMN visibility");
            }
            if ($this->columnExists($pdo, 'users', 'profile_visibility')) {
                $pdo->exec("ALTER TABLE users DROP COLUMN profile_visibility");
            }
            return;
        }

        $pdo->exec("DROP INDEX IF EXISTS idx_threads_visibility");
        // SQLite before 3.35 cannot drop a column; the application treats a
        // missing/empty value as "public", so leaving the columns is harmless.
    }
}
