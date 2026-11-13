<?php

/**
 * MigrationRecoveryTest — what happens when a migration fails halfway.
 *
 * SQLite can roll DDL back; MySQL cannot. The migrator must make the
 * difference visible and give the operator a documented way out instead of
 * silently repeating a partially applied migration forever.
 *
 * The MySQL behaviour is emulated by overriding supportsTransactionalDdl() so
 * the recovery bookkeeping is exercised against a real database.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/Migrator.php';

/**
 * A migrator that behaves like MySQL: DDL is committed immediately.
 */
class NonTransactionalMigrator extends Migrator
{
    protected function supportsTransactionalDdl(): bool
    {
        return false;
    }
}

/**
 * Create a throw-away migrations directory holding one migration.
 * $tag keeps class names unique: the test process cannot redeclare a class
 * when a second fixture is loaded.
 *
 * @return array{dir: string, name: string}
 */
function migration_recovery_fixture_dir(bool $commitFirstStatement, string $tag): array
{
    static $counter = 0;
    $counter++;
    $suffix = ucfirst(preg_replace('/[^A-Za-z0-9]/', '', $tag)) . $counter;
    $base = 'partial_' . strtolower($suffix);

    $dir = sys_get_temp_dir() . '/bb_mig_' . uniqid('', true) . '/migrations';
    @mkdir($dir, 0755, true);

    $body = $commitFirstStatement
        ? "        \$pdo->exec(\"CREATE TABLE IF NOT EXISTS partial_table_{$counter} (id INTEGER PRIMARY KEY, v TEXT)\");\n"
          . "        throw new RuntimeException('boom in second statement');"
        : "        throw new RuntimeException('boom before any DDL');";

    $code = "<?php\nclass Partial{$suffix}\n{\n    public function up(\$pdo)\n    {\n{$body}\n    }\n\n"
        . "    public function down(\$pdo)\n    {\n        \$pdo->exec(\"DROP TABLE IF EXISTS partial_table_{$counter}\");\n    }\n}\n";

    file_put_contents($dir . '/20260101_' . $base . '.php', $code);

    // Migration ids are namespaced with the source directory name.
    $source = basename(dirname($dir));
    return ['dir' => $dir, 'name' => $source . ':20260101_' . $base, 'table' => 'partial_table_' . $counter];
}

function migration_recovery_cleanup(string $dir): void
{
    $migrations = dirname($dir);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
    @rmdir($migrations);
}

function test_failed_migration_on_transactional_driver_leaves_no_trace(): Test
{
    $t = new Test('Migrations - SQLite failure rolls back cleanly');

    $fixture = migration_recovery_fixture_dir(true, 'tx');
    try {
        $pdo = new BbPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrator = new Migrator($pdo, []);
        $migrator->ensureMigrationsTable();
        $migrator->addPath($fixture['dir']);

        $threw = false;
        try {
            $migrator->migrate();
        } catch (RuntimeException $e) {
            $threw = true;
            $t->assert('Error mentions the migration', str_contains($e->getMessage(), $fixture['name']));
            $t->assert('Transactional driver adds no partial-apply hint', !str_contains($e->getMessage(), 'partially applied'));
        }
        $t->assertTrue('migrate() reported the failure', $threw);

        $t->assertCount('No interrupted marker on SQLite', 0, $migrator->getInterruptedMigrations());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$fixture['table']}'")->fetchAll();
        $t->assertCount('DDL rolled back', 0, $tables);
        $t->assertContains('Migration still pending', $fixture['name'], array_column($migrator->getPending(), 'name'));
        $t->assertCount('Nothing recorded as ran', 0, $migrator->getRanMigrations());
    } finally {
        migration_recovery_cleanup($fixture['dir']);
    }

    return $t;
}

