<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/device_context.php';
require_once __DIR__ . '/json_response.php';
if (!defined('FITODOMIK_DYNAMIC_USER_DEVICE_INDEX')) {
    define('FITODOMIK_DYNAMIC_USER_DEVICE_INDEX', '`user_id`, `device_id`');
}
if (!defined('FITODOMIK_DYNAMIC_ENABLED_FILTER')) {
    define('FITODOMIK_DYNAMIC_ENABLED_FILTER', ' AND enabled = 1');
}
if (!defined('FITODOMIK_DYNAMIC_ID_ORDER')) {
    define('FITODOMIK_DYNAMIC_ID_ORDER', ' ORDER BY id ASC');
}
if (!defined('FITODOMIK_DYNAMIC_SENSOR_LIMIT')) {
    define('FITODOMIK_DYNAMIC_SENSOR_LIMIT', 128);
}
if (!defined('FITODOMIK_DYNAMIC_RELAY_LIMIT')) {
    define('FITODOMIK_DYNAMIC_RELAY_LIMIT', 64);
}
if (!defined('FITODOMIK_DYNAMIC_SCENARIO_LIMIT')) {
    define('FITODOMIK_DYNAMIC_SCENARIO_LIMIT', 100);
}
if (!function_exists('fitodomikDynamicJsonResponse')) {
    function fitodomikDynamicJsonResponse($status, $payload)
    {
        fitodomikJsonResponse($status, $payload);
    }
}
if (!function_exists('fitodomikDynamicInput')) {
    function fitodomikDynamicInput()
    {
        $maxBodyBytes = 1024 * 1024;
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBodyBytes) {
            fitodomikDynamicJsonResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
        }
        $rawInput = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if (is_string($rawInput) && strlen($rawInput) > $maxBodyBytes) {
            fitodomikDynamicJsonResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
        }
        $input = json_decode((string)$rawInput, true);
        if (!is_array($input)) {
            $input = $_REQUEST;
        }
        return is_array($input) ? $input : [];
    }
}
if (!function_exists('fitodomikDynamicBool')) {
    function fitodomikDynamicBool($value, $default = true)
    {
        if ($value === null || $value === '') {
            $result = $default ? 1 : 0;
        } elseif (is_bool($value)) {
            $result = $value ? 1 : 0;
        } elseif (is_numeric($value)) {
            $result = ((int)$value) ? 1 : 0;
        } else {
            $result = in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
        }
        return $result;
    }
}
if (!function_exists('fitodomikDynamicUid')) {
    function fitodomikDynamicUid($value, $fallback)
    {
        $value = trim((string)$value);
        if ($value === '') {
            $value = $fallback;
        }
        $value = preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $value);
        return substr($value, 0, 96);
    }
}
if (!function_exists('fitodomikDynamicText')) {
    function fitodomikDynamicText($value, $fallback = '', $max = 160)
    {
        $value = trim((string)$value);
        if ($value === '') {
            $value = $fallback;
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}
if (!function_exists('fitodomikDynamicJsonEncode')) {
    function fitodomikDynamicJsonEncode($value)
    {
        return json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
if (!function_exists('fitodomikDynamicJsonDecode')) {
    function fitodomikDynamicJsonDecode($value)
    {
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
if (!function_exists('fitodomikEnsureDynamicDeviceConfigStorage')) {
    function fitodomikEnsureDynamicDeviceConfigStorage($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        static $readyConnection = null;
        if ($readyConnection === $pdo || $pdo->inTransaction()) { return; }
        fitodomikEnsureDeviceStorage($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_sensor_definitions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                sensor_uid VARCHAR(96) NOT NULL,
                name VARCHAR(160) NOT NULL,
                sensor_type VARCHAR(80) NOT NULL,
                connection_type VARCHAR(32) NOT NULL DEFAULT '',
                pin VARCHAR(40) NOT NULL DEFAULT '',
                address VARCHAR(40) NOT NULL DEFAULT '',
                unit VARCHAR(32) NOT NULL DEFAULT '',
                calibration_json LONGTEXT DEFAULT NULL,
                config_json LONGTEXT DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                send_to_server TINYINT(1) NOT NULL DEFAULT 1,
                show_in_monitoring TINYINT(1) NOT NULL DEFAULT 1,
                show_in_charts TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device_sensor (user_id, device_id, sensor_uid),
                INDEX idx_device_sensor_device (user_id, device_id, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_relay_definitions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                relay_uid VARCHAR(96) NOT NULL,
                name VARCHAR(160) NOT NULL,
                pin VARCHAR(40) NOT NULL DEFAULT '',
                relay_type VARCHAR(40) NOT NULL DEFAULT 'relay',
                active_low TINYINT(1) NOT NULL DEFAULT 1,
                config_json LONGTEXT DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                show_in_control TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device_relay (user_id, device_id, relay_uid),
                INDEX idx_device_relay_device (user_id, device_id, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_sensor_values (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                sensor_uid VARCHAR(96) NOT NULL,
                `value` DECIMAL(14,4) DEFAULT NULL,
                raw_value VARCHAR(160) DEFAULT NULL,
                unit VARCHAR(32) NOT NULL DEFAULT '',
                measured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_device_sensor_values (user_id, device_id, sensor_uid, measured_at),
                INDEX idx_device_sensor_values_device (user_id, device_id),
                INDEX idx_device_sensor_values_measured (user_id, device_id, measured_at),
                INDEX idx_device_sensor_values_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_automation_scenarios (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                scenario_id VARCHAR(96) NOT NULL,
                name VARCHAR(160) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                conditions_json LONGTEXT NOT NULL,
                actions_json LONGTEXT NOT NULL,
                cooldown_seconds INT NOT NULL DEFAULT 60,
                last_triggered_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device_scenario (user_id, device_id, scenario_id),
                INDEX idx_device_scenario_device (user_id, device_id, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_automation_scenario_log (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                scenario_id VARCHAR(96) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                message TEXT DEFAULT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_device_scenario_log (user_id, device_id, scenario_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        fitodomikAddColumnIfMissing($pdo, 'device_sensor_definitions', 'address', "VARCHAR(40) NOT NULL DEFAULT '' AFTER `pin`");
        fitodomikAddIndexIfMissing($pdo, 'device_sensor_definitions', 'idx_device_sensor_plain_device', FITODOMIK_DYNAMIC_USER_DEVICE_INDEX);
        fitodomikAddIndexIfMissing($pdo, 'device_relay_definitions', 'idx_device_relay_plain_device', FITODOMIK_DYNAMIC_USER_DEVICE_INDEX);
        fitodomikAddIndexIfMissing($pdo, 'device_sensor_values', 'idx_device_sensor_values_device', FITODOMIK_DYNAMIC_USER_DEVICE_INDEX);
        fitodomikAddIndexIfMissing($pdo, 'device_sensor_values', 'idx_device_sensor_values_measured', '`user_id`, `device_id`, `measured_at`');
        fitodomikAddIndexIfMissing($pdo, 'device_automation_scenarios', 'idx_device_scenario_plain_device', FITODOMIK_DYNAMIC_USER_DEVICE_INDEX);
        fitodomikAddIndexIfMissing($pdo, 'device_automation_scenario_log', 'idx_device_scenario_log_device', FITODOMIK_DYNAMIC_USER_DEVICE_INDEX);
        $readyConnection = $pdo;
    }
}
if (!function_exists('fitodomikDynamicAuth')) {
    function fitodomikDynamicAuth($pdo, $input = [], $allow_account_token_fallback = true)
    {
        $auth = fitodomikAuthDeviceRequest($pdo, $input, $allow_account_token_fallback);
        if (!$auth) {
            fitodomikDynamicJsonResponse(401, ['success' => false, 'message' => 'Неверный токен устройства или аккаунта']);
        }
        return $auth;
    }
}
if (!function_exists('fitodomikDynamicSensorRow')) {
    function fitodomikDynamicSensorRow($row)
    {
        return [
            'sensor_uid' => $row['sensor_uid'],
            'name' => $row['name'],
            'sensor_type' => $row['sensor_type'],
            'connection' => $row['connection_type'],
            'pin' => $row['pin'],
            'address' => $row['address'] ?? '',
            'unit' => $row['unit'],
            'calibration' => fitodomikDynamicJsonDecode($row['calibration_json'] ?? ''),
            'config' => fitodomikDynamicJsonDecode($row['config_json'] ?? ''),
            'enabled' => (bool)$row['enabled'],
            'send_to_server' => (bool)$row['send_to_server'],
            'show_in_monitoring' => (bool)$row['show_in_monitoring'],
            'show_in_charts' => (bool)$row['show_in_charts'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
if (!function_exists('fitodomikDynamicRelayRow')) {
    function fitodomikDynamicRelayRow($row)
    {
        return [
            'relay_uid' => $row['relay_uid'],
            'name' => $row['name'],
            'pin' => $row['pin'],
            'type' => $row['relay_type'],
            'active_low' => (bool)$row['active_low'],
            'config' => fitodomikDynamicJsonDecode($row['config_json'] ?? ''),
            'enabled' => (bool)$row['enabled'],
            'show_in_control' => (bool)$row['show_in_control'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
if (!function_exists('fitodomikDynamicScenarioRow')) {
    function fitodomikDynamicScenarioRow($row)
    {
        return [
            'scenario_id' => $row['scenario_id'],
            'name' => $row['name'],
            'enabled' => (bool)$row['enabled'],
            'conditions' => fitodomikDynamicJsonDecode($row['conditions_json'] ?? ''),
            'actions' => fitodomikDynamicJsonDecode($row['actions_json'] ?? ''),
            'cooldown_seconds' => (int)$row['cooldown_seconds'],
            'last_triggered_at' => $row['last_triggered_at'],
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
if (!function_exists('fitodomikDynamicListSensors')) {
    function fitodomikDynamicListSensors($pdo, $user_id, $device_id, $include_disabled = true)
    {
        $sql = "SELECT * FROM device_sensor_definitions WHERE user_id = ? AND device_id = ?";
        $params = [(int)$user_id, trim((string)$device_id)];
        if (!$include_disabled) {
            $sql .= FITODOMIK_DYNAMIC_ENABLED_FILTER;
        }
        $sql .= FITODOMIK_DYNAMIC_ID_ORDER;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('fitodomikDynamicSensorRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
if (!function_exists('fitodomikDynamicListRelays')) {
    function fitodomikDynamicListRelays($pdo, $user_id, $device_id, $include_disabled = true)
    {
        $sql = "SELECT * FROM device_relay_definitions WHERE user_id = ? AND device_id = ?";
        $params = [(int)$user_id, trim((string)$device_id)];
        if (!$include_disabled) {
            $sql .= FITODOMIK_DYNAMIC_ENABLED_FILTER;
        }
        $sql .= FITODOMIK_DYNAMIC_ID_ORDER;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('fitodomikDynamicRelayRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
if (!function_exists('fitodomikDynamicListScenarios')) {
    function fitodomikDynamicListScenarios($pdo, $user_id, $device_id, $include_disabled = true)
    {
        $sql = "SELECT * FROM device_automation_scenarios WHERE user_id = ? AND device_id = ?";
        $params = [(int)$user_id, trim((string)$device_id)];
        if (!$include_disabled) {
            $sql .= FITODOMIK_DYNAMIC_ENABLED_FILTER;
        }
        $sql .= FITODOMIK_DYNAMIC_ID_ORDER;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('fitodomikDynamicScenarioRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
if (!function_exists('fitodomikDynamicLatestValues')) {
    function fitodomikDynamicLatestValues($pdo, $user_id, $device_id)
    {
        $stmt = $pdo->prepare("
            SELECT v.sensor_uid, v.value, v.raw_value, v.unit, v.measured_at, v.created_at,
                   UNIX_TIMESTAMP(v.measured_at) AS measured_at_unix
            FROM device_sensor_values v
            INNER JOIN (
                SELECT sensor_uid, MAX(measured_at) AS measured_at
                FROM device_sensor_values
                WHERE user_id = ? AND device_id = ?
                GROUP BY sensor_uid
            ) latest
              ON latest.sensor_uid = v.sensor_uid
             AND latest.measured_at = v.measured_at
            WHERE v.user_id = ? AND v.device_id = ?
            ORDER BY v.sensor_uid ASC
        ");
        $stmt->execute([(int)$user_id, trim((string)$device_id), (int)$user_id, trim((string)$device_id)]);
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[$row['sensor_uid']] = $row;
        }
        return $values;
    }
}
if (!function_exists('fitodomikDynamicListScenarioLog')) {
    function fitodomikDynamicListScenarioLog($pdo, $user_id, $device_id, $scenario_id = '', $limit = 50)
    {
        $limit = max(1, min(200, (int)$limit));
        $params = [(int)$user_id, trim((string)$device_id)];
        $where = "user_id = ? AND device_id = ?";
        $scenario_id = fitodomikDynamicUid($scenario_id, '');
        if ($scenario_id !== '') {
            $where .= " AND scenario_id = ?";
            $params[] = $scenario_id;
        }
        $stmt = $pdo->prepare("
            SELECT scenario_id, event_type, message, payload_json, created_at
            FROM device_automation_scenario_log
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('fitodomikDynamicSeries')) {
    function fitodomikDynamicSeries($pdo, $user_id, $device_id, $sensor_uid, $hours = 24, $limit = 500)
    {
        $hours = max(1, min(24 * 90, (int)$hours));
        $limit = max(1, min(2000, (int)$limit));
        $multiple = is_array($sensor_uid);
        $uids = $multiple ? array_values(array_unique($sensor_uid)) : [fitodomikDynamicUid($sensor_uid, '')];
        if (!$uids) { return []; }
        if (count($uids) > 128) { throw new FitodomikInputException('Слишком много датчиков'); }
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $bucket = max(60, (int)ceil($hours * 3600 / max(1, $limit - 1)));
        $stmt = $pdo->prepare("
            SELECT sensor_uid, DATE_FORMAT(MAX(measured_at), '%Y-%m-%d %H:%i') AS datetime,
                   DATE_FORMAT(MAX(measured_at), '%H:%i') AS time,
                   ROUND(AVG(`value`), 2) AS value
            FROM device_sensor_values
            WHERE user_id = ? AND device_id = ? AND sensor_uid IN ({$placeholders})
              AND measured_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
              AND measured_at <= NOW()
              AND `value` IS NOT NULL
            GROUP BY sensor_uid, FLOOR(UNIX_TIMESTAMP(measured_at) / {$bucket})
            ORDER BY sensor_uid, MAX(measured_at) ASC
        ");
        $stmt->execute(array_merge([(int)$user_id, trim((string)$device_id)], $uids));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = $row['sensor_uid']; unset($row['sensor_uid']); $result[$uid][] = $row;
        }
        foreach ($result as &$points) { $points = array_slice($points, -$limit); }
        unset($points);
        return $multiple ? $result : ($result[$uids[0]] ?? []);
    }
}
if (!function_exists('fitodomikDynamicUpsertSensor')) {
    function fitodomikDynamicUpsertSensor($pdo, $user_id, $device_id, $sensor)
    {
        $sensor = is_array($sensor) ? $sensor : [];
        $uid = fitodomikDynamicUid($sensor['sensor_uid'] ?? $sensor['id'] ?? '', 'sensor_' . bin2hex(random_bytes(3)));
        $name = fitodomikDynamicText($sensor['name'] ?? '', $uid);
        $sensorType = fitodomikDynamicText($sensor['sensor_type'] ?? $sensor['type'] ?? '', 'custom', 80);
        $connection = fitodomikDynamicText($sensor['connection'] ?? $sensor['connection_type'] ?? '', '', 32);
        $pin = fitodomikDynamicText($sensor['pin'] ?? '', '', 40);
        $address = fitodomikDynamicText($sensor['address'] ?? '', '', 40);
        $unit = fitodomikDynamicText($sensor['unit'] ?? '', '', 32);
        $calibration = fitodomikDynamicJsonEncode($sensor['calibration'] ?? []);
        $config = fitodomikDynamicJsonEncode($sensor);
        $existing = $pdo->prepare("
            SELECT id
            FROM device_sensor_definitions
            WHERE user_id = ? AND device_id = ? AND sensor_uid = ?
            LIMIT 1
        ");
        $existing->execute([(int)$user_id, trim((string)$device_id), $uid]);
        if (!$existing->fetchColumn()) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM device_sensor_definitions WHERE user_id = ? AND device_id = ?");
            $count->execute([(int)$user_id, trim((string)$device_id)]);
            if ((int)$count->fetchColumn() >= FITODOMIK_DYNAMIC_SENSOR_LIMIT) {
                throw new FitodomikInputException('Достигнут лимит дополнительных датчиков');
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO device_sensor_definitions (
                user_id, device_id, sensor_uid, name, sensor_type, connection_type, pin, address, unit,
                calibration_json, config_json, enabled, send_to_server, show_in_monitoring, show_in_charts
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                sensor_type = VALUES(sensor_type),
                connection_type = VALUES(connection_type),
                pin = VALUES(pin),
                address = VALUES(address),
                unit = VALUES(unit),
                calibration_json = VALUES(calibration_json),
                config_json = VALUES(config_json),
                enabled = VALUES(enabled),
                send_to_server = VALUES(send_to_server),
                show_in_monitoring = VALUES(show_in_monitoring),
                show_in_charts = VALUES(show_in_charts)
        ");
        $stmt->execute([
            (int)$user_id,
            trim((string)$device_id),
            $uid,
            $name,
            $sensorType,
            $connection,
            $pin,
            $address,
            $unit,
            $calibration,
            $config,
            fitodomikDynamicBool($sensor['enabled'] ?? null, true),
            fitodomikDynamicBool($sensor['send_to_server'] ?? null, true),
            fitodomikDynamicBool($sensor['show_in_monitoring'] ?? null, true),
            fitodomikDynamicBool($sensor['show_in_charts'] ?? null, true),
        ]);
        return $uid;
    }
}
if (!function_exists('fitodomikDynamicUpsertRelay')) {
    function fitodomikDynamicUpsertRelay($pdo, $user_id, $device_id, $relay)
    {
        $relay = is_array($relay) ? $relay : [];
        $uid = fitodomikDynamicUid($relay['relay_uid'] ?? $relay['id'] ?? '', 'relay_' . bin2hex(random_bytes(3)));
        $name = fitodomikDynamicText($relay['name'] ?? '', $uid);
        $pin = fitodomikDynamicText($relay['pin'] ?? '', '', 40);
        $relayType = fitodomikDynamicText($relay['type'] ?? $relay['relay_type'] ?? '', 'relay', 40);
        $config = fitodomikDynamicJsonEncode($relay);
        $existing = $pdo->prepare("
            SELECT id
            FROM device_relay_definitions
            WHERE user_id = ? AND device_id = ? AND relay_uid = ?
            LIMIT 1
        ");
        $existing->execute([(int)$user_id, trim((string)$device_id), $uid]);
        if (!$existing->fetchColumn()) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM device_relay_definitions WHERE user_id = ? AND device_id = ?");
            $count->execute([(int)$user_id, trim((string)$device_id)]);
            if ((int)$count->fetchColumn() >= FITODOMIK_DYNAMIC_RELAY_LIMIT) {
                throw new FitodomikInputException('Достигнут лимит дополнительных реле');
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO device_relay_definitions (
                user_id, device_id, relay_uid, name, pin, relay_type, active_low,
                config_json, enabled, show_in_control
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                pin = VALUES(pin),
                relay_type = VALUES(relay_type),
                active_low = VALUES(active_low),
                config_json = VALUES(config_json),
                enabled = VALUES(enabled),
                show_in_control = VALUES(show_in_control)
        ");
        $stmt->execute([
            (int)$user_id,
            trim((string)$device_id),
            $uid,
            $name,
            $pin,
            $relayType,
            fitodomikDynamicBool($relay['active_low'] ?? null, true),
            $config,
            fitodomikDynamicBool($relay['enabled'] ?? null, true),
            fitodomikDynamicBool($relay['show_in_control'] ?? null, true),
        ]);
        return $uid;
    }
}
if (!function_exists('fitodomikDynamicUidMap')) {
    function fitodomikDynamicUidMap($items, $uidKey)
    {
        $map = [];
        foreach ($items as $item) {
            if (isset($item[$uidKey])) {
                $map[$item[$uidKey]] = true;
            }
        }
        return $map;
    }
}
if (!function_exists('fitodomikDynamicScenarioItems')) {
    function fitodomikDynamicScenarioItems($value, $emptyMessage)
    {
        if (is_string($value)) {
            $value = fitodomikDynamicJsonDecode($value);
        }
        if (!is_array($value) || !$value) {
            throw new FitodomikInputException($emptyMessage);
        }
        return $value;
    }
}
if (!function_exists('fitodomikDynamicPrepareTimeCondition')) {
    function fitodomikDynamicPrepareTimeCondition($condition)
    {
        $from = (string)($condition['from'] ?? $condition['start'] ?? '00:00');
        $to = (string)($condition['to'] ?? $condition['end'] ?? '23:59');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $from)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $to)) {
            throw new FitodomikInputException('Время должно быть в диапазоне 00:00–23:59');
        }
        return [
            'operator' => 'time_between',
            'from' => $from,
            'to' => $to,
        ];
    }
}
if (!function_exists('fitodomikDynamicPrepareSensorCondition')) {
    function fitodomikDynamicPrepareSensorCondition($condition, $operator, $sensorMap)
    {
        $sensorUid = fitodomikDynamicUid($condition['sensor_uid'] ?? '', '');
        if ($sensorUid === '' || empty($sensorMap[$sensorUid])) {
            throw new FitodomikAuthorizationException('Выбранный датчик не принадлежит устройству');
        }
        $item = ['operator' => $operator, 'sensor_uid' => $sensorUid];
        if (in_array($operator, ['<', '>', '=', '==', 'between', 'not_between'], true)) {
            if (!is_numeric($condition['value'] ?? null)) {
                throw new FitodomikInputException('Значение условия должно быть числом');
            }
            $item['value'] = (float)$condition['value'];
        }
        if (in_array($operator, ['between', 'not_between'], true)) {
            if (!is_numeric($condition['value_to'] ?? null)) {
                throw new FitodomikInputException('Верхняя граница условия должна быть числом');
            }
            $item['value_to'] = (float)$condition['value_to'];
        }
        return $item;
    }
}
if (!function_exists('fitodomikDynamicPrepareCondition')) {
    function fitodomikDynamicPrepareCondition($condition, $sensorMap)
    {
        if (!is_array($condition)) {
            throw new FitodomikInputException('Условие сценария должно быть объектом');
        }
        $allowedOperators = ['<', '>', '=', '==', 'between', 'not_between', 'no_data', 'time_between', 'value', 'has_data'];
        $operator = strtolower(trim((string)($condition['operator'] ?? '')));
        if (!in_array($operator, $allowedOperators, true)) {
            throw new FitodomikInputException('Неизвестный оператор сценария');
        }
        $item = $operator === 'time_between'
            ? fitodomikDynamicPrepareTimeCondition($condition)
            : fitodomikDynamicPrepareSensorCondition($condition, $operator, $sensorMap);
        $item['duration_seconds'] = max(0, min(86400, (int)($condition['duration_seconds'] ?? 0)));
        return array_merge($condition, $item);
    }
}
if (!function_exists('fitodomikDynamicPrepareConditions')) {
    function fitodomikDynamicPrepareConditions($conditions, $sensorMap)
    {
        $prepared = [];
        foreach (fitodomikDynamicScenarioItems($conditions, 'Добавьте хотя бы одно условие сценария') as $condition) {
            $prepared[] = fitodomikDynamicPrepareCondition($condition, $sensorMap);
        }
        return $prepared;
    }
}
if (!function_exists('fitodomikDynamicPrepareAction')) {
    function fitodomikDynamicPrepareAction($action, $relayMap)
    {
        if (!is_array($action)) {
            throw new FitodomikInputException('Действие сценария должно быть объектом');
        }
        $allowedActions = ['relay_on', 'relay_off', 'relay_pulse', 'take_photo', 'run_analysis', 'send_notification', 'notify', 'create_command', 'device_command'];
        $type = strtolower(trim((string)($action['type'] ?? '')));
        if (!in_array($type, $allowedActions, true)) {
            throw new FitodomikInputException('Неизвестное действие сценария');
        }
        $item = ['type' => $type];
        if (in_array($type, ['relay_on', 'relay_off', 'relay_pulse'], true)) {
            $relayUid = fitodomikDynamicUid($action['relay_uid'] ?? '', '');
            if ($relayUid === '' || empty($relayMap[$relayUid])) {
                throw new FitodomikAuthorizationException('Выбранное реле не принадлежит устройству');
            }
            $item['relay_uid'] = $relayUid;
        }
        if ($type === 'relay_pulse') {
            $item['duration_seconds'] = max(1, min(3600, (int)($action['duration_seconds'] ?? 3)));
        }
        if (in_array($type, ['send_notification', 'notify'], true)) {
            $item['message'] = fitodomikDynamicText($action['message'] ?? '', 'Сработал сценарий', 500);
        }
        if (in_array($type, ['create_command', 'device_command'], true)) {
            $item['command_type'] = fitodomikDynamicUid($action['command_type'] ?? '', 'sync_device_config');
        }
        return array_merge($action, $item);
    }
}
if (!function_exists('fitodomikDynamicPrepareActions')) {
    function fitodomikDynamicPrepareActions($actions, $relayMap)
    {
        $prepared = [];
        foreach (fitodomikDynamicScenarioItems($actions, 'Добавьте хотя бы одно действие сценария') as $action) {
            $prepared[] = fitodomikDynamicPrepareAction($action, $relayMap);
        }
        return $prepared;
    }
}
if (!function_exists('fitodomikDynamicPrepareScenario')) {
    function fitodomikDynamicPrepareScenario($pdo, $user_id, $device_id, $scenario)
    {
        $scenario = is_array($scenario) ? $scenario : [];
        $sensors = fitodomikDynamicListSensors($pdo, $user_id, $device_id, true);
        $relays = fitodomikDynamicListRelays($pdo, $user_id, $device_id, true);
        $sensorMap = fitodomikDynamicUidMap($sensors, 'sensor_uid');
        $relayMap = fitodomikDynamicUidMap($relays, 'relay_uid');
        $conditions = $scenario['conditions'] ?? $scenario['conditions_json'] ?? [];
        $actions = $scenario['actions'] ?? $scenario['actions_json'] ?? [];
        $scenario['conditions'] = fitodomikDynamicPrepareConditions($conditions, $sensorMap);
        $scenario['actions'] = fitodomikDynamicPrepareActions($actions, $relayMap);
        $scenario['cooldown_seconds'] = max(0, min(86400, (int)($scenario['cooldown_seconds'] ?? 60)));
        return $scenario;
    }
}
if (!function_exists('fitodomikDynamicUpsertScenario')) {
    function fitodomikDynamicUpsertScenario($pdo, $user_id, $device_id, $scenario)
    {
        $scenario = fitodomikDynamicPrepareScenario($pdo, $user_id, $device_id, $scenario);
        $uid = fitodomikDynamicUid($scenario['scenario_id'] ?? $scenario['id'] ?? '', 'scenario_' . bin2hex(random_bytes(3)));
        $name = fitodomikDynamicText($scenario['name'] ?? '', $uid);
        $conditions = fitodomikDynamicJsonEncode($scenario['conditions'] ?? $scenario['conditions_json'] ?? []);
        $actions = fitodomikDynamicJsonEncode($scenario['actions'] ?? $scenario['actions_json'] ?? []);
        $cooldown = max(0, min(86400, (int)($scenario['cooldown_seconds'] ?? 60)));
        $existing = $pdo->prepare("
            SELECT id
            FROM device_automation_scenarios
            WHERE user_id = ? AND device_id = ? AND scenario_id = ?
            LIMIT 1
        ");
        $existing->execute([(int)$user_id, trim((string)$device_id), $uid]);
        if (!$existing->fetchColumn()) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM device_automation_scenarios WHERE user_id = ? AND device_id = ?");
            $count->execute([(int)$user_id, trim((string)$device_id)]);
            if ((int)$count->fetchColumn() >= FITODOMIK_DYNAMIC_SCENARIO_LIMIT) {
                throw new FitodomikInputException('Достигнут лимит сценариев');
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO device_automation_scenarios (
                user_id, device_id, scenario_id, name, enabled, conditions_json, actions_json, cooldown_seconds
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                enabled = VALUES(enabled),
                conditions_json = VALUES(conditions_json),
                actions_json = VALUES(actions_json),
                cooldown_seconds = VALUES(cooldown_seconds)
        ");
        $stmt->execute([
            (int)$user_id,
            trim((string)$device_id),
            $uid,
            $name,
            fitodomikDynamicBool($scenario['enabled'] ?? null, true),
            $conditions,
            $actions,
            $cooldown,
        ]);
        return $uid;
    }
}
function fitodomikDecodeCalibration(string $json): array
{
    if (strlen($json) > 16384) { throw new FitodomikInputException('Калибровка слишком велика'); }
    $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value) || ($value && array_is_list($value))) {
        throw new FitodomikInputException('Калибровка должна быть JSON-объектом');
    }
    return $value;
}
