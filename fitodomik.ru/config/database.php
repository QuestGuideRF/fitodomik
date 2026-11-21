<?php
require_once __DIR__ . '/error_handling.php';
require_once __DIR__ . '/pdo_connection.php';
if (!function_exists('fitodomikCreateDatabaseConnection')) {
    function fitodomikCreateDatabaseConnection()
    {
        $host = fitodomikEnv('DB_HOST', 'localhost');
        $dbname = fitodomikEnv('DB_NAME');
        $username = fitodomikEnv('DB_USERNAME', fitodomikEnv('DB_USER'));
        $password = fitodomikEnv('DB_PASSWORD', fitodomikEnv('DB_PASS'));
        return fitodomikOpenDatabaseConnection($host, $dbname, $username, $password);
    }
}
if (!defined('FITODOMIK_DEFER_DATABASE_BOOTSTRAP') || !FITODOMIK_DEFER_DATABASE_BOOTSTRAP) {
    try {
        $pdo = fitodomikCreateDatabaseConnection();
    } catch (PDOException $e) {
        fitodomikDatabaseUnavailable($e);
    }
}
