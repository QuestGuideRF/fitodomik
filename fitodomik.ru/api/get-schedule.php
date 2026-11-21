<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['GET'], ['X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
require_once '../config/error_handling.php';
require_once '../config/database.php';
require_once '../config/device_context.php';
header('Content-Type: application/json');
function logMessage($message)
{
    $log_dir = dirname(__FILE__) . '/../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    error_log("[" . date("Y-m-d H:i:s") . "] get-schedule.php: " . $message . "\n", 3, $log_dir . "/api_activity.log");
}
try {
    $auth = fitodomikAuthDeviceRequest($pdo, [], true);
    $headers = getallheaders();
    $token = isset($headers['X-Auth-Token']) ? $headers['X-Auth-Token'] : '';
    if (!$auth && empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Отсутствует токен авторизации']);
        exit;
    }
    if ($auth) {
        $user = ['id' => (int)$auth['user_id']];
    } else {
        $user = fitodomikUserByToken($pdo, $token);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Неверный токен авторизации']);
            exit;
        }
    }
    $user_id = $user['id'];
    $deviceId = $auth ? (string)$auth['device']['device_id'] : fitodomikGetSelectedDeviceId($pdo, (int)$user_id);
    if ($deviceId === '') {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Устройство не выбрано']);
        exit;
    }
    logMessage("Запрос расписания для пользователя ID: " . $user_id);
    $stmt = $pdo->prepare("
        SELECT time, curtains_schedule, lighting_schedule
        FROM schedule
        WHERE user_id = ? AND device_id = ?
        ORDER BY CASE
            WHEN time LIKE '0:%' THEN 0
            WHEN time LIKE '1:%' THEN 1
            WHEN time LIKE '2:%' THEN 2
            WHEN time LIKE '3:%' THEN 3
            WHEN time LIKE '4:%' THEN 4
            WHEN time LIKE '5:%' THEN 5
            WHEN time LIKE '6:%' THEN 6
            WHEN time LIKE '7:%' THEN 7
            WHEN time LIKE '8:%' THEN 8
            WHEN time LIKE '9:%' THEN 9
            WHEN time LIKE '10:%' THEN 10
            WHEN time LIKE '11:%' THEN 11
            WHEN time LIKE '12:%' THEN 12
            WHEN time LIKE '13:%' THEN 13
            WHEN time LIKE '14:%' THEN 14
            WHEN time LIKE '15:%' THEN 15
            WHEN time LIKE '16:%' THEN 16
            WHEN time LIKE '17:%' THEN 17
            WHEN time LIKE '18:%' THEN 18
            WHEN time LIKE '19:%' THEN 19
            WHEN time LIKE '20:%' THEN 20
            WHEN time LIKE '21:%' THEN 21
            WHEN time LIKE '22:%' THEN 22
            WHEN time LIKE '23:%' THEN 23
            ELSE 24
        END
    ");
    $stmt->execute([$user_id, $deviceId]);
    $scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $formattedData = [];
    $curtainsActiveHours = 0;
    $lightingActiveHours = 0;
    foreach ($scheduleData as $row) {
        $hour = explode('-', $row['time'])[0];
        if ($row['curtains_schedule'] == 1) {
            $curtainsActiveHours++;
        }
        if ($row['lighting_schedule'] == 1) {
            $lightingActiveHours++;
        }
        $formattedData[] = [
            'hour' => $hour,
            'time_interval' => $row['time'],
            'curtains_active' => (bool)$row['curtains_schedule'],
            'lighting_active' => (bool)$row['lighting_schedule']
        ];
    }
    $response = [
        'success' => true,
        'message' => 'Данные расписания успешно получены',
        'data' => [
            'schedule' => $formattedData,
            'summary' => [
                'curtains_active_hours' => $curtainsActiveHours,
                'lighting_active_hours' => $lightingActiveHours
            ]
        ]
    ];
    logMessage("Отправлен ответ с расписанием для пользователя ID: " . $user_id);
    echo json_encode($response);
} catch (PDOException $e) {
    logMessage("PDO ошибка: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных'
    ]);
} catch (Exception $e) {
    logMessage("Общая ошибка: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера'
    ]);
}
