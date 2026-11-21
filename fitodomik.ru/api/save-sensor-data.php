<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token', 'X-Device-Name']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/device_context.php';
$thresholdsFile = __DIR__ . '/check-thresholds.php';
if (file_exists($thresholdsFile)) {
    require_once $thresholdsFile;
}
function sensorJsonResponse($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function sensorNumericValue(array $data, string $field, ?float $default = null): float
{
    if (!array_key_exists($field, $data) || $data[$field] === '') {
        if ($default !== null) {
            return $default;
        }
        throw new FitodomikInputException("Отсутствует обязательное поле: {$field}");
    }
    $value = $data[$field];
    if (!is_scalar($value) || !is_numeric((string)$value)) {
        throw new FitodomikInputException("Поле {$field} должно быть числом");
    }
    return (float)$value;
}
function sensorBinaryValue(array $data, string $field): int
{
    if (!array_key_exists($field, $data)) {
        return 0;
    }
    $value = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($value === null) {
        throw new FitodomikInputException("Поле {$field} должно содержать 0 или 1");
    }
    return $value ? 1 : 0;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sensorJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    $data = $_POST;
    $device_id = trim((string)($data['device_id'] ?? fitodomikRequestHeader('X-Device-ID')));
    $device_name = fitodomikNormalizeDeviceName($data['device_name'] ?? fitodomikRequestHeader('X-Device-Name'));
    $auth = fitodomikAuthDeviceRequest($pdo, $data, true);
    if (!$auth) {
        sensorJsonResponse(401, ['success' => false, 'message' => 'Неверный токен устройства или аккаунта']);
    }
    $user_id = (int)$auth['user_id'];
    $device = $auth['device'];
    $device_id = $device['device_id'];
    $temperature = sensorNumericValue($data, 'temperature');
    $humidity = sensorNumericValue($data, 'humidity');
    $soil_moisture = sensorNumericValue($data, 'soil_moisture', 0.0);
    $light_level = sensorNumericValue($data, 'light_level', 0.0);
    $co2Value = sensorNumericValue($data, 'co2', 400.0);
    if (floor($co2Value) !== $co2Value) {
        throw new FitodomikInputException('Значение CO2 должно быть целым числом');
    }
    $co2 = (int)$co2Value;
    $pressure = sensorNumericValue($data, 'pressure', 760.0);
    $lamp_state = sensorBinaryValue($data, 'lamp_state');
    $curtains_state = sensorBinaryValue($data, 'curtains_state');
    if ($temperature < -50 || $temperature > 100) {
        throw new FitodomikInputException('Недопустимое значение температуры');
    }
    if ($humidity < 0 || $humidity > 100) {
        throw new FitodomikInputException('Недопустимое значение влажности');
    }
    if ($soil_moisture < 0 || $soil_moisture > 100) {
        throw new FitodomikInputException('Недопустимое значение влажности почвы');
    }
    if ($light_level < 0) {
        throw new FitodomikInputException('Недопустимое значение освещенности');
    }
    if ($co2 < 0 || $co2 > 5000) {
        throw new FitodomikInputException('Недопустимое значение CO2');
    }
    if ($pressure < 650 || $pressure > 850) {
        throw new FitodomikInputException('Недопустимое значение давления');
    }
    $sql = "INSERT INTO sensor_data (
        user_id, device_id, temperature, humidity, soil_moisture, light_level,
        co2, pressure, lamp_state, curtains_state, created_at
    ) VALUES (
        ?, ?, CAST(? AS DECIMAL(5,2)), CAST(? AS DECIMAL(5,2)),
        CAST(? AS DECIMAL(5,2)), CAST(? AS DECIMAL(8,2)), CAST(? AS UNSIGNED),
        CAST(? AS DECIMAL(7,2)), ?, ?, NOW()
    )";
    $params = [
        $user_id,
        $device_id,
        $temperature,
        $humidity,
        $soil_moisture,
        $light_level,
        $co2,
        $pressure,
        $lamp_state,
        $curtains_state,
    ];
    $stmt = $pdo->prepare($sql);
    if (!$stmt->execute($params)) {
        throw new FitodomikStorageException('Ошибка при сохранении данных в базу');
    }
    $saved_id = $pdo->lastInsertId();
    $stmt = $pdo->prepare("
        SELECT id, user_id, device_id,
               CAST(temperature AS DECIMAL(5,2)) AS temperature,
               CAST(humidity AS DECIMAL(5,2)) AS humidity,
               CAST(soil_moisture AS DECIMAL(5,2)) AS soil_moisture,
               CAST(light_level AS DECIMAL(8,2)) AS light_level,
               co2,
               CAST(pressure AS DECIMAL(7,2)) AS pressure,
               lamp_state,
               curtains_state,
               created_at
        FROM sensor_data
        WHERE id = ? AND user_id = ? AND device_id = ?
        LIMIT 1
    ");
    $stmt->execute([$saved_id, $user_id, $device_id]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$saved) {
        throw new FitodomikNotFoundException('Сохранённая запись датчика не найдена');
    }
    if (function_exists('checkThresholds')) {
        checkThresholds($user_id, [
            'temperature' => (float)$saved['temperature'],
            'humidity' => (float)$saved['humidity'],
            'soil_moisture' => (float)$saved['soil_moisture'],
            'co2' => (int)$saved['co2'],
        ]);
    }
    sensorJsonResponse(200, [
        'success' => true,
        'message' => 'Данные успешно сохранены',
        'data' => [
            'id' => (int)$saved['id'],
            'user_id' => $user_id,
            'device_id' => $saved['device_id'],
            'device_name' => $device['device_name'],
            'temperature' => (float)$saved['temperature'],
            'humidity' => (float)$saved['humidity'],
            'soil_moisture' => (float)$saved['soil_moisture'],
            'light_level' => (float)$saved['light_level'],
            'co2' => (int)$saved['co2'],
            'pressure' => (float)$saved['pressure'],
            'lamp_state' => (int)$saved['lamp_state'],
            'curtains_state' => (int)$saved['curtains_state'],
            'created_at' => $saved['created_at'],
        ],
    ]);
} catch (PDOException $e) {
    error_log('Ошибка БД при сохранении данных: ' . $e->getMessage());
    sensorJsonResponse(500, [
        'success' => false,
        'message' => 'Ошибка базы данных',
    ]);
} catch (Exception $e) {
    error_log('Ошибка сохранения данных: ' . $e->getMessage());
    sensorJsonResponse(400, [
        'success' => false,
        'message' => fitodomikApiErrorMessage($e, 'Некорректные данные датчиков'),
    ]);
}
