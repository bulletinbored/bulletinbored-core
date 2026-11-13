<?php

/**
 * Migrator.php — file-based migration system.
 *
 * Migrations are PHP files in migrations/ directory with up() and down() methods.
 * Each migration is tracked in the `migrations` table with name, batch, and timestamp.
 *
 * Recovery after a failed migration
 * --------------------------------
 * SQLite rolls back DDL inside a transaction, so a failed migration leaves no
 * trace. MySQL/MariaDB commit DDL implicitly: if the third statement of a
 * migration fails, the first two are already applied while the migration is
 * not recorded, and the next run repeats all of it.
 *
 * To make that state visible and recoverable, every run is announced in the
 * `migration_progress` table before up() executes and cleared when it
 * completes. A row left behind therefore means "this migration is partially
 * applied":
 *
 *   $migrator->getInterruptedMigrations();   // name, batch, started_at, error
 *   $migrator->forgetInterrupted($name);     // after manually fixing the DB
 *   $migrator->recoverInterrupted($name);    // fix applied: record as ran
 *
 * Core migrations are written to be idempotent (CREATE TABLE IF NOT EXISTS,
 * guarded ALTERs), so retrying through migrate() is a normal recovery path.
 *
 * Plugins can register their own migration paths via addPath().
 *
 * Usage:
 *   $migrator = new Migrator($pdo, $config);
 *   $migrator->ensureMigrationsTable();
 *   $migrator->addPath(__DIR__ . '/plugins/myplugin/migrations');
 *   $migrator->migrate();  // runs all pending
 *   $migrator->rollback(); // rolls back last batch
 */

