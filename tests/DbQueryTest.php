<?php

/**
 * DbQuery tests — tests the lightweight query builder.
 * Uses an in-memory SQLite database.
 */

require_once __DIR__ . '/../lib/BbPdo.php';
require_once __DIR__ . '/../lib/DbQuery.php';

function test_dbquery_insert_and_select(): Test
{
    $t = new Test('DbQuery - Insert & Select');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    // Create test table
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, email TEXT, role TEXT DEFAULT 'user')");

    // Test insert
    $id = $db->table('users')->insert(['username' => 'alice', 'email' => 'alice@test.com', 'role' => 'admin']);
    $t->assert('Insert returns positive ID', $id > 0);

    // Test first
    $user = $db->table('users')->where('id', $id)->first();
    $t->assertNotNull('First returns user', $user);
    $t->assertEquals('Username matches', 'alice', $user['username'] ?? '');
    $t->assertEquals('Email matches', 'alice@test.com', $user['email'] ?? '');
    $t->assertEquals('Role matches', 'admin', $user['role'] ?? '');

    // Test get (multiple rows)
    $db->table('users')->insert(['username' => 'bob', 'email' => 'bob@test.com']);
    $all = $db->table('users')->get();
    $t->assertCount('Get returns 2 users', 2, $all);

    return $t;
}

function test_dbquery_where(): Test
{
    $t = new Test('DbQuery - Where clauses');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, status TEXT, views INTEGER DEFAULT 0)");
    $db->table('posts')->insert(['title' => 'First', 'status' => 'visible', 'views' => 10]);
    $db->table('posts')->insert(['title' => 'Second', 'status' => 'hidden', 'views' => 5]);
    $db->table('posts')->insert(['title' => 'Third', 'status' => 'visible', 'views' => 20]);

    // Test where equality
    $visible = $db->table('posts')->where('status', 'visible')->get();
    $t->assertCount('Where returns 2 visible posts', 2, $visible);

    // Test where with custom operator
    $popular = $db->table('posts')->where('views', 10, '>')->get();
    $t->assertCount('Where > returns 1 post', 1, $popular);

    // Test multiple where (AND)
    $result = $db->table('posts')->where('status', 'visible')->where('views', 15, '>')->get();
    $t->assertCount('Multiple where returns 1 post', 1, $result);
    $t->assertEquals('Correct post returned', 'Third', $result[0]['title'] ?? '');

    // Test whereIn
    $in = $db->table('posts')->whereIn('id', [1, 3])->get();
    $t->assertCount('WhereIn returns 2 posts', 2, $in);

    return $t;
}

function test_dbquery_update_delete(): Test
{
    $t = new Test('DbQuery - Update & Delete');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)");
    $db->table('items')->insert(['name' => 'apple', 'qty' => 5]);
    $db->table('items')->insert(['name' => 'banana', 'qty' => 3]);
    $db->table('items')->insert(['name' => 'cherry', 'qty' => 8]);

    // Test update
    $affected = $db->table('items')->where('name', 'banana')->update(['qty' => 10]);
    $t->assertEquals('Update affects 1 row', 1, $affected);

    $banana = $db->table('items')->where('name', 'banana')->first();
    $t->assertEquals('Updated qty matches', 10, $banana['qty'] ?? 0);

    // Test delete
    $deleted = $db->table('items')->where('name', 'apple')->delete();
    $t->assertEquals('Delete affects 1 row', 1, $deleted);

    $remaining = $db->table('items')->get();
    $t->assertCount('2 items remain', 2, $remaining);

    return $t;
}

function test_dbquery_order_limit_offset(): Test
{
    $t = new Test('DbQuery - Order, Limit, Offset');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE nums (id INTEGER PRIMARY KEY AUTOINCREMENT, val INTEGER)");
    for ($i = 1; $i <= 10; $i++) {
        $db->table('nums')->insert(['val' => $i]);
    }

    // Test orderBy DESC
    $desc = $db->table('nums')->orderBy('val', 'DESC')->limit(3)->get();
    $t->assertCount('Limit returns 3', 3, $desc);
    $t->assertEquals('First is highest', 10, $desc[0]['val'] ?? 0);
    $t->assertEquals('Last is 8', 8, $desc[2]['val'] ?? 0);

    // Test offset
    $page2 = $db->table('nums')->orderBy('val', 'ASC')->limit(5)->offset(5)->get();
    $t->assertCount('Offset page returns 5', 5, $page2);
    $t->assertEquals('First is 6', 6, $page2[0]['val'] ?? 0);

    return $t;
}

