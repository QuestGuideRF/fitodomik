<?php
require_once __DIR__ . '/device_context.php';
require_once __DIR__ . '/device_dynamic_config.php';
if (!function_exists('fitodomikCountRowsSafe')) {
    function fitodomikCountRowsSafe($pdo, $sql, $params = [])
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}
if (!function_exists('fitodomikDeviceSetupStatus')) {
    function fitodomikDeviceSetupStatus($pdo, $user_id, $device_id)
    {
        $user_id = (int)$user_id;
        $device_id = trim((string)$device_id);
        $all = fitodomikDeviceSetupStatuses($pdo, $user_id);
        $row = $all[$device_id] ?? [];
        $device = $row ?: null;
        $sensorData = (int)($row['sensor_data'] ?? 0);
        $dynamicSensors = (int)($row['dynamic_sensors'] ?? 0);
        $dynamicRelays = (int)($row['dynamic_relays'] ?? 0);
        $dynamicValues = (int)($row['dynamic_values'] ?? 0);
        $scenarios = (int)($row['scenarios'] ?? 0);
        $photos = (int)($row['photos'] ?? 0);
        $telegram = (int)($row['telegram'] ?? 0);
        $checks = [
            'linked' => (bool)$device,
            'last_seen' => !empty($device['last_seen_at']),
            'has_sensor_data' => $sensorData > 0 || $dynamicValues > 0,
            'has_dynamic_config' => ($dynamicSensors + $dynamicRelays) > 0,
            'has_sensors' => $dynamicSensors > 0,
            'has_relays' => $dynamicRelays > 0,
            'has_scenarios' => $scenarios > 0,
            'has_photo_analysis' => $photos > 0,
            'telegram_enabled' => $telegram > 0,
        ];
        $score = 0;
        foreach ($checks as $value) {
            if ($value) {
                $score++;
            }
        }
        $state = 'не настроено';
        if ($score >= 7) {
            $state = 'готово';
        } elseif ($score >= 3) {
            $state = 'частично настроено';
        }
        return [
            'state' => $state,
            'score' => $score,
            'total' => count($checks),
            'checks' => $checks,
            'counts' => [
                'sensor_data' => $sensorData,
                'dynamic_sensors' => $dynamicSensors,
                'dynamic_relays' => $dynamicRelays,
                'dynamic_values' => $dynamicValues,
                'scenarios' => $scenarios,
                'photos' => $photos,
            ],
        ];
    }
}
function fitodomikDeviceSetupStatuses(PDO $pdo, int $userId): array
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $userId;
    if (isset($cache[$key])) { return $cache[$key]; }
    $stmt = $pdo->prepare("SELECT d.device_id,d.last_seen_at,
        EXISTS(SELECT 1 FROM sensor_data s WHERE s.user_id=d.user_id AND s.device_id=d.device_id) AS sensor_data,
        (SELECT COUNT(*) FROM device_sensor_definitions s WHERE s.user_id=d.user_id AND s.device_id=d.device_id) AS dynamic_sensors,
        (SELECT COUNT(*) FROM device_relay_definitions s WHERE s.user_id=d.user_id AND s.device_id=d.device_id) AS dynamic_relays,
        EXISTS(SELECT 1 FROM device_sensor_values s WHERE s.user_id=d.user_id AND s.device_id=d.device_id) AS dynamic_values,
        (SELECT COUNT(*) FROM device_automation_scenarios s WHERE s.user_id=d.user_id AND s.device_id=d.device_id) AS scenarios,
        EXISTS(SELECT 1 FROM farm_status s WHERE s.user_id=d.user_id AND s.device_id=d.device_id
            AND (NULLIF(s.photo,'') IS NOT NULL OR NULLIF(s.photo_analysis,'') IS NOT NULL)) AS photos,
        EXISTS(SELECT 1 FROM telegram_links l WHERE l.user_id=d.user_id AND l.status='active') AS telegram
        FROM user_devices d WHERE d.user_id=? AND d.status='active'");
    $stmt->execute([$userId]);
    $rows=[];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $rows[$row['device_id']]=$row; }
    return $cache[$key]=$rows;
}