function test_failed_migration_on_mysql_like_driver_is_recorded(): Test
{
    $t = new Test('Migrations - non-transactional failure is recorded');

    $fixture = migration_recovery_fixture_dir(true, 'mysql');
    try {
        $pdo = new BbPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrator = new NonTransactionalMigrator($pdo, []);
        $migrator->ensureMigrationsTable();
        $migrator->addPath($fixture['dir']);

        try {
            $migrator->migrate();
        } catch (RuntimeException $e) {
            $t->assert('Error explains the partial application', str_contains($e->getMessage(), 'partially applied'));
        }

        $interrupted = $migrator->getInterruptedMigrations();
        $t->assertCount('Interrupted migration recorded', 1, $interrupted);
        if ($interrupted) {
            $t->assertEquals('Recorded name', $fixture['name'], $interrupted[0]['migration']);
            $t->assert('Error text stored', str_contains((string)$interrupted[0]['error'], 'boom'));
        }
        $t->assertTrue('isInterrupted() reports it', $migrator->isInterrupted($fixture['name']));

        // The first statement of the migration really was applied.
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$fixture['table']}'")->fetchAll();
        $t->assertCount('Partial DDL survives (as on MySQL)', 1, $tables);
        $t->assertCount('Migration is not marked as ran', 0, $migrator->getRanMigrations());
        $t->assertContains('Migration stays pending', $fixture['name'], array_column($migrator->getPending(), 'name'));
    } finally {
        migration_recovery_cleanup($fixture['dir']);
    }

    return $t;
}

function test_interrupted_migration_recovery_paths(): Test
{
    $t = new Test('Migrations - recovery paths');

    $fixture = migration_recovery_fixture_dir(true, 'recover');
    try {
        $pdo = new BbPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrator = new NonTransactionalMigrator($pdo, []);
        $migrator->ensureMigrationsTable();
        $migrator->addPath($fixture['dir']);

        try {
            $migrator->migrate();
        } catch (RuntimeException $e) {
            // expected
        }

        $name = $fixture['name'];

        // Path 1: retry. Forget the marker, then run again: the migration is
        // attempted again and fails again, so the marker reappears.
        $migrator->forgetInterrupted($name);
        $t->assertFalse('Marker cleared before retry', $migrator->isInterrupted($name));
        $t->assertContains('Migration retried on next migrate()', $name, array_column($migrator->getPending(), 'name'));

        try {
            $migrator->migrate();
        } catch (RuntimeException $e) {
            // expected
        }
        $t->assertTrue('Interrupted again after the retry', $migrator->isInterrupted($name));

        // Path 2: manual repair. The operator finished the schema by hand and
        // records the migration without running up() again.
        $migrator->recoverInterrupted($name);
        $t->assertFalse('Marker cleared after recovery', $migrator->isInterrupted($name));
        $t->assertContains('Migration recorded as ran', $name, $migrator->getRanMigrations());
        $t->assertCount('No migration from this fixture left pending', 0, array_filter(
            array_column($migrator->getPending(), 'name'),
            fn($pending) => $pending === $name
        ));
        $count = $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = " . $pdo->quote($name))->fetchColumn();
        $t->assertEquals('Recorded exactly once', 1, (int)$count);
    } finally {
        migration_recovery_cleanup($fixture['dir']);
    }

    return $t;
}

function test_successful_migration_clears_marker(): Test
{
    $t = new Test('Migrations - successful run leaves no marker');

    $dir = sys_get_temp_dir() . '/bb_migok_' . uniqid('', true) . '/migrations';
    @mkdir($dir, 0755, true);
    // Keep the file name/class name convention: 20260102_ok_table_x1a2b → OkTableX1a2b
    $suffix = 'x' . bin2hex(random_bytes(3));
    $baseName = '20260102_ok_table_' . $suffix;
    $class = 'OkTable' . ucfirst($suffix);
    file_put_contents($dir . '/' . $baseName . '.php', "<?php\nclass {$class}\n{\n    public function up(\$pdo)\n    {\n"
        . "        \$pdo->exec(\"CREATE TABLE IF NOT EXISTS ok_table (id INTEGER PRIMARY KEY)\");\n    }\n\n"
        . "    public function down(\$pdo)\n    {\n        \$pdo->exec(\"DROP TABLE IF EXISTS ok_table\");\n    }\n}\n");

    try {
        $pdo = new BbPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrator = new NonTransactionalMigrator($pdo, []);
        $migrator->ensureMigrationsTable();
        $migrator->addPath($dir);

        $ran = $migrator->migrate();
        $expected = basename(dirname($dir)) . ':' . $baseName;
        $t->assertContains('Migration ran', $expected, $ran);
        $t->assertCount('No interrupted markers', 0, $migrator->getInterruptedMigrations());
        $t->assertContains('Recorded as ran', $expected, $migrator->getRanMigrations());
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        @rmdir(dirname($dir));
    }

    return $t;
}

register_tests(
    'test_failed_migration_on_transactional_driver_leaves_no_trace',
    'test_failed_migration_on_mysql_like_driver_is_recorded',
    'test_interrupted_migration_recovery_paths',
    'test_successful_migration_clears_marker'
);