function test_dbquery_count_exists(): Test
{
    $t = new Test('DbQuery - Count & Exists');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE cats (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, active INTEGER DEFAULT 1)");
    $db->table('cats')->insert(['name' => 'Whiskers', 'active' => 1]);
    $db->table('cats')->insert(['name' => 'Mittens', 'active' => 1]);
    $db->table('cats')->insert(['name' => 'Shadow', 'active' => 0]);

    // Test count
    $total = $db->table('cats')->count();
    $t->assertEquals('Count returns 3', 3, $total);

    $active = $db->table('cats')->where('active', 1)->count();
    $t->assertEquals('Count active returns 2', 2, $active);

    // Test exists
    $t->assertTrue('Exists returns true for active', $db->table('cats')->where('active', 1)->exists());
    $t->assertFalse('Exists returns false for non-existent', $db->table('cats')->where('name', 'Nobody')->exists());

    return $t;
}

function test_dbquery_paginate(): Test
{
    $t = new Test('DbQuery - Paginate');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE data (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)");
    for ($i = 1; $i <= 25; $i++) {
        $db->table('data')->insert(['val' => "item-{$i}"]);
    }

    // Test paginate page 1
    $page1 = $db->table('data')->paginate(10, 1);
    $t->assertCount('Page 1 has 10 items', 10, $page1['items']);
    $t->assertEquals('Total is 25', 25, $page1['total']);
    $t->assertEquals('Current page is 1', 1, $page1['current_page']);
    $t->assertEquals('Last page is 3', 3, $page1['last_page']);

    // Test paginate page 3
    $page3 = $db->table('data')->paginate(10, 3);
    $t->assertCount('Page 3 has 5 items', 5, $page3['items']);
    $t->assertEquals('Current page is 3', 3, $page3['current_page']);

    return $t;
}

function test_dbquery_insert_ignore(): Test
{
    $t = new Test('DbQuery - Insert Ignore');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE unique_items (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE)");

    $id1 = $db->table('unique_items')->insert(['code' => 'ABC']);
    $t->assert('First insert succeeds', $id1 > 0);

    // insertIgnore should not throw on duplicate
    $id2 = $db->table('unique_items')->insertIgnore(['code' => 'ABC']);
    $t->assertEquals('InsertIgnore returns 0 on duplicate', 0, $id2);

    $count = $db->table('unique_items')->count();
    $t->assertEquals('Only 1 row exists', 1, $count);

    return $t;
}

