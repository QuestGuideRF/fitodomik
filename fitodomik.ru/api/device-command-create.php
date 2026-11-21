<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-CSRF-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_commands.php';
function commandCreateResponse($status, $payload)
{
    fitodomikJsonResponse($status, $payload);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    commandCreateResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $user = null;
    if (isset($_SESSION['user_id'])) {
        fitodomikRequireCsrf(true);
        $stmt = $pdo->prepare("SELECT id, username, first_name, last_name FROM users WHERE id = ? AND is_locked = 0 LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$user) {
        $user = fitodomikUserByToken($pdo, fitodomikRequestHeader('X-Auth-Token'));
    }
    if (!$user) {
        commandCreateResponse(401, ['success' => false, 'message' => 'Требуется авторизация']);
    }
    $device_id = trim((string)($input['device_id'] ?? ''));
    if ($device_id === '') {
        if (isset($_SESSION['user_id'])) { throw new FitodomikInputException('Передайте device_id отображённого устройства'); }
        $device_id = fitodomikDeviceIdFromRequest($input);
        if ($device_id === '') { $device_id = fitodomikGetSelectedDeviceId($pdo, (int)$user['id']); }
    }
    if ($device_id === '') {
        commandCreateResponse(400, ['success' => false, 'message' => 'Устройство не выбрано']);
    }
    $command_type = trim((string)($input['command_type'] ?? $input['type'] ?? ''));
    $payload = $input['payload'] ?? [];
    $expires = (int)($input['expires_minutes'] ?? 30);
    $command_id = fitodomikCreateDeviceCommand(
        $pdo,
        (int)$user['id'],
        $device_id,
        $command_type,
        [
            'payload' => $payload,
            'source' => 'site',
            'requested_by_user_id' => (int)$user['id'],
            'expires_minutes' => $expires,
        ]
    );
    commandCreateResponse(200, [
        'success' => true,
        'message' => 'Команда добавлена в очередь устройства',
        'command_id' => $command_id,
        'device_id' => $device_id,
    ]);
} catch (Exception $e) {
    commandCreateResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
