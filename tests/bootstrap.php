<?php

/**
 * Minimal WordPress function stubs required by
 * TheEventsCalendar::locateThemeTemplate() so it can run outside of a real
 * WordPress/Sage bootstrap.
 */

if (!function_exists('trailingslashit')) {
    function trailingslashit($string)
    {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path)
    {
        $path = str_replace('\\', '/', $path);
        return preg_replace('|(?<=.)/+|', '/', $path);
    }
}
