<?php
require_once dirname(__DIR__) . '/config/file_security.php';
class FitodomikImageUploadException extends RuntimeException
{
}
const FITODOMIK_IMAGE_MAX_PIXELS = 40000000;
const FITODOMIK_IMAGE_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
function fitodomikValidateUploadedImage(array $file, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new FitodomikImageUploadException('Ошибка при загрузке изображения');
    }
    $size = (int)($file['size'] ?? 0);
    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($size < 1 || $size > $maxBytes) {
        throw new FitodomikImageUploadException('Размер изображения должен быть не больше 10 МБ');
    }
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new FitodomikImageUploadException('Файл не прошёл проверку загрузки');
    }
    return [$temporaryPath, fitodomikDetectImageMimeType($temporaryPath)];
}
function fitodomikDetectImageMimeType(string $temporaryPath): string
{
    $imageInfo = @getimagesize($temporaryPath);
    if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
        throw new FitodomikImageUploadException('Разрешены только изображения JPEG, PNG, GIF и WebP');
    }
    if ((int)$imageInfo[0] * (int)$imageInfo[1] > FITODOMIK_IMAGE_MAX_PIXELS) {
        throw new FitodomikImageUploadException('Слишком большое разрешение изображения');
    }
    $mimeType = (string)($imageInfo['mime'] ?? '');
    if (class_exists('finfo')) {
        $detectedType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $mimeType = is_string($detectedType) && $detectedType !== '' ? $detectedType : $mimeType;
    }
    if (!isset(FITODOMIK_IMAGE_MIME_TYPES[$mimeType])) {
        throw new FitodomikImageUploadException('Разрешены только изображения JPEG, PNG, GIF и WebP');
    }
    return $mimeType;
}
function fitodomikPrepareUploadDirectory(string $uploadDir): void
{
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new FitodomikImageUploadException('Не удалось подготовить папку загрузки');
    }
    if (!is_writable($uploadDir)) {
        throw new FitodomikImageUploadException('Папка загрузки недоступна для записи');
    }
}
function fitodomikStoreUploadedImage(
    array $file,
    string $uploadDir,
    string $publicPrefix,
    string $filenamePrefix = 'image',
    int $maxBytes = 10485760
): array {
    [$temporaryPath, $mimeType] = fitodomikValidateUploadedImage($file, $maxBytes);
    fitodomikPrepareUploadDirectory($uploadDir);
    $safePrefix = preg_replace('/[^a-z0-9_-]/i', '', $filenamePrefix) ?: 'image';
    $filename = $safePrefix . '_' . bin2hex(random_bytes(16)) . '.' . FITODOMIK_IMAGE_MIME_TYPES[$mimeType];
    $absolutePath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new FitodomikImageUploadException('Ошибка при сохранении изображения');
    }
    return [
        'absolute_path' => $absolutePath,
        'public_path' => rtrim($publicPrefix, '/') . '/' . $filename,
        'filename' => $filename,
        'mime_type' => $mimeType,
    ];
}
function fitodomikDeleteUploadedImage(string $path, string $uploadDir): bool
{
    if ($path === '' || $uploadDir === '' || strpos($path, "\0") !== false
        || strpos($path, '://') !== false || strpos($uploadDir, '://') !== false) {
        return false;
    }
    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedBase = rtrim(str_replace('\\', '/', $uploadDir), '/');
    $filename = basename($normalizedPath);
    if ($filename === '' || $normalizedPath !== $normalizedBase . '/' . $filename) {
        return false;
    }
    return fitodomikSafeUnlinkInside($filename, $uploadDir);
}
