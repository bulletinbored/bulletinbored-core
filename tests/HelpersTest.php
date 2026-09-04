<?php

/**
 * HelpersTest — tests for src/Helpers/ modules.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../lib/AuthZ.php';

function test_escape_escapes_html(): Test
{
    $t = new Test('Text - escape()');
    $t->assertEquals('Escapes ampersand', '&amp;', escape('&'));
    $t->assertEquals('Escapes quotes', '&quot;', escape('"'));
    $t->assertEquals('Escapes tags', '&lt;script&gt;', escape('<script>'));
    $t->assertEquals('Escapes single quote', '&#039;', escape("'"));
    return $t;
}

function test_validate_input_trims(): Test
{
    $t = new Test('Text - validate_input()');
    $t->assertEquals('Trims whitespace', 'hello', validate_input('  hello  '));
    $t->assertEquals('Stripslashes', "It's", validate_input("It\\'s"));
    $t->assertEquals('Handles array', ['a', 'b'], validate_input(['  a  ', '  b  ']));
    return $t;
}

function test_clean_text_escapes(): Test
{
    $t = new Test('Text - clean_text()');
    $t->assertEquals('Trims and escapes', 'Hello', clean_text('  Hello  '));
    $t->assertEquals('Escapes HTML', '&lt;b&gt;', clean_text('<b>'));
    return $t;
}

function test_time_ago(): Test
{
    $t = new Test('Text - time_ago()');
    $t->assertEquals('Just now', 'just now', time_ago(date('Y-m-d H:i:s')));
    $t->assert('Minutes ago contains minute', str_contains(time_ago(date('Y-m-d H:i:s', strtotime('-5 minutes'))), 'minute'));
    $t->assert('Hours ago contains hour', str_contains(time_ago(date('Y-m-d H:i:s', strtotime('-3 hours'))), 'hour'));
    $t->assert('Days ago contains day', str_contains(time_ago(date('Y-m-d', strtotime('-2 days'))), 'day'));
    return $t;
}

function test_compact_number(): Test
{
    $t = new Test('Text - compact_number()');
    $t->assertEquals('Less than 1K', '42', compact_number(42));
    $t->assertEquals('Over 1K', '1.5K', compact_number(1500));
    $t->assertEquals('Over 1M', '2.3M', compact_number(2300000));
    return $t;
}

function test_excerpt(): Test
{
    $t = new Test('Text - excerpt()');
    $t->assertEquals('Short text unchanged', 'Hello', excerpt('Hello', 110));
    $t->assert('Long text truncated', str_ends_with(excerpt(str_repeat('a', 200), 110), '...'));
    $t->assert('Strips tags', !str_contains(excerpt('<b>Hello</b> World', 110), '<b>'));
    return $t;
}

function test_marked_parse(): Test
{
    $t = new Test('Text - marked_parse()');
    $result = marked_parse('**bold**');
    $t->assert('Renders bold', str_contains($result, '<strong>bold</strong>'));
    $result2 = marked_parse('*italic*');
    $t->assert('Renders italic', str_contains($result2, '<em>italic</em>'));
    return $t;
}

function test_avatar_initial(): Test
{
    $t = new Test('Avatar - avatar_initial()');
    $t->assertEquals('First letter uppercase', 'A', avatar_initial('alice'));
    $t->assertEquals('Already uppercase', 'B', avatar_initial('Bob'));
    return $t;
}

function test_avatar_color_deterministic(): Test
{
    $t = new Test('Avatar - avatar_color()');
    $color1 = avatar_color('alice');
    $color2 = avatar_color('alice');
    $t->assertEquals('Same name same color', $color1, $color2);
    $t->assert('Valid hex color', preg_match('/^#[0-9a-f]{6}$/i', $color1));
    return $t;
}

function test_render_avatar_fallback(): Test
{
    $t = new Test('Avatar - render_avatar()');
    $html = render_avatar('alice', '', 44);
    $t->assert('Contains initial', str_contains($html, 'A'));
    $t->assert('Contains size', str_contains($html, '44px'));
    $t->assert('Uses background color', str_contains($html, 'background:'));
    return $t;
}

function test_validate_password_strength(): Test
{
    $t = new Test('AuthHelpers - validate_password_strength()');
    $t->assert('Strong password passes', empty(validate_password_strength('MyP4ssword123')));
    $t->assert('Too short fails', !empty(validate_password_strength('Ab1')));
    $t->assert('No uppercase fails', !empty(validate_password_strength('mypassword123')));
    $t->assert('No lowercase fails', !empty(validate_password_strength('MYPASSWORD123')));
    $t->assert('No number fails', !empty(validate_password_strength('MyPasswordHere')));
    $t->assert('10 chars with rules passes', empty(validate_password_strength('Abcdefg1hi')));
    return $t;
}

function test_can_view_thread(): Test
{
    $t = new Test('AuthHelpers - can_view_thread()');
    $t->assertTrue('visible is viewable', can_view_thread('visible'));
    $t->assertTrue('sticky is viewable', can_view_thread('sticky'));
    $t->assertTrue('locked is viewable', can_view_thread('locked'));
    $t->assertFalse('hidden NOT viewable by guest', can_view_thread('hidden'));
    $t->assertFalse('pending NOT viewable by guest', can_view_thread('pending'));
    return $t;
}

function test_can_view_thread_moderator(): Test
{
    $t = new Test('AuthHelpers - can_view_thread() moderator');
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT UNIQUE, permissions TEXT)");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('moderator', '[\"threads.approve\"]')");
    $pdo->exec("INSERT INTO roles (name, permissions) VALUES ('user', '[]')");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, role TEXT, status TEXT DEFAULT 'active')");

    $authz = new AuthZ($pdo);
    App::getInstance()->authz = $authz;
    App::getInstance()->pdo = $pdo;

    $_SESSION = ['user_id' => 99, 'user_role' => 'moderator'];
    $stmt = $pdo->prepare("INSERT INTO users (id, username, password, role, status) VALUES (99, 'mod', 'hash', 'moderator', 'active')");
    $stmt->execute();

    $t->assertTrue('moderator can view hidden', can_view_thread('hidden'));
    $t->assertTrue('moderator can view pending', can_view_thread('pending'));

    $_SESSION = [];
    App::reset();
    return $t;
}

function test_forum_statistics(): Test
{
    $t = new Test('Data - forum_statistics()');
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE threads (id INTEGER PRIMARY KEY)");
    $pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER)");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, avatar TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    App::getInstance()->pdo = $pdo;

    $stats = forum_statistics();
    $t->assertEquals('Empty threads', 0, $stats['threads']);
    $t->assertEquals('Empty posts', 0, $stats['posts']);
    $t->assertEquals('Empty members', 0, $stats['members']);

    $pdo->exec("INSERT INTO threads (id) VALUES (1)");
    $pdo->exec("INSERT INTO posts (id, user_id) VALUES (1, 1)");
    $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'alice')");

    $stats = forum_statistics();
    $t->assertEquals('One thread', 1, $stats['threads']);
    $t->assertEquals('One post', 1, $stats['posts']);
    $t->assertEquals('One member', 1, $stats['members']);
    $t->assertEquals('One contributor', 1, $stats['contributors']);
    $t->assert('Has newest member', !empty($stats['newest_member']));

    App::reset();
    return $t;
}

function test_thread_sort_options(): Test
{
    $t = new Test('Data - thread_sort_options()');
    $options = thread_sort_options();
    $t->assert('Has latest', isset($options['latest']));
    $t->assert('Has replies', isset($options['replies']));
    $t->assert('Has views', isset($options['views']));
    $t->assert('Has newest', isset($options['newest']));
    $t->assert('Has oldest', isset($options['oldest']));
    return $t;
}

register_tests(
    'test_escape_escapes_html',
    'test_validate_input_trims',
    'test_clean_text_escapes',
    'test_time_ago',
    'test_compact_number',
    'test_excerpt',
    'test_marked_parse',
    'test_avatar_initial',
    'test_avatar_color_deterministic',
    'test_render_avatar_fallback',
    'test_validate_password_strength',
    'test_can_view_thread',
    'test_can_view_thread_moderator',
    'test_forum_statistics',
    'test_thread_sort_options'
);
