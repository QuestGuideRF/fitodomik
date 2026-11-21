<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/event_log.php';
require_once __DIR__ . '/../config/json_response.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    fitodomikJsonMessageResponse(false, 'Необходима авторизация');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fitodomikJsonMessageResponse(false, 'Метод не поддерживается');
}
fitodomikRequireCsrf(true);
$input_data = json_decode((string)file_get_contents('php://input'), true);
$mode_id = is_array($input_data)
    ? filter_var($input_data['mode_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$user_id = (int)$_SESSION['user_id'];
if ($mode_id === false) {
    fitodomikJsonMessageResponse(false, 'Неверный ID пресета');
}
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $get_mode_sql = "SELECT name FROM preset_modes WHERE id = :mode_id AND user_id = :user_id";
    $get_mode_stmt = $pdo->prepare($get_mode_sql);
    $get_mode_stmt->bindParam(':mode_id', $mode_id, PDO::PARAM_INT);
    $get_mode_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $get_mode_stmt->execute();
    $mode_info = $get_mode_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mode_info) {
        $pdo->rollBack();
        fitodomikJsonMessageResponse(false, 'Пресет не найден или не принадлежит пользователю');
    }
    $delete_sql = "DELETE FROM preset_modes WHERE id = :mode_id AND user_id = :user_id";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_stmt->bindParam(':mode_id', $mode_id, PDO::PARAM_INT);
    $delete_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $delete_stmt->execute();
    fitodomikLogEventOnce($pdo, $user_id, 'settings', 'Удален пресет: ' . $mode_info['name']);
    $pdo->commit();
    fitodomikJsonMessageResponse(true, 'Пресет успешно удален');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка при удалении пресета: ' . $e->getMessage());
    fitodomikJsonMessageResponse(false, 'Ошибка базы данных при удалении пресета');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Общая ошибка при удалении пресета: ' . $e->getMessage());
    fitodomikJsonMessageResponse(false, fitodomikApiErrorMessage($e, 'Не удалось удалить пресет'));
}
