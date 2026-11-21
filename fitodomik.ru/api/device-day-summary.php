<?php
function fitodomikDaySummaryJsonHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('Expires');
    header_remove('Pragma');
    header_remove('Content-Security-Policy');
    header('Content-Type: application/json; charset=utf-8', true);
    header('Cache-Control: private, no-cache', true);
    header('X-Content-Type-Options: nosniff', true);
}
fitodomikDaySummaryJsonHeaders();
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_context.php';
require_once '../config/headers.php';
fitodomikDaySummaryJsonHeaders();
setAjaxCacheHeaders(false, 0);
function daySummaryResponse($status, $payload)
{
    http_response_code($status);
    fitodomikDaySummaryJsonHeaders();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!isset($_SESSION['user_id'])) {
    daySummaryResponse(401, ['success' => false, 'message' => 'Не авторизован']);
}
try {
    $user_id = (int)$_SESSION['user_id'];
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        daySummaryResponse(400, ['success' => false, 'message' => 'Некорректная дата']);
    }
    $requestedDeviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($requestedDeviceId !== '') {
        $stmt = $pdo->prepare("
            SELECT device_id, device_name
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$user_id, $requestedDeviceId]);
        $selectedDevice = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $selectedDevice = fitodomikGetSelectedDevice($pdo, $user_id);
    }
    if (!$selectedDevice) {
        daySummaryResponse(200, ['success' => false, 'message' => 'Устройство не выбрано']);
    }
    $device_id = $selectedDevice['device_id'];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS sensor_count,
               AVG(temperature) AS avg_temperature,
               AVG(humidity) AS avg_humidity,
               AVG(soil_moisture) AS avg_soil,
               AVG(co2) AS avg_co2
        FROM sensor_data
        WHERE user_id = ? AND device_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $stmt->execute([$user_id, $device_id, $date, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $sensorSummary = [
        'sensor_count' => (int)($row['sensor_count'] ?? 0),
        'avg_temperature' => $row['avg_temperature'] === null ? null : round((float)$row['avg_temperature'], 1),
        'avg_humidity' => $row['avg_humidity'] === null ? null : round((float)$row['avg_humidity'], 1),
        'avg_soil' => $row['avg_soil'] === null ? null : round((float)$row['avg_soil'], 1),
        'avg_co2' => $row['avg_co2'] === null ? null : round((float)$row['avg_co2'], 0),
    ];
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN photo IS NOT NULL AND photo <> '' THEN 1 ELSE 0 END) AS photos_count,
            SUM(CASE WHEN photo_analysis IS NOT NULL AND photo_analysis <> '' THEN 1 ELSE 0 END) AS analysis_count
        FROM farm_status
        WHERE user_id = ? AND device_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $stmt->execute([$user_id, $device_id, $date, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $photos_count = (int)($row['photos_count'] ?? 0);
    $analysis_count = (int)($row['analysis_count'] ?? 0);
    $alerts_count = 0;
    $control_events_count = 0;
    $stmt = $pdo->prepare("
        SELECT event_type, event_description, created_at
        FROM event_log
        WHERE user_id = ? AND device_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$user_id, $device_id, $date, $date]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($events as $event) {
        $description = (string)($event['event_description'] ?? '');
        $text = function_exists('mb_strtolower') ? mb_strtolower($description, 'UTF-8') : strtolower($description);
        $type = (string)($event['event_type'] ?? '');
        if (strpos($text, 'тревог') !== false || strpos($text, 'ошиб') !== false || in_array($type, ['alarm', 'temperature', 'humidity', 'soil_moisture', 'co2'], true)) {
            $alerts_count++;
        }
        if ($type === 'device_command' || strpos($text, 'реле') !== false || strpos($text, 'включ') !== false || strpos($text, 'выключ') !== false) {
            $control_events_count++;
        }
    }
    daySummaryResponse(200, [
        'success' => true,
        'date' => $date,
        'device_id' => $device_id,
        'device_name' => $selectedDevice['device_name'] ?? '',
        'sensor_count' => $sensorSummary['sensor_count'],
        'avg_temperature' => $sensorSummary['avg_temperature'],
        'avg_humidity' => $sensorSummary['avg_humidity'],
        'avg_soil' => $sensorSummary['avg_soil'],
        'avg_co2' => $sensorSummary['avg_co2'],
        'photos_count' => $photos_count,
        'analysis_count' => $analysis_count,
        'alerts_count' => $alerts_count,
        'control_events_count' => $control_events_count,
        'events' => $events,
    ]);
} catch (Exception $e) {
    error_log('device-day-summary.php: ' . $e->getMessage());
    daySummaryResponse(500, ['success' => false, 'message' => 'Ошибка при получении сводки дня']);
}
