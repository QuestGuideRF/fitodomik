<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/headers.php';
require_once __DIR__ . '/device-pair-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    devicePairJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    $token = devicePairAuthToken();
    $user = devicePairUserByToken($pdo, $token);
    if (!$user) {
        devicePairJsonResponse(401, ['success' => false, 'message' => 'Аккаунт не найден по токену']);
    }
    $currentDeviceId = fitodomikRequestHeader('X-Device-ID');
    $devices = fitodomikGetUserDevices($pdo, (int)$user['id'], true);
    $latestByDevice = [];
    $latestStmt = $pdo->prepare('SELECT device_id, MAX(created_at) AS latest_at FROM sensor_data WHERE user_id = ? GROUP BY device_id');
    $latestStmt->execute([(int)$user['id']]);
    foreach ($latestStmt->fetchAll(PDO::FETCH_ASSOC) as $latest) {
        $latestByDevice[$latest['device_id']] = $latest['latest_at'];
    }
    $items = [];
    foreach ($devices as $device) {
        $latestSensorAt = $latestByDevice[$device['device_id']] ?? null;
        $items[] = [
            'device_id' => $device['device_id'],
            'device_name' => $device['device_name'],
            'status' => $device['status'],
            'linked_at' => $device['linked_at'],
            'last_seen_at' => $device['last_seen_at'],
            'latest_sensor_at' => $latestSensorAt,
            'device_token' => $device['status'] === 'active' ? fitodomikClientDeviceToken($pdo, $user, $device['device_id']) : '',
            'is_current' => $currentDeviceId !== '' && $currentDeviceId === $device['device_id'],
        ];
    }
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => 'Список устройств получен',
        'token' => $token,
        'current_device_id' => $currentDeviceId,
        'devices' => $items,
    ]);
} catch (Exception $e) {
    error_log('device-list.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
