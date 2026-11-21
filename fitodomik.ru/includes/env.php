<?php
require_once __DIR__ . '/../config/env_common.php';
if (!function_exists('fitodomikRuLoadEnv')) {
    function fitodomikRuLoadEnv($path = null): array
    {
        static $loaded = [];
        if ($path === null) {
            $path = dirname(__DIR__) . '/.env';
        }
        if (!array_key_exists($path, $loaded)) {
            $loaded[$path] = fitodomikReadEnvFile($path);
        }
        return $loaded[$path];
    }
}
if (!function_exists('fitodomikRuEnv')) {
    function fitodomikRuEnv(string $key, $default = '')
    {
        $env = fitodomikRuLoadEnv();
        if (array_key_exists($key, $env)) {
            return $env[$key];
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
