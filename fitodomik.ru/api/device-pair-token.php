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
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT r.code, r.client_secret_hash, r.device_name, r.expires_at, r.user_id, r.status,
               r.device_id, r.token_delivered_at, users.username
        FROM device_pair_requests r
        LEFT JOIN users ON users.id = r.user_id
        WHERE r.code = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$code]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        $pdo->rollBack();
        devicePairJsonResponse(404, ['success' => false, 'message' => 'Код привязки не найден']);
    }
    if (!devicePairSecretMatches($request, $secret)) {
        $pdo->rollBack();
        devicePairJsonResponse(403, ['success' => false, 'message' => 'Секрет приложения не совпадает']);
    }
    if (
        $request['status'] !== 'approved'
        || empty($request['user_id'])
        || strtotime((string)$request['expires_at']) < time()
    ) {
        $pdo->rollBack();
        devicePairJsonResponse(409, ['success' => false, 'message' => 'Привязка ещё не подтверждена']);
    }
    if (!empty($request['token_delivered_at'])) {
        $pdo->rollBack();
        devicePairJsonResponse(410, ['success' => false, 'message' => 'Токен привязки уже был получен']);
    }
    $activeUser = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_locked = 0 LIMIT 1 FOR UPDATE');
    $activeUser->execute([(int)$request['user_id']]);
    if (!$activeUser->fetchColumn()) {
        devicePairJsonResponse(403, ['success' => false, 'message' => 'Аккаунт заблокирован']);
    }
    $device = null;
    if (!empty($request['device_id'])) {
        fitodomikTouchDevice($pdo, (int)$request['user_id'], $request['device_id']);
        $stmt = $pdo->prepare("SELECT device_token, device_name FROM user_devices WHERE user_id = ? AND device_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([(int)$request['user_id'], $request['device_id']]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$device) { throw new DomainException('Активное устройство не найдено'); }
    $token = fitodomikCreateClientGrant($pdo, (int)$request['user_id'], $request['device_id']);
    $grantUser = fitodomikClientGrantUser($pdo, $token);
    $device['device_token'] = fitodomikClientDeviceToken($pdo, $grantUser, $request['device_id']);
    $update = $pdo->prepare("
        UPDATE device_pair_requests
        SET token_delivered_at = NOW()
        WHERE code = ? AND token_delivered_at IS NULL
    ");
    $update->execute([$code]);
    if ($update->rowCount() !== 1) {
        throw new FitodomikConflictException('Токен привязки уже был получен');
    }
    $pdo->commit();
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => 'Аккаунт привязан',
        'token' => $token,
        'device_id' => $request['device_id'],
        'device_token' => $device['device_token'] ?? '',
        'device_name' => $device['device_name'] ?? $request['device_name'],
        'username' => $request['username'],
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('device-pair-token.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
