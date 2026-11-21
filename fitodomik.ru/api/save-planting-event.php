<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/event_log.php';
require_once __DIR__ . '/../config/json_response.php';
header('Content-Type: application/json');
const SAVE_PLANTING_EVENT_ID_PARAM = ':event_id';
const SAVE_PLANTING_USER_ID_PARAM = ':user_id';
function sendJsonResponse($success, $message, $data = [])
{
    fitodomikJsonMessageResponse($success, $message, $data, JSON_UNESCAPED_UNICODE);
}
function plantingDate($value, $default, $label)
{
    $date = trim((string)$value);
    if ($date === '') {
        $date = $default;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        sendJsonResponse(false, "Некорректная дата: {$label}");
    }
    return $date;
}
function plantingTime($value, $label)
{
    $time = trim((string)$value);
    if ($time === '') {
        return null;
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) {
        sendJsonResponse(false, "Некорректное время: {$label}");
    }
    return $time;
}
function plantingText($value, $maxLength)
{
    $text = trim((string)$value);
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength, 'UTF-8')
        : substr($text, 0, $maxLength);
}
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(false, 'Необходима авторизация');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Неверный метод запроса');
}
fitodomikRequireCsrf(true);
$event_id_to_update = null;
if (isset($_POST['event_id']) && $_POST['event_id'] !== '') {
    $event_id_to_update = filter_var($_POST['event_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($event_id_to_update === false) {
        sendJsonResponse(false, 'Некорректный ID события');
    }
}
$user_id = (int)$_SESSION['user_id'];
$event_type = trim((string)($_POST['event-type'] ?? ''));
$allowedEventTypes = ['planting', 'sprouting', 'watering', 'fertilizing', 'harvesting', 'reminder', 'other'];
if (!in_array($event_type, $allowedEventTypes, true)) {
    sendJsonResponse(false, 'Некорректный тип события');
}
$plant_name = plantingText($_POST['plant-name'] ?? '', 150);
$event_date = plantingDate($_POST['event-date'] ?? '', date('Y-m-d'), 'событие');
$event_time = plantingTime($_POST['event-time'] ?? '', 'событие');
$event_notes = plantingText($_POST['event-notes'] ?? '', 2000);
$has_reminder = ($event_type === 'reminder' || !empty($_POST['reminder-date']));
$reminder_date = null;
$reminder_time = null;
if ($has_reminder) {
    $reminder_date = plantingDate($_POST['reminder-date'] ?? '', date('Y-m-d'), 'напоминание');
    $reminder_time = plantingTime($_POST['reminder-time'] ?? '', 'напоминание');
}
if (empty($event_type) || empty($plant_name) || empty($event_date)) {
    sendJsonResponse(false, 'Пожалуйста, заполните все обязательные поля (Тип события, Название растения, Дата события)');
}
if ($event_type === 'reminder' && empty($reminder_date)) {
    sendJsonResponse(false, 'Для напоминания необходимо указать дату напоминания');
}
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    if ($event_id_to_update) {
        $check_stmt = $pdo->prepare("SELECT id FROM planting_events WHERE id = :event_id AND user_id = :user_id");
        $check_stmt->bindParam(SAVE_PLANTING_EVENT_ID_PARAM, $event_id_to_update, PDO::PARAM_INT);
        $check_stmt->bindParam(SAVE_PLANTING_USER_ID_PARAM, $user_id, PDO::PARAM_INT);
        $check_stmt->execute();
        if (!$check_stmt->fetchColumn()) {
            throw new FitodomikNotFoundException("Событие для редактирования не найдено или не принадлежит вам.");
        }
        $update_event_sql = "UPDATE planting_events SET
                                type = :type,
                                plant_name = :plant_name,
                                event_date = :event_date,
                                event_time = :event_time,
                                notes = :notes
                             WHERE id = :event_id AND user_id = :user_id";
        $stmt = $pdo->prepare($update_event_sql);
        $stmt->bindParam(':type', $event_type);
        $stmt->bindParam(':plant_name', $plant_name);
        $stmt->bindParam(':event_date', $event_date);
        $stmt->bindParam(':event_time', $event_time, $event_time === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindParam(':notes', $event_notes);
        $stmt->bindParam(SAVE_PLANTING_EVENT_ID_PARAM, $event_id_to_update, PDO::PARAM_INT);
        $stmt->bindParam(SAVE_PLANTING_USER_ID_PARAM, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $delete_reminder_stmt = $pdo->prepare("DELETE FROM planting_reminders WHERE event_id = :event_id");
        $delete_reminder_stmt->bindParam(SAVE_PLANTING_EVENT_ID_PARAM, $event_id_to_update, PDO::PARAM_INT);
        $delete_reminder_stmt->execute();
        if ($event_type === 'reminder' && $reminder_date) {
            $insert_reminder_stmt = $pdo->prepare("INSERT INTO planting_reminders
                (event_id, reminder_date, reminder_time)
                VALUES (:event_id, :reminder_date, :reminder_time)");
            $insert_reminder_stmt->bindParam(SAVE_PLANTING_EVENT_ID_PARAM, $event_id_to_update, PDO::PARAM_INT);
            $insert_reminder_stmt->bindParam(':reminder_date', $reminder_date);
            $insert_reminder_stmt->bindParam(':reminder_time', $reminder_time, $reminder_time === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert_reminder_stmt->execute();
        }
        $final_event_id = $event_id_to_update;
        $success_message = 'Событие успешно обновлено';
    } else {
        $stmt = $pdo->prepare("INSERT INTO planting_events
            (user_id, type, plant_name, event_date, event_time, notes)
            VALUES (:user_id, :type, :plant_name, :event_date, :event_time, :notes)");
        $stmt->bindParam(SAVE_PLANTING_USER_ID_PARAM, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':type', $event_type);
        $stmt->bindParam(':plant_name', $plant_name);
        $stmt->bindParam(':event_date', $event_date);
        $stmt->bindParam(':event_time', $event_time, $event_time === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindParam(':notes', $event_notes);
        $stmt->execute();
        $final_event_id = $pdo->lastInsertId();
        if ($event_type === 'reminder' && $reminder_date) {
            $stmt = $pdo->prepare("INSERT INTO planting_reminders
                (event_id, reminder_date, reminder_time)
                VALUES (:event_id, :reminder_date, :reminder_time)");
            $stmt->bindParam(SAVE_PLANTING_EVENT_ID_PARAM, $final_event_id, PDO::PARAM_INT);
            $stmt->bindParam(':reminder_date', $reminder_date);
            $stmt->bindParam(':reminder_time', $reminder_time, $reminder_time === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
        }
        $success_message = 'Событие успешно сохранено';
    }
    $log_description = ($event_id_to_update ? 'Обновлено событие' : 'Создано новое событие') . ': ' . $plant_name . ' на ' . $event_date;
    fitodomikLogEventOnce($pdo, $user_id, 'device', $log_description);
    $pdo->commit();
    sendJsonResponse(true, $success_message, ['event_id' => $final_event_id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка при сохранении события: ' . $e->getMessage());
    sendJsonResponse(false, 'Ошибка базы данных при сохранении события');
} catch (Exception $e) {
    error_log('Общая ошибка при сохранении события: ' . $e->getMessage());
    sendJsonResponse(false, fitodomikApiErrorMessage($e, 'Не удалось сохранить событие'));
}
