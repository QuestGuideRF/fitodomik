<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    die('Доступ запрещен');
}
require_once dirname(__DIR__, 2) . '/includes/gallery_helpers.php';
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$uploadDir = galleryUploadDirectory();
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}
class GalleryAdminException extends RuntimeException
{
}
function galleryAdminPayload(PDO $pdo, ?int $ignoreId = null): array
{
    $title = galleryCleanText($_POST['title'] ?? '', 255);
    $seoTitle = galleryCleanText($_POST['seo_title'] ?? '', 180);
    $seoAlt = galleryCleanText($_POST['seo_alt'] ?? '', 255);
    $seoDescription = galleryCleanMultiline($_POST['seo_description'] ?? '');
    $seoKeywords = galleryCleanKeywords($_POST['seo_keywords'] ?? '');
    $isIndexable = isset($_POST['is_indexable']) ? 1 : 0;
    if ($title === '') {
        $title = $seoTitle !== '' ? $seoTitle : 'Фото проекта ФитоДомик';
    }
    if ($seoTitle === '') {
        $seoTitle = $title;
    }
    if ($seoAlt === '') {
        $seoAlt = $seoTitle;
    }
    $slugSource = galleryCleanText($_POST['slug'] ?? '') ?: $seoTitle ?: $title;
    $slug = galleryUniqueSlug($pdo, gallerySlugify($slugSource), $ignoreId);
    return [
        'title' => $title,
        'seo_title' => $seoTitle,
        'seo_alt' => $seoAlt,
        'seo_description' => $seoDescription,
        'seo_keywords' => $seoKeywords,
        'slug' => $slug,
        'is_indexable' => $isIndexable,
    ];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add':
                if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    throw new GalleryAdminException('Выберите изображение для загрузки');
                }
                $temporaryPath = (string)($_FILES['image']['tmp_name'] ?? '');
                $fileSize = (int)($_FILES['image']['size'] ?? 0);
                if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
                    throw new GalleryAdminException('Файл не прошёл проверку загрузки');
                }
                if ($fileSize < 1 || $fileSize > 10485760) {
                    throw new GalleryAdminException('Размер изображения должен быть не больше 10 МБ');
                }
                $imageInfo = @getimagesize($temporaryPath);
                if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
                    throw new GalleryAdminException('Загруженный файл не является изображением');
                }
                if ((int)$imageInfo[0] * (int)$imageInfo[1] > 40000000) {
                    throw new GalleryAdminException('Слишком большое разрешение изображения');
                }
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                if (!$fileInfo) {
                    throw new GalleryAdminException('Не удалось проверить тип файла на сервере');
                }
                $mimeType = finfo_file($fileInfo, $temporaryPath);
                finfo_close($fileInfo);
                if (!in_array($mimeType, $allowedTypes, true)) {
                    throw new GalleryAdminException('Недопустимый тип файла. Разрешены только изображения JPEG, PNG, GIF и WEBP');
                }
                $payload = galleryAdminPayload($pdo);
                $extension = galleryExtensionForUpload($mimeType);
                $filename = galleryUniqueFilename($uploadDir, $payload['slug'], $extension);
                $uploadPath = $uploadDir . $filename;
                if (!move_uploaded_file($temporaryPath, $uploadPath)) {
                    throw new GalleryAdminException('Ошибка при загрузке файла');
                }
                $size = galleryImageSize($uploadPath) ?: [null, null];
                $stmt = $pdo->prepare(
                    "INSERT INTO photos
                        (title, filename, seo_title, seo_alt, seo_description, seo_keywords, slug, image_width, image_height, is_indexable, created_at, uploaded_at, updated_at)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())"
                );
                $stmt->execute([
                    $payload['title'],
                    $filename,
                    $payload['seo_title'],
                    $payload['seo_alt'],
                    $payload['seo_description'],
                    $payload['seo_keywords'],
                    $payload['slug'],
                    $size[0],
                    $size[1],
                    $payload['is_indexable'],
                ]);
                $_SESSION['success'] = 'Изображение успешно загружено. ID: ' . (int)$pdo->lastInsertId();
                break;
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new GalleryAdminException('Некорректный ID изображения');
                }
                $payload = galleryAdminPayload($pdo, $id);
                $stmt = $pdo->prepare(
                    "UPDATE photos
                     SET title = ?,
                         seo_title = ?,
                         seo_alt = ?,
                         seo_description = ?,
                         seo_keywords = ?,
                         slug = ?,
                         is_indexable = ?,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([
                    $payload['title'],
                    $payload['seo_title'],
                    $payload['seo_alt'],
                    $payload['seo_description'],
                    $payload['seo_keywords'],
                    $payload['slug'],
                    $payload['is_indexable'],
                    $id,
                ]);
                $_SESSION['success'] = 'SEO-поля изображения обновлены';
                break;
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new GalleryAdminException('Некорректный ID изображения');
                }
                $stmt = $pdo->prepare("SELECT filename FROM photos WHERE id = ?");
                $stmt->execute([$id]);
                $photo = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($photo) {
                    galleryDeleteFile((string)$photo['filename']);
                    $stmt = $pdo->prepare("DELETE FROM photos WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['success'] = 'Изображение успешно удалено';
                }
                break;
            default:
                throw new GalleryAdminException('Неизвестное действие галереи');
        }
    } catch (GalleryAdminException $e) {
        $_SESSION['error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Admin gallery action failed: ' . $e->getMessage());
        $_SESSION['error'] = 'Не удалось выполнить действие';
    }
    echo '<script>window.location.href = "index.php?section=gallery";</script>';
    exit;
}
try {
    $stmt = $pdo->query("SELECT * FROM photos ORDER BY created_at DESC, id DESC");
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin gallery query failed: ' . $e->getMessage());
    $photos = [];
    $error = 'Не удалось загрузить галерею';
}
?>
<div class="container-fluid gallery-admin">
    <h2 class="mb-4">Управление галереей</h2>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?php
            echo $escape($_SESSION['success']);
            unset($_SESSION['success']);
            ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?php
            echo $escape($_SESSION['error']);
            unset($_SESSION['error']);
            ?>
        </div>
    <?php endif; ?>
    <div class="card mb-4 gallery-admin-card">
        <div class="card-body">
            <h5 class="card-title">Добавить новое изображение</h5>
            <form action="index.php?section=gallery" method="post" enctype="multipart/form-data">
                <?php echo fitodomikCsrfInput(); ?>
                <input type="hidden" name="action" value="add">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="title" class="form-label">Название фото</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="col-lg-6">
                        <label for="seo_title" class="form-label">SEO title</label>
                        <input type="text" class="form-control" id="seo_title" name="seo_title" maxlength="180">
                    </div>
                    <div class="col-lg-6">
                        <label for="seo_alt" class="form-label">Alt-текст</label>
                        <input type="text" class="form-control" id="seo_alt" name="seo_alt" maxlength="255">
                        <div class="form-text">Alt должен коротко описывать, что изображено на фото.</div>
                    </div>
                    <div class="col-lg-6">
                        <label for="slug" class="form-label">Slug</label>
                        <input type="text" class="form-control" id="slug" name="slug" placeholder="fitodomik-arduino-bme280">
                        <div class="form-text">Можно оставить пустым, slug будет создан автоматически.</div>
                    </div>
                    <div class="col-12">
                        <label for="seo_description" class="form-label">SEO-описание / подпись</label>
                        <textarea class="form-control" id="seo_description" name="seo_description" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <label for="seo_keywords" class="form-label">Ключевые слова</label>
                        <textarea class="form-control" id="seo_keywords" name="seo_keywords" rows="2" placeholder="ФитоДомик, умная теплица, Arduino, Raspberry Pi, датчик BME280"></textarea>
                        <div class="form-text">Ключевые слова через запятую. Не нужно повторять одно слово много раз.</div>
                    </div>
                    <div class="col-lg-8">
                        <label for="image" class="form-label">Изображение</label>
                        <input type="file" class="form-control" id="image" name="image" accept="image/*" required>
                    </div>
                    <div class="col-lg-4 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1" id="is_indexable" name="is_indexable" checked>
                            <label class="form-check-label" for="is_indexable">Разрешить индексацию</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Загрузить</button>
            </form>
        </div>
    </div>
    <div class="row">
        <?php foreach ($photos as $photo): ?>
            <?php
            $photoTitle = galleryPhotoTitle($photo);
            $altText = galleryPhotoAlt($photo);
            $photoDescription = galleryPhotoDescription($photo);
            $editId = 'edit-photo-' . (int)$photo['id'];
            $fieldPrefix = 'photo-' . (int)$photo['id'] . '-';
            ?>
            <div class="col-xl-4 col-lg-6 mb-4">
                <div class="card gallery-admin-card h-100">
                    <img src="<?php echo $escape(galleryPhotoImageUrl($photo)); ?>"
                         class="card-img-top"
                         alt="<?php echo $escape($altText); ?>"
                         title="<?php echo $escape($photoTitle); ?>"
                         loading="lazy"
                         decoding="async">
                    <div class="card-body">
                        <div class="d-flex justify-content-between gap-2 align-items-start">
                            <h5 class="card-title mb-1"><?php echo $escape($photoTitle); ?></h5>
                            <span class="badge <?php echo (int)($photo['is_indexable'] ?? 1) === 1 ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo (int)($photo['is_indexable'] ?? 1) === 1 ? 'index' : 'noindex'; ?>
                            </span>
                        </div>
                        <p class="card-text gallery-admin-description"><?php echo $escape($photoDescription); ?></p>
                        <p class="card-text">
                            <small class="text-muted">
                                ID: <?php echo (int)$photo['id']; ?> ·
                                <?php echo $escape($photo['filename']); ?><br>
                                Добавлено: <?php echo !empty($photo['created_at']) ? date('d.m.Y H:i', strtotime((string)$photo['created_at'])) : 'не указано'; ?>
                            </small>
                        </p>
                        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $editId; ?>">
                            Редактировать SEO
                        </button>
                        <form action="index.php?section=gallery" method="post" class="d-inline">
                            <?php echo fitodomikCsrfInput(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$photo['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    onclick="return confirm('Вы уверены, что хотите удалить это изображение?')">
                                Удалить
                            </button>
                        </form>
                        <div class="collapse mt-3" id="<?php echo $editId; ?>">
                            <form action="index.php?section=gallery" method="post">
                                <?php echo fitodomikCsrfInput(); ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo (int)$photo['id']; ?>">
                                <div class="mb-2">
                                    <label class="form-label" for="<?php echo $fieldPrefix; ?>title">Название</label>
                                    <input type="text" class="form-control" id="<?php echo $fieldPrefix; ?>title" name="title" value="<?php echo $escape($photo['title'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="<?php echo $fieldPrefix; ?>seo-title">SEO title</label>
                                    <input type="text" class="form-control" id="<?php echo $fieldPrefix; ?>seo-title" name="seo_title" maxlength="180" value="<?php echo $escape($photo['seo_title'] ?? ''); ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="<?php echo $fieldPrefix; ?>seo-alt">Alt-текст</label>
                                    <input type="text" class="form-control" id="<?php echo $fieldPrefix; ?>seo-alt" name="seo_alt" maxlength="255" value="<?php echo $escape($photo['seo_alt'] ?? ''); ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="<?php echo $fieldPrefix; ?>slug">Slug</label>
                                    <input type="text" class="form-control" id="<?php echo $fieldPrefix; ?>slug" name="slug" value="<?php echo $escape($photo['slug'] ?? ''); ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="<?php echo $fieldPrefix; ?>seo-description">SEO-описание / подпись</label>
                                    <textarea class="form-control" id="<?php echo $fieldPrefix; ?>seo-description" name="seo_description" rows="3"><?php echo $escape($photo['seo_description'] ?? ''); ?></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="<?php echo $fieldPrefix; ?>seo-keywords">Ключевые слова</label>
                                    <textarea class="form-control" id="<?php echo $fieldPrefix; ?>seo-keywords" name="seo_keywords" rows="2"><?php echo $escape($photo['seo_keywords'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1" id="index-<?php echo (int)$photo['id']; ?>" name="is_indexable" <?php echo (int)($photo['is_indexable'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="index-<?php echo (int)$photo['id']; ?>">Разрешить индексацию</label>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Сохранить SEO</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<style>
.gallery-admin {
    color: var(--text-color);
}
.gallery-admin-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    box-shadow: var(--admin-shadow);
}
.gallery-admin-card .card-img-top {
    width: 100%;
    height: 220px;
    object-fit: cover;
    border-bottom: 1px solid var(--border-color);
}
.gallery-admin-description {
    min-height: 3rem;
    color: var(--muted-text);
}
.form-control,
.form-select {
    background: var(--card-bg);
    border-color: var(--border-color);
    color: var(--text-color);
}
.form-control:focus {
    background: var(--card-bg);
    color: var(--text-color);
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 0.25rem rgba(47, 143, 70, 0.18);
}
.form-text,
.text-muted {
    color: var(--muted-text) !important;
}
@media (max-width: 768px) {
    .gallery-admin-card .card-img-top {
        height: 170px;
    }
}
</style>
