<?php
require_once __DIR__ . '/schema_helpers.php';
function fitodomikEnsureAccessSchema(PDO $pdo): void
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) {
        throw new RuntimeException('Access schema migration is CLI only');
    }
    fitodomikAddColumnIfMissing($pdo, 'users', 'auth_generation', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
    fitodomikAddColumnIfMissing($pdo, 'preset_modes', 'description', 'TEXT DEFAULT NULL');
    foreach (['telegram_callback_actions', 'telegram_login_tokens', 'user_remember_tokens'] as $table) {
        if (fitodomikTableExists($pdo, $table)) {
            fitodomikAddColumnIfMissing($pdo, $table, 'telegram_link_id', 'BIGINT DEFAULT NULL');
        }
    }
    fitodomikAddColumnIfMissing($pdo, 'user_remember_tokens', 'auth_generation', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_client_grants (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, origin_device_id VARCHAR(64) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        INDEX idx_grant_origin(user_id, origin_device_id, revoked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_device_credentials (
        grant_id BIGINT UNSIGNED NOT NULL, device_id VARCHAR(64) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        PRIMARY KEY(grant_id, device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    fitodomikAddIndexIfMissing($pdo,'device_commands','idx_command_lease','`user_id`,`device_id`,`status`,`lease_until`');
    $duplicate=$pdo->query('SELECT device_token FROM user_devices WHERE device_token IS NOT NULL GROUP BY device_token HAVING COUNT(*)>1 LIMIT 1')->fetchColumn();
    if ($duplicate!==false) throw new RuntimeException('Duplicate device tokens require explicit resolution before migration');
    $legacyIndex=$pdo->query("SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list,
        SUM(SUB_PART IS NOT NULL) AS partials FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='user_devices' AND INDEX_NAME='idx_user_devices_token' GROUP BY NON_UNIQUE")->fetch(PDO::FETCH_ASSOC);
    if ($legacyIndex && (int)$legacyIndex['NON_UNIQUE'] && !$legacyIndex['partials'] && $legacyIndex['columns_list']==='device_token') {
        $pdo->exec('ALTER TABLE user_devices DROP INDEX idx_user_devices_token, ADD UNIQUE INDEX idx_user_devices_token(device_token)');
    }
    $unique=$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='user_devices' AND NON_UNIQUE=0 GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='device_token' AND SUM(SUB_PART IS NOT NULL)=0")->fetchColumn();
    if ($unique===false) {
        $pdo->exec('CREATE UNIQUE INDEX uq_user_devices_token ON user_devices(device_token)');
    }
    $canonical=$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='user_devices' AND INDEX_NAME='idx_user_devices_token' AND NON_UNIQUE=0
        GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='device_token' AND SUM(SUB_PART IS NOT NULL)=0")->fetchColumn();
    $temporary=$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='user_devices' AND INDEX_NAME='uq_user_devices_token' AND NON_UNIQUE=0
        GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='device_token' AND SUM(SUB_PART IS NOT NULL)=0")->fetchColumn();
    if ($canonical!==false && $temporary!==false) {
        $pdo->exec('DROP INDEX uq_user_devices_token ON user_devices');
    }
    foreach (['device_automation_scenarios'=>'idx_device_scenario_uid','device_pair_requests'=>'idx_device_pair_code',
        'device_relay_definitions'=>'idx_device_relay_uid','device_sensor_definitions'=>'idx_device_sensor_uid',
        'user_telegram_settings'=>'idx_user_telegram_user'] as $table=>$index) {
        $stmt=$pdo->prepare("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list,
            SUM(SUB_PART IS NOT NULL) AS partials FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? GROUP BY INDEX_NAME,NON_UNIQUE");
        $stmt->execute([$table]); $indexes=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $candidate) {
            if ($candidate['INDEX_NAME']!==$index || !(int)$candidate['NON_UNIQUE'] || (int)$candidate['partials']) continue;
            foreach ($indexes as $other) {
                if (!(int)$other['NON_UNIQUE'] && !(int)$other['partials'] && $other['columns_list']===$candidate['columns_list']) {
                    $pdo->exec("DROP INDEX `$index` ON `$table`"); break;
                }
            }
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS fitodomik_migrations (
        migration_id VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
