<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_context.php';
require_once '../config/api_exceptions.php';
function scheduleJsonResponse(int $status, bool $success, string $message): void
{
    http_response_code($status);
    if (!headers_sent()) {
        header_remove('Pragma');
        header_remove('Expires');
        header_remove('Content-Security-Policy');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function ensureScheduleStorage(PDO $pdo, int $userId, string $deviceId): void
{
    $stmt = $pdo->prepare("
        SELECT time
        FROM schedule
        WHERE user_id = ? AND device_id = ?
    ");
    $stmt->execute([$userId, $deviceId]);
    $existingHours = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $time) {
        $hourText = strtok((string)$time, ':');
        if (preg_match('/^(?:[01]?\d|2[0-3])$/', (string)$hourText)) {
            $existingHours[(int)$hourText] = true;
        }
    }
    $insertStmt = $pdo->prepare("
        INSERT INTO schedule (user_id, device_id, curtains_schedule, lighting_schedule, time)
        VALUES (?, ?, 0, 0, ?) ON DUPLICATE KEY UPDATE id=id
    ");
    for ($hour = 0; $hour < 24; $hour++) {
        if (empty($existingHours[$hour])) {
            $insertStmt->execute([$userId, $deviceId, sprintf('%02d:00', $hour)]);
        }
    }
}
function normalizeScheduleItems($value): array
{
    if (!is_array($value) || count($value) > 1440) {
        throw new FitodomikInputException('Некорректные данные расписания');
    }
    $result = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            throw new FitodomikInputException('Некорректная запись расписания');
        }
        $time = trim((string)($item['time'] ?? ''));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) {
            throw new FitodomikInputException('Некорректное время в расписании');
        }
        $active = filter_var($item['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            throw new FitodomikInputException('Некорректное состояние расписания');
        }
        $hour = (int)substr($time, 0, 2);
        $result[] = [
            'time' => sprintf('%02d:00', $hour),
            'hour' => $hour,
            'active' => $active ? 1 : 0,
        ];
    }
    return $result;
}
if (!isset($_SESSION['user_id'])) {
    scheduleJsonResponse(401, false, 'Требуется авторизация');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    scheduleJsonResponse(405, false, 'Метод не поддерживается');
}
fitodomikRequireCsrf(true);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    scheduleJsonResponse(400, false, 'Некорректный формат данных');
}
if (!array_key_exists('scheduleData', $data) || !isset($data['type'])) {
    scheduleJsonResponse(400, false, 'Отсутствуют необходимые параметры');
}
$type = (string)$data['type'];
if (!in_array($type, ['curtains', 'lighting'], true)) {
    scheduleJsonResponse(400, false, 'Неверный тип расписания');
}
try {
    $userId = (int)$_SESSION['user_id'];
    $selectedDevice = fitodomikRenderedDevice($pdo, $userId, $data);
    if (!$selectedDevice) {
        throw new FitodomikInputException('Устройство не выбрано');
    }
    $deviceId = (string)$selectedDevice['device_id'];
    $scheduleData = normalizeScheduleItems($data['scheduleData']);
    $pdo->beginTransaction();
    ensureScheduleStorage($pdo, $userId, $deviceId);
    if ($type === 'curtains') {
        $updateStmt = $pdo->prepare("
            UPDATE schedule
            SET curtains_schedule = :active
            WHERE user_id = :user_id AND device_id = :device_id
              AND CAST(SUBSTRING_INDEX(time, ':', 1) AS UNSIGNED) = :hour
        ");
        $insertStmt = $pdo->prepare("
            INSERT INTO schedule (user_id, device_id, curtains_schedule, lighting_schedule, time)
            VALUES (:user_id, :device_id, :active, 0, :time)
        ");
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE schedule
            SET lighting_schedule = :active
            WHERE user_id = :user_id AND device_id = :device_id
              AND CAST(SUBSTRING_INDEX(time, ':', 1) AS UNSIGNED) = :hour
        ");
        $insertStmt = $pdo->prepare("
            INSERT INTO schedule (user_id, device_id, curtains_schedule, lighting_schedule, time)
            VALUES (:user_id, :device_id, 0, :active, :time)
        ");
    }
    $existsStmt = $pdo->prepare("
        SELECT id
        FROM schedule
        WHERE user_id = :user_id AND device_id = :device_id
          AND CAST(SUBSTRING_INDEX(time, ':', 1) AS UNSIGNED) = :hour
        LIMIT 1
    ");
    foreach ($scheduleData as $item) {
        $updateStmt->execute([
            ':active' => $item['active'],
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':hour' => $item['hour'],
        ]);
        if ($updateStmt->rowCount() !== 0) {
            continue;
        }
        $existsStmt->execute([
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':hour' => $item['hour'],
        ]);
        if ($existsStmt->fetchColumn() !== false) {
            continue;
        }
        $insertStmt->execute([
            ':active' => $item['active'],
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':time' => $item['time'],
        ]);
    }
    $pdo->commit();
    scheduleJsonResponse(200, true, 'Расписание успешно обновлено');
} catch (FitodomikInputException $e) {
    scheduleJsonResponse(400, false, $e->getMessage());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('save-schedule.php: ' . $e->getMessage());
    scheduleJsonResponse(500, false, 'Ошибка при обновлении расписания');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('save-schedule.php: ' . $e->getMessage());
    scheduleJsonResponse(500, false, 'Ошибка при обновлении расписания');
}
