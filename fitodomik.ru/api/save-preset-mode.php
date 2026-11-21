<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once __DIR__ . '/../config/event_log.php';
function presetModeSaveJson(int $status, array $payload): void
{
    if (!headers_sent()) {
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function presetModeTextLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($characters) ? count($characters) : strlen($value);
}
function presetModeParseLampHours($raw): array
{
    $result = [];
    $hours = [];
    if (is_array($raw)) {
        foreach ($raw as $hour) {
            if (!is_int($hour) && !(is_string($hour) && ctype_digit($hour))) {
                $result = ['error' => 'Некорректный час лампы'];
                break;
            }
            $hour = (int)$hour;
            if ($hour < 0 || $hour > 23) {
                $result = ['error' => 'Часы лампы должны быть от 0 до 23'];
                break;
            }
            $hours[$hour] = $hour;
        }
        if (!isset($result['error'])) {
            ksort($hours);
            $result = ['hours' => array_values($hours)];
        }
    }
    return $result;
}
function presetModeDeriveLightFields(array $lampHours): array
{
    $first = $lampHours[0];
    $last = $lampHours[count($lampHours) - 1];
    return [
        'light_hours' => count($lampHours),
        'light_start' => sprintf('%02d:00', $first),
        'light_end' => sprintf('%02d:00', ($last + 1) % 24),
    ];
}
function presetModeValidateCurtainHour($hour): ?string
{
    $message = null;
    if (!ctype_digit((string)$hour)) {
        $message = 'Некорректный час в расписании штор';
    } elseif ((int)$hour < 0 || (int)$hour > 23) {
        $message = 'Часы штор должны быть от 0 до 23';
    }
    return $message;
}
function presetModeIsCurtainStateAllowed($state): bool
{
    return in_array($state, ['up', 'down', 'none'], true);
}
function presetModeParseCurtainSchedule($raw): array
{
    $result = ['error' => 'Некорректное расписание штор'];
    if (is_array($raw)) {
        $schedule = [];
        $error = null;
        foreach ($raw as $hour => $state) {
            $error = presetModeValidateCurtainHour($hour);
            if ($error !== null) {
                break;
            }
            if (!presetModeIsCurtainStateAllowed($state)) {
                $error = 'Некорректное состояние штор';
                break;
            }
            if ($state !== 'none') {
                $schedule[(string)(int)$hour] = $state;
            }
        }
        $result = $error === null ? ['schedule' => $schedule] : ['error' => $error];
    }
    return $result;
}
if (!isset($_SESSION['user_id'])) {
    presetModeSaveJson(401, ['success' => false, 'message' => 'Не авторизован']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    presetModeSaveJson(405, ['success' => false, 'message' => 'Метод не поддерживается']);
}
fitodomikRequireCsrf(true);
$user_id = (int)$_SESSION['user_id'];
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректный формат данных']);
}
$name = trim((string)($data['name'] ?? ''));
if ($name === '') {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Укажите название режима']);
}
if (presetModeTextLength($name) > 100) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Название режима не должно быть длиннее 100 символов']);
}
$modeId = null;
if (isset($data['mode_id']) && (string)$data['mode_id'] !== '') {
    $validatedModeId = filter_var($data['mode_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validatedModeId === false) {
        presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректный ID режима']);
    }
    $modeId = (int)$validatedModeId;
}
if (!isset($data['temperature']) || !is_numeric($data['temperature'])) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректное значение температуры']);
}
$temperature = (float)$data['temperature'];
if ($temperature < 5 || $temperature > 50) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Температура должна быть от 5 до 50°C']);
}
if (!isset($data['tolerance']) || !is_numeric($data['tolerance'])) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректное значение допуска температуры']);
}
$tolerance = (float)$data['tolerance'];
if ($tolerance < 0 || $tolerance > 10) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Допуск температуры должен быть от 0 до 10°C']);
}
if (!isset($data['humidity']) || !is_numeric($data['humidity'])) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректное значение влажности']);
}
$humidity = (int)$data['humidity'];
if (!ctype_digit((string)$data['humidity']) || $humidity < 30 || $humidity > 99) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Влажность должна быть целым числом от 30 до 99%']);
}
if (!isset($data['humidity_tolerance']) || !is_numeric($data['humidity_tolerance'])) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректное значение допуска влажности']);
}
$humidity_tolerance = (float)$data['humidity_tolerance'];
if ($humidity_tolerance < 0 || $humidity_tolerance > 20) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Допуск влажности должен быть от 0 до 20%']);
}
if (!isset($data['soil_moisture']) || !is_numeric($data['soil_moisture'])) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректная влажность почвы']);
}
$soilMoisture = (int)$data['soil_moisture'];
if (!ctype_digit((string)$data['soil_moisture']) || $soilMoisture < 0 || $soilMoisture > 100) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Влажность почвы должна быть целым числом от 0 до 100%']);
}
if (!isset($data['soil_moisture_tolerance']) || !is_numeric($data['soil_moisture_tolerance'])) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректный допуск влажности почвы']);
}
$soilMoistureTolerance = (float)$data['soil_moisture_tolerance'];
if ($soilMoistureTolerance < 0 || $soilMoistureTolerance > 30) {
    presetModeSaveJson(400, ['success' => false, 'message' => 'Допуск влажности почвы должен быть от 0 до 30%']);
}
$lampHours = [];
$lampHoursJson = null;
if (array_key_exists('lamp_hours', $data)) {
    $parsedLampHours = presetModeParseLampHours($data['lamp_hours']);
    if (isset($parsedLampHours['error'])) {
        presetModeSaveJson(400, ['success' => false, 'message' => $parsedLampHours['error']]);
    }
    $lampHours = $parsedLampHours['hours'];
    if ($lampHours !== []) {
        $lampHoursJson = json_encode($lampHours, JSON_UNESCAPED_UNICODE);
    }
}
$light_start = trim((string)($data['light_start'] ?? ''));
$light_end = trim((string)($data['light_end'] ?? ''));
$light_hours = null;
if ($lampHours !== []) {
    $derived = presetModeDeriveLightFields($lampHours);
    $light_hours = $derived['light_hours'];
    $light_start = $derived['light_start'];
    $light_end = $derived['light_end'];
} else {
    if (!isset($data['light_hours']) || !is_numeric($data['light_hours'])) {
        presetModeSaveJson(400, ['success' => false, 'message' => 'Выберите часы работы лампы']);
    }
    $light_hours = (float)$data['light_hours'];
    if ($light_hours < 0 || $light_hours > 24) {
        presetModeSaveJson(400, ['success' => false, 'message' => 'Часы освещения должны быть от 0 до 24']);
    }
    if ($light_start === '' || !preg_match('/^\d{2}:\d{2}$/', $light_start)) {
        presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректное время начала освещения']);
    }
    if ($light_end === '' || !preg_match('/^\d{2}:\d{2}$/', $light_end)) {
        presetModeSaveJson(400, ['success' => false, 'message' => 'Некорректное время окончания освещения']);
    }
}
$curtainScheduleJson = null;
if (array_key_exists('curtain_schedule', $data)) {
    $parsedCurtain = presetModeParseCurtainSchedule($data['curtain_schedule']);
    if (isset($parsedCurtain['error'])) {
        presetModeSaveJson(400, ['success' => false, 'message' => $parsedCurtain['error']]);
    }
    if ($parsedCurtain['schedule'] !== []) {
        $curtainScheduleJson = json_encode($parsedCurtain['schedule'], JSON_UNESCAPED_UNICODE);
    }
}
$insertFields = [
    'user_id', 'name', 'temperature', 'tolerance',
    'humidity', 'humidity_tolerance', 'light_hours',
    'light_start', 'light_end',
];
$insertValues = [
    ':user_id' => $user_id,
    ':name' => $name,
    ':temperature' => $temperature,
    ':tolerance' => $tolerance,
    ':humidity' => $humidity,
    ':humidity_tolerance' => $humidity_tolerance,
    ':light_hours' => $light_hours,
    ':light_start' => $light_start,
    ':light_end' => $light_end,
];
$insertFields[] = 'lamp_hours_json';
$insertValues[':lamp_hours_json'] = $lampHoursJson;
$insertFields[] = 'curtain_schedule';
$insertValues[':curtain_schedule'] = $curtainScheduleJson;
$insertFields[] = 'soil_moisture';
$insertValues[':soil_moisture'] = $soilMoisture;
$insertFields[] = 'soil_moisture_tolerance';
$insertValues[':soil_moisture_tolerance'] = $soilMoistureTolerance;
$insertFields[] = 'created_at';
$columnsSql = implode(', ', $insertFields);
$placeholders = [];
foreach ($insertFields as $field) {
    $placeholders[] = $field === 'created_at' ? 'NOW()' : ':' . $field;
}
$placeholdersSql = implode(', ', $placeholders);
try {
    $pdo->beginTransaction();
    if ($modeId !== null) {
        $owned = $pdo->prepare('SELECT id FROM preset_modes WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE');
        $owned->execute([$modeId, $user_id]);
        if ($owned->fetchColumn() === false) {
            $pdo->rollBack();
            presetModeSaveJson(404, ['success' => false, 'message' => 'Режим не найден или не принадлежит пользователю']);
        }
        $updates = [];
        $updateValues = [];
        foreach ($insertFields as $field) {
            if ($field === 'user_id' || $field === 'created_at') {
                continue;
            }
            $updates[] = $field . ' = :' . $field;
            $updateValues[':' . $field] = $insertValues[':' . $field];
        }
        $updateValues[':mode_id'] = $modeId;
        $updateValues[':owner_id'] = $user_id;
        $stmt = $pdo->prepare('UPDATE preset_modes SET ' . implode(', ', $updates) . ' WHERE id = :mode_id AND user_id = :owner_id');
        $stmt->execute($updateValues);
        $newPresetId = $modeId;
        $message = 'Режим обновлён';
        fitodomikLogEventOnce($pdo, $user_id, 'system', 'Изменен режим: ' . $name);
    } else {
        $stmt = $pdo->prepare("INSERT INTO preset_modes ({$columnsSql}) VALUES ({$placeholdersSql})");
        $stmt->execute($insertValues);
        $newPresetId = (int)$pdo->lastInsertId();
        $message = 'Режим сохранён';
        fitodomikLogEventOnce($pdo, $user_id, 'system', 'Сохранен режим: ' . $name);
    }
    $pdo->commit();
    $savedMode = [
        'id' => $newPresetId,
        'name' => $name,
        'temperature' => $temperature,
        'tolerance' => $tolerance,
        'humidity' => $humidity,
        'humidity_tolerance' => $humidity_tolerance,
        'soil_moisture' => $soilMoisture,
        'soil_moisture_tolerance' => $soilMoistureTolerance,
        'light_hours' => $light_hours,
        'light_start' => $light_start,
        'light_end' => $light_end,
    ];
    if ($lampHoursJson !== null) {
        $savedMode['lamp_hours_json'] = $lampHoursJson;
        $savedMode['lamp_hours'] = $lampHours;
    }
    if ($curtainScheduleJson !== null) {
        $savedMode['curtain_schedule'] = $curtainScheduleJson;
    }
    presetModeSaveJson(200, [
        'success' => true,
        'message' => $message,
        'presetId' => $newPresetId,
        'mode' => $savedMode,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка сохранения режима: ' . $e->getMessage());
    $message = 'Не удалось сохранить режим в базе данных';
    if (stripos($e->getMessage(), 'humidity_tolerance') !== false) {
        $message = 'Не удалось сохранить допуск влажности. Уменьшите значение или обратитесь к администратору.';
    } elseif (stripos($e->getMessage(), 'Unknown column') !== false) {
        $message = 'Схема базы данных устарела. Обновите страницу и повторите попытку.';
    }
    presetModeSaveJson(500, ['success' => false, 'message' => $message]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Общая ошибка сохранения режима: ' . $e->getMessage());
    presetModeSaveJson(500, ['success' => false, 'message' => 'Произошла ошибка при сохранении режима']);
}
