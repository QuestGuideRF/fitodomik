<?php
function fitodomikFarmStatusHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('Expires');
    header_remove('Pragma');
    header('Content-Type: application/json; charset=utf-8', true);
    header('Cache-Control: private, no-cache', true);
    header('X-Content-Type-Options: nosniff', true);
}
function fitodomikFarmStatusJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    fitodomikFarmStatusHeaders();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function fitodomikFarmStatusExistingPhoto($filename): ?string
{
    $basename = basename(str_replace('\\', '/', (string)$filename));
    if ($basename === '') {
        return null;
    }
    $baseDir = dirname(__DIR__) . '/uploads/farm_photos';
    $baseReal = realpath($baseDir);
    $targetReal = $baseReal === false ? false : realpath($baseReal . DIRECTORY_SEPARATOR . $basename);
    if (
        $baseReal === false
        || $targetReal === false
        || strpos($targetReal, $baseReal . DIRECTORY_SEPARATOR) !== 0
        || !is_file($targetReal)
        || is_link($targetReal)
    ) {
        return null;
    }
    return $basename;
}
fitodomikFarmStatusHeaders();
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_context.php';
require_once '../config/device_dynamic_config.php';
require_once '../config/headers.php';
fitodomikFarmStatusHeaders();
if (!isset($_SESSION['user_id'])) {
    fitodomikFarmStatusJson(['success' => false, 'message' => 'Не авторизован'], 401);
}
$user_id = (int)$_SESSION['user_id'];
try {
    $selectedDevice = fitodomikRenderedDevice($pdo, $user_id, $_GET, false);
    $device_id = $selectedDevice['device_id'] ?? '';
    list($deviceWhere, $deviceParams) = fitodomikDeviceWhere($device_id);
    $stmt = $pdo->prepare("
        SELECT *
        FROM farm_status
        WHERE user_id = ? {$deviceWhere}
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(array_merge([$user_id], $deviceParams));
    $status = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("
        SELECT temperature, humidity, co2, soil_moisture, light_level, pressure, curtains_state, lamp_state, created_at,
               UNIX_TIMESTAMP(created_at) AS measured_at_unix
        FROM sensor_data
        WHERE user_id = ? {$deviceWhere}
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(array_merge([$user_id], $deviceParams));
    $sensors = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$status && !$sensors) {
        fitodomikFarmStatusJson([
            'success' => false,
            'message' => $device_id === '' ? 'Данные о статусе фермы не найдены' : 'Для выбранного устройства пока нет данных',
            'device_id' => $device_id,
            'device_name' => $selectedDevice['device_name'] ?? null,
        ]);
    }
    $createdAt = $status['created_at'] ?? ($sensors['created_at'] ?? null);
    $measurements = [];
    foreach (['temperature','humidity','co2','soil_moisture','light_level','pressure','curtains_state','lamp_state'] as $key) {
        $value = $sensors[$key] ?? null;
        $measurements[$key] = $value === null ? null : (in_array($key,['curtains_state','lamp_state'],true) ? (bool)$value : (float)$value);
    }
    $response = [
        'success' => true,
        'id' => $status['id'] ?? null,
        'user_id' => $user_id,
        'device_id' => $device_id,
        'device_name' => $selectedDevice['device_name'] ?? null,
        'photo' => fitodomikFarmStatusExistingPhoto($status['photo'] ?? null),
        'photo_analysis' => fitodomikFarmStatusExistingPhoto($status['photo_analysis'] ?? null),
        'comment' => (string)($status['comment'] ?? 'Фото и отчёт анализа для выбранного устройства пока не загружены.'),
        'created_at' => $createdAt,
        'measured_at' => $sensors['created_at'] ?? null,
        'measured_at_unix' => isset($sensors['measured_at_unix']) ? (int)$sensors['measured_at_unix'] : null,
        'measurement_values' => $measurements,
        'dynamic_values' => $device_id !== '' ? fitodomikDynamicLatestValues($pdo, $user_id, $device_id) : [],
        'sensors' => $sensors ? [
            'temperature' => number_format((float)$sensors['temperature'], 1, '.', ''),
            'humidity' => number_format((float)$sensors['humidity'], 1, '.', ''),
            'co2' => (int)$sensors['co2'],
            'soil_moisture' => number_format((float)$sensors['soil_moisture'], 1, '.', ''),
            'light_level' => number_format((float)$sensors['light_level'], 1, '.', ''),
            'pressure' => number_format((float)$sensors['pressure'], 1, '.', ''),
            'curtains_state' => (bool)$sensors['curtains_state'],
            'lamp_state' => (bool)$sensors['lamp_state'],
        ] : null,
    ];
    fitodomikFarmStatusJson($response);
} catch (Exception $e) {
    error_log('Ошибка при получении данных о ферме: ' . $e->getMessage());
    fitodomikFarmStatusJson([
        'success' => false,
        'message' => 'Ошибка при получении данных о ферме',
    ], 500);
}
