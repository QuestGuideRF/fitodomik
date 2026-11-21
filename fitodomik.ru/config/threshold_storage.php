<?php
if (!function_exists('fitodomikEnsureThresholdStorage')) {
    function fitodomikEnsureThresholdStorage(PDO $pdo): void
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `alarm_thresholds` (
              `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT NOT NULL,
              `device_id` VARCHAR(64) NOT NULL,
              `parameter_type` ENUM('temperature', 'humidity_air', 'humidity_soil', 'co2') NOT NULL,
              `min_limit` DECIMAL(8,2) NOT NULL,
              `max_limit` DECIMAL(8,2) NOT NULL,
              `target_value` DECIMAL(8,2) DEFAULT NULL,
              `tolerance` DECIMAL(5,2) DEFAULT 1.00,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uq_alarm_device_parameter` (`user_id`, `device_id`, `parameter_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
