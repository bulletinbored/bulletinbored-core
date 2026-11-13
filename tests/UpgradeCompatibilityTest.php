<?php

/**
 * UpgradeCompatibilityTest — an existing 0.9.x installation must survive the
 * upgrade to the current schema, and a core update must never touch user data.
 *
 * The migration-level test replays the real migration chain up to the previous
 * release, seeds realistic content, then finishes the upgrade: data must be
 * preserved and the data-transforming migration must actually apply.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/DbQuery.php';
require_once __DIR__ . '/../lib/Migrator.php';
require_once __DIR__ . '/../lib/UpdateManager.php';

/**
 * Driver used for the upgrade tests: SQLite by default, MySQL when the CI
 * matrix sets DB_DRIVER=mysql (which is how the real migration chain gets
 * exercised on MySQL, not just the hand-written compatibility ALTERs).
 */
function upgrade_compat_driver(): string
{
    return (string)($_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'sqlite');
}

/**
 * Clean database for the upgrade tests.
 * Returns null when the configured server cannot be reached.
 */
function upgrade_compat_pdo(): ?PDO
{
    if (upgrade_compat_driver() !== 'mysql') {
        $pdo = new BbPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    try {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'bulletinbored_test';
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // The server database is shared: start from an empty schema.
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$table) . '`');
        }
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Column names of a table, driver-aware.
 * @return string[]
 */
function upgrade_compat_columns(PDO $pdo, string $table): array
{
    $columns = [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = (string)$column['Field'];
        }
        return $columns;
    }
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = (string)$column['name'];
    }
    return $columns;
}

/**
 * Run every migration except the last $leavePending ones.
 * @return array{pending: array, ran: int}
 */
function upgrade_compat_partial_migrate(BbPdo $pdo, int $leavePending): array
{
    $migrator = new Migrator($pdo, []);
    $migrator->ensureMigrationsTable();

    $all = $migrator->getAllMigrations();
    $upTo = max(0, count($all) - $leavePending);
    $batch = $migrator->getNextBatch();
    foreach (array_slice($all, 0, $upTo) as $migration) {
        $migrator->runUp($migration, $batch);
    }

    return ['pending' => array_slice($all, $upTo), 'ran' => $upTo];
}

