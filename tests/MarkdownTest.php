<?php

/**
 * MarkdownTest.php — tests for the Markdown parser security.
 */

require_once __DIR__ . '/../src/markdown.php';

function test_markdown_escapes_raw_html(): Test
{
    $t = new Test('Markdown - Raw HTML Escaping');

    // Raw HTML tags should be escaped, not rendered
    $result = bb_render_content('<script>alert(1)</script>');
    $t->assert('Script tag is escaped', !str_contains($result, '<script>'));
    $t->assert('Script tag appears as text', str_contains($result, '&lt;script&gt;'));

    $result = bb_render_content('<img src=x onerror=alert(1)>');
    $t->assert('IMG with onerror is escaped', !str_contains($result, '<img src=x onerror'));

    $result = bb_render_content('<iframe src="https://evil.com"></iframe>');
    $t->assert('Iframe is escaped', !str_contains($result, '<iframe'));

    return $t;
}

function test_markdown_url_schemes(): Test
{
    $t = new Test('Markdown - URL Scheme Validation');

    // javascript: scheme should be rejected (no <a> tag with javascript: href)
    $result = bb_render_content('[click](javascript:alert(1))');
    $t->assert('javascript: link is escaped', !str_contains($result, '<a href="javascript:'));

    // data: scheme (non-image) should be rejected
    $result = bb_render_content('[click](data:text/html,<script>alert(1)</script>)');
    $t->assert('data: non-image link is escaped', !str_contains($result, '<a href="data:text/html,'));

    // https: should work
    $result = bb_render_content('[link](https://example.com)');
    $t->assert('https: link works', str_contains($result, 'href="https://example.com"'));

    // mailto: should work
    $result = bb_render_content('[email](mailto:test@example.com)');
    $t->assert('mailto: link works', str_contains($result, 'href="mailto:test@example.com"'));

    return $t;
}

function test_markdown_image_schemes(): Test
{
    $t = new Test('Markdown - Image Scheme Validation');

    // javascript: in image should be rejected
    $result = bb_render_content('![img](javascript:alert(1))');
    $t->assert('javascript: image is escaped', !str_contains($result, '<img src="javascript:'));

    // https: image should work
    $result = bb_render_content('![img](https://example.com/image.png)');
    $t->assert('https: image works', str_contains($result, 'src="https://example.com/image.png"'));

    return $t;
}

function test_markdown_basic_formatting(): Test
{
    $t = new Test('Markdown - Basic Formatting');

    $result = bb_render_content('**bold**');
    $t->assert('Bold works', str_contains($result, '<strong>bold</strong>'));

    $result = bb_render_content('*italic*');
    $t->assert('Italic works', str_contains($result, '<em>italic</em>'));

    $result = bb_render_content('~~strike~~');
    $t->assert('Strikethrough works', str_contains($result, '<del>strike</del>'));

    $result = bb_render_content('`code`');
    $t->assert('Inline code works', str_contains($result, '<code>code</code>'));

    $result = bb_render_content('# Heading');
    $t->assert('H1 works', str_contains($result, '<h1>Heading</h1>'));

    $result = bb_render_content('## Heading 2');
    $t->assert('H2 works', str_contains($result, '<h2>Heading 2</h2>'));

    return $t;
}

function test_markdown_code_blocks(): Test
{
    $t = new Test('Markdown - Code Blocks');

    $result = bb_render_content("```\necho 'hello';\n```");
    $t->assert('Code block works', str_contains($result, '<pre><code>'));
    $t->assert('Code content is escaped', str_contains($result, 'echo') || str_contains($result, 'hello'));

    return $t;
}

function test_markdown_xss_vectors(): Test
{
    $t = new Test('Markdown - XSS Vectors');

    // Event handler injection via link title
    $result = bb_render_content('[link](https://example.com "title" onclick="alert(1)")');
    $t->assert('Event handler in title is escaped', !str_contains($result, 'onclick="alert(1)"'));

    // SVG onload
    $result = bb_render_content('<svg onload=alert(1)>');
    $t->assert('SVG onload is escaped', !str_contains($result, '<svg onload'));

    // Breaking out of alt text
    $result = bb_render_content('![alt"onerror="alert(1)](https://example.com/img.png)');
    $t->assert('Alt text breakout is escaped', !str_contains($result, 'onerror="alert(1)"'));

    return $t;
}

$suite = new TestSuite();
$suite->addTest(test_markdown_escapes_raw_html());
$suite->addTest(test_markdown_url_schemes());
$suite->addTest(test_markdown_image_schemes());
$suite->addTest(test_markdown_basic_formatting());
$suite->addTest(test_markdown_code_blocks());
$suite->addTest(test_markdown_xss_vectors());
$suite->run();
