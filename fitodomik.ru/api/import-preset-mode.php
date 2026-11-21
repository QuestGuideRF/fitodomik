<?php
require_once __DIR__ . '/../config/api_contract.php';
require_once __DIR__ . '/../config/preset_import.php';
require_once "../config/database.php";
require_once '../config/headers.php';
require_once '../config/session.php';
header("Content-Type: application/json");
if (!isset($_SESSION['user_id'])) {
    fitodomikContractResponse(['success' => false, 'message' => 'Требуется авторизация'],401,null,'unauthorized');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fitodomikContractResponse(['success' => false, 'message' => 'Неверный метод запроса'],405,null,'method_not_allowed');
}
if (fitodomikUsesContractV2() && !fitodomikVerifyCsrf()) {
    fitodomikContractResponse(['success'=>false,'message'=>'Недействительный CSRF-токен'],403,null,'csrf_failed');
}
fitodomikRequireCsrf(true);
$input = json_decode(file_get_contents('php://input'), true);
$code = is_array($input) && is_string($input['code'] ?? null) ? $input['code'] : '';
if ($code === '' || strlen($code) !== 8) {
    header('Content-Type: application/json');
    fitodomikContractResponse(['success' => false, 'message' => 'Недействительный код импорта. Код должен содержать 8 символов.'],422,null,'invalid_code');
}
$user_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT * FROM preset_modes WHERE share_code = ?");
    $stmt->execute([$code]);
    $mode = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mode) {
        fitodomikContractResponse(['success' => false, 'message' => 'Режим с указанным кодом не найден'],404,null,'preset_not_found');
    }
    if ($mode['user_id'] == $user_id) {
        fitodomikContractResponse(['success' => false, 'message' => 'Вы не можете импортировать свой собственный режим'],422,null,'own_preset');
    }
    $newPresetId = fitodomikImportPreset($pdo, (int)$user_id, $mode);
    fitodomikContractResponse([
        'success' => true,
        'message' => 'Режим успешно импортирован',
        'presetId' => (string)$newPresetId,
        'mode_id' => $newPresetId
    ],200,['mode_id'=>(int)$newPresetId]);
} catch (PDOException $e) {
    error_log('Ошибка при импорте режима: ' . $e->getMessage());
    fitodomikContractResponse(['success' => false, 'message' => 'Ошибка базы данных при импорте режима'],500,null,'database_error');
} catch (Exception $e) {
    error_log('Общая ошибка: ' . $e->getMessage());
    fitodomikContractResponse(['success' => false, 'message' => 'Произошла ошибка при импорте режима'],500,null,'internal_error');
}
