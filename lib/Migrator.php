<?php

/**
 * Migrator.php — file-based migration system.
 *
 * Migrations are PHP files in migrations/ directory with up() and down() methods.
 * Each migration is tracked in the `migrations` table with name, batch, and timestamp.
 *
 * Usage:
 *   $migrator = new Migrator($pdo, $config);
 *   $migrator->ensureMigrationsTable();
 *   $migrator->migrate();  // runs all pending
 *   $migrator->rollback(); // rolls back last batch
 */

class Migrator
{
    private PDO $pdo;
    private array $config;
    private string $migrationsDir;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->migrationsDir = dirname(__DIR__) . '/migrations';
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
     * Get all migration files from the migrations directory.
     * Returns sorted array of ['name' => ..., 'path' => ...].
     */
    public function getAllMigrations(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = glob($this->migrationsDir . '/*.php');
        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $migrations[] = [
                'name' => $name,
                'path' => $file,
            ];
        }

        // Sort by filename (which should be date-prefixed)
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
     */
    public function runUp(array $migration, int $batch): void
    {
        $instance = $this->loadMigration($migration['path']);
        $instance->up($this->pdo);

        // Record the migration
        $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration['name'], $batch]);
    }

    /**
     * Run a single migration's down() method.
     */
    public function runDown(array $migration): void
    {
        $instance = $this->loadMigration($migration['path']);
        $instance->down($this->pdo);

        // Remove the migration record
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

        // Remove date prefix (YYYYMMDD_)
        if (preg_match('/^\d{8}_(.+)$/', $basename, $matches)) {
            $basename = $matches[1];
        }

        // Convert snake_case to PascalCase
        $parts = explode('_', $basename);
        return implode('', array_map('ucfirst', $parts));
    }
}
