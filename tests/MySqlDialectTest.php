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

register_tests(
    'test_mysql_normalisation_rules',
    'test_mysql_rejects_text_defaults',
    'test_migration_mysql_paths_are_valid_mysql'
);
