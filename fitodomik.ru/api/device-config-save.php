<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_dynamic_config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fitodomikDynamicJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
try {
    $input = fitodomikDynamicInput();
    $auth = fitodomikDynamicAuth($pdo, $input, true);
    $userId = (int)$auth['user_id'];
    $deviceId = $auth['device']['device_id'];
    $sensors = $input['device_sensors'] ?? $input['sensors'] ?? [];
    $relays = $input['device_relays'] ?? $input['relays'] ?? [];
    $scenarios = $input['scenarios'] ?? $input['automation_scenarios'] ?? [];
    if (!is_array($sensors) || !is_array($relays) || !is_array($scenarios)) {
        throw new FitodomikInputException('Конфигурация должна быть массивом');
    }
    if (count($sensors) > 100 || count($relays) > 64 || count($scenarios) > 100) {
        throw new FitodomikInputException('Конфигурация превышает допустимый размер');
    }
    $pdo->beginTransaction();
    $sensorUids = [];
    foreach ($sensors as $sensor) {
        if (!is_array($sensor)) {
            throw new FitodomikInputException('Описание датчика должно быть объектом');
        }
        $sensorUids[] = fitodomikDynamicUpsertSensor($pdo, $userId, $deviceId, $sensor);
    }
    if ($sensorUids) {
        $placeholders = implode(',', array_fill(0, count($sensorUids), '?'));
        $stmt = $pdo->prepare("DELETE FROM device_sensor_definitions WHERE user_id = ? AND device_id = ? AND sensor_uid NOT IN ({$placeholders})");
        $stmt->execute(array_merge([$userId, $deviceId], $sensorUids));
    } else {
        $stmt = $pdo->prepare("DELETE FROM device_sensor_definitions WHERE user_id = ? AND device_id = ?");
        $stmt->execute([$userId, $deviceId]);
    }
    $relayUids = [];
    foreach ($relays as $relay) {
        if (!is_array($relay)) {
            throw new FitodomikInputException('Описание реле должно быть объектом');
        }
        $relayUids[] = fitodomikDynamicUpsertRelay($pdo, $userId, $deviceId, $relay);
    }
    if ($relayUids) {
        $placeholders = implode(',', array_fill(0, count($relayUids), '?'));
        $stmt = $pdo->prepare("DELETE FROM device_relay_definitions WHERE user_id = ? AND device_id = ? AND relay_uid NOT IN ({$placeholders})");
        $stmt->execute(array_merge([$userId, $deviceId], $relayUids));
    } else {
        $stmt = $pdo->prepare("DELETE FROM device_relay_definitions WHERE user_id = ? AND device_id = ?");
        $stmt->execute([$userId, $deviceId]);
    }
    $scenarioUids = [];
    foreach ($scenarios as $scenario) {
        if (!is_array($scenario)) {
            throw new FitodomikInputException('Описание сценария должно быть объектом');
        }
        $scenarioUids[] = fitodomikDynamicUpsertScenario($pdo, $userId, $deviceId, $scenario);
    }
    if ($scenarioUids) {
        $placeholders = implode(',', array_fill(0, count($scenarioUids), '?'));
        $stmt = $pdo->prepare("DELETE FROM device_automation_scenarios WHERE user_id = ? AND device_id = ? AND scenario_id NOT IN ({$placeholders})");
        $stmt->execute(array_merge([$userId, $deviceId], $scenarioUids));
    } else {
        $stmt = $pdo->prepare("DELETE FROM device_automation_scenarios WHERE user_id = ? AND device_id = ?");
        $stmt->execute([$userId, $deviceId]);
    }
    $pdo->commit();
    fitodomikDynamicJsonResponse(200, [
        'success' => true,
        'message' => 'Конфигурация устройства сохранена',
        'device_id' => $deviceId,
        'device_sensors' => fitodomikDynamicListSensors($pdo, $userId, $deviceId, true),
        'device_relays' => fitodomikDynamicListRelays($pdo, $userId, $deviceId, true),
        'scenarios' => fitodomikDynamicListScenarios($pdo, $userId, $deviceId, true),
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('device-config-save.php: ' . $e->getMessage());
    fitodomikDynamicJsonResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
