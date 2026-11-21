<?php
require_once __DIR__ . '/api_exceptions.php';
const FITODOMIK_SCHEMA_VERSION = '2026-06-26-unified';
require_once __DIR__ . '/schema_helpers.php';
function devicePairEnsureTables($pdo)
{
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS device_pair_requests (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
            client_secret_hash VARCHAR(255) DEFAULT NULL,
            device_name VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            user_id INT DEFAULT NULL,
            status ENUM('pending', 'approved', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
            device_id VARCHAR(64) DEFAULT NULL,
            token_delivered_at DATETIME DEFAULT NULL,
            INDEX idx_device_pair_code (code),
            INDEX idx_device_pair_status (status),
            INDEX idx_device_pair_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try {
        $pdo->exec("ALTER TABLE device_pair_requests ADD COLUMN client_secret_hash VARCHAR(255) DEFAULT NULL AFTER code");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false && strpos($e->getMessage(), '1060') === false) {
            throw $e;
        }
    }
    fitodomikEnsureDeviceStorage($pdo);
}
if (!function_exists('fitodomikUniqueIndexForColumnsExists')) {
    function fitodomikUniqueIndexForColumnsExists($pdo, $table, $columns)
    {
        if (!fitodomikTableExists($pdo, $table)) {
            return false;
        }
        $columnList = str_replace(['`', ' '], '', $columns);
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM (
                SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND NON_UNIQUE = 0
                GROUP BY INDEX_NAME
                HAVING indexed_columns = ?
            ) existing_unique_indexes
        ");
        $stmt->execute([$table, $columnList]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('fitodomikAddUniqueIndexIfMissing')) {
    function fitodomikAddUniqueIndexIfMissing($pdo, $table, $index, $columns)
    {
        if (
            !fitodomikTableExists($pdo, $table)
            || fitodomikIndexExists($pdo, $table, $index)
            || fitodomikUniqueIndexForColumnsExists($pdo, $table, $columns)
        ) {
            return;
        }
        try {
            $pdo->exec("CREATE UNIQUE INDEX `$index` ON `$table` ($columns)");
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false && strpos($e->getMessage(), '1061') === false) {
                throw $e;
            }
        }
    }
}
if (!function_exists('fitodomikEnsureBaseSchema')) {
    function fitodomikEnsureBaseSchema($pdo)
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                password VARCHAR(255) NOT NULL,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                avatar VARCHAR(255) DEFAULT NULL,
                is_admin TINYINT(1) DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL DEFAULT NULL,
                login_attempts INT DEFAULT 0,
                is_locked TINYINT(1) DEFAULT 0,
                reset_token VARCHAR(100) DEFAULT NULL,
                reset_token_expires TIMESTAMP NULL DEFAULT NULL,
                telegram_username VARCHAR(255) DEFAULT NULL,
                telegram_last_name VARCHAR(120) DEFAULT NULL,
                telegram_chat_id VARCHAR(255) DEFAULT NULL,
                telegram_user_id BIGINT DEFAULT NULL,
                is_verified TINYINT(1) DEFAULT 0,
                UNIQUE KEY username_idx (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'users', 'avatar', "VARCHAR(255) DEFAULT NULL AFTER `created_at`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'is_admin', "TINYINT(1) DEFAULT 0 AFTER `avatar`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `is_admin`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'last_login', "TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'login_attempts', "INT DEFAULT 0 AFTER `last_login`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'is_locked', "TINYINT(1) DEFAULT 0 AFTER `login_attempts`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'reset_token', "VARCHAR(100) DEFAULT NULL AFTER `is_locked`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'reset_token_expires', "TIMESTAMP NULL DEFAULT NULL AFTER `reset_token`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_username', "VARCHAR(255) DEFAULT NULL AFTER `avatar`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_last_name', "VARCHAR(120) DEFAULT NULL AFTER `telegram_username`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_chat_id', "VARCHAR(255) DEFAULT NULL AFTER `telegram_last_name`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_user_id', "BIGINT DEFAULT NULL AFTER `telegram_chat_id`");
        fitodomikAddColumnIfMissing($pdo, 'users', 'is_verified', "TINYINT(1) DEFAULT 0 AFTER `telegram_user_id`");
        fitodomikAddUniqueIndexIfMissing($pdo, 'users', 'username_idx', '`username`');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sensor_data (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) DEFAULT NULL,
                temperature DECIMAL(5,2) DEFAULT NULL,
                humidity DECIMAL(5,2) DEFAULT NULL,
                co2 INT NOT NULL DEFAULT 400,
                soil_moisture DECIMAL(5,2) DEFAULT NULL,
                light_level DECIMAL(8,2) DEFAULT NULL,
                pressure DECIMAL(7,2) DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                curtains_state TINYINT(1) DEFAULT 0,
                lamp_state TINYINT(1) DEFAULT 0,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_sensor_user_device_created (user_id, device_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'device_id', "VARCHAR(64) DEFAULT NULL AFTER `user_id`");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'co2', "INT NOT NULL DEFAULT 400 AFTER `humidity`");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'soil_moisture', "DECIMAL(5,2) DEFAULT NULL AFTER `co2`");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'light_level', "DECIMAL(8,2) DEFAULT NULL AFTER `soil_moisture`");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'pressure', "DECIMAL(7,2) DEFAULT NULL AFTER `light_level`");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'curtains_state', "TINYINT(1) DEFAULT 0 AFTER `created_at`");
        fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'lamp_state', "TINYINT(1) DEFAULT 0 AFTER `curtains_state`");
        fitodomikAddIndexIfMissing($pdo, 'sensor_data', 'idx_user_created', '`user_id`, `created_at`');
        fitodomikAddIndexIfMissing($pdo, 'sensor_data', 'idx_sensor_user_device_created', '`user_id`, `device_id`, `created_at`');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS farm_status (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) DEFAULT NULL,
                temperature FLOAT DEFAULT NULL,
                humidity FLOAT DEFAULT NULL,
                light_level FLOAT DEFAULT NULL,
                curtains_level DECIMAL(4,2) DEFAULT NULL,
                co2_level FLOAT DEFAULT NULL,
                photo VARCHAR(255) DEFAULT NULL,
                photo_analysis VARCHAR(255) DEFAULT NULL,
                comment TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_farm_status_user_device_created (user_id, device_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'farm_status', 'device_id', "VARCHAR(64) DEFAULT NULL AFTER `user_id`");
        fitodomikAddColumnIfMissing($pdo, 'farm_status', 'photo_analysis', "VARCHAR(255) DEFAULT NULL AFTER `photo`");
        fitodomikAddColumnIfMissing($pdo, 'farm_status', 'comment', "TEXT DEFAULT NULL AFTER `photo_analysis`");
        fitodomikAddIndexIfMissing($pdo, 'farm_status', 'idx_farm_status_user_device_created', '`user_id`, `device_id`, `created_at`');
    }
}
if (!function_exists('fitodomikStoredSchemaVersion')) {
    function fitodomikStoredSchemaVersion($pdo)
    {
        try {
            $stmt = $pdo->prepare("SELECT meta_value FROM fitodomik_schema_meta WHERE meta_key = 'schema_version' LIMIT 1");
            $stmt->execute();
            return trim((string)$stmt->fetchColumn());
        } catch (PDOException $e) {
            return '';
        }
    }
}
if (!function_exists('fitodomikStoreSchemaVersion')) {
    function fitodomikStoreSchemaVersion($pdo)
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fitodomik_schema_meta (
                meta_key VARCHAR(80) NOT NULL PRIMARY KEY,
                meta_value VARCHAR(120) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $stmt = $pdo->prepare("
            INSERT INTO fitodomik_schema_meta (meta_key, meta_value)
            VALUES ('schema_version', ?)
            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
        ");
        $stmt->execute([FITODOMIK_SCHEMA_VERSION]);
    }
}
if (!function_exists('fitodomikEnsureCurrentSchema')) {
    function fitodomikEnsureCurrentSchema($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        static $checked = false;
        if ($checked || fitodomikStoredSchemaVersion($pdo) === FITODOMIK_SCHEMA_VERSION) {
            $checked = true;
            return;
        }
        $lockName = 'fitodomik_schema_migration';
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new FitodomikSchemaException('Не удалось получить блокировку обновления схемы');
        }
        try {
            if (fitodomikStoredSchemaVersion($pdo) !== FITODOMIK_SCHEMA_VERSION) {
                fitodomikEnsureBaseSchema($pdo);
                fitodomikStoreSchemaVersion($pdo);
            }
            $checked = true;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}
