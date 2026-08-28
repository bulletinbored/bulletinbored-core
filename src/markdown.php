<?php
/**
 * markdown.php — Secure, zero-dependency Markdown renderer for BulletinBored.
 *
 * Design goals (security first):
 *  - No raw user HTML is ever echoed. The parser only ever emits tags it
 *    generates itself from recognised Markdown syntax. Anything that is not
 *    valid Markdown syntax is HTML-escaped before output.
 *  - Input arrives pre-escaped by validate_input() (htmlspecialchars), so <, >,
 *    & are stored as entities. We decode them once here, then treat the result
 *    as plain text that may contain Markdown tokens — never as HTML.
 *  - The core renders MARKDOWN ONLY. User HTML is never trusted or echoed, so
 *    the core never needs to sanitize or parse raw HTML from any source.
 *  - Auto-embed and link cards are NOT a core concern. A plugin (e.g.
 *    editbored) can register the 'render_content' hook to add them on top of
 *    the core Markdown output.
 */

if (!function_exists('bb_esc')) {
    function bb_esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/**
 * Inline Markdown: bold, italic, strikethrough, code, links, images.
 * Operates on an already HTML-escaped text fragment, so we only ever wrap
 * matched tokens in our own tags and re-escape the captured inner text.
 */
function bb_parse_inline(string $text): string {
    // Escape ALL text first. Every token we emit is generated from recognised
    // Markdown syntax, and the captured inner text is already HTML-escaped, so
    // no raw user HTML can survive. This is the core XSS boundary.
    $text = bb_esc($text);

    // Protect inline code first so its contents are not further formatted.
    $codeSpans = [];
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeSpans) {
        $codeSpans[] = '<code>' . $m[1] . '</code>';
        return "\x01" . (count($codeSpans) - 1) . "\x01";
    }, $text);

    // URLs inside images/links must be shielded from the bold/italic rules
    // below, otherwise underscores in a URL (e.g. .../anteprima_640/...) get
    // turned into <em> tags and corrupt the href/src. We replace each URL with
    // a placeholder now and restore it after all inline formatting is done.
    $protectedUrls = [];
    $shield = function (string $url) use (&$protectedUrls): string {
        $idx = count($protectedUrls);
        $protectedUrls[] = $url;
        return "\x03" . $idx . "\x03";
    };

    // Images: ![alt](url)
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) use ($shield) {
        $alt = bb_esc($m[1]);
        $url = $m[2];
        $title = isset($m[3]) ? ' title="' . bb_esc($m[3]) . '"' : '';
        if (!preg_match('#^(https?://|/|data:image/(?!svg))#i', $url)) {
            return bb_esc('![' . $m[1] . '](' . $m[2] . ')');
        }
        return '<img src="' . $shield($url) . '" alt="' . $alt . '"' . $title . ' loading="lazy">';
    }, $text);

    // Links: [text](url)
    $text = preg_replace_callback('/\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) use ($shield) {
        $label = bb_esc($m[1]);
        $url = $m[2];
        $title = isset($m[3]) ? ' title="' . bb_esc($m[3]) . '"' : '';
        if (!preg_match('#^(https?://|/|mailto:)#i', $url) || preg_match('#^\s*javascript:#i', $url)) {
            return bb_esc('[' . $m[1] . '](' . $m[2] . ')');
        }
        $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . $shield($url) . '"' . $title . $ext . '>' . $label . '</a>';
    }, $text);

    // Bold, italic, strikethrough (order matters: bold before italic).
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(^|[^*])\*([^*\n]+)\*(?!\*)/', '$1<em>$2</em>', $text);
    $text = preg_replace('/(^|[^_])_([^_\n]+)_(?!_)/', '$1<em>$2</em>', $text);
    $text = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $text);

    // Restore protected URLs (now safe from further formatting).
    $text = preg_replace_callback('/\x03(\d+)\x03/', function ($m) use ($protectedUrls) {
        return bb_esc($protectedUrls[(int) $m[1]] ?? '');
    }, $text);

    // Restore protected code spans.
    $text = preg_replace_callback('/\x01(\d+)\x01/', function ($m) use ($codeSpans) {
        return $codeSpans[(int) $m[1]] ?? '';
    }, $text);

    return $text;
}

/**
 * Block-level Markdown parser. Returns safe HTML. Never echoes raw user HTML.
 */
function bb_parse_markdown(string $src): string {
    $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];
    $out = [];
    $i = 0;
    $n = count($lines);

    $flushParagraph = function (&$buf) use (&$out) {
        if ($buf !== '') {
            $out[] = '<p>' . bb_parse_inline(trim($buf)) . '</p>';
            $buf = '';
        }
    };

    $buf = '';
    while ($i < $n) {
        $line = $lines[$i];

        if (preg_match('/^```(\w*)\s*$/', $line, $fm)) {
            $flushParagraph($buf);
            $lang = $fm[1] !== '' ? ' class="language-' . bb_esc($fm[1]) . '"' : '';
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                $code[] = bb_esc($lines[$i]);
                $i++;
            }
            $i++;
            $out[] = '<pre><code' . $lang . '>' . implode("\n", $code) . '</code></pre>';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $hm)) {
            $flushParagraph($buf);
            $level = strlen($hm[1]);
            $out[] = '<h' . $level . '>' . bb_parse_inline(trim($hm[2])) . '</h' . $level . '>';
            $i++;
            continue;
        }

        if (preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flushParagraph($buf);
            $out[] = '<hr>';
            $i++;
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $line)) {
            $flushParagraph($buf);
            $quote = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                $quote[] = $qm[1];
                $i++;
            }
            $out[] = '<blockquote>' . bb_parse_markdown(implode("\n", $quote)) . '</blockquote>';
            continue;
        }

        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line)) {
            $flushParagraph($buf);
            $items = [];
            while ($i < $n && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $lm)) {
                $items[] = '<li>' . bb_parse_inline(trim($lm[1])) . '</li>';
                $i++;
            }
            $out[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }

        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line)) {
            $flushParagraph($buf);
            $items = [];
            while ($i < $n && preg_match('/^\s*\d+\.\s+(.*)$/', $lines[$i], $lm)) {
                $items[] = '<li>' . bb_parse_inline(trim($lm[1])) . '</li>';
                $i++;
            }
            $out[] = '<ol>' . implode('', $items) . '</ol>';
            continue;
        }

        if (trim($line) === '') {
            $flushParagraph($buf);
            $i++;
            continue;
        }

        $buf .= ($buf === '' ? '' : "\n") . $line;
        $i++;
    }
    $flushParagraph($buf);

    return implode("\n", $out);
}

/**
 * Render post content for display.
 *
 * The core renders MARKDOWN ONLY. User HTML is never trusted or echoed: every
 * text node is HTML-escaped by the parser, so pasted HTML appears as literal
 * text. This is the single, secure rendering path for all posts.
 *
 * A plugin can register the 'render_content' hook to take over rendering
 * (e.g. to add auto-embeds or link cards on top of the Markdown output).
 * If a plugin returns a non-null value, that is used instead of the core
 * Markdown renderer.
 */
function bb_render_content(string $text): string {
    if ($text === '' || $text === null) {
        return '';
    }

    if (!empty($GLOBALS['pluginManager']) && method_exists($GLOBALS['pluginManager'], 'applyHook')) {
        $override = $GLOBALS['pluginManager']->applyHook('render_content', $text);
        if (is_string($override) && $override !== '') {
            return $override;
        }
    }

    return '<div class="markdown-content">' . bb_parse_markdown($text) . '</div>';
}
