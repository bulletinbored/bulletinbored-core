<?php

/**
 * Text and content helpers.
 */

function escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function validate_input(mixed $data): mixed {
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
function clean_text(string $data): string {
    return trim($data);
}

function render_site_name(string $name): string {
    return $name;
}

function marked_parse(string $text): string {
    require_once __DIR__ . '/../markdown.php';
    return bb_render_content($text);
}

function time_ago(string $datetime): string {
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

function compact_number(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000) return round($n / 1000, 1) . 'K';
    return (string)$n;
}

function excerpt(string $text, int $length = 110): string {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Load a translation file as a plain array. Translation files are JSON only
 * (no PHP include) so a malicious upload cannot achieve RCE: an uploaded lang
 * file is parsed as data, never executed.
 *
 * @return array<string,string>
 */
function load_lang_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $out[$k] = $v;
        }
    }
    return $out;
}
