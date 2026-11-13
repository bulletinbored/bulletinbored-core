<?php

/**
 * MySqlDialectTest — SQLite-flavoured SQL must survive the trip to MySQL.
 *
 * The database matrix runs the suites on MySQL/MariaDB, but only the migration
 * chain exercises the real DDL a fresh installation sends. MySQL 8 is stricter
 * than MariaDB in a few spots (a TEXT column may not carry a DEFAULT), and a
 * construct that MariaDB accepts can therefore break every MySQL installation.
 *
 * Two layers:
 *   1. unit tests for BbPdo's dialect normalisation;
 *   2. a static guard over the MySQL code paths of the migrations, so an
 *      invalid statement is caught without waiting for a MySQL server.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/Migrator.php';

function mysql_dialect_driver(): string
{
    return (string)($_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'sqlite');
}

function mysql_dialect_pdo(): ?PDO
{
    if (mysql_dialect_driver() !== 'mysql') {
        return null;
    }
    try {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'bulletinbored_test';
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
        $pdo = new BbPdo("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function test_mysql_normalisation_rules(): Test
{
    $t = new Test('MySQL dialect - normalisation rules');

    $t->assertEquals(
        'AUTOINCREMENT rewritten',
        'CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT)',
        BbPdo::normalizeForMysql('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)')
    );
    $t->assertEquals(
        'CREATE INDEX IF NOT EXISTS rewritten',
        'CREATE INDEX i ON t(a)',
        BbPdo::normalizeForMysql('CREATE INDEX IF NOT EXISTS i ON t(a)')
    );
    $t->assertEquals(
        'Reserved word at the end of a statement quoted',
        'SELECT * FROM t WHERE `read`',
        BbPdo::normalizeForMysql('SELECT * FROM t WHERE read')
    );

    return $t;
}

function test_mysql_rejects_text_defaults(): Test
{
    $t = new Test('MySQL dialect - TEXT columns lose their DEFAULT');

    // MySQL 8 error 1101: BLOB/TEXT columns cannot have a default value.
    $t->assertEquals(
        'DEFAULT NULL stripped from TEXT',
        'CREATE TABLE t (allowed_roles TEXT)',
        BbPdo::normalizeForMysql('CREATE TABLE t (allowed_roles TEXT DEFAULT NULL)')
    );
    $t->assertEquals(
        'DEFAULT NULL stripped from an ALTER',
        'ALTER TABLE categories ADD COLUMN allowed_roles TEXT',
        BbPdo::normalizeForMysql('ALTER TABLE categories ADD COLUMN allowed_roles TEXT DEFAULT NULL')
    );
    $t->assertEquals(
        'Empty-string default stripped',
        'CREATE TABLE t (a TEXT)',
        BbPdo::normalizeForMysql("CREATE TABLE t (a TEXT DEFAULT '')")
    );
    $t->assertEquals(
        'JSON default stripped',
        'CREATE TABLE t (a TEXT)',
        BbPdo::normalizeForMysql("CREATE TABLE t (a TEXT DEFAULT '[]')")
    );
    $t->assertEquals(
        'Multiple TEXT columns handled, other defaults kept',
        'CREATE TABLE t (a TEXT, b TEXT, c VARCHAR(64) DEFAULT NULL)',
        BbPdo::normalizeForMysql("CREATE TABLE t (a TEXT DEFAULT NULL, b TEXT DEFAULT '', c VARCHAR(64) DEFAULT NULL)")
    );
    $t->assertEquals(
        'Numerical defaults on TEXT stripped',
        'CREATE TABLE t (a TEXT, b INT DEFAULT 0)',
        BbPdo::normalizeForMysql('CREATE TABLE t (a TEXT DEFAULT 5, b INT DEFAULT 0)')
    );

    return $t;
}

/**
 * Remove SQL/PHP comments so explanatory text ("TEXT columns cannot carry a
 * default") is not mistaken for a statement.
 */
function mysql_dialect_strip_comments(string $code): string
{
    $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
    $lines = preg_split('/\R/', $code) ?: [];
    foreach ($lines as $i => $line) {
        $line = preg_replace('#\s--\s.*$#', '', $line) ?? $line;
        $line = preg_replace('#^\s*--.*$#', '', $line) ?? $line;
        $line = preg_replace('#^\s*//.*$#', '', $line) ?? $line;
        $lines[$i] = $line;
    }
    return implode("\n", $lines);
}

