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
 *  - Auto-embed: bare URLs to allow-listed hosts (YouTube, Twitter/X,
 *    Instagram, Facebook) are turned into the same restricted <iframe> markup
 *    already accepted by sanitize_html(). Host validation is done here on the
 *    server, so a user cannot smuggle an arbitrary iframe.
 *  - Legacy posts saved by the old editbored editor (HTML) keep rendering via
 *    sanitize_html(), so existing threads are not broken.
 */

// Hosts permitted for auto-embed iframes. Mirrors sanitize_html() allow-list.
function bb_embed_allowed_hosts(): array {
    return [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'platform.twitter.com', 'twitter.com', 'x.com',
        'www.facebook.com', 'facebook.com',
        'www.instagram.com', 'instagram.com',
    ];
}

/**
 * Turn a bare media URL into a safe embed iframe, or null if not allowed.
 * Only the documented players are produced; everything else returns null and
 * the URL is rendered as a normal link instead.
 */
function bb_build_embed(string $url): ?string {
    $url = trim($url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return null;
    }

    // Strip leading "www." for comparison against the allow-list.
    $bare = preg_replace('/^www\./', '', $host);

    if (in_array($bare, ['youtube.com', 'youtube-nocookie.com'], true)) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $id = $q['v'] ?? '';
        if ($id === '' && preg_match('#/(?:embed|shorts)/([A-Za-z0-9_-]{6,})#', $url, $m)) {
            $id = $m[1];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
            return null;
        }
        $domain = $bare === 'youtube-nocookie.com' ? 'www.youtube-nocookie.com' : 'www.youtube.com';
        $src = 'https://' . $domain . '/embed/' . $id;
        return '<div class="embed embed-youtube">'
            . '<iframe src="' . bb_esc($src) . '" title="Embedded video" '
            . 'frameborder="0" allowfullscreen loading="lazy"></iframe></div>';
    }

    if (in_array($bare, ['twitter.com', 'x.com'], true)) {
        // Twitter/X uses a script-based embed; we use the canonical player iframe
        // from the allow-listed platform.twitter.com origin.
        return '<div class="embed embed-twitter">'
            . '<iframe src="https://platform.twitter.com/embed/Tweet.html?url='
            . urlencode($url) . '" title="Embedded post" '
            . 'frameborder="0" loading="lazy"></iframe></div>';
    }

    if (in_array($bare, ['facebook.com', 'instagram.com'], true)) {
        $src = 'https://www.' . $bare . '/plugins/embed?url=' . urlencode($url);
        return '<div class="embed embed-' . ($bare === 'instagram.com' ? 'instagram' : 'facebook') . '">'
            . '<iframe src="' . bb_esc($src) . '" title="Embedded post" '
            . 'frameborder="0" loading="lazy"></iframe></div>';
    }

    return null;
}

/**
 * Safe generic link card for any http(s) URL. No iframe, no inline scripts:
 * just a styled <a> with the host's favicon and domain. Everything that is
 * echoed (the domain, the URL) is HTML-escaped via bb_esc().
 */