class Migrator
{
    private PDO $pdo;
    private array $config;
    private string $migrationsDir;
    private array $extraPaths = [];

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->migrationsDir = dirname(__DIR__) . '/migrations';
    }

    /**
     * Register an additional directory to scan for migration files.
     * Used by plugins to provide their own migrations.
     */
    public function addPath(string $path): self
    {
        $path = rtrim($path, '/');
        if (is_dir($path) && !in_array($path, $this->extraPaths, true)) {
            $this->extraPaths[] = $path;
        }
        return $this;
    }

    /**
     * Register migration paths from all enabled plugins.
     * Looks for a 'migrations' subdirectory in each plugin folder.
     */
    public function addPluginPaths(string $pluginsDir): self
    {
        $pluginsDir = rtrim($pluginsDir, '/');
        if (!is_dir($pluginsDir)) {
            return $this;
        }

        foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) as $dir) {
            $migrationsDir = $dir . '/migrations';
            if (is_dir($migrationsDir)) {
                $this->addPath($migrationsDir);
            }
        }

        return $this;
    }

    /**
     * Create the migrations tracking table if it doesn't exist.
     */
    public function ensureMigrationsTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    batch INT NOT NULL,
                    ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS migration_progress (
                    migration VARCHAR(255) NOT NULL PRIMARY KEY,
                    batch INT NOT NULL,
                    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    error TEXT NULL
                )
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration TEXT NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS migration_progress (
                    migration TEXT NOT NULL PRIMARY KEY,
                    batch INTEGER NOT NULL,
                    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    error TEXT NULL
                )
            ");
        }
    }

    /**
     * Whether the driver can roll back DDL inside a transaction.
     * Protected so a subclass (and the test suite) can emulate a driver
     * without transactional DDL.
     */
    protected function supportsTransactionalDdl(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /**
     * Get all migration files from all registered directories.
     * Returns sorted array of ['name' => ..., 'path' => ..., 'source' => ...].
     * Source indicates origin: 'core' or plugin folder name.
     * Migration IDs are namespaced: "core:filename" or "plugin:filename".
     */
    public function getAllMigrations(): array
    {
        $directories = array_merge([$this->migrationsDir], $this->extraPaths);
        $migrations = [];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.php');
            $isCore = ($dir === $this->migrationsDir);
            $source = $isCore ? 'core' : basename(dirname($dir));

            foreach ($files as $file) {
                $baseName = basename($file, '.php');
                // Namespaced ID: core:20260829_initial_schema or myplugin:2026001_create_table
                $name = $isCore ? 'core:' . $baseName : $source . ':' . $baseName;
                $migrations[] = [
                    'name' => $name,
                    'path' => $file,
                    'source' => $source,
                ];
            }
        }

        usort($migrations, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $migrations;
    }

    /**
     * Get names of all ran migrations.
     */
    public function getRanMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get pending migrations (files that haven't been run).
     */
    public function getPending(): array
    {
        $all = $this->getAllMigrations();
        $ran = $this->getRanMigrations();

        return array_filter($all, fn($m) => !in_array($m['name'], $ran, true));
    }

    /**
     * Get the next batch number.
     */
    public function getNextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM migrations");
        $max = $stmt->fetchColumn();
        return ($max !== null ? (int)$max : 0) + 1;
    }

    /**
     * Get the last batch number.
     */
    public function getLastBatch(): ?int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM migrations");
        $max = $stmt->fetchColumn();
        return $max !== null ? (int)$max : null;
    }

    /**
     * Get migrations in a specific batch.
     */
    public function getMigrationsByBatch(int $batch): array
    {
        $stmt = $this->pdo->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $all = $this->getAllMigrations();
        $result = [];

        foreach ($names as $name) {
            foreach ($all as $migration) {
                if ($migration['name'] === $name) {
                    $result[] = $migration;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Get the batch number for a specific migration.
     */
    public function getBatchFor(string $name): ?int
    {
        $stmt = $this->pdo->prepare("SELECT batch FROM migrations WHERE migration = ?");
        $stmt->execute([$name]);
        $batch = $stmt->fetchColumn();
        return $batch !== false ? (int)$batch : null;
    }

    /**
     * Run a single migration's up() method.
     *
     * On SQLite the whole migration runs inside a transaction and a failure
     * leaves no trace. On MySQL DDL cannot be rolled back, so the run is
     * announced in migration_progress first and the error is recorded there
     * when up() fails; the migration stays pending and can be re-run (core
     * migrations are idempotent) or repaired manually.
     */
    public function runUp(array $migration, int $batch): void
    {
        $instance = $this->loadMigration($migration['path']);
        $transactional = $this->supportsTransactionalDdl();

        $this->markStarted($migration['name'], $batch, !$transactional);

        try {
            if ($transactional) {
                $this->pdo->beginTransaction();
            }

            $instance->up($this->pdo);

            $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
            $stmt->execute([$migration['name'], $batch]);

            if ($transactional) {
                $this->pdo->commit();
            }

            $this->clearProgress($migration['name']);
        } catch (\Throwable $e) {
            if ($transactional && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $hint = '';
            if ($transactional) {
                // Nothing was applied: drop the marker, a retry starts clean.
                $this->clearProgress($migration['name']);
            } else {
                $this->recordFailure($migration['name'], $e->getMessage());
                $hint = ' The driver cannot roll back schema changes: this migration may be partially applied.'
                    . ' Core migrations are idempotent, so running the updater again is safe; otherwise repair'
                    . ' the schema manually and call recoverInterrupted().';
            }

            throw new RuntimeException(
                "Migration '{$migration['name']}' failed: " . $e->getMessage() . $hint,
                0,
                $e
            );
        }
    }

    /**
     * Migrations that started but never completed (non-transactional drivers).
     *
     * @return array<int, array{migration: string, batch: int, started_at: string, error: ?string}>
     */
    public function getInterruptedMigrations(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT migration, batch, started_at, error FROM migration_progress ORDER BY started_at ASC");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isInterrupted(string $name): bool
    {
        foreach ($this->getInterruptedMigrations() as $row) {
            if ($row['migration'] === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Forget an interrupted migration without recording it as applied.
     * Use after repairing the schema by hand so the migration runs again.
     */
    public function forgetInterrupted(string $name): void
    {
        $this->clearProgress($name);
    }

    /**
     * Record an interrupted migration as applied without re-running up().
     * Use when the schema was completed manually after a partial run.
     */
    public function recoverInterrupted(string $name, ?int $batch = null): void
    {
        $batch = $batch ?? $this->getNextBatch();
        $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$name, $batch]);
        $this->clearProgress($name);
    }

    /**
     * Announce a migration run in migration_progress.
     *
     * On drivers with transactional DDL this is best effort: a failure would
     * not lose recoverable state, because a failed migration is rolled back.
     * On MySQL/MariaDB it is mandatory: without the marker an interrupted
     * migration leaves a half-applied schema with no trace of what happened,
     * so the migration is refused instead of being run blind.
     */
    private function markStarted(string $name, int $batch, bool $required = false): void
    {
        try {
            $this->pdo->prepare("DELETE FROM migration_progress WHERE migration = ?")->execute([$name]);
            $this->pdo->prepare("INSERT INTO migration_progress (migration, batch) VALUES (?, ?)")
                ->execute([$name, $batch]);

            if ($required && !$this->progressExists($name)) {
                throw new RuntimeException('the progress row could not be read back');
            }
        } catch (\Throwable $e) {
            if ($required) {
                throw new RuntimeException(
                    "Cannot record the start of migration '{$name}' in migration_progress: " . $e->getMessage()
                    . ' This driver cannot roll schema changes back, so running the migration without a recovery'
                    . ' marker is unsafe. Fix the database permissions/table and retry.',
                    0,
                    $e
                );
            }
            // Transactional driver: the migration can be rolled back on failure.
            error_log('Migrator: cannot record progress for ' . $name . ': ' . $e->getMessage());
        }
    }

    private function progressExists(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM migration_progress WHERE migration = ?");
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function recordFailure(string $name, string $error): void
    {
        try {
            $this->pdo->prepare("UPDATE migration_progress SET error = ? WHERE migration = ?")
                ->execute([substr($error, 0, 2000), $name]);
        } catch (\Throwable $e) {
            error_log('Migrator: cannot record failure for ' . $name . ': ' . $e->getMessage());
        }
    }

    private function clearProgress(string $name): void
    {
        try {
            $this->pdo->prepare("DELETE FROM migration_progress WHERE migration = ?")->execute([$name]);
        } catch (\Throwable $e) {
            // Table may not exist on very old installations; ignore.
        }
    }

    /**
     * Run a single migration's down() method.
     * If the migration declares itself irreversible (via irreversible() returning true),
     * the down() is skipped and only the tracking record is removed.
     */
    public function runDown(array $migration): void
    {
        $instance = $this->loadMigration($migration['path']);

        if (method_exists($instance, 'irreversible') && $instance->irreversible()) {
            // Irreversible migration: cannot roll back schema, just remove tracking.
            trigger_error(
                "Migration '{$migration['name']}' is irreversible — skipping down().",
                E_USER_WARNING
            );
        } else {
            $instance->down($this->pdo);
        }

        $stmt = $this->pdo->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$migration['name']]);
    }

    /**
     * Rollback a migration by name.
     */
    public function rollbackByName(string $name): void
    {
        $all = $this->getAllMigrations();
        foreach ($all as $migration) {
            if ($migration['name'] === $name) {
                $this->runDown($migration);
                return;
            }
        }
        throw new RuntimeException("Migration not found: {$name}");
    }

    /**
     * Retrying an interrupted migration is only safe when the migration is
     * idempotent. Core migrations are written that way, and a plugin can opt in
     * by implementing isIdempotent(): bool.
     *
     * Everything else is stopped with an actionable error: re-running half a
     * plugin migration automatically could duplicate non-reversible work
     * (data imports, column drops, external calls).
     *
     * @throws RuntimeException when an interrupted non-core migration is not
     *                          declared idempotent.
     */
    private function assertInterruptedMigrationsAreRetryable(array $pending): void
    {
        $pendingByName = [];
        foreach ($pending as $migration) {
            $pendingByName[$migration['name']] = $migration;
        }

        foreach ($this->getInterruptedMigrations() as $interrupted) {
            $name = (string)$interrupted['migration'];
            if (!isset($pendingByName[$name])) {
                continue; // already recorded/recovered, nothing to retry
            }
            if (str_starts_with($name, 'core:')) {
                continue; // core migrations are idempotent by construction
            }

            $instance = null;
            try {
                $instance = $this->loadMigration($pendingByName[$name]['path']);
            } catch (\Throwable $e) {
                error_log('Migrator: cannot load interrupted migration ' . $name . ': ' . $e->getMessage());
            }

            if ($instance !== null && method_exists($instance, 'isIdempotent') && $instance->isIdempotent()) {
                continue; // the plugin vouches for a safe retry
            }

            throw new RuntimeException(
                "Migration '{$name}' was interrupted and is not declared idempotent"
                . (!empty($interrupted['error']) ? ' (last error: ' . $interrupted['error'] . ')' : '')
                . '. It was not retried automatically: repair the schema and call recoverInterrupted() to record it,'
                . ' or call forgetInterrupted() to discard the marker and retry deliberately.'
            );
        }
    }

    /**
     * Run all pending migrations with file-based locking.
     * Prevents concurrent migration runs on the same database.
     *
     * @return array Names of migrations that were run.
     * @throws RuntimeException if locking fails or a migration fails.
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $lockFile = $this->getLockFile();
        $lockHandle = fopen($lockFile, 'c');

        if ($lockHandle === false) {
            throw new RuntimeException("Cannot create migration lock file: {$lockFile}");
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new RuntimeException("Another migration is already running. Lock file: {$lockFile}");
        }

        $pending = $this->getPending();

        if (empty($pending)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return [];
        }

        try {
            $this->assertInterruptedMigrationsAreRetryable($pending);
            $pendingNames = array_column($pending, 'name');
            foreach ($this->getInterruptedMigrations() as $interrupted) {
                if (in_array($interrupted['migration'], $pendingNames, true)) {
                    error_log(sprintf(
                        'Migrator: retrying interrupted migration %s (previous error: %s)',
                        $interrupted['migration'],
                        $interrupted['error'] ?? 'unknown'
                    ));
                }
            }

            $batch = $this->getNextBatch();
            $ran = [];

            foreach ($pending as $migration) {
                $this->runUp($migration, $batch);
                $ran[] = $migration['name'];
            }

            return $ran;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Rollback the last batch of migrations.
     *
     * @return Array of migration names that were rolled back.
     */
    public function rollback(): array
    {
        $this->ensureMigrationsTable();

        $lockFile = $this->getLockFile();
        $lockHandle = fopen($lockFile, 'c');

        if ($lockHandle === false) {
            throw new RuntimeException("Cannot create migration lock file: {$lockFile}");
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new RuntimeException("Another migration is already running. Lock file: {$lockFile}");
        }

        try {
            $lastBatch = $this->getLastBatch();
            if ($lastBatch === null) {
                return [];
            }

            $migrations = $this->getMigrationsByBatch($lastBatch);
            $rolledBack = [];

            foreach ($migrations as $migration) {
                $this->runDown($migration);
                $rolledBack[] = $migration['name'];
            }

            return $rolledBack;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Get the path to the migration lock file.
     */
    private function getLockFile(): string
    {
        $dir = dirname($this->migrationsDir) . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/.migration_lock';
    }

    /**
     * Load a migration file and return an instance.
     */
    private function loadMigration(string $path): object
    {
        require_once $path;

        $className = $this->getClassNameFromFile($path);

        if (!class_exists($className)) {
            throw new RuntimeException("Migration class '{$className}' not found in {$path}");
        }

        return new $className();
    }

    /**
     * Extract class name from migration file.
     * Convention: filename 20260829_add_views.php -> class AddViews
     */
    private function getClassNameFromFile(string $path): string
    {
        $basename = basename($path, '.php');

        if (preg_match('/^\d{8}_(.+)$/', $basename, $matches)) {
            $basename = $matches[1];
        }

        $parts = explode('_', $basename);
        return implode('', array_map('ucfirst', $parts));
    }
}
