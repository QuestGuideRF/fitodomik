<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/device_context.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Метод не разрешён']);
    exit;
}
fitodomikRequireCsrf(true);
$user_id = (int)$_SESSION['user_id'];
try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    $device = fitodomikRenderedDevice($pdo, $user_id, is_array($input) ? $input : $_POST);
    $selectedDeviceId = $device['device_id'];
    if ($selectedDeviceId === '') {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Устройство не выбрано']);
        exit;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM event_log WHERE user_id = :user_id AND device_id = :device_id');
    $stmt->execute([
        ':user_id' => $user_id,
        ':device_id' => $selectedDeviceId,
    ]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Журнал событий успешно очищен']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка при очистке журнала: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных при очистке журнала']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Общая ошибка при очистке журнала: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка при очистке журнала']);
}
