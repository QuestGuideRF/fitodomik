<?php
const FITODOMIK_PROTECTED_UPLOAD_FILES = [
    '.htaccess',
    'web.config',
    'index.php',
    'default.jpg',
    'default.jpeg',
    'default.png',
    'default.gif',
    'default.webp',
    'default.svg',
    'default-avatar.jpg',
    'default-avatar.png',
    'default_avatar.jpg',
    'default_avatar.png',
    'placeholder.jpg',
    'placeholder.png',
    'placeholder.webp',
];
const FITODOMIK_DELETE_TOMBSTONE = '.fitodomik-delete.pending';
if (!function_exists('fitodomikPathInsideBase')) {
    function fitodomikUploadFilename(string $relativePath): ?string
    {
        $normalizedName = str_replace('\\', '/', $relativePath);
        $isValid = $normalizedName !== ''
            && strpos($normalizedName, "\0") === false
            && strpos($normalizedName, '://') === false
            && $normalizedName === basename($normalizedName)
            && $normalizedName !== '.'
            && $normalizedName !== '..'
            && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/iD', $normalizedName) === 1
            && !in_array(strtolower($normalizedName), FITODOMIK_PROTECTED_UPLOAD_FILES, true);
        return $isValid ? $normalizedName : null;
    }
    function fitodomikCanonicalUploadBase(string $baseDir): ?string
    {
        if ($baseDir === '' || strpos($baseDir, "\0") !== false || strpos($baseDir, '://') !== false) {
            return null;
        }
        $uploadsRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
        $baseReal = realpath($baseDir);
        $uploadsPrefix = $uploadsRoot === false
            ? ''
            : rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $isAllowed = $uploadsRoot !== false
            && $baseReal !== false
            && is_dir($baseReal)
            && !is_link(rtrim($baseDir, '/\\'))
            && strncmp($baseReal, $uploadsPrefix, strlen($uploadsPrefix)) === 0;
        return $isAllowed ? $baseReal : null;
    }
    function fitodomikCanonicalUploadFile(string $filename, string $baseReal): ?string
    {
        $candidate = $baseReal . DIRECTORY_SEPARATOR . $filename;
        $pathReal = realpath($candidate);
        $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $isAllowed = !is_link($candidate)
            && $pathReal !== false
            && strncmp($pathReal, $basePrefix, strlen($basePrefix)) === 0
            && is_file($pathReal)
            && !is_dir($pathReal)
            && !is_link($pathReal);
        return $isAllowed ? $pathReal : null;
    }
    function fitodomikPathInsideBase(string $relativePath, string $baseDir): ?string
    {
        $filename = fitodomikUploadFilename($relativePath);
        if ($filename === null) {
            return null;
        }
        $baseReal = fitodomikCanonicalUploadBase($baseDir);
        if ($baseReal === null) {
            return null;
        }
        return fitodomikCanonicalUploadFile($filename, $baseReal);
    }
}
if (!function_exists('fitodomikCanonicalUploadEntry')) {
    function fitodomikCanonicalUploadEntry(string $filename, string $baseReal, string $pathReal): ?string
    {
        $entryReal = fitodomikCanonicalUploadFile($filename, $baseReal);
        return $entryReal !== null && hash_equals($pathReal, $entryReal) ? $entryReal : null;
    }
    function fitodomikRemoveDeleteTombstone(): bool
    {
        $exists = file_exists(FITODOMIK_DELETE_TOMBSTONE) || is_link(FITODOMIK_DELETE_TOMBSTONE);
        return !$exists || unlink('.fitodomik-delete.pending');
    }
    function fitodomikDeleteCanonicalUpload(string $pathReal, string $baseReal): bool
    {
        $deleted = false;
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'fitodomik-upload-delete-' . hash('sha256', $pathReal) . '.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if (is_resource($lockHandle)) {
            $locked = flock($lockHandle, LOCK_EX);
            if ($locked) {
                $filename = basename($pathReal);
                $current = fitodomikCanonicalUploadFile($filename, $baseReal);
                if ($current !== null && hash_equals($pathReal, $current)) {
                    $tombstone = $baseReal . DIRECTORY_SEPARATOR . '.fitodomik-delete.' . bin2hex(random_bytes(12)) . '.pending';
                    try {
                        if (!file_exists($tombstone) && !is_link($tombstone) && rename($pathReal, $tombstone)) {
                            $deleted = unlink($tombstone);
                            if (!$deleted && !file_exists($pathReal) && !is_link($pathReal)) {
                                @rename($tombstone, $pathReal);
                            }
                        }
                    } finally {
                        clearstatcache(true, $pathReal);
                    }
                }
                flock($lockHandle, LOCK_UN);
            }
            fclose($lockHandle);
        }
        return $deleted;
    }
}
if (!function_exists('fitodomikSafeUnlinkInside')) {
    function fitodomikSafeUnlinkInside(string $relativePath, string $baseDir): bool
    {
        $deleted = false;
        $filename = fitodomikUploadFilename($relativePath);
        $baseReal = fitodomikCanonicalUploadBase($baseDir);
        if ($filename !== null && $baseReal !== null) {
            $pathReal = fitodomikCanonicalUploadFile($filename, $baseReal);
            if ($pathReal !== null) {
                $entryReal = fitodomikCanonicalUploadEntry($filename, $baseReal, $pathReal);
                if ($entryReal !== null) {
                    $deleted = fitodomikDeleteCanonicalUpload($entryReal, $baseReal);
                    if ($deleted && preg_match('/\A(?:farm|analysis)_[1-9][0-9]*_[0-9]+_[a-f0-9]{12}\.(?:jpg|png|gif|webp)\z/D', $filename)) {
                        global $pdo;
                        if (isset($pdo)) {
                            try {
                                $usage = $pdo->prepare('DELETE FROM farm_photo_storage WHERE filename=?');
                                $usage->execute([$filename]);
                            } catch (Throwable $e) { error_log('Photo quota cleanup failed'); }
                        }
                    }
                }
            }
        }
        if (!$deleted) {
            error_log('Rejected unsafe upload deletion request');
        }
        return $deleted;
    }
}
if (!function_exists('fitodomikSafeUnlinkFarmPhoto')) {
    function fitodomikSafeUnlinkFarmPhoto(string $filename, string $baseDir, int $userId): bool
    {
        $normalizedName = str_replace('\\', '/', $filename);
        $allowed = $userId >= 1
            && $normalizedName === basename($normalizedName)
            && preg_match(
                '/\A(?:farm|analysis)_' . $userId . '_\d+_[a-f0-9]{12}\.(?:jpg|png|gif|webp)\z/D',
                $normalizedName
            ) === 1;
        return $allowed ? fitodomikSafeUnlinkInside($normalizedName, $baseDir) : false;
    }
}
if (!function_exists('fitodomikSafeUnlinkAvatar')) {
    function fitodomikSafeUnlinkAvatar(string $filename, string $baseDir, int $userId): bool
    {
        $normalizedName = str_replace('\\', '/', $filename);
        $allowed = $userId >= 1
            && $normalizedName === basename($normalizedName)
            && preg_match(
                '/\Aavatar_' . $userId . '_[a-f0-9]{24}\.(?:jpg|png|gif|webp)\z/D',
                $normalizedName
            ) === 1;
        return $allowed ? fitodomikSafeUnlinkInside($normalizedName, $baseDir) : false;
    }
}
