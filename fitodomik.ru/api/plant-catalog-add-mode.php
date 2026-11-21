<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/device_context.php';
require_once __DIR__ . '/../includes/plant_catalog.php';
function fitodomikCatalogClampDecimal($value, float $maximum): float
{
    return min($maximum, max(-$maximum, (float)$value));
}
function fitodomikCatalogExistingMode(
    PDO $pdo,
    int $userId,
    int $plantId
): ?array {
    $stmt = $pdo->prepare('SELECT id, name FROM preset_modes WHERE user_id = ? AND source_plant_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$userId, $plantId]);
    $mode = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($mode) ? $mode : null;
}
function fitodomikCatalogInsertMode(
    PDO $pdo,
    int $userId,
    int $plantId,
    ?string $deviceId,
    array $mode
): int {
    $values = [
        'user_id' => $userId,
        'name' => $mode['name'],
        'temperature' => $mode['temperature'],
        'tolerance' => fitodomikCatalogClampDecimal($mode['tolerance'], 9.9),
        'humidity' => $mode['humidity'],
        'humidity_tolerance' => fitodomikCatalogClampDecimal(
            $mode['humidity_tolerance'],
            9.9
        ),
        'light_hours' => $mode['light_hours'],
        'light_start' => $mode['light_start'],
        'light_end' => $mode['light_end'],
        'source_plant_id' => $plantId,
        'source_device_id' => $deviceId,
        'soil_moisture' => $mode['soil_moisture'],
        'soil_moisture_tolerance' => fitodomikCatalogClampDecimal($mode['soil_moisture_tolerance'], 999.9),
        'lamp_hours_json' => $mode['lamp_hours_json'],
        'curtain_schedule' => null,
    ];
    $stmt = $pdo->prepare('INSERT INTO preset_modes
        (user_id, name, temperature, tolerance, humidity, humidity_tolerance,
         light_hours, light_start, light_end, source_plant_id, source_device_id,
         soil_moisture, soil_moisture_tolerance, lamp_hours_json, curtain_schedule)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(array_values($values));
    $modeId = (int)$pdo->lastInsertId();
    if ($modeId < 1) {
        $lookup = $pdo->prepare('SELECT id FROM preset_modes WHERE user_id = ? AND name = ? ORDER BY id DESC LIMIT 1');
        $lookup->execute([$userId, $mode['name']]);
        $modeId = (int)$lookup->fetchColumn();
    }
    return $modeId;
}
if (!isset($_SESSION['user_id'])) {
    fitodomikPlantCatalogJsonResponse(401, ['success' => false, 'message' => 'Войдите, чтобы добавить режим']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fitodomikPlantCatalogJsonResponse(405, ['success' => false, 'message' => 'Метод не поддерживается']);
}
fitodomikRequireCsrf(true);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    fitodomikPlantCatalogJsonResponse(400, ['success' => false, 'message' => 'Некорректный формат данных']);
}
$slug = trim((string)($data['plant'] ?? ''));
$duplicateAction = trim((string)($data['duplicate_action'] ?? ''));
if (!in_array($duplicateAction, ['', 'open', 'copy'], true)) {
    fitodomikPlantCatalogJsonResponse(400, ['success' => false, 'message' => 'Некорректное действие с копией']);
}
$plant = fitodomikPlantCatalogItem($pdo, $slug);
if ($plant === null) {
    fitodomikPlantCatalogJsonResponse(404, ['success' => false, 'message' => 'Растение не найдено']);
}
$userId = (int)$_SESSION['user_id'];
$requestedDeviceId = trim((string)($data['device_id'] ?? ''));
$sourceDeviceId = null;
if ($requestedDeviceId !== '') {
    if (!fitodomikSelectDevice($pdo, $userId, $requestedDeviceId)) {
        fitodomikPlantCatalogJsonResponse(403, ['success' => false, 'message' => 'Устройство не принадлежит вашему аккаунту']);
    }
    $sourceDeviceId = $requestedDeviceId;
} else {
    $selectedDeviceId = fitodomikGetSelectedDeviceId($pdo, $userId);
    $sourceDeviceId = $selectedDeviceId !== '' ? $selectedDeviceId : null;
}
try {
    $mode = fitodomikPlantCatalogModeSnapshot($plant);
    $existing = fitodomikCatalogExistingMode(
        $pdo,
        $userId,
        (int)$plant['id']
    );
    $farmUrl = '/farm.php#presetModesHeader';
    if ($existing && $duplicateAction === '') {
        fitodomikPlantCatalogJsonResponse(409, [
            'success' => false,
            'duplicate' => true,
            'message' => 'Этот режим уже добавлен. Что сделать?',
            'existing_mode' => ['id' => (int)$existing['id'], 'name' => (string)$existing['name']],
            'farm_url' => $farmUrl,
        ]);
    }
    if ($existing && $duplicateAction === 'open') {
        fitodomikPlantCatalogJsonResponse(200, [
            'success' => true,
            'created' => false,
            'message' => 'Открываем существующий режим',
            'mode_id' => (int)$existing['id'],
            'farm_url' => $farmUrl,
        ]);
    }
    if ($existing && $duplicateAction === 'copy') {
        $baseName = fitodomikPlantCatalogLimitText($mode['name'] . ' — копия', 90);
        $mode['name'] = $baseName;
        $suffix = 2;
        $nameStmt = $pdo->prepare('SELECT 1 FROM preset_modes WHERE user_id = ? AND name = ? LIMIT 1');
        while (true) {
            $nameStmt->execute([$userId, $mode['name']]);
            if ($nameStmt->fetchColumn() === false) {
                break;
            }
            $mode['name'] = fitodomikPlantCatalogLimitText($baseName, 84) . ' ' . $suffix;
            $suffix++;
        }
    }
    $pdo->beginTransaction();
    $modeId = fitodomikCatalogInsertMode(
        $pdo,
        $userId,
        (int)$plant['id'],
        $sourceDeviceId,
        $mode
    );
    try {
        $log = $pdo->prepare("\n            INSERT INTO event_log (user_id, event_type, event_description, created_at)\n            VALUES (?, 'settings', ?, NOW())\n        ");
        $log->execute([$userId, 'Добавлен режим из каталога: ' . $plant['name_ru']]);
    } catch (Throwable $logError) {
        error_log('plant catalog event log: ' . $logError->getMessage());
    }
    $pdo->commit();
    fitodomikPlantCatalogJsonResponse(201, [
        'success' => true,
        'created' => true,
        'message' => 'Режим «' . $plant['name_ru'] . '» добавлен в ваши режимы.',
        'mode_id' => $modeId,
        'farm_url' => $farmUrl,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('plant-catalog-add-mode.php: ' . $e->getMessage());
    fitodomikPlantCatalogJsonResponse(500, ['success' => false, 'message' => 'Не удалось добавить режим']);
}
