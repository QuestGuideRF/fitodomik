<?php
require_once __DIR__ . '/env_common.php';
if (!function_exists('fitodomikLoadEnv')) {
    function fitodomikLoadEnv($path = null)
    {
        static $loaded = [];
        $path = $path ?: dirname(__DIR__) . '/.env';
        if (isset($loaded[$path])) {
            return $loaded[$path];
        }
        $values = fitodomikReadEnvFile($path, false);
        fitodomikPublishEnvValues($values);
        $loaded[$path] = $values;
        return $values;
    }
}
if (!function_exists('fitodomikEnv')) {
    function fitodomikProcessEnvValue(array $keys): array
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false) {
                return ['found' => true, 'value' => $value];
            }
        }
        return ['found' => false, 'value' => ''];
    }

    function fitodomikEnv($key, $default = '')
    {
        $env = fitodomikLoadEnv();
        $aliases = [
            'DB_USERNAME' => 'DB_USER',
            'DB_PASSWORD' => 'DB_PASS',
            'DB_USER' => 'DB_USERNAME',
            'DB_PASS' => 'DB_PASSWORD',
        ];
        $keys = isset($aliases[$key]) ? [$key, $aliases[$key]] : [$key];
        foreach ($keys as $candidate) {
            if (array_key_exists($candidate, $env)) {
                return $env[$candidate];
            }
        }
        $processValue = fitodomikProcessEnvValue($keys);
        return $processValue['found'] ? $processValue['value'] : $default;
    }
}
