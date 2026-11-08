<?php

/**
 * Text and content helpers.
 */

/**
 * Escape a string for safe HTML output.
 *
 * @param string $s  Raw string
 * @return string    HTML-escaped string
 */
function escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * Sanitize and normalize user input from HTTP requests.
 *
 * Use this for ALL incoming form data (POST, GET, REQUEST) before validation
 * or storage. Handles both scalar values and arrays recursively.
 *
 * Operations performed:
 * - Trims leading/trailing whitespace
 * - Removes backslashes added by PHP's deprecated magic_quotes_gpc
 * - Recursively processes arrays
 *
 * Does NOT HTML-escape. Escaping is context-dependent and should be done
 * at output time via escape() or via template auto-escaping.
 *
 * @param mixed $data  Input value or array of values
 * @return mixed       Sanitized value with same structure
 *
 * @example
 *   $username = validate_input($_POST['username'] ?? '');
 *   $email    = validate_input($_POST['email'] ?? '');
 *   $tags     = validate_input($_POST['tags'] ?? []); // array
 *
 * @see escape()      For HTML output escaping
 * @see clean_text()  For single-line titles/headings needing only trim
 */
function validate_input(mixed $data): mixed {
    if (is_array($data)) {
        return array_map('validate_input', $data);
    }
    return trim(stripslashes($data));
}

/**
 * Normalize a single-line text value for storage.
 *
 * Use this for short, single-line fields like titles, headings, slugs,
 * or labels where only whitespace trimming is desired.
 *
 * Operations performed:
 * - Trims leading/trailing whitespace only
 *
 * Does NOT:
 * - Strip backslashes (use validate_input() if magic_quotes may apply)
 * - HTML-escape (values are stored raw and escaped on output)
 * - Handle arrays (string input only)
 *
 * @param string $data  Raw single-line text
 * @return string       Trimmed text
 *
 * @example
 *   $title = clean_text($_POST['title'] ?? '');      // thread title
 *   $name  = clean_text($_POST['category_name'] ?? ''); // category name
 *
 * @see validate_input()  For multi-line content or form fields needing stripslashes
 * @see escape()          For HTML output escaping
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
