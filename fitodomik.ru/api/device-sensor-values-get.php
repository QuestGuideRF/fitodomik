<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET', 'POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_dynamic_config.php';
try {
    $input = fitodomikDynamicInput();
    $auth = fitodomikDynamicAuth($pdo, $input, true);
    $sensorUid = fitodomikDynamicUid($input['sensor_uid'] ?? '', '');
    $limit = max(1, min(2000, (int)($input['limit'] ?? 300)));
    $hours = max(1, min(24 * 90, (int)($input['hours'] ?? 24)));
    $params = [(int)$auth['user_id'], $auth['device']['device_id']];
    $where = "user_id = ? AND device_id = ? AND measured_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
    if ($sensorUid !== '') {
        $where .= " AND sensor_uid = ?";
        $params[] = $sensorUid;
    }
    $stmt = $pdo->prepare("
        SELECT sensor_uid, `value`, raw_value, unit, measured_at, created_at
        FROM device_sensor_values
        WHERE {$where}
        ORDER BY measured_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    fitodomikDynamicJsonResponse(200, [
        'success' => true,
        'device_id' => $auth['device']['device_id'],
        'values' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Exception $e) {
    fitodomikDynamicJsonResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
