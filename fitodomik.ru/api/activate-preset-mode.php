<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/threshold_storage.php';
require_once '../config/device_context.php';
require_once __DIR__ . '/../config/event_log.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Неверный метод запроса']);
    exit;
}
fitodomikRequireCsrf(true);
$input = json_decode((string)file_get_contents('php://input'), true);
$mode_id = is_array($input)
    ? filter_var($input['mode_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($mode_id === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Неверный ID режима']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
try {
    $device = fitodomikRenderedDevice($pdo, $user_id, $input);
    if (!$device) {
        throw new FitodomikNotFoundException('Устройство не выбрано');
    }
    $deviceId = (string)$device['device_id'];
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM preset_modes WHERE id = ? AND user_id = ?");
    $stmt->execute([$mode_id, $user_id]);
    $mode = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mode) {
        throw new FitodomikNotFoundException('Режим не найден или вам не принадлежит');
    }
    $temp_min_limit = max(0, $mode['temperature'] - $mode['tolerance']);
    $temp_max_limit = $mode['temperature'] + $mode['tolerance'];
    $humidity_min_limit = max(0, $mode['humidity'] - $mode['humidity_tolerance']);
    $humidity_max_limit = min(100, $mode['humidity'] + $mode['humidity_tolerance']);
    $stmtTemp = $pdo->prepare("
        INSERT INTO alarm_thresholds
        (user_id, device_id, parameter_type, min_limit, max_limit, target_value, tolerance)
        VALUES (?, ?, 'temperature', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        min_limit = VALUES(min_limit),
        max_limit = VALUES(max_limit),
        target_value = VALUES(target_value),
        tolerance = VALUES(tolerance)
    ");
    $stmtTemp->execute([
        $user_id,
        $deviceId,
        $temp_min_limit,
        $temp_max_limit,
        $mode['temperature'],
        $mode['tolerance']
    ]);
    $stmtHumidity = $pdo->prepare("
        INSERT INTO alarm_thresholds
        (user_id, device_id, parameter_type, min_limit, max_limit, target_value, tolerance)
        VALUES (?, ?, 'humidity_air', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        min_limit = VALUES(min_limit),
        max_limit = VALUES(max_limit),
        target_value = VALUES(target_value),
        tolerance = VALUES(tolerance)
    ");
    $stmtHumidity->execute([
        $user_id,
        $deviceId,
        $humidity_min_limit,
        $humidity_max_limit,
        $mode['humidity'],
        $mode['humidity_tolerance']
    ]);
    if ($mode['soil_moisture'] !== null && $mode['soil_moisture_tolerance'] !== null) {
        $soilMin = max(0, (float)$mode['soil_moisture'] - (float)$mode['soil_moisture_tolerance']);
        $soilMax = min(100, (float)$mode['soil_moisture'] + (float)$mode['soil_moisture_tolerance']);
        $stmtSoil = $pdo->prepare("\n            INSERT INTO alarm_thresholds\n            (user_id, device_id, parameter_type, min_limit, max_limit, target_value, tolerance)\n            VALUES (?, ?, 'humidity_soil', ?, ?, ?, ?)\n            ON DUPLICATE KEY UPDATE\n            min_limit = VALUES(min_limit),\n            max_limit = VALUES(max_limit),\n            target_value = VALUES(target_value),\n            tolerance = VALUES(tolerance)\n        ");
        $stmtSoil->execute([
            $user_id,
            $deviceId,
            $soilMin,
            $soilMax,
            $mode['soil_moisture'],
            $mode['soil_moisture_tolerance']
        ]);
    }
    $hours = json_decode((string)($mode['lamp_hours_json'] ?? ''), true);
    if (!is_array($hours)) {
        $start = (int)substr((string)$mode['light_start'], 0, 2);
        $count = max(0, min(24, (int)round((float)$mode['light_hours'])));
        $hours = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $hours[] = ($start + $offset) % 24;
        }
    }
    $activeHours = [];
    foreach ($hours as $hour) {
        if (!is_int($hour) || $hour < 0 || $hour > 23) {
            throw new FitodomikInputException('Некорректные часы освещения в режиме');
        }
        $activeHours[$hour] = true;
    }
    $upsertSchedule = $pdo->prepare("
        INSERT INTO schedule (user_id, device_id, time, curtains_schedule, lighting_schedule)
        VALUES (?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE lighting_schedule = VALUES(lighting_schedule)
    ");
    for ($hour = 0; $hour < 24; $hour++) {
        $upsertSchedule->execute([$user_id, $deviceId, sprintf('%02d:00', $hour), isset($activeHours[$hour]) ? 1 : 0]);
    }
    fitodomikLogEventOnce($pdo, $user_id, 'device', 'Применен режим: ' . $mode['name'], true);
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Режим успешно активирован',
        'mode' => [
            'name' => $mode['name'],
            'temperature' => $mode['temperature'],
            'humidity' => $mode['humidity'],
            'soil_moisture' => $mode['soil_moisture'],
            'light_hours' => $mode['light_hours']
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Детальная ошибка активации режима: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
    exit;
} catch (Exception $e) {
    http_response_code($e instanceof FitodomikNotFoundException ? 404 : 422);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Общая ошибка: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
    exit;
}