function bb_build_link_card(string $url): string {
    $url = trim($url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    $favicon = 'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=64';
    return '<div class="embed embed-link">'
        . '<a href="' . bb_esc($url) . '" target="_blank" rel="noopener noreferrer">'
        . '<img src="' . bb_esc($favicon) . '" alt="" loading="lazy" class="embed-link-favicon">'
        . '<span class="embed-link-domain">' . bb_esc($host) . '</span>'
        . '<span class="embed-link-url">' . bb_esc($url) . '</span>'
        . '</a></div>';
}
/**
 * Escape a string for safe HTML output. We deliberately do NOT use
 * htmlspecialchars_decode on user content before this — the parser escapes
 * every text node itself.
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

    // Images: ![alt](url)
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
        $alt = bb_esc($m[1]);
        $url = $m[2];
        $title = isset($m[3]) ? ' title="' . bb_esc($m[3]) . '"' : '';
        // Allow http(s), root-relative, and data:image URIs, but never SVG
        // (data:image/svg+xml can carry <script> and is a stored-XSS vector).
        if (!preg_match('#^(https?://|/|data:image/(?!svg))#i', $url)) {
            return bb_esc('![' . $m[1] . '](' . $m[2] . ')');
        }
        return '<img src="' . bb_esc($url) . '" alt="' . $alt . '"' . $title . ' loading="lazy">';
    }, $text);

    // Links: [text](url)
    $text = preg_replace_callback('/\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
        $label = bb_esc($m[1]);
        $url = $m[2];
        $title = isset($m[3]) ? ' title="' . bb_esc($m[3]) . '"' : '';
        if (!preg_match('#^(https?://|/|mailto:)#i', $url) || preg_match('#^\s*javascript:#i', $url)) {
            return bb_esc('[' . $m[1] . '](' . $m[2] . ')');
        }
        $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . bb_esc($url) . '"' . $title . $ext . '>' . $label . '</a>';
    }, $text);

    // Bold, italic, strikethrough (order matters: bold before italic).
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(^|[^*])\*([^*\n]+)\*(?!\*)/', '$1<em>$2</em>', $text);
    $text = preg_replace('/(^|[^_])_([^_\n]+)_(?!_)/', '$1<em>$2</em>', $text);
    $text = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $text);

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
    // Decode the entities produced by validate_input() so we can see real
    // characters, then split into lines. We treat the result as plain text.
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

        // Fenced code block.
        if (preg_match('/^```(\w*)\s*$/', $line, $fm)) {
            $flushParagraph($buf);
            $lang = $fm[1] !== '' ? ' class="language-' . bb_esc($fm[1]) . '"' : '';
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                $code[] = bb_esc($lines[$i]);
                $i++;
            }
            $i++; // skip closing fence
            $out[] = '<pre><code' . $lang . '>' . implode("\n", $code) . '</code></pre>';
            continue;
        }

        // Headings.
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $hm)) {
            $flushParagraph($buf);
            $level = strlen($hm[1]);
            $out[] = '<h' . $level . '>' . bb_parse_inline(trim($hm[2])) . '</h' . $level . '>';
            $i++;
            continue;
        }

        // Horizontal rule.
        if (preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flushParagraph($buf);
            $out[] = '<hr>';
            $i++;
            continue;
        }

        // Blockquote (consume consecutive > lines).
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

        // Unordered list.
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

        // Ordered list.
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

        // Blank line ends a paragraph.
        if (trim($line) === '') {
            $flushParagraph($buf);
            $i++;
            continue;
        }

        // Auto-embed: a standalone line that is exactly a media URL.
        $trimmed = trim($line);
        if (preg_match('#^https?://\S+$#i', $trimmed)) {
            $embed = bb_build_embed($trimmed);
            if ($embed === null) {
                $embed = bb_build_link_card($trimmed);
            }
            if ($embed !== '' && $embed !== null) {
                $flushParagraph($buf);
                $out[] = $embed;
                $i++;
                continue;
            }
        }

        // Normal paragraph text (accumulate).
        $buf .= ($buf === '' ? '' : "\n") . $line;
        $i++;
    }
    $flushParagraph($buf);

    return implode("\n", $out);
}

/**
 * Render post content for display.
 *  - Markdown posts (the new default) are parsed securely server-side.
 *  - Legacy HTML posts (saved by the old editbored editor) are passed through
 *    sanitize_html() so existing threads keep rendering without stored-XSS.
 */
function bb_render_content(string $text): string {
    if ($text === '' || $text === null) {
        return '';
    }
    // Legacy HTML is detected by the presence of encoded tags (&lt;), the same
    // signal marked_parse() used. For those we sanitize the HTML.
    if (str_contains($text, '&' . chr(108) . 't;')) {
        return '<div class="markdown-content">' . sanitize_html($text) . '</div>';
    }
    return '<div class="markdown-content">' . bb_parse_markdown($text) . '</div>';
}
