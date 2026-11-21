<?php
if (!function_exists('fitodomikCurrentTheme')) {
    function fitodomikCurrentTheme(): string
    {
        $theme = (string)($_COOKIE['theme'] ?? '');
        return $theme === 'dark' ? 'dark' : 'light';
    }
}
