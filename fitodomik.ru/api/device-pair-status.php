<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/headers.php';
require_once __DIR__ . '/device-pair-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    devicePairJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
$input = devicePairInput();
$code = trim((string)($input['pair_code'] ?? $input['code'] ?? ''));
$secret = trim((string)($input['pair_secret'] ?? $input['secret'] ?? ''));
if ($code === '') {
    devicePairJsonResponse(400, ['success' => false, 'message' => 'Код привязки не передан']);
}
try {
    devicePairExpireOldRequests($pdo);
    $stmt = $pdo->prepare("
        SELECT code, client_secret_hash, device_name, expires_at, used_at, user_id, status, device_id
        FROM device_pair_requests
        WHERE code = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        devicePairJsonResponse(404, ['success' => false, 'message' => 'Код привязки не найден']);
    }
    if (!devicePairSecretMatches($request, $secret)) {
        devicePairJsonResponse(403, ['success' => false, 'message' => 'Секрет приложения не совпадает']);
    }
    $status = $request['status'];
    $message = 'Ожидаю подтверждение на сайте';
    if ($status === 'approved') {
        $message = 'Привязка подтверждена';
    } elseif ($status === 'expired') {
        $message = 'Код привязки истёк';
    } elseif ($status === 'cancelled') {
        $message = 'Привязка отменена';
    }
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => $message,
        'pair_code' => $request['code'],
        'device_name' => $request['device_name'],
        'status' => $status,
        'expires_at' => $request['expires_at'],
        'used_at' => $request['used_at'],
        'device_id' => $request['device_id'],
    ]);
} catch (Exception $e) {
    error_log('device-pair-status.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
