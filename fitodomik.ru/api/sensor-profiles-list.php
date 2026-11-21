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
    $connection = strtolower(trim((string)($_GET['connection'] ?? '')));
    $search = trim((string)($_GET['q'] ?? ''));
    $where = "user_id = ?";
    $params = [(int)$user['id']];
    if ($connection !== '' && in_array($connection, ['analog', 'digital', 'i2c', 'uart', 'spi'], true)) {
        $where .= " AND connection_type = ?";
        $params[] = $connection;
    }
    if ($search !== '') {
        $where .= " AND (title LIKE ? OR profile_id LIKE ? OR sensor_type LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $stmt = $pdo->prepare("
        SELECT *
        FROM device_sensor_profiles
        WHERE {$where}
        ORDER BY is_public DESC, updated_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $profiles = array_map(static function ($row) {
        return fitodomikSensorProfilePublicRow($row, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    fitodomikSensorProfilesResponse(200, ['success' => true, 'profiles' => $profiles]);
} catch (Exception $e) {
    fitodomikSensorProfilesResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
