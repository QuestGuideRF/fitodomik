<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/sensor_profiles.php';
try {
    $user = fitodomikSensorProfilesAuth($pdo);
    if (!$user) {
        fitodomikSensorProfilesResponse(401, ['success' => false, 'message' => 'Требуется авторизация']);
    }
    $input = fitodomikSensorProfilesInput();
    $profileId = trim((string)($input['profile_id'] ?? ''));
    if ($profileId === '') {
        fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => 'profile_id не указан']);
    }
    $stmt = $pdo->prepare("DELETE FROM device_sensor_profiles WHERE user_id = ? AND profile_id = ?");
    $stmt->execute([(int)$user['id'], $profileId]);
    fitodomikSensorProfilesResponse(200, ['success' => true, 'message' => 'Профиль удалён']);
} catch (Exception $e) {
    fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
