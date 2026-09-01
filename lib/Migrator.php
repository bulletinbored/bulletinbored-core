<?php

/**
 * Migrator.php — file-based migration system.
 *
 * Migrations are PHP files in migrations/ directory with up() and down() methods.
 * Each migration is tracked in the `migrations` table with name, batch, and timestamp.
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
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration TEXT NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    ran_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
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
     * Run a single migration's up() method within a transaction.
     * On failure, the migration is NOT recorded and the transaction is rolled back.
     */
    public function runUp(array $migration, int $batch): void
    {
        $instance = $this->loadMigration($migration['path']);

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $supportsTransactionalDdl = ($driver === 'mysql');

        try {
            if ($supportsTransactionalDdl) {
                $this->pdo->beginTransaction();
            }

            $instance->up($this->pdo);

            $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
            $stmt->execute([$migration['name'], $batch]);

            if ($supportsTransactionalDdl) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($supportsTransactionalDdl && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException(
                "Migration '{$migration['name']}' failed: " . $e->getMessage(),
                0,
                $e
            );
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
     * Run all pending migrations with file-based locking.
     * Prevents concurrent migration runs on the same database.
     *
     * @return array Names of migrations that were run.
     * @throws RuntimeException if locking fails or a migration fails.
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $pending = $this->getPending();

        if (empty($pending)) {
            return [];
        }

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
        $lastBatch = $this->getLastBatch();

        if ($lastBatch === null) {
            return [];
        }

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
