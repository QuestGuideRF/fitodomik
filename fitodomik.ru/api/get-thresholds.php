<?php
require_once __DIR__ . '/../config/api_contract.php';
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['X-Auth-Token', 'X-Device-ID', 'X-Device-Token', 'X-Fitodomik-Contract']);
require_once '../config/error_handling.php';
require_once '../config/database.php';
require_once '../config/headers.php';
require_once '../config/device_context.php';
require_once '../config/threshold_storage.php';
header('Content-Type: application/json');
setAjaxCacheHeaders(false, 0);
function logMessage($message)
{
    $log_dir = dirname(__FILE__) . '/../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    error_log("[" . date("Y-m-d H:i:s") . "] get-thresholds.php: " . $message . "\n", 3, $log_dir . "/api_activity.log");
}
try {
    $auth = fitodomikAuthDeviceRequest($pdo, [], true);
    $headers = getallheaders();
    $token = isset($headers['X-Auth-Token']) ? $headers['X-Auth-Token'] : '';
    if (!$auth && empty($token)) {
        http_response_code(401);
        fitodomikContractResponse(['success' => false, 'message' => 'Отсутствует токен авторизации'],401,null,'unauthorized');
    }
    if ($auth) {
        $user = ['id' => (int)$auth['user_id']];
    } else {
        $user = fitodomikUserByToken($pdo, $token);
        if (!$user) {
            http_response_code(401);
            fitodomikContractResponse(['success' => false, 'message' => 'Неверный токен авторизации'],401,null,'unauthorized');
        }
    }
    $deviceId = $auth ? (string)$auth['device']['device_id']
        : fitodomikGetSelectedDeviceId($pdo, (int)$user['id']);
    if ($deviceId === '') {
        http_response_code(404);
        fitodomikContractResponse(['success' => false, 'message' => 'Устройство не выбрано'],404,null,'device_not_found');
    }
    $stmt = $pdo->prepare("
        SELECT parameter_type, min_limit, max_limit, target_value, tolerance
        FROM alarm_thresholds
        WHERE user_id = ? AND device_id = ?
    ");
    $stmt->execute([$user['id'], $deviceId]);
    $thresholds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $thresholds[$row['parameter_type']] = [
            'min_limit' => (float)$row['min_limit'],
            'max_limit' => (float)$row['max_limit'],
            'target_value' => (float)$row['target_value'],
            'tolerance' => (float)$row['tolerance']
        ];
    }
    if (empty($thresholds)) {
        logMessage("Пороговые значения не найдены для пользователя ID: " . $user['id'] . ", создаем по умолчанию");
        $defaultThresholds = [
            ['temperature', 15.0, 30.0, 25.0, 1.0],
            ['humidity_air', 40.0, 70.0, 60.0, 5.0],
            ['humidity_soil', 30.0, 70.0, 50.0, 5.0],
            ['co2', 600.0, 1500.0, 1000.0, 100.0]
        ];
        $stmt = $pdo->prepare("
            INSERT INTO alarm_thresholds
            (user_id, device_id, parameter_type, min_limit, max_limit, target_value, tolerance)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = id
        ");
        foreach ($defaultThresholds as $threshold) {
            $stmt->execute([$user['id'], $deviceId, $threshold[0], $threshold[1], $threshold[2], $threshold[3], $threshold[4]]);
            $thresholds[$threshold[0]] = [
                'min_limit' => (float)$threshold[1],
                'max_limit' => (float)$threshold[2],
                'target_value' => (float)$threshold[3],
                'tolerance' => (float)$threshold[4]
            ];
        }
    }
    logMessage("Отправлен ответ с пороговыми значениями для пользователя ID: " . $user['id']);
    fitodomikContractResponse($thresholds,200,['device_id'=>(string)$deviceId,'thresholds'=>(object)$thresholds]);
} catch (PDOException $e) {
    logMessage("PDO ошибка: " . $e->getMessage());
    http_response_code(500);
    fitodomikContractResponse([
        'success' => false,
        'message' => 'Ошибка базы данных'
    ],500,null,'database_error');
} catch (Exception $e) {
    logMessage("Общая ошибка: " . $e->getMessage());
    http_response_code(500);
    fitodomikContractResponse([
        'success' => false,
        'message' => 'Ошибка сервера'
    ],500,null,'internal_error');
}
