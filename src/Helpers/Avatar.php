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
    if ($avatar && file_exists(__DIR__ . '/../uploads/avatars/' . $avatar)) {
        return '<img src="' . base_url() . '/uploads/avatars/' . escape($avatar) . '" alt="' . escape($username) . '" class="rounded-circle ' . escape($class) . '" width="' . $size . '" height="' . $size . '">';
    }
    $initial = avatar_initial($username);
    $color = avatar_color($username);
    return '<div class="rounded-circle d-flex align-items-center justify-content-center ' . escape($class) . '" style="width:' . $size . 'px;height:' . $size . 'px;background:' . $color . ';color:#fff;font-weight:bold;font-size:' . ($size * 0.4) . 'px;">' . escape($initial) . '</div>';
}
