<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['Content-Type', 'X-Auth-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/sensor_profiles.php';
try {
    $user = fitodomikSensorProfilesAuth($pdo);
    if (!$user) {
        fitodomikSensorProfilesResponse(401, ['success' => false, 'message' => 'Требуется авторизация']);
    }
    $profileId = trim((string)($_GET['profile_id'] ?? ''));
    if ($profileId === '') {
        fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => 'profile_id не указан']);
    }
    $stmt = $pdo->prepare("
        SELECT *
        FROM device_sensor_profiles
        WHERE profile_id = ? AND user_id = ?
        ORDER BY user_id = ? DESC
        LIMIT 1
    ");
    $stmt->execute([$profileId, (int)$user['id'], (int)$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fitodomikSensorProfilesResponse(404, ['success' => false, 'message' => 'Профиль не найден']);
    }
    fitodomikSensorProfilesResponse(200, ['success' => true, 'profile' => fitodomikSensorProfilePublicRow($row, true)['profile']]);
} catch (Exception $e) {
    fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