/**
 * Extract the body of a method from a file by brace counting.
 */
function mysql_dialect_method_body(string $code, string $method): ?string
{
    $pattern = '/function\\s+' . preg_quote($method, '/') . '\\s*\\([^)]*\\)[^{]*\\{/';
    if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $length = strlen($code);
    for ($i = $start; $i < $length; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $start, $i - $start);
            }
        }
    }
    return null;
}

function test_migration_mysql_paths_are_valid_mysql(): Test
{
    $t = new Test('MySQL dialect - migration MySQL paths are valid');

    $files = glob(dirname(__DIR__) . '/migrations/*.php') ?: [];
    $t->assert('Migrations were found', count($files) > 0);

    $problems = [];
    foreach ($files as $file) {
        $code = (string)file_get_contents($file);
        $name = basename($file);

        // 1. MySQL-specific branches.
        $body = mysql_dialect_method_body($code, 'upMysql');
        if ($body !== null) {
            $body = mysql_dialect_strip_comments($body);
            if (preg_match('/\\b(?:TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB)\\b[^,;)\\n]*\\bDEFAULT\\b/i', $body)) {
                $problems[] = "{$name} (upMysql): TEXT/BLOB column with a DEFAULT";
            }
            if (preg_match('/\\bAUTOINCREMENT\\b/i', $body)) {
                $problems[] = "{$name} (upMysql): SQLite AUTOINCREMENT";
            }
            if (preg_match('/ADD\\s+COLUMN\\s+IF\\s+NOT\\s+EXISTS/i', $body)) {
                $problems[] = "{$name} (upMysql): ADD COLUMN IF NOT EXISTS is MariaDB-only";
            }
            if (preg_match('/CREATE\\s+(?:UNIQUE\\s+)?INDEX\\s+IF\\s+NOT\\s+EXISTS/i', $body)) {
                $problems[] = "{$name} (upMysql): CREATE INDEX IF NOT EXISTS is MariaDB-only";
            }
        }

        // 2. The web installer ships its own MySQL DDL: every line that creates
        // a MySQL table (marked by AUTO_INCREMENT) must be MySQL-valid too.
        foreach (explode("\n", $code) as $number => $line) {
            if (!str_contains($line, 'AUTO_INCREMENT')) {
                continue;
            }
            if (preg_match('/\b(?:TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB)\b[^,;)\n]*\bDEFAULT\b/i', $line)) {
                $problems[] = $name . ':' . ($number + 1) . ' (installer MySQL DDL): TEXT/BLOB column with a DEFAULT';
            }
        }

        // 3. Driver ternaries that hand MySQL a SQLite type.
        if (preg_match("/===\\s*'mysql'\\s*\\?\\s*'[^']*(?:TEXT|BLOB)[^']*DEFAULT[^']*'/i", $code, $m)) {
            $problems[] = "{$name}: MySQL branch uses " . $m[0];
        }
    }

    $t->assert('MySQL code paths contain no SQLite-only construct', $problems === []);
    foreach ($problems as $problem) {
        $t->assert($problem, false);
    }

    return $t;
}

function test_indexed_string_columns_fit_old_key_limits(): Test
{
    $t = new Test('MySQL dialect - indexed strings fit a 1000-byte key');

    // utf8mb4 uses up to 4 bytes per character: an indexed VARCHAR longer than
    // 191 characters exceeds the 767-byte limit of older InnoDB builds and the
    // 1000-byte MyISAM limit (error 1071 "Specified key was too long", which
    // real shared hosts do return).
    $files = array_merge(
        glob(dirname(__DIR__) . '/migrations/*.php') ?: [],
        [dirname(__DIR__) . '/lib/Migrator.php', dirname(__DIR__) . '/install3.php']
    );

    $problems = [];
    foreach ($files as $file) {
        $name = basename($file);
        $lines = explode("\n", (string)file_get_contents($file));
        foreach ($lines as $number => $line) {
            // A line can declare several columns: check each definition alone so
            // an unindexed VARCHAR(255) does not trip the guard.
            foreach (explode(',', $line) as $column) {
                if (!preg_match('/VARCHAR\((\d+)\)/i', $column, $match)) {
                    continue;
                }
                if (!preg_match('/\b(?:UNIQUE|PRIMARY\s+KEY)\b/i', $column)) {
                    continue;
                }
                if ((int)$match[1] > 191) {
                    $problems[] = $name . ':' . ($number + 1) . ' indexed VARCHAR(' . $match[1] . ')';
                }
            }
        }
    }

    $t->assert('No indexed VARCHAR longer than 191 characters', $problems === []);
    foreach ($problems as $problem) {
        $t->assert($problem, false);
    }

    return $t;
}

