<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once __DIR__ . '/../config/event_log.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Неверный метод запроса']);
    exit;
}
fitodomikRequireCsrf(true);
$input = json_decode(file_get_contents('php://input'), true);
$modeId = $input['mode_id'] ?? 0;
$modeId = intval($modeId);
if (!$modeId) {
    echo json_encode(['success' => false, 'message' => 'Неверный ID режима']);
    exit;
}
$user_id = $_SESSION['user_id'];
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM preset_modes WHERE id = ? AND user_id = ?");
    $stmt->execute([$modeId, $user_id]);
    $mode = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mode) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Режим не найден или вам не принадлежит']);
        exit;
    }
    if (!empty($mode['share_code'])) {
        $pdo->commit();
        echo json_encode(['success' => true, 'share_code' => $mode['share_code']]);
        exit;
    }
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $shareCode = '';
    for ($i = 0; $i < 8; $i++) {
        $shareCode .= $characters[random_int(0, $charactersLength - 1)];
    }
    $codeExists = true;
    while ($codeExists) {
        $check = $pdo->prepare("SELECT id FROM preset_modes WHERE share_code = ?");
        $check->execute([$shareCode]);
        if (!$check->fetch()) {
            $codeExists = false;
        } else {
            $shareCode = '';
            for ($i = 0; $i < 8; $i++) {
                $shareCode .= $characters[random_int(0, $charactersLength - 1)];
            }
        }
    }
    $updateStmt = $pdo->prepare("UPDATE preset_modes SET share_code = ? WHERE id = ?");
    $updateStmt->execute([$shareCode, $modeId]);
    fitodomikLogEventOnce($pdo, $user_id, 'system', 'Предоставлен доступ к режиму: ' . $mode['name']);
    $pdo->commit();
    echo json_encode(['success' => true, 'share_code' => $shareCode]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка при создании кода доступа: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных при создании кода доступа']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Общая ошибка: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => fitodomikApiErrorMessage($e, 'Не удалось создать код доступа')]);
}
