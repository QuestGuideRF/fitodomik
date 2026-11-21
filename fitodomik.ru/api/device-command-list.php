<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['Content-Type', 'X-Auth-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_commands.php';
function commandListResponse($status, $payload)
{
    fitodomikJsonResponse($status, $payload);
}
try {
    $user = null;
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, username, first_name, last_name FROM users WHERE id = ? AND is_locked = 0 LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$user) {
        $user = fitodomikUserByToken($pdo, fitodomikRequestHeader('X-Auth-Token'));
    }
    if (!$user) {
        commandListResponse(401, ['success' => false, 'message' => 'Требуется авторизация']);
    }
    fitodomikExpireOldDeviceCommands($pdo);
    $device_id = trim((string)($_GET['device_id'] ?? ''));
    if ($device_id === '') {
        $device_id = fitodomikGetSelectedDeviceId($pdo, (int)$user['id']);
    }
    $deviceStmt = $pdo->prepare("SELECT device_id FROM user_devices WHERE user_id = ? AND device_id = ? AND status = 'active' LIMIT 1");
    $deviceStmt->execute([(int)$user['id'], $device_id]);
    if (!$deviceStmt->fetch()) {
        commandListResponse(404, ['success' => false, 'message' => 'Устройство не найдено']);
    }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    $stmt = $pdo->prepare("
        SELECT id, command_type, command_payload, status, source,
               requested_by_user_id, requested_by_telegram_id, created_by_user_id, telegram_user_id, scenario_id,
               created_at, started_at, picked_at, finished_at, duration_ms, expires_at,
               result_message, result_payload, error_message, attempts, cancelled_at, cancelled_by_user_id
        FROM device_commands
        WHERE user_id = :user_id AND device_id = :device_id
        ORDER BY created_at DESC
        LIMIT :result_limit
    ");
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':device_id', $device_id, PDO::PARAM_STR);
    $stmt->bindValue(':result_limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($commands as &$command) {
        $command['command_id'] = (int)$command['id'];
        $command['payload'] = fitodomikCommandJson($command['command_payload'] ?? '');
        unset($command['id'], $command['command_payload']);
    }
    commandListResponse(200, ['success' => true, 'device_id' => $device_id, 'commands' => $commands]);
} catch (Exception $e) {
    commandListResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
