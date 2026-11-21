<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['X-Auth-Token', 'X-Device-ID']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_context.php';
function widgetResponse(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    widgetResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    // user_id and the public api_key are deliberately never authentication inputs.
    $auth = fitodomikAuthDeviceRequest($pdo, [], true);
    if (!$auth) widgetResponse(401, ['success' => false, 'message' => 'Нет доступа к устройству']);
    $stmt = $pdo->prepare('SELECT temperature, humidity, soil_moisture, pressure, co2, light_level, lamp_state, curtains_state, created_at FROM sensor_data WHERE user_id = ? AND device_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->execute([(int)$auth['user_id'], $auth['device']['device_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) widgetResponse(404, ['success' => false, 'message' => 'Нет данных устройства']);
    $data = ['device_name' => $auth['device']['device_name'], 'updated_at' => $row['created_at'],
        'is_online' => strtotime($row['created_at']) >= time() - 5400];
    foreach (['temperature' => 'temperature', 'air_humidity' => 'humidity', 'soil_moisture' => 'soil_moisture', 'pressure' => 'pressure', 'co2' => 'co2', 'light' => 'light_level'] as $name => $column) {
        $data[$name] = $row[$column] === null ? null : (float)$row[$column];
    }
    $data['lamp_status'] = (bool)$row['lamp_state'];
    $data['curtains_status'] = (bool)$row['curtains_state'];
    widgetResponse(200, ['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('widget-data.php: ' . $e->getMessage());
    widgetResponse(500, ['success' => false, 'message' => 'Не удалось получить данные']);
}
