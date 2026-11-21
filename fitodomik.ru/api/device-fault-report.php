<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_context.php';
function faultReportResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    faultReportResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        faultReportResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($raw) || strlen($raw) > 4096) {
        faultReportResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
    }
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        faultReportResponse(400, ['success' => false, 'message' => 'Ожидается JSON']);
    }
    $auth = fitodomikAuthDeviceRequest($pdo, $input, false);
    if (!$auth) {
        faultReportResponse(401, ['success' => false, 'message' => 'Неверный токен устройства']);
    }
    $userId = (int)$auth['user_id'];
    $deviceId = (string)$auth['device']['device_id'];
    $eventKey = (string)($input['event_key'] ?? '');
    $faultCode = (string)($input['fault_code'] ?? '');
    if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $eventKey)
        || $faultCode !== 'PUMP_TIMEOUT') {
        faultReportResponse(400, ['success' => false, 'message' => 'Некорректное событие']);
    }
    $photoFile = null;
    if (!empty($input['photo_file'])) {
        $candidate = (string)$input['photo_file'];
        if (!preg_match('/\A(?:farm|analysis)_' . $userId . '_[0-9]+_[a-f0-9]{12}\.(?:jpg|png|gif|webp)\z/iD', $candidate)) {
            faultReportResponse(400, ['success' => false, 'message' => 'Некорректное фото']);
        }
        $photo = $pdo->prepare('SELECT id FROM farm_status WHERE user_id=? AND device_id=? AND photo=? LIMIT 1');
        $photo->execute([$userId, $deviceId, $candidate]);
        if (!$photo->fetchColumn()) {
            faultReportResponse(403, ['success' => false, 'message' => 'Фото не принадлежит устройству']);
        }
        $photoFile = $candidate;
    }
    $retryAfter = fitodomikConsumeRateLimit($pdo, 'device-fault-report', $userId . '|' . $deviceId, 30, 60, 60);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        faultReportResponse(429, ['success' => false, 'message' => 'Слишком много событий']);
    }
    $insert = $pdo->prepare('INSERT INTO device_fault_reports '
        . '(user_id, device_id, event_key, fault_code, photo_file) VALUES (?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), photo_file=COALESCE(photo_file, VALUES(photo_file))');
    $insert->execute([$userId, $deviceId, $eventKey, $faultCode, $photoFile]);
    faultReportResponse(200, ['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('device-fault-report.php: ' . $e->getMessage());
    faultReportResponse(500, ['success' => false, 'message' => 'Не удалось сохранить аварию']);
}
