<?php
require_once __DIR__ . '/client_grants.php';
require_once __DIR__ . '/account_admission.php';
require_once __DIR__ . '/api_exceptions.php';
if (!defined('FITODOMIK_VARCHAR64_AFTER_USER_ID')) {
    define('FITODOMIK_VARCHAR64_AFTER_USER_ID', "VARCHAR(64) DEFAULT NULL AFTER `user_id`");
}
if (!defined('FITODOMIK_DEVICE_STORAGE_VERSION')) {
    define('FITODOMIK_DEVICE_STORAGE_VERSION', '2026-06-19');
}
require_once __DIR__ . '/schema_helpers.php';
if (!function_exists('fitodomikDropSingleUserUniqueIndexes')) {
    function fitodomikDropSingleUserUniqueIndexes($pdo, $table)
    {
        if (!fitodomikTableExists($pdo, $table)) {
            return;
        }
        $stmt = $pdo->prepare("
            SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND NON_UNIQUE = 0
            AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME
            HAVING columns_list = 'user_id'
        ");
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $indexName = str_replace('`', '``', $index['INDEX_NAME']);
            $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName`");
        }
    }
}
if (!function_exists('fitodomikEnsureDeviceStorage')) {
    function fitodomikEnsureDeviceStorage($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        static $storageReady = false;
        if ($storageReady || fitodomikDeviceStorageIsCurrent($pdo)) {
            $storageReady = true;
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_devices (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(64) NOT NULL UNIQUE,
                device_token VARCHAR(128) DEFAULT NULL UNIQUE,
                user_id INT NOT NULL,
                device_name VARCHAR(120) NOT NULL,
                status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_devices_user (user_id),
                INDEX idx_user_devices_status (status),
                INDEX idx_user_devices_user_status (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'user_devices', 'device_token', "VARCHAR(128) DEFAULT NULL AFTER `device_id`");
        fitodomikAddIndexIfMissing($pdo, 'user_devices', 'idx_user_devices_token', '`device_token`');
        if (fitodomikTableExists($pdo, 'sensor_data')) {
            fitodomikAddColumnIfMissing($pdo, 'sensor_data', 'device_id', FITODOMIK_VARCHAR64_AFTER_USER_ID);
            fitodomikAddIndexIfMissing($pdo, 'sensor_data', 'idx_sensor_user_device_created', '`user_id`, `device_id`, `created_at`');
        }
        if (fitodomikTableExists($pdo, 'farm_status')) {
            fitodomikDropSingleUserUniqueIndexes($pdo, 'farm_status');
            fitodomikAddColumnIfMissing($pdo, 'farm_status', 'device_id', FITODOMIK_VARCHAR64_AFTER_USER_ID);
            fitodomikAddIndexIfMissing($pdo, 'farm_status', 'idx_farm_status_user_device_created', '`user_id`, `device_id`, `created_at`');
        }
        fitodomikMarkDeviceStorageCurrent($pdo);
        $storageReady = true;
    }
}
if (!function_exists('fitodomikDeviceStorageIsCurrent')) {
    function fitodomikDeviceStorageIsCurrent($pdo)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT meta_value FROM fitodomik_schema_meta WHERE meta_key = 'device_storage_version' LIMIT 1"
            );
            $stmt->execute();
            return hash_equals(FITODOMIK_DEVICE_STORAGE_VERSION, trim((string)$stmt->fetchColumn()));
        } catch (PDOException $e) {
            return false;
        }
    }
}
if (!function_exists('fitodomikMarkDeviceStorageCurrent')) {
    function fitodomikMarkDeviceStorageCurrent($pdo)
    {
        $stmt = $pdo->prepare(
            "INSERT INTO fitodomik_schema_meta (meta_key, meta_value)
             VALUES ('device_storage_version', ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        );
        $stmt->execute([FITODOMIK_DEVICE_STORAGE_VERSION]);
    }
}
if (!function_exists('fitodomikRequestHeader')) {
    function fitodomikRequestHeader($name)
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return trim((string)$value);
            }
        }
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string)($_SERVER[$serverKey] ?? ''));
    }
}
if (!function_exists('fitodomikNormalizeDeviceName')) {
    function fitodomikNormalizeDeviceName($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            $name = 'FitoDomik';
        }
        return function_exists('mb_substr') ? mb_substr($name, 0, 120, 'UTF-8') : substr($name, 0, 120);
    }
}
if (!function_exists('fitodomikNormalizeDeviceId')) {
    function fitodomikNormalizeDeviceId($deviceId)
    {
        $deviceId = trim((string)$deviceId);
        if ($deviceId === '') {
            return '';
        }
        if (strlen($deviceId) > 64 || preg_match('/^[a-zA-Z0-9._:-]+$/', $deviceId) !== 1) {
            throw new FitodomikInputException('Некорректный идентификатор устройства');
        }
        return $deviceId;
    }
}
if (!function_exists('fitodomikCreateDeviceId')) {
    function fitodomikCreateDeviceId($pdo)
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $deviceId = 'fd-' . bin2hex(random_bytes(8));
            $stmt = $pdo->prepare("SELECT id FROM user_devices WHERE device_id = ? LIMIT 1");
            $stmt->execute([$deviceId]);
            if (!$stmt->fetch()) {
                return $deviceId;
            }
        }
        throw new FitodomikStorageException('Не удалось создать уникальный ID устройства');
    }
}
if (!function_exists('fitodomikCreateDeviceToken')) {
    function fitodomikCreateDeviceToken($pdo)
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("SELECT id FROM user_devices WHERE device_token = ? LIMIT 1");
            $stmt->execute([$token]);
            if (!$stmt->fetch()) {
                return $token;
            }
        }
        throw new FitodomikStorageException('Не удалось создать уникальный токен устройства');
    }
}
if (!function_exists('fitodomikUserByToken')) {
    function fitodomikUserByToken($pdo, $token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return null;
        }
        return fitodomikClientGrantUser($pdo, $token);
    }
}
if (!function_exists('fitodomikEnsureUserDevice')) {
    function fitodomikEnsureUserDevice($pdo, $user_id, $device_id, $device_name, $ensureStorage = true)
    {
        $user_id = (int)$user_id;
        $device_id = fitodomikNormalizeDeviceId($device_id);
        if ($device_id === '') {
            $device_id = fitodomikCreateDeviceId($pdo);
        }
        $device_name = fitodomikNormalizeDeviceName($device_name);
        $find = $pdo->prepare("
            SELECT device_id, device_token, user_id, device_name, status, linked_at, last_seen_at, revoked_at
            FROM user_devices
            WHERE device_id = ?
            LIMIT 1
        ");
        $find->execute([$device_id]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing && (int)$existing['user_id'] !== $user_id) {
            throw new DomainException('Идентификатор устройства уже принадлежит другому аккаунту');
        }
        if ($existing) {
            $device_token = trim((string)($existing['device_token'] ?? ''));
            $isReactivating = ($existing['status'] ?? '') === 'revoked';
            if ($isReactivating || $device_token === '') {
                $device_token = fitodomikCreateDeviceToken($pdo);
            }
            $stmt = $pdo->prepare("
                UPDATE user_devices
                SET device_token = ?, device_name = ?, status = 'active',
                    last_seen_at = NOW(), revoked_at = NULL
                WHERE user_id = ? AND device_id = ?
            ");
            $stmt->execute([$device_token, $device_name, $user_id, $device_id]);
        } else {
            $device_token = fitodomikCreateDeviceToken($pdo);
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO user_devices
                        (device_id, device_token, user_id, device_name, status, linked_at, last_seen_at, revoked_at)
                    VALUES (?, ?, ?, ?, 'active', NOW(), NOW(), NULL)
                ");
                $stmt->execute([$device_id, $device_token, $user_id, $device_name]);
            } catch (PDOException $e) {
                $find->execute([$device_id]);
                $conflicting = $find->fetch(PDO::FETCH_ASSOC);
                if (!$conflicting || (int)$conflicting['user_id'] !== $user_id) {
                    throw new DomainException('Идентификатор устройства уже принадлежит другому аккаунту', 0, $e);
                }
            }
        }
        $select = $pdo->prepare("
            SELECT device_id, device_token, user_id, device_name, status, linked_at, last_seen_at, revoked_at
            FROM user_devices
            WHERE user_id = ? AND device_id = ?
            LIMIT 1
        ");
        $select->execute([$user_id, $device_id]);
        return $select->fetch(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('fitodomikTouchDevice')) {
    function fitodomikTouchDevice($pdo, $user_id, $device_id)
    {
        $device_id = trim((string)$device_id);
        if ($device_id === '') {
            return;
        }
        $stmt = $pdo->prepare("UPDATE user_devices SET last_seen_at = NOW() WHERE user_id = ? AND device_id = ?");
        $stmt->execute([(int)$user_id, $device_id]);
    }
}
if (!function_exists('fitodomikGetDeviceByToken')) {
    function fitodomikGetDeviceByToken($pdo, $device_id, $device_token)
    {
        $device_id = trim((string)$device_id);
        $device_token = trim((string)$device_token);
        if ($device_id === '' || $device_token === '') {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT d.device_id, d.device_token, d.user_id, d.device_name, d.status,
                   d.linked_at, d.last_seen_at, d.revoked_at,
                   u.username, u.first_name, u.last_name, u.is_locked
            FROM user_devices d
            INNER JOIN users u ON u.id = d.user_id
            WHERE d.device_id = ? AND d.device_token = ? AND d.status = 'active' AND u.is_locked = 0
            LIMIT 1
        ");
        $stmt->execute([$device_id, $device_token]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) { $device = fitodomikClientDeviceByToken($pdo, $device_id, $device_token); }
        return fitodomikAccountAdmitted($device) ? $device : null;
    }
}
if (!function_exists('fitodomikDeviceTokenFromRequest')) {
    function fitodomikDeviceTokenFromRequest($input = [])
    {
        $input = is_array($input) ? $input : [];
        $headerToken = fitodomikRequestHeader('X-Device-Token');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
            return $headerToken;
        }
        return trim((string)($input['device_token'] ?? $headerToken));
    }
}
if (!function_exists('fitodomikDeviceIdFromRequest')) {
    function fitodomikDeviceIdFromRequest($input = [])
    {
        $input = is_array($input) ? $input : [];
        return fitodomikNormalizeDeviceId($input['device_id'] ?? fitodomikRequestHeader('X-Device-ID'));
    }
}
if (!function_exists('fitodomikAuthDeviceRequest')) {
    function fitodomikAuthDeviceRequest($pdo, $input = [], $allow_account_token_fallback = true)
    {
        $device_id = fitodomikDeviceIdFromRequest($input);
        $device_token = fitodomikDeviceTokenFromRequest($input);
        $auth = null;
        if ($device_id !== '' && $device_token !== '') {
            $device = fitodomikGetDeviceByToken($pdo, $device_id, $device_token);
            if ($device) {
                fitodomikTouchDevice($pdo, (int)$device['user_id'], $device_id);
                $auth = [
                    'user_id' => (int)$device['user_id'],
                    'user' => [
                        'id' => (int)$device['user_id'],
                        'username' => $device['username'],
                        'first_name' => $device['first_name'],
                        'last_name' => $device['last_name'],
                    ],
                    'device' => $device,
                    'auth_mode' => 'device_token',
                ];
            }
        }
        if ($auth === null && $allow_account_token_fallback && $device_id !== '') {
            $account_token = fitodomikRequestHeader('X-Auth-Token');
            $user = fitodomikUserByToken($pdo, $account_token);
            if ($user) {
                $stmt = $pdo->prepare("
                    SELECT device_id, device_token, user_id, device_name, status, linked_at, last_seen_at, revoked_at
                    FROM user_devices
                    WHERE user_id = ? AND device_id = ? AND status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([(int)$user['id'], $device_id]);
                $device = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($device) {
                    if (empty($device['device_token'])) {
                        $newToken = fitodomikCreateDeviceToken($pdo);
                        $update = $pdo->prepare("UPDATE user_devices SET device_token = ? WHERE user_id = ? AND device_id = ?");
                        $update->execute([$newToken, (int)$user['id'], $device_id]);
                        $device['device_token'] = $newToken;
                    }
                    fitodomikTouchDevice($pdo, (int)$user['id'], $device_id);
                    $auth = [
                        'user_id' => (int)$user['id'],
                        'user' => $user,
                        'device' => $device,
                        'auth_mode' => 'account_token',
                    ];
                }
            }
        }
        return $auth;
    }
}
if (!function_exists('fitodomikGetUserDevices')) {
    function fitodomikGetUserDevices($pdo, $user_id, $include_revoked = false)
    {
        $sql = "
            SELECT device_id, device_token, device_name, status, linked_at, last_seen_at, revoked_at
            FROM user_devices
            WHERE user_id = ?
        ";
        $params = [(int)$user_id];
        if (!$include_revoked) {
            $sql .= " AND status = 'active'";
        }
        $sql .= " ORDER BY status = 'active' DESC, COALESCE(last_seen_at, linked_at) DESC, linked_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('fitodomikGetSelectedDeviceId')) {
    function fitodomikGetSelectedDeviceId($pdo, $user_id)
    {
        $devices = fitodomikGetUserDevices($pdo, $user_id, false);
        if (empty($devices)) {
            unset($_SESSION['selected_device_id']);
            return '';
        }
        $selected = trim((string)($_SESSION['selected_device_id'] ?? ''));
        foreach ($devices as $device) {
            if ($device['device_id'] === $selected) {
                return $selected;
            }
        }
        $_SESSION['selected_device_id'] = $devices[0]['device_id'];
        return $devices[0]['device_id'];
    }
}
if (!function_exists('fitodomikGetSelectedDevice')) {
    function fitodomikGetSelectedDevice($pdo, $user_id)
    {
        $deviceId = fitodomikGetSelectedDeviceId($pdo, $user_id);
        if ($deviceId === '') {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT device_id, device_token, device_name, status, linked_at, last_seen_at, revoked_at
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([(int)$user_id, $deviceId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        return $device ?: null;
    }
}
if (!function_exists('fitodomikSelectDevice')) {
    function fitodomikSelectDevice($pdo, $user_id, $device_id)
    {
        $stmt = $pdo->prepare("
            SELECT device_id
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([(int)$user_id, trim((string)$device_id)]);
        if (!$stmt->fetch()) {
            return false;
        }
        $_SESSION['selected_device_id'] = trim((string)$device_id);
        return true;
    }
}
if (!function_exists('fitodomikDeviceWhere')) {
    function fitodomikDeviceWhere($device_id, $prefix = '')
    {
        $device_id = trim((string)$device_id);
        if ($device_id === '') {
            return ['', []];
        }
        $column = $prefix === '' ? 'device_id' : $prefix . '.device_id';
        return [" AND $column = ?", [$device_id]];
    }
}
if (!function_exists('fitodomikDeviceLabel')) {
    function fitodomikDeviceLabel($device)
    {
        if (!$device) {
            return 'Устройство не выбрано';
        }
        return $device['device_name'] . ' (' . $device['device_id'] . ')';
    }
}
if (!function_exists('fitodomikDefaultNotificationSettings')) {
    function fitodomikDefaultNotificationSettings()
    {
        return [
            'telegram_enabled' => 1,
            'notify_soil_moisture' => 1,
            'notify_temperature' => 1,
            'notify_camera' => 1,
            'send_photos_telegram' => 0,
            'send_daily_report' => 1,
            'daily_report_time' => '20:00',
            'alert_check_interval_minutes' => 60,
            'alert_repeat_cooldown_minutes' => 180,
        ];
    }
}
if (!function_exists('fitodomikEnsureNotificationStorage')) {
    function fitodomikEnsureNotificationStorage($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        static $readyConnection = null;
        if ($readyConnection === $pdo || $pdo->inTransaction()) { return; }
        fitodomikEnsureDeviceStorage($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_telegram_settings (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                telegram_chat_id VARCHAR(64) DEFAULT NULL,
                telegram_username VARCHAR(120) DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'telegram_chat_id', FITODOMIK_VARCHAR64_AFTER_USER_ID);
        fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'telegram_username', "VARCHAR(120) DEFAULT NULL AFTER `telegram_chat_id`");
        fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `telegram_username`");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_notification_settings (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                telegram_enabled TINYINT(1) NOT NULL DEFAULT 1,
                notify_soil_moisture TINYINT(1) NOT NULL DEFAULT 1,
                notify_temperature TINYINT(1) NOT NULL DEFAULT 1,
                notify_camera TINYINT(1) NOT NULL DEFAULT 1,
                send_photos_telegram TINYINT(1) NOT NULL DEFAULT 0,
                send_daily_report TINYINT(1) NOT NULL DEFAULT 1,
                daily_report_time VARCHAR(5) NOT NULL DEFAULT '20:00',
                alert_check_interval_minutes INT NOT NULL DEFAULT 60,
                alert_repeat_cooldown_minutes INT NOT NULL DEFAULT 180,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device_notifications (user_id, device_id),
                INDEX idx_device_notifications_device (device_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'telegram_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `device_id`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'notify_soil_moisture', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `telegram_enabled`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'notify_temperature', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_soil_moisture`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'notify_camera', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_temperature`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'send_photos_telegram', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `notify_camera`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'send_daily_report', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `send_photos_telegram`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'daily_report_time', "VARCHAR(5) NOT NULL DEFAULT '20:00' AFTER `send_daily_report`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'alert_check_interval_minutes', "INT NOT NULL DEFAULT 60 AFTER `daily_report_time`");
        fitodomikAddColumnIfMissing($pdo, 'device_notification_settings', 'alert_repeat_cooldown_minutes', "INT NOT NULL DEFAULT 180 AFTER `alert_check_interval_minutes`");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_alert_rules (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                rule_id VARCHAR(80) NOT NULL,
                name VARCHAR(160) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                rule_data LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device_alert_rule (user_id, device_id, rule_id),
                INDEX idx_device_alert_rules_device (device_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $readyConnection = $pdo;
    }
}
if (!function_exists('fitodomikGetOrCreateNotificationSettings')) {
    function fitodomikGetOrCreateNotificationSettings($pdo, $user_id, $device_id)
    {
        $defaults = fitodomikDefaultNotificationSettings();
        $stmt = $pdo->prepare("
            INSERT INTO device_notification_settings (
                user_id, device_id, telegram_enabled, notify_soil_moisture, notify_temperature,
                notify_camera, send_photos_telegram, send_daily_report, daily_report_time,
                alert_check_interval_minutes, alert_repeat_cooldown_minutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE device_id = VALUES(device_id)
        ");
        $stmt->execute([
            (int)$user_id,
            $device_id,
            $defaults['telegram_enabled'],
            $defaults['notify_soil_moisture'],
            $defaults['notify_temperature'],
            $defaults['notify_camera'],
            $defaults['send_photos_telegram'],
            $defaults['send_daily_report'],
            $defaults['daily_report_time'],
            $defaults['alert_check_interval_minutes'],
            $defaults['alert_repeat_cooldown_minutes'],
        ]);
        $select = $pdo->prepare("
            SELECT *
            FROM device_notification_settings
            WHERE user_id = ? AND device_id = ?
            LIMIT 1
        ");
        $select->execute([(int)$user_id, $device_id]);
        return $select->fetch(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('fitodomikGetOrCreateTelegramSettings')) {
    function fitodomikGetOrCreateTelegramSettings($pdo, $user_id)
    {
        $stmt = $pdo->prepare("
            INSERT INTO user_telegram_settings (user_id, enabled)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
        ");
        $stmt->execute([(int)$user_id]);
        $select = $pdo->prepare("SELECT * FROM user_telegram_settings WHERE user_id = ? LIMIT 1");
        $select->execute([(int)$user_id]);
        return $select->fetch(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('fitodomikGetDeviceAlertRules')) {
    function fitodomikGetDeviceAlertRules($pdo, $user_id, $device_id)
    {
        $stmt = $pdo->prepare("
            SELECT rule_id, name, enabled, rule_data
            FROM device_alert_rules
            WHERE user_id = ? AND device_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([(int)$user_id, trim((string)$device_id)]);
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = json_decode((string)$row['rule_data'], true);
            if (!is_array($data)) {
                continue;
            }
            $data['id'] = $row['rule_id'];
            $data['name'] = $row['name'];
            $data['enabled'] = (bool)$row['enabled'];
            $rules[] = $data;
        }
        return $rules;
    }
}
if (!function_exists('fitodomikSaveDeviceAlertRules')) {
    function fitodomikNormalizeDeviceAlertRule($rule)
    {
        if (!is_array($rule)) {
            return null;
        }
        $ruleId = trim((string)($rule['id'] ?? ''));
        if ($ruleId === '') {
            $ruleId = 'rule_' . bin2hex(random_bytes(4));
        }
        if (strlen($ruleId) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $ruleId) !== 1) {
            throw new FitodomikInputException('Некорректный идентификатор правила уведомлений');
        }
        $ruleJson = json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($ruleJson) || strlen($ruleJson) > 16384) {
            throw new FitodomikInputException('Правило уведомлений слишком большое');
        }
        return [
            $ruleId,
            fitodomikNormalizeDeviceName($rule['name'] ?? $ruleId),
            !empty($rule['enabled']) ? 1 : 0,
            $ruleJson,
        ];
    }
    function fitodomikSaveDeviceAlertRules($pdo, $user_id, $device_id, $rules)
    {
        if (!is_array($rules)) {
            return;
        }
        if (count($rules) > 50) {
            throw new FitodomikInputException('Разрешено не более 50 правил уведомлений на устройство');
        }
        $normalizedRules = [];
        foreach ($rules as $rule) {
            $normalizedRule = fitodomikNormalizeDeviceAlertRule($rule);
            if ($normalizedRule !== null) {
                $normalizedRules[] = $normalizedRule;
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO device_alert_rules (user_id, device_id, rule_id, name, enabled, rule_data)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                enabled = VALUES(enabled),
                rule_data = VALUES(rule_data)
        ");
        foreach ($normalizedRules as [$rule_id, $name, $enabled, $ruleJson]) {
            $stmt->execute([
                (int)$user_id,
                trim((string)$device_id),
                $rule_id,
                $name,
                $enabled,
                $ruleJson,
            ]);
        }
    }
}
// Browser mutations carry the device rendered in that tab. Never use mutable
// selected_device_id as an implicit actuator target.
function fitodomikRenderedDevice(PDO $pdo, int $userId, array $input, bool $required = true): ?array
{
    $id = fitodomikDeviceIdFromRequest($input);
    if ($id === '') {
        if ($required) { throw new FitodomikInputException('Передайте device_id отображённого устройства'); }
        return fitodomikGetSelectedDevice($pdo, $userId);
    }
    $stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? AND device_id=? AND status='active' LIMIT 1");
    $stmt->execute([$userId, $id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) { throw new FitodomikAuthorizationException('Активное устройство не принадлежит аккаунту'); }
    return $device;
}
