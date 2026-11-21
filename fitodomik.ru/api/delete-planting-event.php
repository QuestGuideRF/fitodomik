<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/event_log.php';
require_once __DIR__ . '/../config/json_response.php';
header('Content-Type: application/json');
const DELETE_PLANTING_EVENT_ID_PARAM = ':event_id';
if (!isset($_SESSION['user_id'])) {
    fitodomikJsonMessageResponse(false, 'Необходима авторизация');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fitodomikJsonMessageResponse(false, 'Метод не поддерживается');
}
fitodomikRequireCsrf(true);
$input_data = json_decode((string)file_get_contents('php://input'), true);
$event_id = is_array($input_data)
    ? filter_var($input_data['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$user_id = (int)$_SESSION['user_id'];
if ($event_id === false) {
    fitodomikJsonMessageResponse(false, 'Неверный ID события');
}
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $event_info_sql = "SELECT type, plant_name FROM planting_events WHERE id = :event_id AND user_id = :user_id";
    $event_info_stmt = $pdo->prepare($event_info_sql);
    $event_info_stmt->bindParam(DELETE_PLANTING_EVENT_ID_PARAM, $event_id, PDO::PARAM_INT);
    $event_info_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $event_info_stmt->execute();
    $event_info = $event_info_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event_info) {
        $pdo->rollBack();
        fitodomikJsonMessageResponse(false, 'Событие не найдено или не принадлежит пользователю');
    }
    $delete_reminders_sql = "DELETE FROM planting_reminders WHERE event_id = :event_id";
    $delete_reminders_stmt = $pdo->prepare($delete_reminders_sql);
    $delete_reminders_stmt->bindParam(DELETE_PLANTING_EVENT_ID_PARAM, $event_id, PDO::PARAM_INT);
    $delete_reminders_stmt->execute();
    $delete_event_sql = "DELETE FROM planting_events WHERE id = :event_id AND user_id = :user_id";
    $delete_event_stmt = $pdo->prepare($delete_event_sql);
    $delete_event_stmt->bindParam(DELETE_PLANTING_EVENT_ID_PARAM, $event_id, PDO::PARAM_INT);
    $delete_event_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $delete_event_stmt->execute();
    $log_description = "Удалено событие посадки: " . $event_info['plant_name'] . " (тип: " . $event_info['type'] . ")";
    fitodomikLogEventOnce($pdo, $user_id, 'device', $log_description);
    $pdo->commit();
    fitodomikJsonMessageResponse(true, 'Событие успешно удалено');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка при удалении события: ' . $e->getMessage());
    fitodomikJsonMessageResponse(false, 'Ошибка базы данных при удалении события');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Общая ошибка при удалении события: ' . $e->getMessage());
    fitodomikJsonMessageResponse(false, fitodomikApiErrorMessage($e, 'Не удалось удалить событие'));
}
