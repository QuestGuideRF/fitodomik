<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/device_context.php';
require_once __DIR__ . '/json_response.php';
if (!function_exists('fitodomikCommandAllowedTypes')) {
    function fitodomikCommandAllowedTypes()
    {
        return [
            'take_photo',
            'analyze_plant',
            'refresh_thresholds',
            'refresh_schedule',
            'upload_thresholds_to_arduino',
            'reconnect_arduino',
            'relay_on',
            'relay_off',
            'relay_pulse',
            'device_test',
            'sync_device_config',
            'request_device_config',
            'apply_device_config',
            'ping',
            'pump_fault_reset',
        ];
    }
}
if (!function_exists('fitodomikCommandLabel')) {
    function fitodomikCommandLabel($type)
    {
        $labels = [
            'take_photo' => 'Сделать фото',
            'analyze_plant' => 'Анализ растения',
            'refresh_thresholds' => 'Обновить пороги',
            'refresh_schedule' => 'Обновить расписание',
            'upload_thresholds_to_arduino' => 'Загрузить настройки в Arduino',
            'reconnect_arduino' => 'Переподключить Arduino',
            'relay_on' => 'Включить реле',
            'relay_off' => 'Выключить реле',
            'relay_pulse' => 'Импульс реле',
            'device_test' => 'Тест устройства',
            'sync_device_config' => 'Синхронизировать конфигурацию',
            'request_device_config' => 'Запросить конфигурацию',
            'apply_device_config' => 'Применить конфигурацию',
            'ping' => 'Проверка связи',
        ];
        return $labels[$type] ?? $type;
    }
}
if (!function_exists('fitodomikCommandAllowedSources')) {
    function fitodomikCommandAllowedSources()
    {
        return ['web', 'site', 'telegram', 'automation', 'desktop', 'system'];
    }
}
if (!function_exists('fitodomikEnsureCommandStorage')) {
    function fitodomikEnsureCommandStorage($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        static $readyConnection = null;
        if ($readyConnection === $pdo) {
            return;
        }
        fitodomikEnsureDeviceStorage($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_commands (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                command_type VARCHAR(80) NOT NULL,
                command_payload LONGTEXT DEFAULT NULL,
                status ENUM('pending','running','done','error','cancelled','expired') NOT NULL DEFAULT 'pending',
                source ENUM('web','telegram','system') NOT NULL DEFAULT 'web',
                requested_by_user_id INT DEFAULT NULL,
                requested_by_telegram_id BIGINT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                result_message TEXT DEFAULT NULL,
                result_payload LONGTEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                result_notified_at DATETIME DEFAULT NULL,
                INDEX idx_device_commands_device_status (user_id, device_id, status),
                INDEX idx_device_commands_created (created_at),
                INDEX idx_device_commands_expires (expires_at),
                INDEX idx_device_commands_telegram_notify (source, requested_by_telegram_id, status, result_notified_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        try {
            $pdo->exec("ALTER TABLE device_commands MODIFY COLUMN source ENUM('web','site','telegram','automation','desktop','system') NOT NULL DEFAULT 'site'");
        } catch (Exception $e) {
            error_log('device_commands source enum migration failed: ' . $e->getMessage());
        }
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'created_by_user_id', "INT DEFAULT NULL AFTER `requested_by_telegram_id`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'telegram_user_id', "BIGINT DEFAULT NULL AFTER `created_by_user_id`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'scenario_id', "VARCHAR(96) DEFAULT NULL AFTER `telegram_user_id`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'picked_at', "DATETIME DEFAULT NULL AFTER `started_at`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'duration_ms', "INT DEFAULT NULL AFTER `finished_at`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'attempts', "INT NOT NULL DEFAULT 0 AFTER `error_message`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'cancelled_at', "DATETIME DEFAULT NULL AFTER `attempts`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'cancelled_by_user_id', "INT DEFAULT NULL AFTER `cancelled_at`");
        fitodomikAddColumnIfMissing($pdo, 'device_commands', 'result_notified_at', "DATETIME DEFAULT NULL AFTER `error_message`");
        fitodomikAddIndexIfMissing($pdo, 'device_commands', 'idx_device_commands_source_created', '`user_id`, `device_id`, `source`, `created_at`');
        if (!fitodomikTableExists($pdo, 'event_log')) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS event_log (
                    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    device_id VARCHAR(64) DEFAULT NULL,
                    event_type VARCHAR(80) NOT NULL,
                    event_description TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_event_user_device_created (user_id, device_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            fitodomikAddColumnIfMissing($pdo, 'event_log', 'device_id', "VARCHAR(64) DEFAULT NULL AFTER `user_id`");
            fitodomikAddIndexIfMissing($pdo, 'event_log', 'idx_event_user_device_created', '`user_id`, `device_id`, `created_at`');
        }
        $readyConnection = $pdo;
    }
}
if (!function_exists('fitodomikCommandJson')) {
    function fitodomikCommandJson($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
if (!function_exists('fitodomikNormalizeCommandPayload')) {
    function fitodomikNormalizeCommandPayload($type, $payload)
    {
        $type = trim((string)$type);
        $payload = fitodomikCommandJson($payload);
        if (!in_array($type, fitodomikCommandAllowedTypes(), true)) {
            throw new FitodomikInputException('Неизвестная команда устройства');
        }
        if (in_array($type, ['relay_on', 'relay_off', 'relay_pulse'], true)) {
            $relay = (int)($payload['relay'] ?? $payload['relay_id'] ?? 0);
            if ($relay < 1 || $relay > 4) {
                throw new FitodomikInputException('Реле должно быть в диапазоне 1-4');
            }
            $payload['relay'] = $relay;
            if ($type === 'relay_pulse') {
                $seconds = (float)($payload['seconds'] ?? 3);
                $payload['seconds'] = max(0.2, min(30.0, $seconds));
            }
        }
        if ($type === 'device_test') {
            $device = strtolower(trim((string)($payload['device'] ?? 'all')));
            $allowed = ['all', 'lamp', 'curtains', 'pump', 'fan'];
            if (!in_array($device, $allowed, true)) {
                throw new FitodomikInputException('Неизвестное устройство для теста');
            }
            $payload['device'] = $device;
            $payload['seconds'] = max(0.2, min(10.0, (float)($payload['seconds'] ?? ($device === 'pump' ? 1 : 3))));
        }
        return $payload;
    }
}
if (!function_exists('fitodomikLogDeviceEvent')) {
    function fitodomikLogDeviceEvent($pdo, $user_id, $device_id, $event_type, $description)
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO event_log (user_id, device_id, event_type, event_description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([(int)$user_id, trim((string)$device_id), $event_type, $description]);
        } catch (Exception $e) {
            error_log('device command event log failed: ' . $e->getMessage());
        }
    }
}
if (!function_exists('fitodomikCreateDeviceCommand')) {
    function fitodomikCreateDeviceCommand($pdo, $user_id, $device_id, $command_type, array $options = [])
    {
        $user_id = (int)$user_id;
        $device_id = trim((string)$device_id);
        $command_type = trim((string)$command_type);
        $payload = $options['payload'] ?? [];
        $source = $options['source'] ?? 'web';
        $requested_by_user_id = $options['requested_by_user_id'] ?? null;
        $requested_by_telegram_id = $options['requested_by_telegram_id'] ?? null;
        $expires_minutes = $options['expires_minutes'] ?? 30;
        $source = $source === 'web' ? 'site' : $source;
        $source = in_array($source, fitodomikCommandAllowedSources(), true) ? $source : 'site';
        $payload = fitodomikNormalizeCommandPayload($command_type, $payload);
        $deviceStmt = $pdo->prepare("SELECT device_id, device_name FROM user_devices WHERE user_id = ? AND device_id = ? AND status = 'active' LIMIT 1");
        $deviceStmt->execute([$user_id, $device_id]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            throw new FitodomikNotFoundException('Устройство не найдено или не принадлежит пользователю');
        }
        $expires_minutes = max(1, min(1440, (int)$expires_minutes));
        $stmt = $pdo->prepare("
            INSERT INTO device_commands (
                user_id, device_id, command_type, command_payload, status, source,
                requested_by_user_id, requested_by_telegram_id, created_by_user_id, telegram_user_id, available_at, expires_at
            ) VALUES (
                ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL {$expires_minutes} MINUTE)
            )
        ");
        $params = [
            $user_id,
            $device_id,
            $command_type,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $source,
            $requested_by_user_id === null ? null : (int)$requested_by_user_id,
            $requested_by_telegram_id === null ? null : (int)$requested_by_telegram_id,
            $requested_by_user_id === null ? null : (int)$requested_by_user_id,
            $requested_by_telegram_id === null ? null : (int)$requested_by_telegram_id,
        ];
        try {
            $stmt->execute($params);
        } catch (Exception $e) {
            if ($source === 'site') {
                $params[4] = 'web';
                $stmt->execute($params);
            } else {
                throw $e;
            }
        }
        $commandId = (int)$pdo->lastInsertId();
        fitodomikLogDeviceEvent($pdo, $user_id, $device_id, 'device_command', 'Создана команда: ' . fitodomikCommandLabel($command_type));
        return $commandId;
    }
}
if (!function_exists('fitodomikExpireOldDeviceCommands')) {
    function fitodomikExpireOldDeviceCommands($pdo)
    {
        $pdo->exec("
            UPDATE device_commands
            SET status = 'expired', finished_at = NOW(), error_message = 'Команда не была завершена устройством до истечения срока'
            WHERE status IN ('pending','running') AND expires_at IS NOT NULL AND expires_at < NOW()
        ");
    }
}
if (!function_exists('fitodomikFetchPendingDeviceCommands')) {
    function fitodomikFetchPendingDeviceCommands($pdo, $user_id, $device_id, $limit = 5, $clientId = '')
    {
        fitodomikExpireOldDeviceCommands($pdo);
        $limit = max(1, min(20, (int)$limit));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT id, command_type, command_payload, source, created_at, expires_at
            FROM device_commands
            WHERE user_id = ? AND device_id = ?
              AND (status = 'pending' OR (status = 'running' AND delivery_client = ? AND lease_until <= NOW()))
              AND available_at <= NOW()
              AND (expires_at IS NULL OR expires_at >= NOW())
            ORDER BY created_at ASC
            LIMIT {$limit}
            FOR UPDATE
        ");
        $stmt->execute([(int)$user_id, trim((string)$device_id), $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(static function ($row) {
            return (int)$row['id'];
        }, $rows);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare("
                UPDATE device_commands
                SET status = 'running',
                    started_at = COALESCE(started_at, NOW()),
                    picked_at = NOW(),
                    attempts = attempts + 1,
                    delivery_client = NULLIF(?, ''),
                    lease_until = IF(? = '', NULL, DATE_ADD(NOW(), INTERVAL 5 MINUTE))
                WHERE id IN ({$placeholders})
                  AND user_id = ?
                  AND device_id = ?
                  AND status IN ('pending','running')
            ");
            $update->execute(array_merge(
                [$clientId, $clientId], $ids,
                [(int)$user_id, trim((string)$device_id)]
            ));
        }
        $pdo->commit();
        foreach ($rows as &$row) {
            $row['command_id'] = (int)$row['id'];
            $row['payload'] = fitodomikCommandJson($row['command_payload'] ?? '');
            unset($row['id'], $row['command_payload']);
        }
        return $rows;
    }
}
if (!function_exists('fitodomikSaveDeviceCommandResult')) {
    function fitodomikSaveDeviceCommandResult($pdo, $user_id, $device_id, $command_id, array $result)
    {
        $status = $result['status'] ?? 'error';
        $message = $result['message'] ?? '';
        $payload = $result['payload'] ?? [];
        $started_at = $result['started_at'] ?? null;
        $finished_at = $result['finished_at'] ?? null;
        $status = $status === 'done' ? 'done' : 'error';
        $stmt = $pdo->prepare("
            UPDATE device_commands
            SET status = ?,
                started_at = COALESCE(?, started_at),
                finished_at = COALESCE(?, NOW()),
                duration_ms = IF(started_at IS NOT NULL, TIMESTAMPDIFF(MICROSECOND, started_at, COALESCE(?, NOW())) DIV 1000, duration_ms),
                result_message = ?,
                result_payload = ?,
                error_message = ?
            WHERE id = ? AND user_id = ? AND device_id = ? AND status IN ('pending','running')
        ");
        $error = $status === 'error' ? $message : null;
        $stmt->execute([
            $status,
            $started_at ?: null,
            $finished_at ?: null,
            $finished_at ?: null,
            trim((string)$message),
            json_encode(fitodomikCommandJson($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $error,
            (int)$command_id,
            (int)$user_id,
            trim((string)$device_id),
        ]);
        if ($stmt->rowCount() < 1) {
            $existing = $pdo->prepare('SELECT status FROM device_commands WHERE id = ? AND user_id = ? AND device_id = ? LIMIT 1');
            $existing->execute([(int)$command_id, (int)$user_id, trim((string)$device_id)]);
            if ($existing->fetchColumn() === $status) { return; }
            throw new FitodomikNotFoundException('Команда не найдена или уже завершена');
        }
        fitodomikLogDeviceEvent($pdo, $user_id, $device_id, 'device_command', 'Команда #' . (int)$command_id . ': ' . ($status === 'done' ? 'выполнена' : 'ошибка'));
    }
}
