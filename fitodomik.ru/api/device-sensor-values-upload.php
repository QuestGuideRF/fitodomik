<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_dynamic_config.php';
try {
    $input = fitodomikDynamicInput();
    $auth = fitodomikDynamicAuth($pdo, $input, true);
    $values = $input['values'] ?? [];
    if (!is_array($values) || array_keys($values) !== range(0, count($values) - 1)) {
        $values = [$input];
    }
    if (count($values) > 200) {
        throw new FitodomikInputException('За один запрос можно сохранить не более 200 значений');
    }
    $configuredSensors = fitodomikDynamicListSensors(
        $pdo,
        (int)$auth['user_id'],
        $auth['device']['device_id'],
        true
    );
    $configuredUids = array_fill_keys(array_column($configuredSensors, 'sensor_uid'), true);
    $stmt = $pdo->prepare("
        INSERT INTO device_sensor_values (user_id, device_id, sensor_uid, `value`, raw_value, unit, measured_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), NOW())
    ");
    $saved = 0;
    $pdo->beginTransaction();
    foreach ($values as $item) {
        if (!is_array($item)) {
            throw new FitodomikInputException('Некорректная запись датчика');
        }
        $uid = fitodomikDynamicUid($item['sensor_uid'] ?? $item['id'] ?? '', '');
        if ($uid === '') {
            throw new FitodomikInputException('Не указан sensor_uid');
        }
        if (!isset($configuredUids[$uid])) {
            throw new FitodomikAuthorizationException('Датчик не принадлежит выбранному устройству');
        }
        $rawValue = $item['raw_value'] ?? $item['raw'] ?? $item['value'] ?? null;
        $numericValue = is_numeric($item['value'] ?? null) ? (float)$item['value'] : null;
        $unit = fitodomikDynamicText($item['unit'] ?? '', '', 32);
        $measuredAt = trim((string)($item['measured_at'] ?? $item['timestamp'] ?? ''));
        $stmt->execute([
            (int)$auth['user_id'],
            $auth['device']['device_id'],
            $uid,
            $numericValue,
            $rawValue === null ? null : fitodomikDynamicText($rawValue, '', 160),
            $unit,
            $measuredAt !== '' ? $measuredAt : null,
        ]);
        $saved++;
    }
    $pdo->commit();
    fitodomikDynamicJsonResponse(200, [
        'success' => true,
        'message' => 'Значения датчиков сохранены',
        'saved' => $saved,
        'device_id' => $auth['device']['device_id'],
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('device-sensor-values-upload.php: ' . $e->getMessage());
    fitodomikDynamicJsonResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
