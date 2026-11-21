<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_context.php';
require_once '../config/threshold_storage.php';
function limitsJsonResponse(int $status, bool $success, ?string $error = null): void
{
    http_response_code($status);
    $payload = ['success' => $success];
    if ($error !== null) {
        $payload['error'] = $error;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function limitsNumber($value, string $label): float
{
    if (!is_scalar($value) || !is_numeric((string)$value)) {
        throw new FitodomikInputException("{$label} должно быть числом");
    }
    return (float)$value;
}
function limitsTarget(array $section, array $definition, ?array $current, float $min, float $max): float
{
    if (array_key_exists('target', $section) && $section['target'] !== '') {
        return limitsNumber($section['target'], "Целевое значение «{$definition['label']}»");
    }
    if ($current !== null && $current['target_value'] !== null) {
        return max($min, min($max, (float)$current['target_value']));
    }
    return ($min + $max) / 2;
}
function limitsTolerance(array $section, array $definition, ?array $current): float
{
    $tolerance = $current !== null && $current['tolerance'] !== null
        ? (float)$current['tolerance']
        : 1.0;
    if ($definition['allow_tolerance'] && array_key_exists('tolerance', $section) && $section['tolerance'] !== '') {
        $tolerance = limitsNumber($section['tolerance'], "Допуск «{$definition['label']}»");
    }
    return $tolerance;
}
function limitsNormalizeRange(array $section, array $definition, ?array $current): array
{
    if (!array_key_exists('min', $section) || !array_key_exists('max', $section)) {
        throw new FitodomikInputException("Для параметра «{$definition['label']}» нужны min и max");
    }
    $min = limitsNumber($section['min'], "Минимум «{$definition['label']}»");
    $max = limitsNumber($section['max'], "Максимум «{$definition['label']}»");
    if ($min < $definition['allowed_min'] || $max > $definition['allowed_max'] || $min > $max) {
        throw new FitodomikInputException("Недопустимый диапазон для «{$definition['label']}»");
    }
    $target = limitsTarget($section, $definition, $current, $min, $max);
    if ($target < $min || $target > $max) {
        throw new FitodomikInputException("Целевое значение «{$definition['label']}» должно быть внутри диапазона");
    }
    $tolerance = limitsTolerance($section, $definition, $current);
    if ($tolerance < 0 || $tolerance > $definition['max_tolerance']) {
        throw new FitodomikInputException("Недопустимый допуск для «{$definition['label']}»");
    }
    return [
        'min' => $min,
        'max' => $max,
        'target' => $target,
        'tolerance' => $tolerance,
    ];
}
function limitsUpsert(PDO $pdo, int $userId, string $deviceId, string $parameterType, array $values): void
{
    $stmt = $pdo->prepare("
        INSERT INTO alarm_thresholds
            (user_id, device_id, parameter_type, min_limit, max_limit, target_value, tolerance)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            min_limit = VALUES(min_limit),
            max_limit = VALUES(max_limit),
            target_value = VALUES(target_value),
            tolerance = VALUES(tolerance)
    ");
    $stmt->execute([
        $userId,
        $deviceId,
        $parameterType,
        $values['min'],
        $values['max'],
        $values['target'],
        $values['tolerance'],
    ]);
}
function limitsLogEvent(PDO $pdo, int $userId, string $deviceId, string $eventType, string $description): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM event_log
            WHERE user_id = ?
              AND device_id = ?
              AND event_type = ?
              AND event_description = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmt->execute([$userId, $deviceId, $eventType, $description]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $stmt = $pdo->prepare("
            INSERT INTO event_log (user_id, device_id, event_type, event_description)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $deviceId, $eventType, $description]);
    } catch (PDOException $e) {
        error_log('save-limits event log: ' . $e->getMessage());
    }
}
function limitsCheckCurrentValues(
    PDO $pdo,
    int $userId,
    string $deviceId,
    array $definitions,
    array $normalized
): void {
    if ($normalized === []) {
        return;
    }
    $stmt = $pdo->prepare("
        SELECT temperature, humidity, soil_moisture, co2
        FROM sensor_data
        WHERE user_id = ? AND device_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $deviceId]);
    $sensorData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sensorData) {
        return;
    }
    foreach ($normalized as $inputKey => $limits) {
        $definition = $definitions[$inputKey];
        $sensorKey = $definition['sensor_key'];
        if (!isset($sensorData[$sensorKey])) {
            continue;
        }
        $value = (float)$sensorData[$sensorKey];
        if ($value >= $limits['min'] && $value <= $limits['max']) {
            continue;
        }
        $description = 'Тревога: ' . $definition['label'] . ' ' . $value . $definition['unit']
            . ' вышла за установленные пределы (' . $limits['min'] . $definition['unit']
            . ' - ' . $limits['max'] . $definition['unit'] . ')';
        limitsLogEvent($pdo, $userId, $deviceId, $definition['event_type'], $description);
    }
}
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    limitsJsonResponse(401, false, 'Для сохранения данных необходимо авторизоваться');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    limitsJsonResponse(405, false, 'Метод не поддерживается');
}
fitodomikRequireCsrf(true);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    limitsJsonResponse(400, false, 'Invalid request data');
}
$definitions = [
    'temperature' => [
        'parameter_type' => 'temperature',
        'label' => 'температура',
        'sensor_key' => 'temperature',
        'event_type' => 'temperature',
        'unit' => '°C',
        'allowed_min' => -50.0,
        'allowed_max' => 100.0,
        'allow_tolerance' => true,
        'max_tolerance' => 50.0,
    ],
    'humidity' => [
        'parameter_type' => 'humidity_air',
        'label' => 'влажность воздуха',
        'sensor_key' => 'humidity',
        'event_type' => 'humidity',
        'unit' => '%',
        'allowed_min' => 0.0,
        'allowed_max' => 100.0,
        'allow_tolerance' => true,
        'max_tolerance' => 100.0,
    ],
    'soil_moisture' => [
        'parameter_type' => 'humidity_soil',
        'label' => 'влажность почвы',
        'sensor_key' => 'soil_moisture',
        'event_type' => 'soil_moisture',
        'unit' => '%',
        'allowed_min' => 0.0,
        'allowed_max' => 100.0,
        'allow_tolerance' => false,
        'max_tolerance' => 100.0,
    ],
    'co2' => [
        'parameter_type' => 'co2',
        'label' => 'уровень CO2',
        'sensor_key' => 'co2',
        'event_type' => 'co2',
        'unit' => ' ppm',
        'allowed_min' => 0.0,
        'allowed_max' => 10000.0,
        'allow_tolerance' => false,
        'max_tolerance' => 10000.0,
    ],
];
try {
    $userId = (int)$_SESSION['user_id'];
    $selectedDevice = fitodomikRenderedDevice($pdo, $userId, $data);
    if (!$selectedDevice) {
        throw new FitodomikInputException('Устройство не выбрано');
    }
    $deviceId = (string)$selectedDevice['device_id'];
    $stmt = $pdo->prepare("
        SELECT parameter_type, target_value, tolerance
        FROM alarm_thresholds
        WHERE user_id = ? AND device_id = ?
    ");
    $stmt->execute([$userId, $deviceId]);
    $currentValues = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $currentValues[$row['parameter_type']] = $row;
    }
    $normalized = [];
    foreach ($definitions as $inputKey => $definition) {
        if (!array_key_exists($inputKey, $data)) {
            continue;
        }
        if (!is_array($data[$inputKey])) {
            throw new FitodomikInputException("Некорректные данные для «{$definition['label']}»");
        }
        $current = $currentValues[$definition['parameter_type']] ?? null;
        $normalized[$inputKey] = limitsNormalizeRange($data[$inputKey], $definition, $current);
    }
    $pdo->beginTransaction();
    foreach ($normalized as $inputKey => $values) {
        limitsUpsert($pdo, $userId, $deviceId, $definitions[$inputKey]['parameter_type'], $values);
    }
    limitsCheckCurrentValues($pdo, $userId, $deviceId, $definitions, $normalized);
    $pdo->commit();
    limitsJsonResponse(200, true);
} catch (FitodomikInputException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    limitsJsonResponse(400, false, $e->getMessage());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error in save-limits.php: ' . $e->getMessage());
    limitsJsonResponse(500, false, 'Ошибка базы данных');
}
