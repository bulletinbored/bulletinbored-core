<?php

/**
 * DbQuerySecurityTest — identifiers cannot be smuggled into SQL.
 *
 * Values are always bound by PDO, but table/column names, ORDER BY columns and
 * the comparison operator of a where clause are interpolated. DbQuery must
 * reject anything that is not a plain identifier so that plugin authors cannot
 * turn a query builder call into SQL injection by accident.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/DbQuery.php';

function dbquery_security_db(): DbQuery
{
    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, role TEXT, deleted_at TEXT NULL)");
    return new DbQuery($pdo);
}

function test_dbquery_rejects_invalid_table(): Test
{
    $t = new Test('DbQuery security - invalid table names rejected');
    $db = dbquery_security_db();

    $t->assertThrows('Semicolon rejected', fn() => $db->table('users; DROP TABLE users'), InvalidArgumentException::class);
    $t->assertThrows('Space rejected', fn() => $db->table('users u'), InvalidArgumentException::class);
    $t->assertThrows('Subquery rejected', fn() => $db->table('(SELECT 1)'), InvalidArgumentException::class);
    $t->assertThrows('Quote rejected', fn() => $db->table('users`'), InvalidArgumentException::class);
    $t->assertNotThrows('Plain table accepted', fn() => $db->table('users'));
    $t->assertNotThrows('Schema-qualified table accepted', fn() => $db->table('main.users'));

    return $t;
}

function test_dbquery_rejects_invalid_columns(): Test
{
    $t = new Test('DbQuery security - invalid column references rejected');
    $db = dbquery_security_db();

    $t->assertThrows('where() column with SQL rejected', fn() => $db->table('users')->where('1=1 OR id', 1), InvalidArgumentException::class);
    $t->assertThrows('where() column with parenthesis rejected', fn() => $db->table('users')->where('id)', 1), InvalidArgumentException::class);
    $t->assertThrows('whereIn() column rejected', fn() => $db->table('users')->whereIn('id) OR (1', [1]), InvalidArgumentException::class);
    $t->assertThrows('orderBy() column rejected', fn() => $db->table('users')->orderBy('id; DROP TABLE users'), InvalidArgumentException::class);
    $t->assertThrows('select() expression rejected', fn() => $db->table('users')->select('COUNT(*)'), InvalidArgumentException::class);
    $t->assertThrows('insert() column rejected', fn() => $db->table('users')->insert(['username); DROP TABLE users; --' => 'x']), InvalidArgumentException::class);
    $t->assertThrows('update() column rejected', fn() => $db->table('users')->where('id', 1)->update(['role = 1, username' => 'x']), InvalidArgumentException::class);

    $t->assertNotThrows('Star select accepted', fn() => $db->table('users')->select('*'));
    $t->assertNotThrows('Column alias accepted', fn() => $db->table('users')->select('username AS name'));
    $t->assertNotThrows('Qualified column accepted', fn() => $db->table('users')->select('users.username'));

    return $t;
}

function test_dbquery_rejects_invalid_operators(): Test
{
    $t = new Test('DbQuery security - operators are allow-listed');
    $db = dbquery_security_db();

    $t->assertThrows('OR 1=1 operator rejected', fn() => $db->table('users')->where('id', 1, '= 1 OR 1'), InvalidArgumentException::class);
    $t->assertThrows('Subquery operator rejected', fn() => $db->table('users')->where('id', 1, '= (SELECT 1)'), InvalidArgumentException::class);
    $t->assertNotThrows('Lowercase like accepted', fn() => $db->table('users')->where('username', 'a%', 'like'));
    $t->assertNotThrows('Not like accepted', fn() => $db->table('users')->where('username', 'a%', 'NOT LIKE'));

    return $t;
}

function test_dbquery_null_comparisons(): Test
{
    $t = new Test('DbQuery - NULL comparisons use IS NULL');
    $db = dbquery_security_db();

    $db->table('users')->insert(['username' => 'alice', 'role' => 'admin', 'deleted_at' => null]);
    $db->table('users')->insert(['username' => 'bob', 'role' => 'user', 'deleted_at' => '2026-01-01']);

    $t->assertCount('where(col, null) matches NULL rows', 1, $db->table('users')->where('deleted_at', null)->get());
    $t->assertCount('where(col, null, !=) matches non-NULL rows', 1, $db->table('users')->where('deleted_at', null, '!=')->get());
    $t->assertCount('whereIn([]) matches nothing', 0, $db->table('users')->whereIn('id', [])->get());
    $t->assertEquals('whereIn([]) count is zero', 0, $db->table('users')->whereIn('id', [])->count());

    return $t;
}

function test_dbquery_identifiers_still_work(): Test
{
    $t = new Test('DbQuery - valid queries keep working');
    $db = dbquery_security_db();

    $id = $db->table('users')->insert(['username' => 'carol', 'role' => 'moderator']);
    $row = $db->table('users')->where('id', $id)->first();
    $t->assertEquals('Inserted row is readable', 'carol', $row['username'] ?? null);

    $rows = $db->table('users')->select('username')->orderBy('id', 'desc')->get();
    $t->assertCount('Select/order still work', 1, $rows);

    $db->table('users')->where('id', $id)->update(['role' => 'admin']);
    $t->assertEquals('Update still works', 1, $db->table('users')->where('id', $id)->update(['role' => 'user']));

    return $t;
}

register_tests(
    'test_dbquery_rejects_invalid_table',
    'test_dbquery_rejects_invalid_columns',
    'test_dbquery_rejects_invalid_operators',
    'test_dbquery_null_comparisons',
    'test_dbquery_identifiers_still_work'
);
