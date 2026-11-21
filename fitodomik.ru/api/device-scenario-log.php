<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET', 'POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/device_dynamic_config.php';
try {
    $input = fitodomikDynamicInput();
    $auth = fitodomikDynamicAuth($pdo, $input, true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['event_type']) || isset($input['message']) || isset($input['scenario_id']))) {
        $scenarioId = fitodomikDynamicUid($input['scenario_id'] ?? '', 'manual');
        $payloadJson = fitodomikDynamicJsonEncode($input['payload'] ?? []);
        if (strlen($payloadJson) > 16384) {
            throw new FitodomikInputException('Данные события слишком большие');
        }
        $stmt = $pdo->prepare("
            INSERT INTO device_automation_scenario_log (user_id, device_id, scenario_id, event_type, message, payload_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            (int)$auth['user_id'],
            $auth['device']['device_id'],
            $scenarioId,
            fitodomikDynamicText($input['event_type'] ?? '', 'event', 80),
            fitodomikDynamicText($input['message'] ?? '', '', 1000),
            $payloadJson,
        ]);
        if ($scenarioId !== '' && $scenarioId !== 'manual') {
            $update = $pdo->prepare("UPDATE device_automation_scenarios SET last_triggered_at = NOW() WHERE user_id = ? AND device_id = ? AND scenario_id = ?");
            $update->execute([(int)$auth['user_id'], $auth['device']['device_id'], $scenarioId]);
        }
        $cleanup = $pdo->prepare("
            DELETE FROM device_automation_scenario_log
            WHERE user_id = ?
              AND device_id = ?
              AND (
                  created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                  OR id < COALESCE((
                      SELECT cutoff_id
                      FROM (
                          SELECT id AS cutoff_id
                          FROM device_automation_scenario_log
                          WHERE user_id = ? AND device_id = ?
                          ORDER BY id DESC
                          LIMIT 1 OFFSET 1999
                      ) retained
                  ), 0)
              )
        ");
        $cleanup->execute([
            (int)$auth['user_id'],
            $auth['device']['device_id'],
            (int)$auth['user_id'],
            $auth['device']['device_id'],
        ]);
    }
    $limit = max(1, min(200, (int)($input['limit'] ?? 100)));
    $scenarioId = fitodomikDynamicUid($input['scenario_id'] ?? '', '');
    $params = [(int)$auth['user_id'], $auth['device']['device_id']];
    $where = "user_id = ? AND device_id = ?";
    if ($scenarioId !== '') {
        $where .= " AND scenario_id = ?";
        $params[] = $scenarioId;
    }
    $stmt = $pdo->prepare("
        SELECT scenario_id, event_type, message, payload_json, created_at
        FROM device_automation_scenario_log
        WHERE {$where}
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    fitodomikDynamicJsonResponse(200, [
        'success' => true,
        'device_id' => $auth['device']['device_id'],
        'events' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Exception $e) {
    fitodomikDynamicJsonResponse(400, ['success' => false, 'message' => fitodomikApiErrorMessage($e)]);
}
