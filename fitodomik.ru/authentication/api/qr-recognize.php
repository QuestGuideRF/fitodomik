<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/session.php';
require_once dirname(__DIR__, 2) . '/config/headers.php';
require_once dirname(__DIR__, 2) . '/api/device-pair-common.php';
require_once dirname(__DIR__, 2) . '/includes/qr_recognition.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (function_exists('set_time_limit')) {
    @set_time_limit(20);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    devicePairJsonResponse(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Используйте POST-запрос для распознавания QR-кода.',
    ]);
}
if (!isLoggedIn()) {
    devicePairJsonResponse(401, [
        'success' => false,
        'code' => 'UNAUTHORIZED',
        'message' => 'Войдите в аккаунт и повторите попытку.',
    ]);
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > FITODOMIK_QR_MAX_BYTES + 1048576) {
    devicePairJsonResponse(413, [
        'success' => false,
        'code' => 'IMAGE_TOO_LARGE',
        'message' => 'Размер фотографии не должен превышать 6 МБ.',
    ]);
}
$fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    devicePairJsonResponse(403, [
        'success' => false,
        'code' => 'CROSS_ORIGIN_REQUEST',
        'message' => 'Запрос отклонён. Обновите страницу и повторите попытку.',
    ]);
}
if (!fitodomikVerifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    devicePairJsonResponse(403, [
        'success' => false,
        'code' => 'CSRF_INVALID',
        'message' => 'Сессия устарела. Обновите страницу и повторите попытку.',
    ]);
}
$userId = (int)$_SESSION['user_id'];
$temporaryHandle = null;
try {
    $retryAfter = fitodomikConsumeRateLimit($pdo, 'qr-image-recognition', (string)$userId, 10, 300, 300);
    if ($retryAfter > 0) {
        header('Retry-After: ' . (int)$retryAfter);
        throw fitodomikQrError(
            'RATE_LIMITED',
            'Слишком много попыток. Подождите несколько минут и повторите.',
            429
        );
    }
    if (!isset($_FILES['qr_image']) || !is_array($_FILES['qr_image'])) {
        throw fitodomikQrError('IMAGE_REQUIRED', 'Сделайте фотографию QR-кода или выберите изображение.', 400);
    }
    $upload = fitodomikValidateQrUpload($_FILES['qr_image']);
    [$temporaryHandle, $temporaryPath] = fitodomikCopyQrUploadToPrivateTemp($upload['upload_path']);
    try {
        $qrContent = fitodomikDecodeQrImage($temporaryPath);
    } finally {
        if (is_resource($temporaryHandle)) {
            fclose($temporaryHandle);
            $temporaryHandle = null;
        }
    }
    if ($qrContent === null) {
        throw fitodomikQrError(
            'QR_NOT_FOUND',
            'QR-код на фотографии не найден. Наведите камеру ближе, обеспечьте хорошее освещение и повторите снимок.',
            422
        );
    }
    $pairCode = fitodomikParseQrPairCode($qrContent);
    $pairRequest = fitodomikValidateQrPairRequest($pdo, $pairCode);
    error_log('qr-recognize success user_id=' . $userId);
    devicePairJsonResponse(200, [
        'success' => true,
        'message' => 'QR-код распознан. Подтвердите привязку устройства.',
        'result' => [
            'linked' => false,
            'device_name' => $pairRequest['device_name'],
            'redirect' => $pairRequest['redirect'],
        ],
    ]);
} catch (FitodomikQrRecognitionException $exception) {
    error_log(
        'qr-recognize rejected user_id=' . $userId
        . ' code=' . $exception->getClientCode()
    );
    devicePairJsonResponse($exception->getHttpStatus(), [
        'success' => false,
        'code' => $exception->getClientCode(),
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    error_log('qr-recognize failure user_id=' . $userId . ' type=' . get_class($exception));
    devicePairJsonResponse(500, [
        'success' => false,
        'code' => 'SERVER_ERROR',
        'message' => 'Не удалось обработать фотографию. Повторите попытку позже.',
    ]);
} finally {
    if (is_resource($temporaryHandle)) {
        fclose($temporaryHandle);
    }
}
