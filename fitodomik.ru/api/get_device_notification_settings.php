<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Device-ID', 'X-Device-Token', 'X-Auth-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/headers.php';
require_once __DIR__ . '/device-pair-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    devicePairJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
$input = devicePairInput();
try {
    $auth = fitodomikAuthDeviceRequest($pdo, $input, true);
    if (!$auth) {
        devicePairJsonResponse(401, ['success' => false, 'message' => 'Неверный токен устройства']);
    }
    $user_id = (int)$auth['user_id'];
    $device = $auth['device'];
    $settings = fitodomikGetOrCreateNotificationSettings($pdo, $user_id, $device['device_id']);
    $telegram = fitodomikGetOrCreateTelegramSettings($pdo, $user_id);
    $alert_rules = fitodomikGetDeviceAlertRules($pdo, $user_id, $device['device_id']);
    devicePairJsonResponse(200, [
        'success' => true,
        'device_id' => $device['device_id'],
        'device_name' => $device['device_name'],
        'telegram' => [
            'chat_id' => (string)($telegram['telegram_chat_id'] ?? ''),
            'enabled' => (bool)$settings['telegram_enabled'] && (bool)$telegram['enabled'],
            'send_alerts' => (bool)$settings['telegram_enabled'],
            'send_photos' => (bool)$settings['send_photos_telegram'],
            'send_daily_report' => (bool)$settings['send_daily_report'],
            'daily_report_time' => $settings['daily_report_time'],
            'check_interval_minutes' => (int)$settings['alert_check_interval_minutes'],
            'alert_repeat_cooldown_minutes' => (int)$settings['alert_repeat_cooldown_minutes'],
        ],
        'notification_flags' => [
            'notify_soil_moisture' => (bool)$settings['notify_soil_moisture'],
            'notify_temperature' => (bool)$settings['notify_temperature'],
            'notify_camera' => (bool)$settings['notify_camera'],
        ],
        'alert_rules' => $alert_rules,
    ]);
} catch (Exception $e) {
    error_log('get_device_notification_settings.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
