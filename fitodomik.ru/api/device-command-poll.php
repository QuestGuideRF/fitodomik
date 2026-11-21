<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET', 'POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_commands.php';
function commandPollResponse($status, $payload)
{
    fitodomikJsonResponse($status, $payload);
}
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_REQUEST;
    }
    $auth = fitodomikAuthDeviceRequest($pdo, $input, false);
    if (!$auth) {
        commandPollResponse(401, ['success' => false, 'message' => 'Неверный токен устройства']);
    }
    $limit = (int)($input['limit'] ?? 5);
    $clientId = (string)($input['delivery_client'] ?? '');
    if ($clientId !== '' && !preg_match('/\A[0-9a-f-]{36}\z/iD', $clientId)) {
        commandPollResponse(422, ['success' => false, 'message' => 'Некорректный delivery_client']);
    }
    $commands = fitodomikFetchPendingDeviceCommands($pdo, (int)$auth['user_id'], $auth['device']['device_id'], $limit, $clientId);
    commandPollResponse(200, [
        'success' => true,
        'device_id' => $auth['device']['device_id'],
        'commands' => $commands,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    commandPollResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
