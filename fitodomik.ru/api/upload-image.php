<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token', 'X-Device-Name']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/device_context.php';
require_once '../config/session.php';
require_once '../config/file_security.php';
const FARM_PHOTO_PUBLIC_PATH = '/uploads/farm_photos/';
const FARM_PHOTO_USER_STORAGE_LIMIT = 524288000;
function uploadJsonResponse($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function validateUploadedImage($file, $label)
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new FitodomikUploadException('Ошибка загрузки файла: ' . $label);
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 10 * 1024 * 1024) {
        throw new FitodomikUploadException($label . ': размер файла должен быть не больше 10 МБ');
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new FitodomikUploadException($label . ': файл не прошёл проверку загрузки');
    }
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        throw new FitodomikUploadException($label . ': разрешены только JPEG, PNG, GIF и WebP');
    }
    $mimeType = (string)$imageInfo['mime'];
    if (class_exists('finfo')) {
        $detectedType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (is_string($detectedType) && $detectedType !== '') {
            $mimeType = $detectedType;
        }
    }
    if (!isset($allowedTypes[$mimeType])) {
        throw new FitodomikUploadException($label . ': разрешены только JPEG, PNG, GIF и WebP');
    }
    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    if ($width < 1 || $height < 1 || $width * $height > 40000000) {
        throw new FitodomikUploadException($label . ': недопустимые размеры изображения');
    }
    return [
        'tmp_name' => $tmpName,
        'extension' => $allowedTypes[$mimeType],
        'size' => $size,
    ];
}
function uploadUserStorageBytes(string $uploadDir, int $userId): int
{
    global $pdo;
    $ready = $pdo->query("SELECT meta_value FROM fitodomik_schema_meta WHERE meta_key='photo_storage_ready'")->fetchColumn();
    if ($ready !== '1') throw new FitodomikStorageException('Индекс квоты фото не подготовлен');
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM farm_photo_storage WHERE user_id=?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
function removeUploadedFiles(array $filenames, string $uploadDir, int $userId): void
{
    if ($userId < 1) {
        return;
    }
    foreach ($filenames as $filename) {
        if (!is_string($filename) || $filename === '') {
            continue;
        }
        fitodomikSafeUnlinkFarmPhoto($filename, $uploadDir, $userId);
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadJsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
$storedFiles = [];
$upload_dir = dirname(__DIR__) . FARM_PHOTO_PUBLIC_PATH;
$uploadUserId = 0;
try {
    $device_id = trim((string)($_POST['device_id'] ?? fitodomikRequestHeader('X-Device-ID')));
    $device_name = fitodomikNormalizeDeviceName($_POST['device_name'] ?? fitodomikRequestHeader('X-Device-Name'));
    $auth = fitodomikAuthDeviceRequest($pdo, $_POST, true);
    if ($auth) {
        $user_id = (int)$auth['user_id'];
        $device = $auth['device'];
    } elseif (isset($_SESSION['user_id'])) {
        fitodomikRequireCsrf(true);
        $user_id = (int)$_SESSION['user_id'];
        if ($device_id === '' && isset($_SESSION['selected_device_id'])) {
            $device_id = trim((string)$_SESSION['selected_device_id']);
        }
        if ($device_id === '') {
            $device_id = fitodomikGetSelectedDeviceId($pdo, $user_id);
        }
        $deviceStmt = $pdo->prepare("
            SELECT device_id, device_name, status
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $deviceStmt->execute([$user_id, $device_id]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            throw new FitodomikNotFoundException('Выбранное устройство не найдено');
        }
    } else {
        throw new FitodomikAuthorizationException(
            'Пользователь не авторизован. Необходимо указать действительный токен устройства или войти в систему.'
        );
    }
    $device_id = $device['device_id'];
    $uploadUserId = $user_id;
    $retryAfter = fitodomikConsumeRateLimit(
        $pdo,
        'image-upload',
        $user_id . '|' . $device_id,
        30,
        3600,
        3600
    );
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        uploadJsonResponse(429, [
            'success' => false,
            'message' => 'Слишком много загрузок. Повторите позже.',
        ]);
    }
    $image = validateUploadedImage($_FILES['image'] ?? null, 'Основное изображение');
    $text = trim((string)($_POST['text'] ?? 'Фото с камеры'));
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 500, 'UTF-8') : substr($text, 0, 500);
    $has_analysis = isset($_POST['has_analysis']) && $_POST['has_analysis'] === 'true';
    $analysis_image = null;
    if ($has_analysis && isset($_FILES['analysis_image']) && $_FILES['analysis_image']['error'] === UPLOAD_ERR_OK) {
        $analysis_image = validateUploadedImage($_FILES['analysis_image'], 'Результат анализа');
    }
    $quotaLock = 'fitodomik-photo-quota-' . $user_id;
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$quotaLock]);
    if ((int)$lockStmt->fetchColumn() !== 1) throw new FitodomikStorageException('Хранилище занято, повторите загрузку');
    $pdo->beginTransaction();
    $incomingBytes = (int)$image['size'] + (int)($analysis_image['size'] ?? 0);
    if (uploadUserStorageBytes($upload_dir, $user_id) + $incomingBytes > FARM_PHOTO_USER_STORAGE_LIMIT) {
        throw new FitodomikUploadException('Достигнут лимит хранилища фотографий для аккаунта');
    }
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new FitodomikStorageException('Не удалось создать директорию для загрузки');
    }
    if (!is_writable($upload_dir)) {
        throw new FitodomikStorageException('Директория загрузки недоступна для записи');
    }
    $timestamp = time();
    $nonce = bin2hex(random_bytes(6));
    $new_file_name = "farm_{$user_id}_{$timestamp}_{$nonce}.{$image['extension']}";
    $file_path = $upload_dir . $new_file_name;
    if (!move_uploaded_file($image['tmp_name'], $file_path)) {
        throw new FitodomikUploadException('Ошибка при сохранении файла');
    }
    $storedFiles[] = $new_file_name;
    $analysis_file_name = null;
    if ($analysis_image) {
        $analysis_file_name = "analysis_{$user_id}_{$timestamp}_{$nonce}.{$analysis_image['extension']}";
        $analysis_file_path = $upload_dir . $analysis_file_name;
        if (!move_uploaded_file($analysis_image['tmp_name'], $analysis_file_path)) {
            throw new FitodomikUploadException('Ошибка при сохранении файла анализа');
        }
        $storedFiles[] = $analysis_file_name;
    }
    $stmt = $pdo->prepare("
        SELECT light_level, comment
        FROM farm_status
        WHERE user_id = ? AND device_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id, $device_id]);
    $lastStatus = $stmt->fetch(PDO::FETCH_ASSOC);
    $light_level = $lastStatus ? $lastStatus['light_level'] : null;
    $stmt = $pdo->prepare("
        INSERT INTO farm_status (
            user_id, device_id, light_level, photo, photo_analysis, comment, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, NOW()
        )
    ");
    $result = $stmt->execute([
        $user_id,
        $device_id,
        $light_level,
        $new_file_name,
        $analysis_file_name,
        $text,
    ]);
    if (!$result) {
        throw new FitodomikStorageException('Ошибка при сохранении данных в базу данных');
    }
    $storage = $pdo->prepare('INSERT INTO farm_photo_storage(filename,user_id,size_bytes) VALUES(?,?,?)');
    $storage->execute([$new_file_name,$user_id,(int)$image['size']]);
    if ($analysis_file_name) $storage->execute([$analysis_file_name,$user_id,(int)$analysis_image['size']]);
    $pdo->commit();
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$quotaLock]);
    uploadJsonResponse(200, [
        'success' => true,
        'message' => 'Фото успешно загружено',
        'user_id' => $user_id,
        'device_id' => $device_id,
        'device_name' => $device['device_name'],
        'file_path' => FARM_PHOTO_PUBLIC_PATH . $new_file_name,
        'analysis_path' => $analysis_file_name ? FARM_PHOTO_PUBLIC_PATH . $analysis_file_name : null,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (isset($quotaLock)) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$quotaLock]);
    }
    removeUploadedFiles($storedFiles, $upload_dir, $uploadUserId);
    uploadJsonResponse(400, [
        'success' => false,
        'message' => fitodomikApiErrorMessage($e, 'Не удалось загрузить изображение'),
    ]);
}
