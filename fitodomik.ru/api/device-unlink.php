<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/headers.php';
require_once __DIR__ . '/device-pair-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    devicePairJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    $token = devicePairAuthToken();
    $user = devicePairUserByToken($pdo, $token);
    if (!$user) {
        devicePairJsonResponse(401, ['success' => false, 'message' => 'Аккаунт не найден по токену']);
    }
    $input = devicePairInput();
    $device_id = trim((string)($input['device_id'] ?? ''));
    if ($device_id === '') {
        devicePairJsonResponse(200, ['success' => true, 'message' => 'Локальная привязка очищена']);
    }
    $changed = fitodomikUnlinkDevice($pdo, (int)$user['id'], $device_id);
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => ($changed > 0 ? 'Устройство отвязано' : 'Устройство уже не числится активным'),
    ]);
} catch (Exception $e) {
    error_log('device-unlink.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
