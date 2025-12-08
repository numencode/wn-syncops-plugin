<?php

if (!function_exists('format_path')) {
    /**
     * Formats a folder or path string to ensure it has a single trailing slash.
     *
     * @param string|null $path The path string to format.
     * @return string|null The formatted path string or null if the input was null.
     */
    function format_path(?string $path): ?string
    {
        return $path !== null && $path !== '' ? rtrim($path, '/') . '/' : null;
    }
}
