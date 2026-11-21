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
    $profile = $input['profile'] ?? $input;
    $validation = fitodomikSensorProfileValidate($profile);
    if (!$validation['ok']) {
        fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => 'Профиль не валиден', 'errors' => $validation['errors']]);
    }
    $profileId = trim((string)$profile['profile_id']);
    $title = trim((string)($profile['name'] ?? $profileId));
    $sensorType = trim((string)($profile['sensor_type'] ?? 'custom'));
    $connection = strtolower(trim((string)($profile['connection'] ?? 'digital')));
    if (!empty($input['is_public'])) {
        fitodomikSensorProfilesResponse(403, ['success' => false, 'message' => 'Публикация исполняемых шаблонов отключена']);
    }
    $isPublic = 0;
    $status = 'private';
    $encodedProfile = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedProfile) || strlen($encodedProfile) > 65536) {
        throw new FitodomikInputException('Размер профиля не должен превышать 64 КБ');
    }
    $existing = $pdo->prepare("SELECT id FROM device_sensor_profiles WHERE user_id = ? AND profile_id = ? LIMIT 1");
    $existing->execute([(int)$user['id'], $profileId]);
    if (!$existing->fetchColumn()) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM device_sensor_profiles WHERE user_id = ?");
        $count->execute([(int)$user['id']]);
        if ((int)$count->fetchColumn() >= 50) {
            throw new FitodomikInputException('Достигнут лимит пользовательских профилей');
        }
    }
    $shortTitle = function_exists('mb_substr') ? mb_substr($title, 0, 180, 'UTF-8') : substr($title, 0, 180);
    $shortSensorType = function_exists('mb_substr') ? mb_substr($sensorType, 0, 80, 'UTF-8') : substr($sensorType, 0, 80);
    $stmt = $pdo->prepare("
        INSERT INTO device_sensor_profiles (
            user_id, profile_id, title, sensor_type, connection_type, profile_json, is_public, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            sensor_type = VALUES(sensor_type),
            connection_type = VALUES(connection_type),
            profile_json = VALUES(profile_json),
            is_public = VALUES(is_public),
            status = VALUES(status)
    ");
    $stmt->execute([
        (int)$user['id'],
        $profileId,
        $shortTitle,
        $shortSensorType,
        $connection,
        $encodedProfile,
        $isPublic,
        $status,
    ]);
    fitodomikSensorProfilesResponse(200, ['success' => true, 'message' => 'Профиль сохранён', 'profile_id' => $profileId]);
} catch (Exception $e) {
    fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
