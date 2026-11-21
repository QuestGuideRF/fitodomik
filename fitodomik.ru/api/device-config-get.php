<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET', 'POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_dynamic_config.php';
try {
    $input = fitodomikDynamicInput();
    $auth = fitodomikDynamicAuth($pdo, $input, true);
    $userId = (int)$auth['user_id'];
    $deviceId = $auth['device']['device_id'];
    fitodomikDynamicJsonResponse(200, [
        'success' => true,
        'device_id' => $deviceId,
        'device_sensors' => fitodomikDynamicListSensors($pdo, $userId, $deviceId, true),
        'device_relays' => fitodomikDynamicListRelays($pdo, $userId, $deviceId, true),
        'scenarios' => fitodomikDynamicListScenarios($pdo, $userId, $deviceId, true),
    ]);
} catch (Exception $e) {
    error_log('device-config-get.php: ' . $e->getMessage());
    fitodomikDynamicJsonResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
