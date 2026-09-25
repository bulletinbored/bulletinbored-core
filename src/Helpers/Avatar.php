<?php

/**
 * Avatar rendering helpers.
 */

function avatar_initial($name) {
    return mb_strtoupper(mb_substr($name, 0, 1));
}

function avatar_color($name) {
    $colors = ['#550296', '#0d6efd', '#198754', '#dc3545', '#fd7e14', '#6f42c1'];
    $hash = 0;
    for ($i = 0; $i < mb_strlen($name); $i++) {
        $hash = (ord(mb_substr($name, $i, 1)) + $hash) % count($colors);
    }
    return $colors[$hash];
}

function render_avatar($username, $avatar = '', $size = 44, $class = '') {
    $size = max(8, (int)$size);
    if ($avatar && file_exists(__DIR__ . '/../uploads/avatars/' . $avatar)) {
        return '<img src="' . base_url() . '/uploads/avatars/' . escape($avatar) . '" alt="' . escape($username) . '" class="rounded-circle ' . escape($class) . '" width="' . $size . '" height="' . $size . '">';
    }
    // Render the fallback as an inline SVG: presentation attributes (fill,
    // width, font-size) are not affected by the style-src CSP, unlike an
    // inline style="..." attribute which the strict policy blocks.
    $initial = avatar_initial($username);
    $color = avatar_color($username);
    return '<svg class="avatar-initial ' . escape($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" role="img" aria-label="' . escape($username) . '">'
        . '<circle cx="50" cy="50" r="50" fill="' . $color . '"/>'
        . '<text x="50" y="50" text-anchor="middle" dominant-baseline="central" fill="#ffffff" font-weight="bold" font-size="42">'
        . escape($initial) . '</text></svg>';
}