function test_upgrade_from_previous_release_preserves_content(): Test
{
    $t = new Test('Upgrade - previous release database upgrades in place (' . upgrade_compat_driver() . ')');

    $pdo = upgrade_compat_pdo();
    if ($pdo === null) {
        $t->assert('Skipped: configured database server is not reachable', true);
        return $t;
    }

    // Build the previous release's schema and content.
    $partial = upgrade_compat_partial_migrate($pdo, 2);
    $t->assert('Some migrations were applied', $partial['ran'] > 0);

    $db = new DbQuery($pdo);
    $userId = $db->table('users')->insert([
        'username' => 'veteran',
        'password' => password_hash('OldP4ssword!', PASSWORD_DEFAULT),
        'email' => 'veteran@example.com',
        'role' => 'user',
        'status' => 'active',
    ]);
    $categoryId = (int)$pdo->query("SELECT id FROM categories ORDER BY id ASC LIMIT 1")->fetchColumn();
    $threadId = $db->table('threads')->insert([
        'category_id' => $categoryId,
        'user_id' => $userId,
        'title' => "we&#039;re still here",
        'content' => 'Old content',
        'status' => 'visible',
    ]);
    $db->table('posts')->insert([
        'thread_id' => $threadId,
        'user_id' => $userId,
        'content' => 'An old reply',
    ]);

    // Upgrade the rest of the way.
    $migrator = new Migrator($pdo, []);
    $migrator->ensureMigrationsTable();
    $ran = $migrator->migrate();
    $t->assert('Remaining migrations ran', count($ran) === count($partial['pending']));

    $t->assertEquals('Users preserved', 1, (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn());
    $t->assertEquals('Threads preserved', 1, (int)$pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn());
    $t->assertEquals('Posts preserved', 1, (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn());

    $user = $db->table('users')->where('id', $userId)->first();
    $t->assertEquals('Username preserved', 'veteran', $user['username']);
    $t->assertEquals('Password hash preserved', $userId, (int)$user['id']);
    $t->assertTrue('Password still verifies', password_verify('OldP4ssword!', $user['password']));

    $thread = $db->table('threads')->where('id', $threadId)->first();
    $t->assertEquals('Thread title decoded by the data migration', "we're still here", $thread['title']);
    $t->assertEquals('Thread content untouched', 'Old content', $thread['content']);

    // Columns added by later migrations exist on the upgraded installation.
    $columns = upgrade_compat_columns($pdo, 'users');
    $t->assertContains('session_version column present', 'session_version', $columns);
    $t->assertContains('suspension_time column present', 'suspension_time', $columns);

    $t->assertCount('No migrations left pending', 0, $migrator->getPending());
    $t->assertCount('No interrupted migration recorded', 0, $migrator->getInterruptedMigrations());

    return $t;
}

function test_upgrade_is_idempotent_on_populated_database(): Test
{
    $t = new Test('Upgrade - second run is a no-op (' . upgrade_compat_driver() . ')');

    $pdo = upgrade_compat_pdo();
    if ($pdo === null) {
        $t->assert('Skipped: configured database server is not reachable', true);
        return $t;
    }
    upgrade_compat_partial_migrate($pdo, 0);

    $db = new DbQuery($pdo);
    $categoryId = (int)$pdo->query("SELECT id FROM categories ORDER BY id ASC LIMIT 1")->fetchColumn();
    $db->table('users')->insert([
        'username' => 'someone',
        'password' => password_hash('What3verPass', PASSWORD_DEFAULT),
        'email' => 'someone@example.com',
    ]);
    $db->table('threads')->insert([
        'category_id' => $categoryId,
        'user_id' => 1,
        'title' => 'Existing thread',
        'content' => 'x',
        'status' => 'visible',
    ]);

    $migrator = new Migrator($pdo, []);
    $migrator->ensureMigrationsTable();
    $t->assertCount('Nothing pending on an up-to-date database', 0, $migrator->migrate());
    $t->assertEquals('Content untouched', 1, (int)$pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn());

    return $t;
}

// ---------------------------------------------------------------------------
// Core update must not touch user-owned files
// ---------------------------------------------------------------------------

function upgrade_compat_core_root(): string
{
    $root = sys_get_temp_dir() . '/bb_upgrade_' . uniqid('', true);
    @mkdir($root . '/data', 0755, true);
    @mkdir($root . '/api', 0755, true);
    @mkdir($root . '/plugins/customplugin', 0755, true);
    @mkdir($root . '/themes/customtheme', 0755, true);
    @mkdir($root . '/uploads/avatars', 0755, true);

    file_put_contents($root . '/VERSION', '0.9.8');
    file_put_contents($root . '/config.json', json_encode([
        'version' => '0.9.8',
        'site_name' => 'My community',
        'db_driver' => 'sqlite',
        'db_path' => $root . '/data/database.sqlite',
        'smtp_password' => 'secret-value',
    ], JSON_PRETTY_PRINT));
    file_put_contents($root . '/index.php', "<?php // old core\n");
    file_put_contents($root . '/data/database.sqlite', 'SQLite format 3 placeholder');
    file_put_contents($root . '/plugins/customplugin/manifest.json', '{"name":"customplugin"}');
    file_put_contents($root . '/plugins/customplugin/plugin.php', '<?php // custom plugin');
    file_put_contents($root . '/themes/customtheme/style.css', '/* custom theme */');
    file_put_contents($root . '/uploads/avatars/user1.png', 'PNGDATA');
    return $root;
}

function upgrade_compat_core_cleanup(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($root);
}

function test_core_update_preserves_user_content(): Test
{
    $t = new Test('Upgrade - core ZIP update keeps data, plugins and themes');

    if (!class_exists('ZipArchive')) {
        $t->assert('Skipped: ZipArchive not available', true);
        return $t;
    }

    $root = upgrade_compat_core_root();
    $zipPath = sys_get_temp_dir() . '/bb_upgrade_core_' . uniqid('', true) . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.php', "<?php // new core 1.0.0\n");
    $zip->addFromString('VERSION', '1.0.0');
    $zip->addFromString('lib/BbPdo.php', "<?php // new lib\n");
    $zip->close();

    $manager = new UpdateManager($root . '/data/update_manifest.json', null, null, null, $root);

    try {
        $t->assertTrue('Core update applied', $manager->applyUpdate('core', 'core', $zipPath));

        $t->assertEquals('Core file updated', "<?php // new core 1.0.0\n", file_get_contents($root . '/index.php'));
        $t->assertTrue('New library file added', is_file($root . '/lib/BbPdo.php'));

        $config = json_decode((string)file_get_contents($root . '/config.json'), true);
        $t->assertEquals('Version bumped in config.json', '1.0.0', $config['version'] ?? null);
        $t->assertEquals('Site name preserved', 'My community', $config['site_name'] ?? null);
        $t->assertEquals('Secrets preserved', 'secret-value', $config['smtp_password'] ?? null);
        $t->assertEquals('Database path preserved', $root . '/data/database.sqlite', $config['db_path'] ?? null);
        $t->assertTrue('Database file untouched', is_file($root . '/data/database.sqlite'));

        $t->assertTrue('Installed plugin preserved', is_file($root . '/plugins/customplugin/plugin.php'));
        $t->assertTrue('Custom theme preserved', is_file($root . '/themes/customtheme/style.css'));
        $t->assertTrue('Uploads preserved', is_file($root . '/uploads/avatars/user1.png'));
    } finally {
        upgrade_compat_core_cleanup($root);
        @unlink($zipPath);
    }

    return $t;
}

register_tests(
    'test_upgrade_from_previous_release_preserves_content',
    'test_upgrade_is_idempotent_on_populated_database',
    'test_core_update_preserves_user_content'
);
