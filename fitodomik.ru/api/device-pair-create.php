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
$device_name = trim((string)($input['device_name'] ?? 'FitoDomik'));
if ($device_name === '') {
    $device_name = 'FitoDomik';
}
$device_name = function_exists('mb_substr') ? mb_substr($device_name, 0, 120, 'UTF-8') : substr($device_name, 0, 120);
try {
    $retryAfter = fitodomikConsumeRateLimit($pdo, 'device-pair-create', fitodomikClientAddress(), 20, 900, 900);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        devicePairJsonResponse(429, ['success' => false, 'message' => 'Слишком много запросов. Повторите позже.']);
    }
    devicePairExpireOldRequests($pdo);
    $code = bin2hex(random_bytes(24));
    $client_secret = bin2hex(random_bytes(32));
    $client_secret_hash = hash('sha256', $client_secret);
    $expires_at = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO device_pair_requests (code, client_secret_hash, device_name, expires_at, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$code, $client_secret_hash, $device_name, $expires_at]);
    $link = devicePairPublicBaseUrl() . '/device-link.php?code=' . urlencode($code);
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => 'QR-код создан',
        'pair_code' => $code,
        'pair_secret' => $client_secret,
        'device_name' => $device_name,
        'link' => $link,
        'expires_at' => $expires_at,
        'expires_in' => 900,
    ]);
} catch (Exception $e) {
    error_log('device-pair-create.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
