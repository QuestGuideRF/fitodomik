<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
require_once '../config/error_handling.php';
require_once '../config/database.php';
require_once '../config/headers.php';
require_once '../config/device_context.php';
header('Content-Type: application/json');
setAjaxCacheHeaders(false, 0);
try {
    $auth = fitodomikAuthDeviceRequest($pdo, [], true);
    if (!$auth) {
        $token = fitodomikRequestHeader('X-Auth-Token');
        $user = fitodomikUserByToken($pdo, $token);
        if ($user) {
            $deviceStmt = $pdo->prepare("
                SELECT device_id, device_token, user_id, device_name, status
                FROM user_devices
                WHERE user_id = ? AND status = 'active'
                ORDER BY linked_at ASC
                LIMIT 1
            ");
            $deviceStmt->execute([(int)$user['id']]);
            $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
            if ($device) {
                $auth = [
                    'user_id' => (int)$user['id'],
                    'user' => $user,
                    'device' => $device,
                ];
            }
        }
    }
    if (!$auth) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Неверный токен устройства или аккаунта']);
        exit;
    }
    $userId = (int)$auth['user_id'];
    $deviceId = $auth['device']['device_id'];
    $stmt = $pdo->prepare("
        SELECT MAX(id) AS max_id
        FROM sensor_data
        WHERE user_id = ? AND device_id = ?
    ");
    $stmt->execute([$userId, $deviceId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $max_id = ($result && $result['max_id'] !== null) ? (int)$result['max_id'] : 0;
    echo json_encode([
        'success' => true,
        'max_id' => $max_id,
        'user_id' => $userId,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (PDOException $e) {
    error_log('get-max-sensor-id database error');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных'
    ]);
} catch (Exception $e) {
    error_log('get-max-sensor-id request failed');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера'
    ]);
}