function test_dbquery_pluck(): Test
{
    $t = new Test('DbQuery - Pluck');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE names (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
    $db->table('names')->insert(['name' => 'Alice']);
    $db->table('names')->insert(['name' => 'Bob']);
    $db->table('names')->insert(['name' => 'Charlie']);

    $names = $db->table('names')->pluck('name');
    $t->assertCount('Pluck returns 3 names', 3, $names);
    $t->assertContains('Pluck contains Alice', 'Alice', $names);
    $t->assertContains('Pluck contains Bob', 'Bob', $names);

    return $t;
}

function test_dbquery_raw(): Test
{
    $t = new Test('DbQuery - Raw queries');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE raw_test (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)");
    $db->table('raw_test')->insert(['val' => 'hello']);

    // Test raw
    $result = $db->raw("SELECT * FROM raw_test WHERE val = ?", ['hello']);
    $t->assertCount('Raw returns 1 row', 1, $result);

    // Test rawFirst
    $row = $db->rawFirst("SELECT * FROM raw_test WHERE val = ?", ['hello']);
    $t->assertNotNull('RawFirst returns row', $row);
    $t->assertEquals('RawFirst value matches', 'hello', $row['val'] ?? '');

    // Test rawExec
    $affected = $db->rawExec("UPDATE raw_test SET val = ? WHERE id = ?", ['world', 1]);
    $t->assertEquals('RawExec affects 1 row', 1, $affected);

    return $t;
}

register_tests(
    'test_dbquery_joins',
    'test_dbquery_null_and_between',
    'test_dbquery_insert_and_select',
    'test_dbquery_where',
    'test_dbquery_update_delete',
    'test_dbquery_order_limit_offset',
    'test_dbquery_count_exists',
    'test_dbquery_paginate',
    'test_dbquery_insert_ignore',
    'test_dbquery_pluck',
    'test_dbquery_raw'
);

function test_dbquery_joins(): Test
{
    $t = new Test('DbQuery - Joins');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE threads (id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER)");
    $pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, thread_id INTEGER, content TEXT)");

    $db->table('threads')->insert(['id' => 1, 'title' => 'First', 'user_id' => 7]);
    $db->table('threads')->insert(['id' => 2, 'title' => 'Second', 'user_id' => 8]);
    $db->table('posts')->insert(['id' => 10, 'thread_id' => 1, 'content' => 'a']);
    $db->table('posts')->insert(['id' => 11, 'thread_id' => 1, 'content' => 'b']);

    $rows = $db->table('threads')
        ->join('posts', 'posts.thread_id', '=', 'threads.id')
        ->select('threads.title', 'posts.content')
        ->orderBy('posts.id')
        ->get();
    $t->assertCount('INNER JOIN returns matching rows only', 2, $rows);
    $t->assertEquals('Joined column available', 'First', $rows[0]['title'] ?? null);

    $left = $db->table('threads')
        ->leftJoin('posts', 'posts.thread_id', '=', 'threads.id')
        ->select('threads.id')
        ->orderBy('threads.id')
        ->get();
    $t->assertCount('LEFT JOIN keeps unmatched rows', 3, $left);
    $t->assertEquals('LEFT JOIN count matches', 3, $db->table('threads')->leftJoin('posts', 'posts.thread_id', '=', 'threads.id')->count());

    // Table alias.
    $aliased = $db->table('threads')->join('posts AS p', 'p.thread_id', '=', 'threads.id')->select('p.id')->get();
    $t->assertCount('Join with alias works', 2, $aliased);

    return $t;
}

function test_dbquery_null_and_between(): Test
{
    $t = new Test('DbQuery - whereNull / whereBetween');

    $pdo = new BbPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new DbQuery($pdo);

    $pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT, score INTEGER)");
    $db->table('items')->insert(['name' => 'a', 'deleted_at' => null, 'score' => 5]);
    $db->table('items')->insert(['name' => 'b', 'deleted_at' => '2026-01-01', 'score' => 15]);
    $db->table('items')->insert(['name' => 'c', 'deleted_at' => null, 'score' => 25]);

    $t->assertCount('whereNull matches NULL rows', 2, $db->table('items')->whereNull('deleted_at')->get());
    $t->assertCount('whereNotNull matches the rest', 1, $db->table('items')->whereNotNull('deleted_at')->get());
    $t->assertCount('whereBetween is inclusive', 2, $db->table('items')->whereBetween('score', 5, 15)->get());
    $t->assertEquals('Two BETWEEN parameters are bound', 2, $db->table('items')->whereBetween('score', 5, 15)->count());
    $t->assertCount('Empty range matches nothing', 0, $db->table('items')->whereBetween('score', 100, 200)->get());

    $t->assertThrows('whereNull validates the column', fn() => $db->table('items')->whereNull('x IS NULL OR 1'), InvalidArgumentException::class);
    $t->assertThrows('whereBetween validates the column', fn() => $db->table('items')->whereBetween('score) OR (1', 1, 2), InvalidArgumentException::class);
    $t->assertThrows('join validates the table', fn() => $db->table('items')->join('x; DROP TABLE items', 'a', '=', 'b'), InvalidArgumentException::class);
    $t->assertThrows('join validates the columns', fn() => $db->table('items')->join('other', 'a', '=', 'b OR 1=1'), InvalidArgumentException::class);
    $t->assertThrows('join validates the operator', fn() => $db->table('items')->join('other', 'a', '= 1 OR 1', 'b'), InvalidArgumentException::class);

    return $t;
}
