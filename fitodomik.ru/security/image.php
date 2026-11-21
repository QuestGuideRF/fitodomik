<?php
const HTTP_BAD_REQUEST = 'HTTP/1.0 400 Bad Request';
const FITODOMIK_IMAGE_IMMUTABLE_CACHE = 'Cache-Control: public, max-age=31536000, immutable';
const FITODOMIK_NUMERIC_VERSION_PATTERN = '/^\d+$/';
require_once __DIR__ . '/security_bootstrap.php';
function imageProxyError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header_remove('Expires');
    header_remove('Pragma');
    header_remove('Content-Security-Policy');
    header_remove('X-Frame-Options');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    exit($message);
}
if (!isset($_GET['file']) || empty($_GET['file'])) {
    imageProxyError(400, 'Файл не указан');
}
$file = trim((string)$_GET['file']);
if (!preg_match('#^(avatars|farm_photos|icon)/([a-z0-9._-]+\.(jpg|jpeg|png|gif|webp|ico|svg))$#i', $file, $matches)) {
    imageProxyError(400, 'Только изображения разрешены');
}
$directory = strtolower($matches[1]);
$filename = $matches[2];
$extension = strtolower($matches[3]);
if ($directory === 'farm_photos') {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/session.php';
    if (!isset($_SESSION['user_id'])) {
        imageProxyError(401, 'Требуется авторизация');
    }
    $owner = $pdo->prepare('SELECT id FROM farm_status WHERE user_id = ? AND (photo = ? OR photo_analysis = ?) LIMIT 1');
    $owner->execute([(int)$_SESSION['user_id'], $filename, $filename]);
    if (!$owner->fetchColumn()) {
        imageProxyError(404, 'Файл не найден');
    }
    session_write_close();
}
$root_dir = dirname(__DIR__);
$baseDirectory = $directory === 'icon'
    ? $root_dir . '/icon'
    : $root_dir . '/uploads/' . $directory;
$baseRealPath = realpath($baseDirectory);
$file_path = $baseRealPath === false ? false : realpath($baseRealPath . DIRECTORY_SEPARATOR . $filename);
if (
    $file_path === false
    || $baseRealPath === false
    || strpos($file_path, $baseRealPath . DIRECTORY_SEPARATOR) !== 0
    || !is_file($file_path)
    || is_link($file_path)
) {
    imageProxyError(404, 'Файл не найден');
}
$contentTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'svg' => 'image/svg+xml',
];
if (isset($contentTypes[$extension])) {
    header("Content-Type: " . $contentTypes[$extension]);
} else {
    imageProxyError(400, 'Неподдерживаемый формат изображения');
}
$version = (string)($_GET['v'] ?? '');
$isFingerprintAvatar = $directory === 'avatars'
    && preg_match('/\Aavatar_\d+_[a-f0-9]{24}\.(?:jpg|jpeg|png|gif|webp)\z/i', $filename);
$isFingerprintFarmPhoto = $directory === 'farm_photos'
    && preg_match('/\A(?:farm|analysis)_\d+_\d+_[a-f0-9]{10,}\.(?:jpg|jpeg|png|gif|webp)\z/i', $filename);
$cacheControl = "Cache-Control: private, no-cache";
if ($directory === 'icon') {
    $cacheControl = FITODOMIK_IMAGE_IMMUTABLE_CACHE;
} elseif ($directory === 'avatars' && (preg_match(FITODOMIK_NUMERIC_VERSION_PATTERN, $version) || $isFingerprintAvatar)) {
    $cacheControl = FITODOMIK_IMAGE_IMMUTABLE_CACHE;
} elseif ($directory === 'avatars') {
    $cacheControl = "Cache-Control: public, max-age=86400";
} elseif ($isFingerprintFarmPhoto) {
    $cacheControl = 'Cache-Control: private, no-store';
} elseif (preg_match(FITODOMIK_NUMERIC_VERSION_PATTERN, $version)) {
    $cacheControl = "Cache-Control: private, max-age=86400";
}
if (preg_match(FITODOMIK_NUMERIC_VERSION_PATTERN, $version) && $directory !== 'farm_photos' && strpos($cacheControl, 'immutable') === false) {
    $cacheControl .= ", immutable";
}
header_remove('Expires');
header_remove('Pragma');
header_remove('Content-Security-Policy');
header_remove('X-Frame-Options');
if ($directory === 'farm_photos') { $cacheControl = 'Cache-Control: private, no-store'; }
header($cacheControl);
header("X-Content-Type-Options: nosniff");
// Thumbnails use the same owner check and private response as the original.
if ($directory === 'farm_photos' && ($_GET['thumb'] ?? '') === '1'
    && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
    $size = @getimagesize($file_path);
    if ($size && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 12000000
        && filesize($file_path) <= 10 * 1024 * 1024) {
        $source = @imagecreatefromstring((string)file_get_contents($file_path));
        if ($source !== false) {
            $scale = min(1, 176 / $size[0], 128 / $size[1]);
            $width = max(1, (int)round($size[0] * $scale));
            $height = max(1, (int)round($size[1] * $scale));
            $thumbnail = imagecreatetruecolor($width, $height);
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
            imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            header('Content-Type: image/jpeg');
            imagejpeg($thumbnail, null, 75);
            exit;
        }
    }
}
$filesize = filesize($file_path);
if ($filesize !== false) {
    header("Content-Length: " . $filesize);
}
readfile($file_path);
