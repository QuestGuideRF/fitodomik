<?php
const FITODOMIK_QR_MAX_BYTES = 6291456;
const FITODOMIK_QR_MAX_PIXELS = 13000000;
const FITODOMIK_QR_MAX_DIMENSION = 5000;
const FITODOMIK_QR_PAIR_CODE_PATTERN = '/\A[a-f0-9]{48}\z/iD';
const FITODOMIK_QR_IMAGE_TOO_LARGE_MESSAGE = 'Размер фотографии не должен превышать 6 МБ.';
const FITODOMIK_QR_UNAVAILABLE_MESSAGE = 'Распознавание QR временно недоступно. Повторите попытку позже.';
const FITODOMIK_QR_FOREIGN_CODE_MESSAGE = 'Этот QR-код не относится к системе ФитоДомик.';
const FITODOMIK_QR_ALLOWED_MIME_TYPES = [
    'image/jpeg' => true,
    'image/png' => true,
    'image/webp' => true,
];
class FitodomikQrRecognitionException extends RuntimeException
{
    private string $clientCode;
    private int $httpStatus;
    public function __construct(string $clientCode, string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->clientCode = $clientCode;
        $this->httpStatus = $httpStatus;
    }
    public function getClientCode(): string
    {
        return $this->clientCode;
    }
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
function fitodomikQrError(string $code, string $message, int $status): FitodomikQrRecognitionException
{
    return new FitodomikQrRecognitionException($code, $message, $status);
}
function fitodomikInspectQrImage(string $path, int $size): array
{
    if ($size < 1 || $size > FITODOMIK_QR_MAX_BYTES) {
        throw fitodomikQrError(
            'IMAGE_TOO_LARGE',
            FITODOMIK_QR_IMAGE_TOO_LARGE_MESSAGE,
            413
        );
    }
    if (!class_exists('finfo')) {
        throw fitodomikQrError(
            'QR_DECODER_UNAVAILABLE',
            FITODOMIK_QR_UNAVAILABLE_MESSAGE,
            503
        );
    }
    $imageInfo = @getimagesize($path);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
        throw fitodomikQrError('INVALID_IMAGE', 'Выбранный файл не является изображением.', 415);
    }
    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    if ($width > FITODOMIK_QR_MAX_DIMENSION || $height > FITODOMIK_QR_MAX_DIMENSION
        || $width * $height > FITODOMIK_QR_MAX_PIXELS) {
        throw fitodomikQrError(
            'IMAGE_DIMENSIONS_TOO_LARGE',
            'Разрешение фотографии слишком большое.',
            413
        );
    }
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $metadataMime = (string)($imageInfo['mime'] ?? '');
    $webpUnsupported = $detectedMime === 'image/webp' && !function_exists('imagecreatefromwebp');
    if (!is_string($detectedMime) || !isset(FITODOMIK_QR_ALLOWED_MIME_TYPES[$detectedMime])
        || $webpUnsupported
        || !hash_equals($detectedMime, $metadataMime)) {
        throw fitodomikQrError(
            'UNSUPPORTED_IMAGE_TYPE',
            'Разрешены только фотографии JPEG, PNG и WebP.',
            415
        );
    }
    return [
        'mime_type' => $detectedMime,
        'width' => $width,
        'height' => $height,
        'size' => $size,
    ];
}
function fitodomikValidateQrUpload(array $file): array
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? FITODOMIK_QR_IMAGE_TOO_LARGE_MESSAGE
            : 'Не удалось загрузить фотографию. Повторите попытку.';
        $code = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'IMAGE_TOO_LARGE'
            : 'UPLOAD_FAILED';
        throw fitodomikQrError($code, $message, $code === 'IMAGE_TOO_LARGE' ? 413 : 400);
    }
    $path = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($path === '' || strpos($path, "\0") !== false || !is_uploaded_file($path)) {
        throw fitodomikQrError('INVALID_UPLOAD', 'Файл не прошёл проверку загрузки.', 400);
    }
    $details = fitodomikInspectQrImage($path, $size);
    $details['upload_path'] = $path;
    return $details;
}
function fitodomikCopyQrUploadToPrivateTemp(string $uploadPath): array
{
    $temporaryHandle = tmpfile();
    if (!is_resource($temporaryHandle)) {
        throw fitodomikQrError(
            'TEMPORARY_STORAGE_FAILED',
            'Не удалось обработать фотографию. Повторите попытку позже.',
            500
        );
    }
    $source = @fopen($uploadPath, 'rb');
    if (!is_resource($source)) {
        fclose($temporaryHandle);
        throw fitodomikQrError('INVALID_UPLOAD', 'Файл не прошёл проверку загрузки.', 400);
    }
    try {
        $copiedBytes = stream_copy_to_stream($source, $temporaryHandle, FITODOMIK_QR_MAX_BYTES + 1);
    } finally {
        fclose($source);
    }
    if (!is_int($copiedBytes) || $copiedBytes < 1 || $copiedBytes > FITODOMIK_QR_MAX_BYTES) {
        fclose($temporaryHandle);
        throw fitodomikQrError('IMAGE_TOO_LARGE', FITODOMIK_QR_IMAGE_TOO_LARGE_MESSAGE, 413);
    }
    fflush($temporaryHandle);
    $metadata = stream_get_meta_data($temporaryHandle);
    $temporaryPath = (string)($metadata['uri'] ?? '');
    if ($temporaryPath === '') {
        fclose($temporaryHandle);
        throw fitodomikQrError(
            'TEMPORARY_STORAGE_FAILED',
            'Не удалось обработать фотографию. Повторите попытку позже.',
            500
        );
    }
    return [$temporaryHandle, $temporaryPath];
}
function fitodomikQrDecoder(): chillerlan\QRCode\QRCode
{
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoloadPath) || !extension_loaded('mbstring') || !extension_loaded('gd')) {
        throw fitodomikQrError(
            'QR_DECODER_UNAVAILABLE',
            FITODOMIK_QR_UNAVAILABLE_MESSAGE,
            503
        );
    }
    require_once $autoloadPath;
    if (!class_exists(chillerlan\QRCode\QRCode::class)) {
        throw fitodomikQrError(
            'QR_DECODER_UNAVAILABLE',
            FITODOMIK_QR_UNAVAILABLE_MESSAGE,
            503
        );
    }
    return new chillerlan\QRCode\QRCode();
}
function fitodomikDecodeQrBlob(string $blob, array $options): ?string
{
    try {
        $decoder = new chillerlan\QRCode\QRCode(new chillerlan\QRCode\QROptions($options));
        $result = $decoder->readFromBlob($blob);
        $content = trim((string)$result);
        return $content !== '' && strlen($content) <= 2048 ? $content : null;
    } catch (Throwable $exception) {
        return null;
    }
}
function fitodomikDecodeRotatedQrImage(GdImage $sourceImage, array $options): ?string
{
    $content = null;
    foreach ([90, 180, 270] as $angle) {
        $rotatedImage = imagerotate($sourceImage, $angle, 0xffffff);
        if (!$rotatedImage instanceof GdImage) {
            continue;
        }
        try {
            ob_start();
            imagepng($rotatedImage, null, 6);
            $rotatedBlob = ob_get_clean();
            if (is_string($rotatedBlob) && $rotatedBlob !== '') {
                $content = fitodomikDecodeQrBlob($rotatedBlob, $options);
            }
        } finally {
            imagedestroy($rotatedImage);
        }
        if ($content !== null) {
            break;
        }
    }
    return $content;
}
function fitodomikDecodeQrImage(string $temporaryPath): ?string
{
    fitodomikQrDecoder();
    $blob = @file_get_contents($temporaryPath, false, null, 0, FITODOMIK_QR_MAX_BYTES + 1);
    if (!is_string($blob) || $blob === '' || strlen($blob) > FITODOMIK_QR_MAX_BYTES) {
        throw fitodomikQrError('INVALID_IMAGE', 'Выбранный файл не является изображением.', 415);
    }
    $attempts = [
        [
            'readerUseImagickIfAvailable' => false,
            'readerGrayscale' => false,
            'readerIncreaseContrast' => false,
        ],
        [
            'readerUseImagickIfAvailable' => false,
            'readerGrayscale' => true,
            'readerIncreaseContrast' => true,
        ],
    ];
    foreach ($attempts as $options) {
        $content = fitodomikDecodeQrBlob($blob, $options);
        if ($content !== null) {
            return $content;
        }
    }
    $sourceImage = @imagecreatefromstring($blob);
    if (!$sourceImage instanceof GdImage) {
        return null;
    }
    try {
        return fitodomikDecodeRotatedQrImage($sourceImage, $attempts[1]);
    } finally {
        imagedestroy($sourceImage);
    }
}
function fitodomikParseQrPairCode(string $content): string
{
    $content = trim($content);
    if (preg_match(FITODOMIK_QR_PAIR_CODE_PATTERN, $content) === 1) {
        return strtolower($content);
    }
    if (strlen($content) > 2048 || strpos($content, "\0") !== false) {
        throw fitodomikQrError('QR_INVALID', FITODOMIK_QR_FOREIGN_CODE_MESSAGE, 422);
    }
    $parts = parse_url($content);
    if (!is_array($parts)) {
        throw fitodomikQrError('QR_INVALID', FITODOMIK_QR_FOREIGN_CODE_MESSAGE, 422);
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    $hasCredentials = isset($parts['user']) || isset($parts['pass']);
    $allowedHosts = ['fitodomik.ru', 'www.fitodomik.ru'];
    if ($scheme !== 'https' || !in_array($host, $allowedHosts, true) || $port !== 443
        || $path !== '/device-link.php' || $hasCredentials || isset($parts['fragment'])) {
        throw fitodomikQrError('QR_INVALID', FITODOMIK_QR_FOREIGN_CODE_MESSAGE, 422);
    }
    $query = (string)($parts['query'] ?? '');
    $queryParts = $query === '' ? [] : explode('&', $query);
    if (count($queryParts) !== 1) {
        throw fitodomikQrError('QR_INVALID', FITODOMIK_QR_FOREIGN_CODE_MESSAGE, 422);
    }
    $keyValue = explode('=', $queryParts[0], 2);
    $key = rawurldecode((string)($keyValue[0] ?? ''));
    $code = rawurldecode((string)($keyValue[1] ?? ''));
    if ($key !== 'code' || preg_match(FITODOMIK_QR_PAIR_CODE_PATTERN, $code) !== 1) {
        throw fitodomikQrError('QR_INVALID', FITODOMIK_QR_FOREIGN_CODE_MESSAGE, 422);
    }
    return strtolower($code);
}
function fitodomikValidateQrPairRequest(PDO $pdo, string $code): array
{
    devicePairExpireOldRequests($pdo);
    $statement = $pdo->prepare(
        'SELECT device_name, status, expires_at FROM device_pair_requests WHERE code = ? LIMIT 1'
    );
    $statement->execute([$code]);
    $pairRequest = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pairRequest)) {
        throw fitodomikQrError('QR_INVALID', FITODOMIK_QR_FOREIGN_CODE_MESSAGE, 422);
    }
    $expiresAt = strtotime((string)($pairRequest['expires_at'] ?? ''));
    if (($pairRequest['status'] ?? '') === 'expired' || $expiresAt === false || $expiresAt < time()) {
        throw fitodomikQrError('QR_EXPIRED', 'Срок действия QR-кода истёк. Создайте новый код и повторите попытку.', 410);
    }
    if (($pairRequest['status'] ?? '') !== 'pending') {
        throw fitodomikQrError('QR_ALREADY_USED', 'Этот QR-код уже был использован.', 409);
    }
    return [
        'code' => $code,
        'device_name' => (string)$pairRequest['device_name'],
        'redirect' => '/device-link.php?code=' . rawurlencode($code),
    ];
}
