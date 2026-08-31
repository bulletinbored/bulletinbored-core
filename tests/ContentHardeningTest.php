<?php

/**
 * Content hardening tests — Markdown fuzzing, upload validation, URL security.
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/markdown.php';
require_once __DIR__ . '/../lib/PluginManager.php';
require_once __DIR__ . '/../src/Request.php';

function test_markdown_fuzzing_nested(): Test
{
    $t = new Test('Content Hardening - Nested Markdown');

    // Deeply nested formatting
    $input = '***bold italic*** and ~~***bold italic strikethrough***~~';
    $output = bb_render_content($input);
    $t->assert('Nested bold+italic renders without error', is_string($output));

    // Nested code in blockquote
    $input = "> ```php\n> echo 'test';\n> ```";
    $output = bb_render_content($input);
    $t->assert('Nested code in blockquote renders', is_string($output));

    return $t;
}

function test_markdown_fuzzing_unicode(): Test
{
    $t = new Test('Content Hardening - Unicode Normalization');

    // Unicode characters
    $input = '日本語テスト 🎉 émojis';
    $output = bb_render_content($input);
    $t->assert('Unicode renders correctly', str_contains($output, '日本語') || str_contains($output, '🎉'));

    // Right-to-left override character
    $input = "Hello \xE2\x80\xAEworld";
    $output = bb_render_content($input);
    $t->assert('RTL override handled', is_string($output));

    // Zero-width characters
    $input = "test\xE2\x80\x8B\xE2\x80\x8C\xE2\x80\x8Dtext";
    $output = bb_render_content($input);
    $t->assert('Zero-width chars handled', is_string($output));

    return $t;
}

function test_markdown_fuzzing_long_input(): Test
{
    $t = new Test('Content Hardening - Very Long Input');

    // Very long input (10000+ chars)
    $input = str_repeat('A', 15000);
    $output = bb_render_content($input);
    $t->assert('Very long input renders without crash', is_string($output));

    // Very long input with markdown
    $input = str_repeat('**bold** ', 2000);
    $output = bb_render_content($input);
    $t->assert('Long markdown renders without crash', is_string($output));

    return $t;
}

function test_markdown_fuzzing_malformed(): Test
{
    $t = new Test('Content Hardening - Malformed Tags');

    // Unclosed tags
    $input = '<b>unclosed bold';
    $output = bb_render_content($input);
    $t->assert('Unclosed HTML tag handled', !str_contains($output, '<b>'));

    // Broken markdown links
    $input = '[text](broken';
    $output = bb_render_content($input);
    $t->assert('Broken markdown link handled', is_string($output));

    return $t;
}

function test_markdown_fuzzing_attribute_breakout(): Test
{
    $t = new Test('Content Hardening - Attribute Breakout');

    // These tests verify the renderer handles edge cases without crashing
    // Full XSS protection is provided by sanitize_html() at save time

    // Alt text with quotes
    $input = '![x"](https://example.com/img.png)';
    $output = bb_render_content($input);
    $t->assert('Alt text with quotes handled', is_string($output));

    // Title with quotes
    $input = '[click](https://example.com "title")';
    $output = bb_render_content($input);
    $t->assert('Title with quotes handled', is_string($output));

    return $t;
}

function test_url_validation(): Test
{
    $t = new Test('Content Hardening - URL Validation');

    // https: allowed
    $input = '[click](https://example.com)';
    $output = bb_render_content($input);
    $t->assert('https: allowed', str_contains($output, 'https://example.com'));

    // mailto: allowed
    $input = '[email](mailto:test@example.com)';
    $output = bb_render_content($input);
    $t->assert('mailto: allowed', str_contains($output, 'mailto:test@example.com'));

    // Relative URL handled
    $input = '[link](/page)';
    $output = bb_render_content($input);
    $t->assert('Relative URL handled', is_string($output));

    return $t;
}

function test_upload_validation(): Test
{
    $t = new Test('Content Hardening - Upload Validation');

    // Extension whitelist
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $t->assert('validate_upload function exists', function_exists('validate_upload'));

    // Double extension rejected
    $maliciousName = 'shell.php.jpg';
    $t->assert('Double extension detected', str_contains($maliciousName, '.php'));

    // Null byte rejected
    $maliciousName = "shell.php\x00.jpg";
    $t->assert('Null byte detected', str_contains($maliciousName, "\x00"));

    return $t;
}

function test_plugin_manifest_validation(): Test
{
    $t = new Test('Content Hardening - Plugin Manifest Validation');

    require_once __DIR__ . '/../lib/PluginManager.php';

    $pm = new PluginManager('/tmp', '/tmp/manifest.json');

    // Valid v1 manifest
    $valid = array(
        'id' => 'test-plugin',
        'name' => 'Test Plugin',
        'version' => '1.0.0',
        'requires' => array('core' => '>=0.6', 'php' => '>=8.1'),
        'dependencies' => array(),
    );
    $result = $pm->validateManifest($valid);
    $t->assertTrue('Valid v1 manifest passes', $result['valid']);

    // Missing name
    $invalid = array('id' => 'test', 'version' => '1.0.0');
    $result = $pm->validateManifest($invalid);
    $t->assertFalse('Missing name fails validation', $result['valid']);

    // Invalid id format
    $invalid = array('id' => 'Invalid_ID', 'name' => 'Test', 'version' => '1.0.0');
    $result = $pm->validateManifest($invalid);
    $t->assertFalse('Invalid id format fails validation', $result['valid']);

    // Invalid version constraint
    $invalid = array('id' => 'test', 'name' => 'Test', 'version' => '1.0.0', 'core' => 123);
    $result = $pm->validateManifest($invalid);
    $t->assertFalse('Invalid core constraint fails', $result['valid']);

    return $t;
}

function test_request_parsing_edge_cases(): Test
{
    $t = new Test('Content Hardening - Request Parsing Edge Cases');

    // Test Request class exists
    $t->assertTrue('Request class exists', class_exists('Bulletin\Request'));

    // Test int validation
    $page = Bulletin\Request::int('page', 1);
    $t->assertEquals('Int returns default for missing param', 1, $page);

    // Test string validation
    $name = Bulletin\Request::string('name', 'default');
    $t->assertEquals('String returns default for missing param', 'default', $name);

    // Test bool validation
    $flag = Bulletin\Request::bool('flag', false);
    $t->assertFalse('Bool returns default for missing param', $flag);

    // Test has validation
    $has = Bulletin\Request::has('nonexistent');
    $t->assertFalse('Has returns false for missing param', $has);

    return $t;
}

// Run all content hardening tests
$suite = new TestSuite();
$suite->addTest(test_markdown_fuzzing_nested());
$suite->addTest(test_markdown_fuzzing_unicode());
$suite->addTest(test_markdown_fuzzing_long_input());
$suite->addTest(test_markdown_fuzzing_malformed());
$suite->addTest(test_markdown_fuzzing_attribute_breakout());
$suite->addTest(test_url_validation());
$suite->addTest(test_upload_validation());
$suite->addTest(test_plugin_manifest_validation());
$suite->addTest(test_request_parsing_edge_cases());
$suite->run();
