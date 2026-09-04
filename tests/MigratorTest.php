<?php

/**
 * Migrator tests — tests the migration system.
 */

require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/Migrator.php';

function test_migrator_creates_table(): Test
{
    $t = new Test('Migrator - Creates migrations table');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->ensureMigrationsTable();

    // Verify table exists
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetchAll();
    $t->assertCount('migrations table created', 1, $tables);

    return $t;
}

function test_migrator_discovers_files(): Test
{
    $t = new Test('Migrator - Discovers migration files');

    $pdo = new BbPdo('sqlite::memory:');
    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);

    $files = $migrator->getAllMigrations();
    $t->assert('Found at least one migration file', count($files) > 0);

    // Check that initial schema is found
    $names = array_column($files, 'name');
    $t->assertContains('Initial schema migration found', 'core:20260829_initial_schema', $names);

    return $t;
}

function test_migrator_runs_up(): Test
{
    $t = new Test('Migrator - Runs up migration');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->ensureMigrationsTable();

    // Get pending
    $pending = $migrator->getPending();
    $t->assert('Has pending migrations', count($pending) > 0);

    // Run first pending
    $migration = array_values($pending)[0];
    $batch = $migrator->getNextBatch();
    $migrator->runUp($migration, $batch);

    // Verify it was recorded
    $ran = $migrator->getRanMigrations();
    $t->assertContains('Migration recorded as ran', $migration['name'], $ran);

    // Verify tables were created
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll();
    $t->assertCount('users table created', 1, $tables);

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='threads'")->fetchAll();
    $t->assertCount('threads table created', 1, $tables);

    return $t;
}

function test_migrator_runs_down(): Test
{
    $t = new Test('Migrator - Runs down migration');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->ensureMigrationsTable();

    // Run up first
    $pending = $migrator->getPending();
    $migration = array_values($pending)[0];
    $batch = $migrator->getNextBatch();
    $migrator->runUp($migration, $batch);

    // Now rollback
    $migrator->runDown($migration);

    // Verify it was removed from tracking
    $ran = $migrator->getRanMigrations();
    $t->assert('Migration removed from tracking', !in_array($migration['name'], $ran, true));

    // Verify tables were dropped
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll();
    $t->assertCount('users table dropped', 0, $tables);

    return $t;
}

function test_migrator_batch_tracking(): Test
{
    $t = new Test('Migrator - Batch tracking');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->ensureMigrationsTable();

    // Initially no batch
    $t->assertNull('No batch initially', $migrator->getLastBatch());

    // Run a migration
    $pending = $migrator->getPending();
    $migration = array_values($pending)[0];
    $batch1 = $migrator->getNextBatch();
    $migrator->runUp($migration, $batch1);

    // Check batch was assigned
    $t->assertEquals('Batch 1 assigned', 1, $migrator->getLastBatch());
    $t->assertEquals('Batch for migration is 1', 1, $migrator->getBatchFor($migration['name']));

    return $t;
}

function test_migrator_pending_detection(): Test
{
    $t = new Test('Migrator - Pending detection');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->ensureMigrationsTable();

    // Initially all pending
    $pending = $migrator->getPending();
    $all = $migrator->getAllMigrations();
    $t->assertEquals('All migrations pending initially', count($all), count($pending));

    // Run one
    $migration = array_values($pending)[0];
    $batch = $migrator->getNextBatch();
    $migrator->runUp($migration, $batch);

    // Now one less pending
    $pendingAfter = $migrator->getPending();
    $t->assertEquals('One less pending after migration', count($all) - 1, count($pendingAfter));

    return $t;
}

function test_migration_class_loading(): Test
{
    $t = new Test('Migrator - Class loading');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrator = new Migrator($pdo, ['db_driver' => 'sqlite']);
    $migrator->ensureMigrationsTable();

    // Run migration and verify it creates expected schema
    $pending = $migrator->getPending();
    $migration = array_values($pending)[0];
    $batch = $migrator->getNextBatch();
    $migrator->runUp($migration, $batch);

    // Check all expected tables exist
    $expectedTables = ['users', 'categories', 'threads', 'posts', 'uploads', 'thread_watchers', 'notifications', 'private_messages', 'roles', 'email_verifications', 'password_resets'];

    foreach ($expectedTables as $table) {
        $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchAll();
        $t->assertCount("Table {$table} exists", 1, $result);
    }

    // Check default roles were seeded
    $roleCount = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    $t->assertEquals('Default roles seeded', 3, $roleCount);

    // Check default category was seeded
    $catCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $t->assertEquals('Default category seeded', 1, $catCount);

    return $t;
}

register_tests(
    'test_migrator_creates_table',
    'test_migrator_discovers_files',
    'test_migrator_runs_up',
    'test_migrator_runs_down',
    'test_migrator_batch_tracking',
    'test_migrator_pending_detection',
    'test_migration_class_loading'
);
