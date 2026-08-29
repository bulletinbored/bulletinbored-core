<?php

/**
 * Request.php — centralized input sanitization.
 *
 * Provides a single entry point for all user input. Every value read through
 * this class is sanitized consistently, eliminating the risk that a new
 * handler forgets to escape input.
 *
 * Usage:
 *   $request->post('name');          // raw trimmed string (escape at output)
 *   $request->post('name', 'default'); // with default
 *   $request->get('page', 1, 'int'); // cast to int
 *   $request->has('key');            // isset check
 */

namespace Bulletin;

class Request
{
    /**
     * Get a value from $_GET, sanitized.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        return self::sanitize($_GET[$key]);
    }

    /**
     * Get a value from $_POST, sanitized.
     */
    public static function post(string $key, mixed $default = null): mixed
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return self::sanitize($_POST[$key]);
    }

    /**
     * Get a value from either $_GET or $_POST (POST first).
     */
    public static function input(string $key, mixed $default = null): mixed
    {
        if (isset($_POST[$key])) {
            return self::sanitize($_POST[$key]);
        }
        if (isset($_GET[$key])) {
            return self::sanitize($_GET[$key]);
        }
        return $default;
    }

    /**
     * Check if a key exists in $_GET or $_POST.
     */
    public static function has(string $key): bool
    {
        return isset($_GET[$key]) || isset($_POST[$key]);
    }

    /**
     * Get the raw, un-sanitized value (use sparingly — only when the value
     * will be passed to prepared statements or other safe sinks).
     */
    public static function raw(string $key, mixed $default = null): mixed
    {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }
        return $default;
    }

    /**
     * Sanitize a value: trim + stripslashes. Output escaping is the
     * responsibility of the view layer (escape() at render time).
     */
    public static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'sanitize'], $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        return stripslashes(trim($value));
    }
}
