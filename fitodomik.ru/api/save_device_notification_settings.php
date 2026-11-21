<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Device-ID', 'X-Device-Token', 'X-Auth-Token', 'X-CSRF-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/headers.php';
require_once __DIR__ . '/device-pair-common.php';
require_once '../config/session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    devicePairJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
function boolInput($input, $key, $default = false)
{
    if (!array_key_exists($key, $input)) {
        return $default ? 1 : 0;
    }
    $value = $input[$key];
    return ($value === true || $value === '1' || $value === 'on' || $value === 1) ? 1 : 0;
}
$input = devicePairInput();
try {
    $auth = fitodomikAuthDeviceRequest($pdo, $input, true);
    $authMode = is_array($auth) ? (string)($auth['auth_mode'] ?? '') : '';
    if (!$auth && isset($_SESSION['user_id'])) {
        fitodomikRequireCsrf(true);
        $device_id = trim((string)($input['device_id'] ?? ''));
        $stmt = $pdo->prepare("
            SELECT device_id, device_token, user_id, device_name, status
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([(int)$_SESSION['user_id'], $device_id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($device) {
            $auth = [
                'user_id' => (int)$_SESSION['user_id'],
                'device' => $device,
                'auth_mode' => 'session',
            ];
            $authMode = 'session';
        }
    }
    if (!$auth) {
        devicePairJsonResponse(401, ['success' => false, 'message' => 'Нет доступа к устройству']);
    }
    $user_id = (int)$auth['user_id'];
    $device = $auth['device'];
    $chat_id = null;
    if (isset($input['telegram_chat_id'])) {
        if (!in_array($authMode, ['session', 'account_token'], true)) {
            devicePairJsonResponse(403, ['success' => false, 'message' => 'Токен устройства не может менять привязку Telegram']);
        }
        $chat_id = trim((string)$input['telegram_chat_id']);
        if ($chat_id !== '' && preg_match('/^-?\d{1,20}$/', $chat_id) !== 1) {
            devicePairJsonResponse(422, ['success' => false, 'message' => 'Некорректный Telegram chat ID']);
        }
    }
    $pdo->beginTransaction();
    $currentSettings = fitodomikGetOrCreateNotificationSettings($pdo, $user_id, $device['device_id']);
    $input += $currentSettings;
    fitodomikGetOrCreateTelegramSettings($pdo, $user_id);
    $daily_time = trim((string)($input['daily_report_time'] ?? '20:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $daily_time)) {
        $daily_time = '20:00';
    }
    $check_interval = max(1, min(1440, (int)($input['alert_check_interval_minutes'] ?? 60)));
    $cooldown = max(1, min(10080, (int)($input['alert_repeat_cooldown_minutes'] ?? 180)));
    $stmt = $pdo->prepare("
        UPDATE device_notification_settings
        SET telegram_enabled = ?,
            notify_soil_moisture = ?,
            notify_temperature = ?,
            notify_camera = ?,
            send_photos_telegram = ?,
            send_daily_report = ?,
            daily_report_time = ?,
            alert_check_interval_minutes = ?,
            alert_repeat_cooldown_minutes = ?
        WHERE user_id = ? AND device_id = ?
    ");
    $stmt->execute([
        boolInput($input, 'telegram_enabled', true),
        boolInput($input, 'notify_soil_moisture', true),
        boolInput($input, 'notify_temperature', true),
        boolInput($input, 'notify_camera', true),
        boolInput($input, 'send_photos_telegram', false),
        boolInput($input, 'send_daily_report', true),
        $daily_time,
        $check_interval,
        $cooldown,
        $user_id,
        $device['device_id'],
    ]);
    if ($chat_id !== null) {
        $stmt = $pdo->prepare("UPDATE user_telegram_settings SET telegram_chat_id = ?, enabled = 1 WHERE user_id = ?");
        $stmt->execute([$chat_id, $user_id]);
    }
    if (isset($input['alert_rules']) && is_array($input['alert_rules'])) {
        fitodomikSaveDeviceAlertRules($pdo, $user_id, $device['device_id'], $input['alert_rules']);
    }
    $pdo->commit();
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => 'Настройки уведомлений сохранены',
        'device_id' => $device['device_id'],
    ]);
} catch (FitodomikInputException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    devicePairJsonResponse(422, ['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('save_device_notification_settings.php: ' . $e->getMessage());
    devicePairJsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
