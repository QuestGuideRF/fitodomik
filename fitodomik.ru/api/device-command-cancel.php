<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-CSRF-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_commands.php';
function commandCancelResponse($status, $payload)
{
    fitodomikJsonResponse($status, $payload);
}
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $sessionAuthenticated = isset($_SESSION['user_id']);
    $user_id = $sessionAuthenticated ? (int)$_SESSION['user_id'] : 0;
    if ($sessionAuthenticated) {
        fitodomikRequireCsrf(true);
    }
    if (!$user_id) {
        $user = fitodomikUserByToken($pdo, fitodomikRequestHeader('X-Auth-Token'));
        $user_id = $user ? (int)$user['id'] : 0;
    }
    if (!$user_id) {
        commandCancelResponse(401, ['success' => false, 'message' => 'Требуется авторизация']);
    }
    $command_id = (int)($input['command_id'] ?? 0);
    $commandStmt = $pdo->prepare("
        SELECT device_id
        FROM device_commands
        WHERE id = ? AND user_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $commandStmt->execute([$command_id, $user_id]);
    $command = $commandStmt->fetch(PDO::FETCH_ASSOC);
    if (!$command) {
        commandCancelResponse(200, [
            'success' => false,
            'message' => 'Команду нельзя отменить',
        ]);
    }
    $device_id = trim((string)($input['device_id'] ?? $command['device_id']));
    if ($device_id === '' || !hash_equals((string)$command['device_id'], $device_id)) {
        commandCancelResponse(403, [
            'success' => false,
            'message' => 'Нет доступа к команде устройства',
        ]);
    }
    $stmt = $pdo->prepare("
        UPDATE device_commands
        SET status = 'cancelled', finished_at = NOW(), result_message = 'Команда отменена пользователем'
        WHERE id = ? AND user_id = ? AND device_id = ? AND status = 'pending'
    ");
    $stmt->execute([$command_id, $user_id, $device_id]);
    commandCancelResponse(200, [
        'success' => $stmt->rowCount() > 0,
        'message' => $stmt->rowCount() > 0 ? 'Команда отменена' : 'Команду нельзя отменить',
    ]);
} catch (Exception $e) {
    error_log('device-command-cancel.php: ' . $e->getMessage());
    commandCancelResponse(400, ['success' => false, 'message' => 'Не удалось отменить команду']);
}
