<?php
declare(strict_types=1);
require_once __DIR__ . '/image_upload.php';
const GALLERY_TABLE = 'photos';
const GALLERY_ALTER_TABLE_PREFIX = 'ALTER TABLE `';
const GALLERY_SELECT_COUNT_PREFIX = 'SELECT COUNT(*) FROM `';
const GALLERY_UPDATE_PREFIX = 'UPDATE `';
function galleryUploadDirectory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'gallery' . DIRECTORY_SEPARATOR;
}
function galleryDeleteFile(string $filename): bool
{
    if ($filename === '' || basename($filename) !== $filename) {
        return false;
    }
    return fitodomikDeleteUploadedImage(galleryUploadDirectory() . $filename, galleryUploadDirectory());
}
function galleryPublicPath(string $filename): string
{
    $basename = basename(str_replace('\\', '/', $filename));
    return '/uploads/gallery/' . rawurlencode($basename);
}
function galleryPhotoFileExists(array $photo): bool
{
    $filename = basename(str_replace('\\', '/', (string)($photo['filename'] ?? '')));
    if ($filename === '' || $filename !== (string)($photo['filename'] ?? '')) {
        return false;
    }
    $baseDirectory = realpath(galleryUploadDirectory());
    $realPath = realpath(galleryUploadDirectory() . $filename);
    if ($baseDirectory === false || $realPath === false || !is_file($realPath) || is_link($realPath)) {
        return false;
    }
    $basePrefix = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strncmp($realPath, $basePrefix, strlen($basePrefix)) === 0;
}
function gallerySiteOrigin(): string
{
    return function_exists('fitodomikRuSiteUrl') ? fitodomikRuSiteUrl() : 'https://fitodomik.ru';
}
function galleryAbsoluteUrl(string $path): string
{
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return rtrim(gallerySiteOrigin(), '/') . '/' . ltrim($path, '/');
}
function galleryColumnExists(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([GALLERY_TABLE, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('galleryColumnExists: ' . $e->getMessage());
        return false;
    }
}
function galleryIndexExists(PDO $pdo, string $indexName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([GALLERY_TABLE, $indexName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('galleryIndexExists: ' . $e->getMessage());
        return false;
    }
}
function galleryEnsureSchema(PDO $pdo): array
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return []; }
    $warnings = [];
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . GALLERY_TABLE . "` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `seo_title` VARCHAR(180) DEFAULT NULL,
            `seo_alt` VARCHAR(255) DEFAULT NULL,
            `seo_description` TEXT DEFAULT NULL,
            `seo_keywords` TEXT DEFAULT NULL,
            `slug` VARCHAR(180) DEFAULT NULL,
            `image_width` INT DEFAULT NULL,
            `image_height` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `is_indexable` TINYINT(1) DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_photos_slug` (`slug`),
            KEY `idx_photos_indexable` (`is_indexable`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columns = [
        'seo_title' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `seo_title` VARCHAR(180) DEFAULT NULL AFTER `title`",
        'seo_alt' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `seo_alt` VARCHAR(255) DEFAULT NULL AFTER `seo_title`",
        'seo_description' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `seo_description` TEXT DEFAULT NULL AFTER `seo_alt`",
        'seo_keywords' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `seo_keywords` TEXT DEFAULT NULL AFTER `seo_description`",
        'slug' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `slug` VARCHAR(180) DEFAULT NULL AFTER `seo_keywords`",
        'image_width' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `image_width` INT DEFAULT NULL AFTER `slug`",
        'image_height' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `image_height` INT DEFAULT NULL AFTER `image_width`",
        'uploaded_at' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `created_at`",
        'updated_at' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `uploaded_at`",
        'is_indexable' => GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD COLUMN `is_indexable` TINYINT(1) DEFAULT 1 AFTER `updated_at`",
    ];
    foreach ($columns as $column => $sql) {
        if (!galleryColumnExists($pdo, $column)) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $warnings[] = "Не удалось добавить поле {$column}: " . $e->getMessage();
            }
        }
    }
    galleryRepairZeroIds($pdo, $warnings);
    galleryEnsureAutoIncrement($pdo, $warnings);
    galleryBackfillSlugs($pdo, $warnings);
    galleryBackfillDimensions($pdo);
    if (galleryColumnExists($pdo, 'slug') && !galleryIndexExists($pdo, 'idx_photos_slug')) {
        try {
            $pdo->exec(GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD INDEX `idx_photos_slug` (`slug`)");
        } catch (PDOException $e) {
            $warnings[] = 'Не удалось добавить индекс slug: ' . $e->getMessage();
        }
    }
    if (galleryColumnExists($pdo, 'is_indexable') && !galleryIndexExists($pdo, 'idx_photos_indexable')) {
        try {
            $pdo->exec(GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD INDEX `idx_photos_indexable` (`is_indexable`)");
        } catch (PDOException $e) {
            $warnings[] = 'Не удалось добавить индекс is_indexable: ' . $e->getMessage();
        }
    }
    return $warnings;
}
function galleryRepairZeroIds(PDO $pdo, array &$warnings): void
{
    try {
        $zeroCount = (int)$pdo->query(GALLERY_SELECT_COUNT_PREFIX . GALLERY_TABLE . "` WHERE `id` = 0")->fetchColumn();
        $guard = 0;
        while ($zeroCount > 0 && $guard < 1000) {
            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(`id`), 0) + 1 FROM `" . GALLERY_TABLE . "`")->fetchColumn();
            $stmt = $pdo->prepare(GALLERY_UPDATE_PREFIX . GALLERY_TABLE . "` SET `id` = ? WHERE `id` = 0 LIMIT 1");
            $stmt->execute([$nextId]);
            $zeroCount = (int)$pdo->query(GALLERY_SELECT_COUNT_PREFIX . GALLERY_TABLE . "` WHERE `id` = 0")->fetchColumn();
            $guard++;
        }
        if ($zeroCount > 0) {
            $warnings[] = 'В таблице photos остались записи с id=0. Нужна ручная миграция, автоисправление остановлено по лимиту.';
        }
    } catch (PDOException $e) {
        $warnings[] = 'Не удалось проверить id=0 в photos: ' . $e->getMessage();
    }
}
function galleryEnsureAutoIncrement(PDO $pdo, array &$warnings): void
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . GALLERY_TABLE . "` LIKE 'id'");
        $stmt->execute();
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $warnings[] = 'В таблице photos нет поля id. Автоматическая миграция пропущена.';
            return;
        }
        $hasPrimary = strtoupper((string)($column['Key'] ?? '')) === 'PRI';
        if (!$hasPrimary) {
            try {
                $pdo->exec(GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` ADD PRIMARY KEY (`id`)");
            } catch (PDOException $e) {
                $warnings[] = 'Не удалось добавить PRIMARY KEY для photos.id: ' . $e->getMessage();
                return;
            }
        }
        if (stripos((string)($column['Extra'] ?? ''), 'auto_increment') === false) {
            try {
                $pdo->exec(GALLERY_ALTER_TABLE_PREFIX . GALLERY_TABLE . "` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
            } catch (PDOException $e) {
                $warnings[] = 'Не удалось включить AUTO_INCREMENT для photos.id: ' . $e->getMessage();
            }
        }
    } catch (PDOException $e) {
        $warnings[] = 'Не удалось проверить AUTO_INCREMENT для photos.id: ' . $e->getMessage();
    }
}
function galleryBackfillSlugs(PDO $pdo, array &$warnings): void
{
    if (!galleryColumnExists($pdo, 'slug')) {
        return;
    }
    try {
        $rows = $pdo->query("SELECT `id`, `title`, `filename`, `slug` FROM `" . GALLERY_TABLE . "` WHERE `slug` IS NULL OR `slug` = ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $base = gallerySlugify((string)($row['title'] ?: pathinfo((string)$row['filename'], PATHINFO_FILENAME)));
            $slug = galleryUniqueSlug($pdo, $base, (int)$row['id']);
            $stmt = $pdo->prepare(GALLERY_UPDATE_PREFIX . GALLERY_TABLE . "` SET `slug` = ? WHERE `id` = ?");
            $stmt->execute([$slug, (int)$row['id']]);
        }
    } catch (PDOException $e) {
        $warnings[] = 'Не удалось заполнить slug для старых фото: ' . $e->getMessage();
    }
}
function galleryBackfillDimensions(PDO $pdo): void
{
    if (!galleryColumnExists($pdo, 'image_width') || !galleryColumnExists($pdo, 'image_height')) {
        return;
    }
    try {
        $stmt = $pdo->query("SELECT `id`, `filename`, `image_width`, `image_height` FROM `" . GALLERY_TABLE . "` WHERE `image_width` IS NULL OR `image_height` IS NULL LIMIT 200");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $path = galleryUploadDirectory() . (string)$row['filename'];
            $size = galleryImageSize($path);
            if ($size) {
                $update = $pdo->prepare(GALLERY_UPDATE_PREFIX . GALLERY_TABLE . "` SET `image_width` = ?, `image_height` = ? WHERE `id` = ?");
                $update->execute([$size[0], $size[1], (int)$row['id']]);
            }
        }
    } catch (PDOException $e) {
        error_log('galleryBackfillDimensions: ' . $e->getMessage());
    }
}
function galleryImageSize(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $size = @getimagesize($path);
    if (!$size || empty($size[0]) || empty($size[1])) {
        return null;
    }
    return [(int)$size[0], (int)$size[1]];
}
function galleryCleanText(?string $value, int $maxLength = 0): string
{
    $text = trim(strip_tags((string)$value));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if ($maxLength > 0 && function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    if ($maxLength > 0) {
        return substr($text, 0, $maxLength);
    }
    return $text;
}
function galleryCleanMultiline(?string $value): string
{
    $text = trim(strip_tags((string)$value));
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return $text;
}
function galleryCleanKeywords(?string $value): string
{
    $text = galleryCleanText($value);
    $parts = array_filter(array_map('trim', preg_split('/[,;]+/u', $text) ?: []));
    $parts = array_slice(array_unique($parts), 0, 30);
    return implode(', ', $parts);
}
function galleryTransliterate(string $text): string
{
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return strtr($lower, $map);
}
function gallerySlugify(string $value, string $fallback = 'fitodomik-photo'): string
{
    $slug = galleryTransliterate($value);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim(strtolower($slug), '-');
    if ($slug === '') {
        $slug = $fallback;
    }
    return substr($slug, 0, 160);
}
function galleryUniqueSlug(PDO $pdo, string $slug, ?int $ignoreId = null): string
{
    $base = gallerySlugify($slug);
    $candidate = $base;
    $counter = 2;
    while (gallerySlugExists($pdo, $candidate, $ignoreId)) {
        $suffix = '-' . $counter;
        $candidate = substr($base, 0, 180 - strlen($suffix)) . $suffix;
        $counter++;
    }
    return $candidate;
}
function gallerySlugExists(PDO $pdo, string $slug, ?int $ignoreId = null): bool
{
    if ($ignoreId !== null) {
        $stmt = $pdo->prepare(GALLERY_SELECT_COUNT_PREFIX . GALLERY_TABLE . "` WHERE `slug` = ? AND `id` <> ?");
        $stmt->execute([$slug, $ignoreId]);
    } else {
        $stmt = $pdo->prepare(GALLERY_SELECT_COUNT_PREFIX . GALLERY_TABLE . "` WHERE `slug` = ?");
        $stmt->execute([$slug]);
    }
    return (int)$stmt->fetchColumn() > 0;
}
function galleryUniqueFilename(string $uploadDir, string $slug, string $extension): string
{
    $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'jpg');
    $base = gallerySlugify($slug);
    $candidate = $base . '-' . date('Ymd-His') . '.' . $extension;
    $counter = 2;
    while (is_file($uploadDir . $candidate)) {
        $candidate = $base . '-' . date('Ymd-His') . '-' . $counter . '.' . $extension;
        $counter++;
    }
    return $candidate;
}
function galleryExtensionForUpload(string $mimeType): string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (isset($map[$mimeType])) {
        return $map[$mimeType];
    }
    return 'jpg';
}
function galleryPhotoTitle(array $photo): string
{
    return galleryCleanText($photo['seo_title'] ?? '') ?: galleryCleanText($photo['title'] ?? '') ?: 'Фото проекта ФитоДомик';
}
function galleryPhotoAlt(array $photo): string
{
    $alt = galleryCleanText($photo['seo_alt'] ?? '', 255) ?: galleryPhotoTitle($photo);
    $alt = preg_replace('/^(?:фото|изображение|photo|image)\s+/iu', '', $alt) ?? $alt;
    return $alt !== '' ? $alt : 'Проект ФитоДомик';
}
function galleryPhotoDescription(array $photo): string
{
    return galleryCleanMultiline($photo['seo_description'] ?? '') ?: galleryPhotoTitle($photo);
}
function galleryPhotoKeywords(array $photo): string
{
    return galleryCleanKeywords($photo['seo_keywords'] ?? '') ?: 'ФитоДомик, умная теплица, Arduino, Raspberry Pi, IoT ферма';
}
function galleryPhotoImageUrl(array $photo, bool $absolute = false): string
{
    $path = galleryPublicPath((string)$photo['filename']);
    return $absolute ? galleryAbsoluteUrl($path) : $path;
}
function galleryPhotoPageUrl(array $photo, bool $absolute = false): string
{
    $slug = galleryCleanText($photo['slug'] ?? '');
    $id = (int)($photo['id'] ?? 0);
    $path = '/pages/galery/photo.php?id=' . $id;
    if ($slug !== '') {
        $path .= '&slug=' . rawurlencode($slug);
    }
    return $absolute ? galleryAbsoluteUrl($path) : $path;
}
function galleryJsonldValue(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}
function galleryBuildGallerySchema(array $photos): array
{
    $images = [];
    foreach (array_slice($photos, 0, 50) as $photo) {
        if (isset($photo['is_indexable']) && (int)$photo['is_indexable'] === 0) {
            continue;
        }
        $images[] = galleryBuildImageSchema($photo);
    }
    return [
        '@context' => 'https://schema.org',
        '@type' => 'ImageGallery',
        'name' => 'Галерея проекта ФитоДомик',
        'description' => 'Фотографии умной мини-фермы ФитоДомик: Arduino, Raspberry Pi, датчики, реле, камера, сборка и выращивание растений.',
        'url' => galleryAbsoluteUrl('/pages/galery/'),
        'image' => $images,
    ];
}
function galleryBuildImageSchema(array $photo): array
{
    $schema = [
        '@type' => 'ImageObject',
        'name' => galleryJsonldValue(galleryPhotoTitle($photo)),
        'description' => galleryJsonldValue(galleryPhotoDescription($photo)),
        'contentUrl' => galleryPhotoImageUrl($photo, true),
        'thumbnailUrl' => galleryPhotoImageUrl($photo, true),
        'url' => galleryPhotoPageUrl($photo, true),
        'caption' => galleryJsonldValue(galleryPhotoAlt($photo)),
        'keywords' => galleryPhotoKeywords($photo),
    ];
    if (!empty($photo['created_at'])) {
        $schema['datePublished'] = date('c', strtotime((string)$photo['created_at']));
    }
    if (!empty($photo['image_width'])) {
        $schema['width'] = (int)$photo['image_width'];
    }
    if (!empty($photo['image_height'])) {
        $schema['height'] = (int)$photo['image_height'];
    }
    return $schema;
}
