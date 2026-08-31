<?php

/**
 * Renderer tests — template engine escaping, partials, global variables.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Renderer.php';

function test_renderer_escaping(): Test
{
    $t = new Test('Renderer - Escaping');

    // Test escape function
    $t->assertEquals('Escapes <', '&lt;', escape('<'));
    $t->assertEquals('Escapes >', '&gt;', escape('>'));
    $t->assertEquals('Escapes &', '&amp;', escape('&'));
    $t->assertEquals('Escapes quotes', '&quot;', escape('"'));

    // Test render with escaping via template file
    $tmpDir = sys_get_temp_dir() . '/bb_renderer_test_' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/test.php', '<div><?php echo escape($data["name"] ?? ""); ?></div>');
    $renderer = new Bulletin\Renderer($tmpDir);
    $output = $renderer->render('test', ['name' => '<script>alert(1)</script>']);
    $t->assert('XSS in variable escaped', !str_contains($output, '<script>'));

    // Cleanup
    unlink($tmpDir . '/test.php');
    rmdir($tmpDir);

    return $t;
}

function test_renderer_variable_passing(): Test
{
    $t = new Test('Renderer - Variable Passing');

    $tmpDir = sys_get_temp_dir() . '/bb_renderer_test_' . uniqid();
    mkdir($tmpDir, 0755, true);
    $renderer = new Bulletin\Renderer($tmpDir);

    // Create template with variable
    file_put_contents($tmpDir . '/greeting.php', '<h1>Hello, <?php echo escape($data["name"] ?? "Guest"); ?>!</h1>');

    $output = $renderer->render('greeting', ['name' => 'Alice']);
    $t->assert('Variable passed correctly', str_contains($output, 'Hello, Alice!'));

    // Default value
    $output = $renderer->render('greeting');
    $t->assert('Default value works', str_contains($output, 'Hello, Guest!'));

    // Cleanup
    unlink($tmpDir . '/greeting.php');
    rmdir($tmpDir);

    return $t;
}

function test_renderer_global_variables(): Test
{
    $t = new Test('Renderer - Global Variables');

    $tmpDir = sys_get_temp_dir() . '/bb_renderer_test_' . uniqid();
    mkdir($tmpDir, 0755, true);
    $renderer = new Bulletin\Renderer($tmpDir);

    // Create template that uses extracted global (not $data)
    file_put_contents($tmpDir . '/header.php', '<header><?php echo escape($siteName ?? ""); ?></header>');

    // Share a global variable (addGlobal returns a clone)
    $renderer = $renderer->addGlobal('siteName', 'Test Forum');
    $output = $renderer->render('header');
    $t->assert('Global variable accessible', str_contains($output, 'Test Forum'));

    // Cleanup
    unlink($tmpDir . '/header.php');
    rmdir($tmpDir);

    return $t;
}

function test_renderer_data_overrides_global(): Test
{
    $t = new Test('Renderer - Data Overrides Global');

    $tmpDir = sys_get_temp_dir() . '/bb_renderer_test_' . uniqid();
    mkdir($tmpDir, 0755, true);
    $renderer = new Bulletin\Renderer($tmpDir);

    // Create template using extracted variable
    file_put_contents($tmpDir . '/test.php', '<?php echo escape($title ?? ""); ?>');

    // Share a global
    $renderer->addGlobal('title', 'Global Title');

    // Data should override global
    $output = $renderer->render('test', ['title' => 'Local Title']);
    $t->assertEquals('Data overrides global', 'Local Title', $output);

    // Cleanup
    unlink($tmpDir . '/test.php');
    rmdir($tmpDir);

    return $t;
}

// Run all renderer tests
$suite = new TestSuite();
$suite->addTest(test_renderer_escaping());
$suite->addTest(test_renderer_variable_passing());
$suite->addTest(test_renderer_global_variables());
$suite->addTest(test_renderer_data_overrides_global());
$suite->run();
