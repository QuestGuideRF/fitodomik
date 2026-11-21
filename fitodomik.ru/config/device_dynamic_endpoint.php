<?php
require_once __DIR__ . '/device_dynamic_config.php';
function fitodomikRunDynamicCrudEndpoint(PDO $pdo, string $entity, string $operation): void
{
    static $entities = [
        'sensor' => [
            'uid' => 'sensor_uid', 'collection' => 'sensors',
            'save' => 'fitodomikDynamicUpsertSensor', 'list' => 'fitodomikDynamicListSensors',
            'delete_sql' => 'DELETE FROM device_sensor_definitions WHERE user_id = ? AND device_id = ? AND sensor_uid = ?',
            'saved' => 'Датчик сохранён', 'deleted' => 'Датчик удалён', 'missing' => 'Датчик не найден',
        ],
        'relay' => [
            'uid' => 'relay_uid', 'collection' => 'relays',
            'save' => 'fitodomikDynamicUpsertRelay', 'list' => 'fitodomikDynamicListRelays',
            'delete_sql' => 'DELETE FROM device_relay_definitions WHERE user_id = ? AND device_id = ? AND relay_uid = ?',
            'saved' => 'Реле сохранено', 'deleted' => 'Реле удалено', 'missing' => 'Реле не найдено',
        ],
        'scenario' => [
            'uid' => 'scenario_id', 'collection' => 'scenarios',
            'save' => 'fitodomikDynamicUpsertScenario', 'list' => 'fitodomikDynamicListScenarios',
            'delete_sql' => 'DELETE FROM device_automation_scenarios WHERE user_id = ? AND device_id = ? AND scenario_id = ?',
            'saved' => 'Сценарий сохранён', 'deleted' => 'Сценарий удалён', 'missing' => 'Сценарий не найден',
        ],
    ];
    if (!isset($entities[$entity]) || !in_array($operation, ['save', 'list', 'delete'], true)) {
        throw new InvalidArgumentException('Unknown dynamic CRUD route');
    }
    $definition = $entities[$entity];
    if ($operation === 'delete' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        fitodomikDynamicJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
    }
    try {
        $input = fitodomikDynamicInput();
        $auth = fitodomikDynamicAuth($pdo, $input, true);
        $userId = (int)$auth['user_id'];
        $deviceId = $auth['device']['device_id'];
        if ($operation === 'list') {
            $includeDisabled = fitodomikDynamicBool($input['include_disabled'] ?? null, true) === 1;
            fitodomikDynamicJsonResponse(200, [
                'success' => true,
                'device_id' => $deviceId,
                $definition['collection'] => $definition['list']($pdo, $userId, $deviceId, $includeDisabled),
            ]);
        }
        if ($operation === 'save') {
            $uid = $definition['save']($pdo, $userId, $deviceId, $input[$entity] ?? $input);
            fitodomikDynamicJsonResponse(200, ['success' => true, 'message' => $definition['saved'], $definition['uid'] => $uid]);
        }
        $uid = fitodomikDynamicUid($input[$definition['uid']] ?? $input['id'] ?? '', '');
        if ($uid === '') {
            throw new FitodomikInputException($definition['uid'] . ' пустой');
        }
        $stmt = $pdo->prepare($definition['delete_sql']);
        $stmt->execute([$userId, $deviceId, $uid]);
        if ($stmt->rowCount() !== 1) {
            throw new FitodomikNotFoundException($definition['missing']);
        }
        fitodomikDynamicJsonResponse(200, ['success' => true, 'message' => $definition['deleted']]);
    } catch (Exception $e) {
        fitodomikDynamicJsonResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
    }
}