function test_migrations_tables_use_innodb(): Test
{
    $t = new Test('MySQL dialect - migrations tables are InnoDB (' . mysql_dialect_driver() . ')');

    if (mysql_dialect_driver() !== 'mysql') {
        $t->assert('Skipped: not a MySQL run', true);
        return $t;
    }

    $pdo = mysql_dialect_pdo();
    if ($pdo === null) {
        $t->assert('Skipped: MySQL server not reachable', true);
        return $t;
    }

    foreach (['migrations', 'migration_progress'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }

    $migrator = new Migrator($pdo, []);
    $migrator->ensureMigrationsTable();

    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('migrations', 'migration_progress')"
    );
    $stmt->execute();
    $engines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $engines[$row['TABLE_NAME']] = $row['ENGINE'];
    }

    foreach (['migrations', 'migration_progress'] as $table) {
        $t->assertEquals("{$table} uses InnoDB", 'InnoDB', $engines[$table] ?? null);
    }

    return $t;
}

function test_foreign_keys_skip_non_innodb_tables(): Test
{
    $t = new Test('MySQL dialect - foreign keys skipped on MyISAM (' . mysql_dialect_driver() . ')');

    if (mysql_dialect_driver() !== 'mysql') {
        $t->assert('Skipped: not a MySQL run', true);
        return $t;
    }

    $pdo = mysql_dialect_pdo();
    if ($pdo === null) {
        $t->assert('Skipped: MySQL server not reachable', true);
        return $t;
    }

    require_once dirname(__DIR__) . '/migrations/20260904_add_foreign_keys.php';

    // MyISAM cannot hold foreign keys: the migration must skip them instead of
    // failing every request on a host that defaults to MyISAM.
    $pdo->exec("DROP TABLE IF EXISTS fk_threads");
    $pdo->exec("DROP TABLE IF EXISTS fk_categories");
    $pdo->exec("CREATE TABLE fk_threads (id INT AUTO_INCREMENT PRIMARY KEY, category_id INT) ENGINE=MyISAM");
    $pdo->exec("CREATE TABLE fk_categories (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");

    $migration = new AddForeignKeys();
    $threw = null;
    try {
        // Only the engine check matters here: it must return before touching
        // the real schema, which is not present in this fixture.
        $method = new ReflectionMethod($migration, 'nonInnoDbTables');
        $method->setAccessible(true);
        $result = $method->invoke($migration, $pdo);
        $t->assertNotNull('MyISAM table detected', $result);
    } catch (\Throwable $e) {
        $threw = $e;
    }
    $t->assertNull('Inspection does not throw', $threw);

    $pdo->exec('DROP TABLE IF EXISTS fk_threads');
    $pdo->exec('DROP TABLE IF EXISTS fk_categories');

    return $t;
}

function test_doctor_queries_are_driver_aware(): Test
{
    $t = new Test('MySQL dialect - doctor does not query sqlite_master on MySQL');

    $code = (string)file_get_contents(dirname(__DIR__) . '/bb.php');
    // Comments mention both names; compare the statements only.
    $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
    $code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;

    $t->assert('doctor queries information_schema for MySQL', str_contains($code, 'information_schema.TABLES'));

    // The sqlite_master query must live in the non-MySQL branch.
    $posSqlite = strpos($code, 'sqlite_master');
    $posMysql = strpos($code, 'information_schema.TABLES');
    $t->assertNotFalse('sqlite_master still used for SQLite', $posSqlite);
    $t->assertNotFalse('information_schema used for MySQL', $posMysql);
    $t->assertTrue('The MySQL branch comes first', $posMysql !== false && $posSqlite !== false && $posMysql < $posSqlite);

    return $t;
}

register_tests(
    'test_doctor_queries_are_driver_aware',
    'test_mysql_normalisation_rules',
    'test_mysql_rejects_text_defaults',
    'test_migration_mysql_paths_are_valid_mysql',
    'test_indexed_string_columns_fit_old_key_limits',
    'test_migrations_tables_use_innodb',
    'test_foreign_keys_skip_non_innodb_tables'
);
