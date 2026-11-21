<?php

/**
 * Text and content helpers.
 */

function escape($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function validate_input($data) {
    if (is_array($data)) {
        return array_map('validate_input', $data);
    }
    return trim(stripslashes($data));
}

/**
 * Trim a raw single-line text value (e.g. a thread title) for storage.
 *
 * Intentionally does NOT HTML-escape. Values are stored exactly as the user
 * typed them and escaped only on output (like usernames via validate_input()).
 * Escaping here as well caused double encoding: "we're" was stored as
 * "we&#039;re" and then displayed literally as "we&#039;re".
 */
function clean_text($data) {
    return trim($data);
}

function render_site_name(string $name): string {
    return $name;
}

function marked_parse($text) {
    require_once __DIR__ . '/../markdown.php';
    return bb_render_content($text);
}

function time_ago($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}

function compact_number($n) {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000) return round($n / 1000, 1) . 'K';
    return (string)$n;
}

function excerpt($text, $length = 110) {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Render a short, single-line excerpt with inline Markdown formatting.
 *
 * Post content is stored as Markdown. The "last activity" preview used to run
 * it through excerpt(), which only strips HTML tags and therefore exposed the
 * raw syntax ("**bold**"). This helper parses inline Markdown and keeps only
 * inline formatting that is valid inside the preview link. Images and links
 * are unwrapped because the excerpt itself is already an anchor.
 */
function excerpt_html($text, $length = 110) {
    $text = (string)$text;
    if ($text === '') {
        return '';
    }

    require_once __DIR__ . '/../markdown.php';

    $html = bb_parse_inline(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Keep only formatting that is safe and valid inside the excerpt anchor.
    $html = strip_tags($html, '<strong><b><em><i><code><del><s>');
    $html = trim(preg_replace('/\s+/u', ' ', $html));

    if ($html === '') {
        return '';
    }

    $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $out = '';
    $open = [];
    $visible = 0;
    $truncated = false;

    foreach ($parts as $part) {
        if ($part[0] === '<') {
            if (preg_match('#^</([a-z0-9]+)\s*>$#i', $part, $m)) {
                $name = strtolower($m[1]);
                if (!empty($open) && end($open) === $name) {
                    array_pop($open);
                }
                $out .= $part;
            } elseif (preg_match('#^<([a-z0-9]+)(?:\s[^>]*)?>$#i', $part, $m)) {
                $open[] = strtolower($m[1]);
                $out .= $part;
            }
            continue;
        }

        $remaining = $length - $visible;
        if ($remaining <= 0) {
            $truncated = true;
            break;
        }

        $partLength = mb_strlen($part, 'UTF-8');
        if ($partLength > $remaining) {
            $out .= mb_substr($part, 0, $remaining, 'UTF-8');
            $visible += $remaining;
            $truncated = true;
            break;
        }

        $out .= $part;
        $visible += $partLength;
    }

    while (!empty($open)) {
        $out .= '</' . array_pop($open) . '>';
    }

    if ($truncated) {
        $out = rtrim($out) . '...';
    }

    return $out;
}
