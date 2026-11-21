<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_commands.php';
function commandResultResponse($status, $payload)
{
    fitodomikJsonResponse($status, $payload);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    commandResultResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $auth = fitodomikAuthDeviceRequest($pdo, $input, false);
    if (!$auth) {
        commandResultResponse(401, ['success' => false, 'message' => 'Неверный токен устройства']);
    }
    $command_id = (int)($input['command_id'] ?? 0);
    if ($command_id <= 0) {
        commandResultResponse(400, ['success' => false, 'message' => 'Не указан command_id']);
    }
    fitodomikSaveDeviceCommandResult(
        $pdo,
        (int)$auth['user_id'],
        $auth['device']['device_id'],
        $command_id,
        [
            'status' => (string)($input['status'] ?? 'error'),
            'message' => (string)($input['message'] ?? ''),
            'payload' => $input['result_payload'] ?? $input['payload'] ?? [],
            'started_at' => $input['started_at'] ?? null,
            'finished_at' => $input['finished_at'] ?? null,
        ]
    );
    commandResultResponse(200, ['success' => true, 'message' => 'Результат команды сохранён']);
} catch (Exception $e) {
    commandResultResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
