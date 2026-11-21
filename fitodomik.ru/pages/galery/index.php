<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/gallery_helpers.php';
const GALLERY_MODELS_BASE_PATH = '/static/models/';
$pageTitle = 'Галерея ФитоДомик — фото умной теплицы, Arduino, Raspberry Pi и датчиков';
$pageDescription = 'Фотографии проекта ФитоДомик: умная мини-ферма, Arduino, Raspberry Pi, датчики BME280, MH-Z19B, реле, камера, сборка и выращивание растений.';
$pageKeywords = 'ФитоДомик фото, умная теплица фото, Arduino теплица, Raspberry Pi теплица, IoT ферма, датчики растений, мини ферма своими руками';
$modelDownloadUrl = static function (string $filename): string {
    $basename = basename(str_replace('\\', '/', $filename));
    $extension = strtolower((string)pathinfo($basename, PATHINFO_EXTENSION));
    $stem = (string)pathinfo($basename, PATHINFO_FILENAME);
    return GALLERY_MODELS_BASE_PATH . rawurlencode($stem) . '.v13.' . rawurlencode($extension);
};
$modelViewerUrl = static function (string $filename): string {
    $basename = basename(str_replace('\\', '/', $filename));
    $extension = strtolower((string)pathinfo($basename, PATHINFO_EXTENSION));
    $stem = (string)pathinfo($basename, PATHINFO_FILENAME);
    return GALLERY_MODELS_BASE_PATH . rawurlencode($stem) . '.v16.' . rawurlencode($extension);
};
$modelPosterUrl = static function (string $filename): string {
    $basename = basename(str_replace('\\', '/', $filename));
    $stem = (string)pathinfo($basename, PATHINFO_FILENAME);
    return GALLERY_MODELS_BASE_PATH . 'resources/' . rawurlencode($stem) . '.poster.v16.png';
};
try {
    $cursorId = max(0, (int)($_GET['before_id'] ?? 0));
    $cursorTime = trim((string)($_GET['before_time'] ?? ''));
    $params = [];
    $cursorSql = '';
    if ($cursorId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $cursorTime)) {
        $cursorSql = ' WHERE created_at < ? OR (created_at = ? AND id < ?)';
        $params = [$cursorTime, $cursorTime, $cursorId];
    }
    $stmt = $pdo->prepare('SELECT * FROM photos' . $cursorSql . ' ORDER BY created_at DESC, id DESC LIMIT 49');
    $stmt->execute($params);
    $pageRows = $stmt->fetchAll();
    $hasMorePhotos = count($pageRows) > 48;
    if ($hasMorePhotos) array_pop($pageRows);
    $lastPhoto = $pageRows ? $pageRows[count($pageRows)-1] : null;
    $photos = array_values(array_filter($pageRows, static function (array $photo): bool {
        return galleryPhotoFileExists($photo);
    }));
    $indexablePhotos = array_values(array_filter($photos, static function ($photo) {
        return (int)($photo['is_indexable'] ?? 1) === 1;
    }));
    if (!empty($indexablePhotos)) {
        $pageImage = galleryPhotoImageUrl($indexablePhotos[0], true);
    }
    $pageStructuredData = galleryBuildGallerySchema($photos);
    ob_start();
?>
<div class="container gallery-container">
    <h1 class="gallery-title">Фотогалерея</h1>
    <div class="row">
        <?php if (empty($photos)): ?>
            <div class="col-md-12">
                <div class="alert alert-info">
                    <h4 class="alert-heading">В галерее пока нет фотографий</h4>
                    <p>Загляните позже, чтобы увидеть новые фотографии.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($photos as $index => $photo): ?>
                <?php
                $photoTitle = galleryPhotoTitle($photo);
                $contentAlt = galleryPhotoAlt($photo);
                $photoDescription = galleryPhotoDescription($photo);
                $photoImageUrl = galleryPhotoImageUrl($photo);
                $photoAssetUrl = $photoImageUrl;
                $photoPageUrl = galleryPhotoPageUrl($photo);
                $photoWidth = (int)($photo['image_width'] ?? 0);
                $photoHeight = (int)($photo['image_height'] ?? 0);
                $photoIndexable = (int)($photo['is_indexable'] ?? 1) === 1;
                $photoLoading = $index < 2 ? 'eager' : 'lazy';
                ?>
                <div class="col-md-4">
                    <figure class="gallery-card" <?php echo $photoIndexable ? 'itemscope itemtype="https://schema.org/ImageObject"' : ''; ?>>
                        <div class="image-container">
                            <a href="<?php echo htmlspecialchars($photoPageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                               <?php echo $photoIndexable ? 'itemprop="url"' : ''; ?>
                               aria-label="<?php echo htmlspecialchars($photoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                               onclick="handleGalleryPhotoClick(event, <?php echo $index; ?>)">
                                <img src="<?php echo htmlspecialchars($photoAssetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     alt="<?php echo htmlspecialchars($contentAlt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     title="<?php echo htmlspecialchars($photoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     class="gallery-image"
                                     loading="<?php echo $photoLoading; ?>"
                                     decoding="async"
                                     <?php echo $index === 0 ? 'fetchpriority="high"' : ''; ?>
                                     <?php echo $photoWidth > 0 ? 'width="' . $photoWidth . '"' : ''; ?>
                                     <?php echo $photoHeight > 0 ? 'height="' . $photoHeight . '"' : ''; ?>
                                     <?php echo $photoIndexable ? 'itemprop="contentUrl"' : ''; ?>
                                     data-title="<?php echo htmlspecialchars($photoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                     data-full="<?php echo htmlspecialchars($photoAssetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            </a>
                        </div>
                        <figcaption class="gallery-card-caption" <?php echo $photoIndexable ? 'itemprop="caption"' : ''; ?>>
                            <div class="gallery-card-title" <?php echo $photoIndexable ? 'itemprop="name"' : ''; ?>>
                                <?php echo htmlspecialchars($photoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </div>
                            <?php if ($photoDescription !== $photoTitle): ?>
                                <p class="gallery-card-description" <?php echo $photoIndexable ? 'itemprop="description"' : ''; ?>>
                                    <?php echo nl2br(htmlspecialchars($photoDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?>
                                </p>
                            <?php elseif ($photoIndexable): ?>
                                <meta itemprop="description" content="<?php echo htmlspecialchars($photoDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <?php if ($photoIndexable): ?>
                                <meta itemprop="thumbnailUrl" content="<?php echo htmlspecialchars(galleryPhotoImageUrl($photo, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <meta itemprop="keywords" content="<?php echo htmlspecialchars(galleryPhotoKeywords($photo), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <?php endif; ?>
                        </figcaption>
                    </figure>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <dialog id="lightbox" class="lightbox" aria-labelledby="lightbox-title">
        <button class="close-btn lightbox-close" type="button" onclick="closeLightbox(event)" aria-label="Закрыть">&times;</button>
        <button class="lightbox-nav lightbox-nav--prev" type="button" onclick="showPreviousPhoto(event)" aria-label="Предыдущее фото">
            <i class="bi bi-chevron-left"></i>
        </button>
        <figure class="lightbox-stage">
            <img id="lightbox-img" src="" alt="">
            <figcaption id="lightbox-title" class="lightbox-title" aria-live="polite"></figcaption>
        </figure>
        <button class="lightbox-nav lightbox-nav--next" type="button" onclick="showNextPhoto(event)" aria-label="Следующее фото">
            <i class="bi bi-chevron-right"></i>
        </button>
    </dialog>
    <nav aria-label="Страницы фотографий">
        <?php if ($cursorId > 0): ?><a href="/pages/galery/">Первые фотографии</a><?php endif; ?>
        <?php if ($hasMorePhotos && $lastPhoto): ?>
            <a rel="next" href="?<?php echo htmlspecialchars(http_build_query(['before_time'=>$lastPhoto['created_at'],'before_id'=>$lastPhoto['id']]), ENT_QUOTES, 'UTF-8'); ?>">Следующие фотографии</a>
        <?php endif; ?>
    </nav>
    <div class="models-section">
        <h2 class="gallery-title">3D-модели компонентов</h2>
        <div class="row">
            <?php
            $models = [
                [
                    'file' => 'raspberry_pi_3.glb',
                    'title' => 'Raspberry Pi 3',
                    'description' => 'Основной контроллер системы',
                    'type' => 'glb'
                ],
                [
                    'file' => 'arduino_nano_v3_mini_usb_download_free.glb',
                    'title' => 'Arduino Nano V3',
                    'description' => 'Микроконтроллер для датчиков',
                    'type' => 'glb'
                ],
                [
                    'file' => 'bme280_from_adafruit_cansat_kit_component.glb',
                    'title' => 'BME280 Датчик',
                    'description' => 'Датчик температуры, влажности и давления',
                    'type' => 'glb'
                ],
                [
                    'file' => 'relay_shield__wemos_d1_mini.glb',
                    'title' => 'Relay Shield Wemos D1',
                    'description' => 'Модуль реле для управления',
                    'type' => 'glb'
                ],
                [
                    'file' => 'logitech_webcam.glb',
                    'title' => 'Веб-камера Logitech',
                    'description' => 'Камера для мониторинга',
                    'type' => 'glb'
                ],
                [
                    'file' => 'Soil Moisture Sensor Board.glb',
                    'title' => 'Датчик влажности почвы',
                    'description' => 'Корпус для датчика влажности почвы',
                    'type' => 'glb'
                ],
                [
                    'file' => 'MH-Z19B.glb',
                    'title' => 'MH-Z19B CO2 Датчик',
                    'description' => 'Датчик углекислого газа MH-Z19B',
                    'type' => 'glb'
                ],
                [
                    'file' => 'YL-69.glb',
                    'title' => 'YL-69 Датчик влажности',
                    'description' => 'Датчик влажности YL-69',
                    'type' => 'glb'
                ]
            ];
            $hasGlbModels = !empty(array_filter($models, static function ($model) {
                return $model['type'] === 'glb';
            }));
            $hasStlModels = !empty(array_filter($models, static function ($model) {
                return $model['type'] === 'stl';
            }));
            foreach ($models as $index => $model):
                $modelUrl = $modelViewerUrl((string)$model['file']);
                $modelDownload = $modelDownloadUrl((string)$model['file']);
                $modelPoster = $modelPosterUrl((string)$model['file']);
            ?>
                <div class="col-md-4">
                    <div class="model-card">
                        <div class="model-viewer-container" id="model-container-<?php echo $index; ?>">
                            <div class="model-static-preview">
                                <img
                                    class="model-static-preview__image"
                                    src="<?php echo htmlspecialchars($modelPoster, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    alt="Предпросмотр 3D-модели <?php echo htmlspecialchars($model['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    width="900"
                                    height="516"
                                    loading="lazy"
                                    decoding="async">
                                <span class="model-static-preview__badge" aria-hidden="true">3D</span>
                            </div>
                        </div>
                        <div class="model-card-content">
                            <h3 class="model-card-title"><?php echo htmlspecialchars($model['title']); ?></h3>
                            <p class="model-card-description"><?php echo htmlspecialchars($model['description']); ?></p>
                            <div class="model-actions">
                                <button
                                    class="btn-view-3d"
                                    type="button"
                                    data-model-url="<?php echo htmlspecialchars($modelUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    data-model-title="<?php echo htmlspecialchars($model['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    data-model-type="<?php echo htmlspecialchars($model['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/>
                                        <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319z"/>
                                    </svg>
                                    Просмотр
                                </button>
                                <a href="<?php echo htmlspecialchars($modelDownload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" download class="btn-download-3d">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                        <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                                    </svg>
                                    Скачать
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <dialog id="model-modal" class="model-modal" aria-labelledby="modal-model-title">
        <button class="close-btn model-modal-close" type="button" aria-label="Закрыть просмотр 3D-модели">&times;</button>
        <div class="model-modal-content">
            <div id="modal-model-container"></div>
            <div id="modal-model-title" class="modal-model-title"></div>
        </div>
    </dialog>
</div>
<style>
.gallery-container {
    padding: 2rem 0;
}
.gallery-title {
    color: var(--text-color);
    margin-bottom: 2rem;
    text-align: center;
}
.gallery-card {
    margin-bottom: 2rem;
    border-radius: 8px;
    overflow: hidden;
    transition: transform 0.3s ease;
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    padding-bottom: 10px;
}
.gallery-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}
.gallery-card-title {
    padding: 10px 15px;
    font-size: 1.1rem;
    color: var(--text-color);
    text-align: center;
    font-weight: 500;
}
.gallery-card-caption {
    padding-bottom: 14px;
}
.gallery-card-description {
    padding: 0 15px;
    margin: 0 0 0.75rem;
    color: var(--text-color);
    font-size: 0.95rem;
    line-height: 1.45;
    opacity: 0.78;
    text-align: center;
}
.gallery-image {
    width: 100%;
    height: 300px;
    display: block;
    object-fit: contain;
    object-position: center;
    background-color: var(--card-bg);
    border-radius: 8px 8px 0 0;
    transition: opacity 0.3s ease;
    cursor: zoom-in;
}
.gallery-card:hover .gallery-image {
    opacity: 0.96;
}
.image-container {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: transparent;
    border-radius: 8px 8px 0 0;
    overflow: hidden;
}
[data-theme="light"] .gallery-card {
    background-color: #fff;
    border: 1px solid rgba(47, 143, 70, 0.16);
}
[data-theme="light"] .gallery-card-title {
    color: #13251a;
}
[data-theme="dark"] .gallery-card {
    background-color: #142118;
    border: 1px solid rgba(164, 230, 173, 0.18);
}
[data-theme="dark"] .gallery-card-title {
    color: #edf8ee;
}
@media (max-width: 768px) {
    .gallery-image {
        height: 200px;
    }
    .gallery-card-title {
        font-size: 1rem;
    }
}
.lightbox {
    display: none;
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    max-width: none;
    max-height: none;
    box-sizing: border-box;
    margin: 0;
    border: 0;
    align-items: center;
    justify-content: center;
    padding: 88px 88px 32px;
    background-color: rgba(0, 0, 0, 0.95);
    z-index: 2050;
    text-align: center;
    -webkit-backdrop-filter: blur(5px);
    backdrop-filter: blur(5px);
}
.lightbox[open] {
    display: flex;
}
.lightbox::backdrop {
    background: transparent;
}
.lightbox-stage {
    display: flex;
    width: min(100%, 1100px);
    height: 100%;
    margin: 0;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}
.lightbox img {
    max-width: 100%;
    max-height: calc(100vh - 190px);
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 0 30px rgba(0, 0, 0, 0.5);
}
.lightbox-title {
    color: var(--text-color-light);
    font-size: 1.5em;
    margin-top: 20px;
    padding: 0 20px;
}
.close-btn {
    position: absolute;
    top: 76px;
    right: 30px;
    color: var(--text-color-light);
    font-size: 40px;
    cursor: pointer;
    transition: opacity 0.2s ease;
}
.lightbox-close {
    width: 48px;
    height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.42);
    line-height: 1;
    z-index: 2;
}
.close-btn:hover {
    opacity: 0.8;
}
.lightbox-nav {
    position: absolute;
    top: 50%;
    width: 54px;
    height: 54px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.42);
    color: #fff;
    font-size: 1.55rem;
    transform: translateY(-50%);
    transition: background 0.2s ease, opacity 0.2s ease;
    z-index: 2;
}
.lightbox-nav:hover {
    background: rgba(255, 255, 255, 0.16);
}
.lightbox-nav--prev {
    left: 24px;
}
.lightbox-nav--next {
    right: 24px;
}
.models-section {
    margin-top: 4rem;
    padding-top: 3rem;
    border-top: 2px solid var(--border-color);
    max-width: 100%;
}
.models-section > .row,
.models-section > .row > [class*="col-"] {
    min-width: 0;
}
.models-subtitle {
    text-align: center;
    color: var(--text-color);
    margin-bottom: 2rem;
    font-size: 1.1rem;
    opacity: 0.8;
}
.model-card {
    margin-bottom: 2rem;
    border-radius: 12px;
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    min-height: 100%;
}
.model-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}
.model-viewer-container {
    width: 100%;
    height: 340px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(47, 143, 70, 0.12), rgba(183, 216, 121, 0.18));
    position: relative;
    overflow: hidden;
}
.model-viewer {
    width: 100%;
    height: 100%;
    --poster-color: transparent;
}
.model-static-preview {
    position: relative;
    width: 100%;
    height: 100%;
}
.model-static-preview__image {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.model-static-preview__badge {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    display: inline-grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 8px;
    background: var(--primary-dark);
    color: #fff;
    font-family: var(--font-display);
    font-size: 0.9rem;
    font-weight: 800;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
}
.model-viewer::part(default-progress-bar) {
    display: block;
    height: 4px;
    background-color: var(--primary-color);
}
.stl-viewer {
    width: 100%;
    height: 100%;
}
.model-state {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: var(--text-color);
    font-size: 16px;
    text-align: center;
}
.model-state--poster {
    background: linear-gradient(135deg, rgba(47, 143, 70, 0.16), rgba(183, 216, 121, 0.24));
}
.model-state--large {
    font-size: 18px;
}
.model-state--error {
    color: #b42318;
    font-size: 14px;
}
.model-state__content {
    text-align: center;
}
.model-state__icon {
    margin-bottom: 10px;
    font-size: 24px;
}
.model-state--large .model-state__icon {
    font-size: 48px;
}
.model-card-content {
    padding: 1.25rem 1.25rem 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    flex: 1;
    min-width: 0;
}
.model-card-title {
    font-size: 1.35rem;
    line-height: 1.2;
    font-weight: 600;
    color: var(--text-color);
    margin: 0;
}
.model-card-description {
    color: var(--text-color);
    opacity: 0.7;
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.45;
    flex: 1;
}
.model-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
    margin-top: auto;
    width: 100%;
    min-width: 0;
}
.btn-view-3d, .btn-download-3d {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 0.72rem 0.65rem;
    border: 1px solid transparent;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    font-size: 0.95rem;
    line-height: 1;
    white-space: normal;
    text-align: center;
    -webkit-appearance: none;
    appearance: none;
    box-sizing: border-box;
    font-family: inherit;
}
.btn-view-3d {
    background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
    color: white;
}
.btn-view-3d:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(86, 171, 47, 0.4);
    color: white;
}
.btn-download-3d {
    background-color: var(--card-bg);
    color: var(--text-color);
    border: 2px solid var(--border-color);
}
.btn-download-3d:hover {
    background-color: var(--border-color);
    transform: translateY(-2px);
    color: var(--text-color);
}
.model-modal {
    position: fixed;
    inset: 0;
    width: 100vw;
    max-width: none;
    height: 100vh;
    height: 100dvh;
    max-height: none;
    margin: 0;
    padding: 0;
    border: 0;
    background-color: rgba(0, 0, 0, 0.95);
    color: inherit;
    z-index: 2050;
    -webkit-backdrop-filter: blur(10px);
    backdrop-filter: blur(10px);
}
.model-modal::backdrop {
    background: transparent;
}
.model-modal-close {
    top: 16px;
    right: 18px;
    width: 48px;
    height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.28);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.48);
    line-height: 1;
    z-index: 2;
}
.model-modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 90%;
    max-width: 900px;
    height: 80vh;
    background-color: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
#modal-model-container {
    width: 100%;
    height: calc(100% - 60px);
    background: linear-gradient(135deg, rgba(47, 143, 70, 0.12), rgba(183, 216, 121, 0.18));
}
#modal-model-container model-viewer,
#modal-model-container canvas {
    width: 100%;
    height: 100%;
}
.modal-model-title {
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-color);
    background-color: var(--card-bg);
    border-top: 1px solid var(--border-color);
}
[data-theme="light"] .model-card {
    background-color: #fff;
    border: 1px solid rgba(47, 143, 70, 0.16);
}
[data-theme="light"] .model-card-title {
    color: #13251a;
}
[data-theme="light"] .model-card-description {
    color: #52635a;
}
[data-theme="light"] .btn-download-3d {
    background-color: #fff;
    color: #166534;
    border-color: rgba(47, 143, 70, 0.24);
}
[data-theme="light"] .btn-download-3d:hover {
    background-color: #eef6e7;
}
[data-theme="light"] .model-modal-content {
    background-color: #fff;
}
[data-theme="light"] .modal-model-title {
    color: #13251a;
    background-color: #fff;
    border-top-color: rgba(47, 143, 70, 0.16);
}
[data-theme="dark"] .model-card {
    background-color: #142118;
    border: 1px solid rgba(164, 230, 173, 0.18);
}
[data-theme="dark"] .model-card-title {
    color: #edf8ee;
}
[data-theme="dark"] .model-card-description {
    color: rgba(237, 248, 238, 0.82);
}
[data-theme="dark"] .btn-download-3d {
    background-color: rgba(255, 255, 255, 0.04);
    color: #edf8ee;
    border-color: rgba(164, 230, 173, 0.24);
}
[data-theme="dark"] .btn-download-3d:hover {
    background-color: rgba(164, 230, 173, 0.16);
}
[data-theme="dark"] .model-modal-content {
    background-color: #142118;
}
[data-theme="dark"] .modal-model-title {
    color: #edf8ee;
    background-color: #0d1711;
    border-top-color: rgba(164, 230, 173, 0.18);
}
@media (min-width: 769px) and (max-width: 1100px) {
    .models-section > .row > .col-md-4 {
        flex: 0 0 50%;
        max-width: 50%;
    }
}
@media (max-width: 768px) {
    .models-section > .row > .col-md-4 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    .model-viewer-container {
        height: 280px;
    }
    .model-card-title {
        font-size: 1.1rem;
    }
    .model-actions {
        grid-template-columns: 1fr;
    }
    .btn-view-3d, .btn-download-3d {
        width: 100%;
    }
    .model-modal-content {
        width: 95%;
        height: 70vh;
    }
    .lightbox {
        padding: 82px 16px 24px;
    }
    .lightbox img {
        max-height: calc(100vh - 190px);
    }
    .lightbox-title {
        font-size: 1.05rem;
        margin-top: 14px;
    }
    .close-btn {
        top: 70px;
        right: 14px;
    }
    .model-modal-close {
        top: 14px;
        right: 14px;
    }
    .lightbox-close {
        width: 44px;
        height: 44px;
        font-size: 34px;
    }
    .lightbox-nav {
        top: auto;
        bottom: 18px;
        width: 46px;
        height: 46px;
        font-size: 1.25rem;
        transform: none;
    }
    .lightbox-nav--prev {
        left: calc(50% - 58px);
    }
    .lightbox-nav--next {
        right: calc(50% - 58px);
    }
}
</style>
<?php if ($hasGlbModels): ?>
<script type="module" src="/static/js/model-viewer.v13.js"></script>
<?php endif; ?>
<?php if ($hasStlModels): ?>
<script src="/assets/vendor/three/0.128.0/three.min.js"></script>
<script src="/assets/vendor/three/0.128.0/STLLoader.js"></script>
<script src="/assets/vendor/three/0.128.0/OrbitControls.js"></script>
<?php endif; ?>
<script>
let galleryPhotos = [];
let currentPhotoIndex = 0;
let lastLightboxTrigger = null;
function collectGalleryPhotos() {
    galleryPhotos = Array.from(document.querySelectorAll('.gallery-image')).map(function(img) {
        return {
            src: img.dataset.full || img.src,
            title: img.dataset.title || img.alt || ''
        };
    });
}
function renderLightboxPhoto() {
    const lightboxImg = document.getElementById('lightbox-img');
    const lightboxTitle = document.getElementById('lightbox-title');
    const photo = galleryPhotos[currentPhotoIndex];
    if (!photo) {
        return;
    }
    lightboxImg.src = photo.src;
    lightboxImg.alt = photo.title;
    lightboxTitle.textContent = photo.title;
}
function openLightbox(index) {
    if (!galleryPhotos.length) {
        collectGalleryPhotos();
    }
    const lightbox = document.getElementById('lightbox');
    currentPhotoIndex = index;
    renderLightboxPhoto();
    if (typeof lightbox.showModal === 'function' && !lightbox.open) {
        lightbox.showModal();
    } else {
        lightbox.setAttribute('open', '');
    }
    document.body.style.overflow = 'hidden';
}
function handleGalleryPhotoClick(event, index) {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }
    event.preventDefault();
    lastLightboxTrigger = event.currentTarget;
    openLightbox(index);
}
function closeLightbox(event) {
    if (event) {
        event.stopPropagation();
    }
    const lightbox = document.getElementById('lightbox');
    if (!lightbox || !lightbox.open) {
        return;
    }
    if (typeof lightbox.close === 'function') {
        lightbox.close();
    } else {
        lightbox.removeAttribute('open');
    }
    const modelModal = document.getElementById('model-modal');
    document.body.style.overflow = modelModal?.open ? 'hidden' : '';
    const trigger = lastLightboxTrigger;
    lastLightboxTrigger = null;
    if (trigger instanceof HTMLElement && trigger.isConnected) {
        trigger.focus();
    }
}
function showPreviousPhoto(event) {
    event.stopPropagation();
    if (!galleryPhotos.length) {
        return;
    }
    currentPhotoIndex = (currentPhotoIndex - 1 + galleryPhotos.length) % galleryPhotos.length;
    renderLightboxPhoto();
}
function showNextPhoto(event) {
    event.stopPropagation();
    if (!galleryPhotos.length) {
        return;
    }
    currentPhotoIndex = (currentPhotoIndex + 1) % galleryPhotos.length;
    renderLightboxPhoto();
}
let currentSTLRuntime = null;
let activeModelViewer = null;
let activeModelViewerCleanup = null;
let lastModelTrigger = null;
const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
const modelLoadingMarkup = '<div class="model-state model-state--large"><div class="model-state__content"><div class="model-state__icon">🔄</div><div>Загрузка 3D модели...</div></div></div>';
const modelErrorMarkup = '<div class="model-state model-state--error">⚠️ Ошибка загрузки модели<br>Проверьте путь к файлу</div>';
let modelViewerIdCounter = 0;
function repairModelViewerAccessibility(viewer) {
    if (!viewer.dataset.fitodomikViewerId) {
        modelViewerIdCounter += 1;
        viewer.dataset.fitodomikViewerId = String(modelViewerIdCounter);
    }
    const suffix = viewer.dataset.fitodomikViewerId;
    let mutationObserver = null;
    let repairFrame = 0;
    function applyRepair() {
        if (!viewer.isConnected || !viewer.shadowRoot) {
            return;
        }
        const arButton = viewer.shadowRoot.querySelector('[part~="default-ar-button"]');
        const exitArButton = viewer.shadowRoot.querySelector('[part~="default-exit-webxr-ar-button"]');
        const posterButton = viewer.shadowRoot.querySelector('button[id^="default-poster"]');
        if (arButton) {
            arButton.id = 'default-ar-button-' + suffix;
            arButton.removeAttribute('href');
            arButton.removeAttribute('role');
            arButton.setAttribute('aria-hidden', 'true');
            arButton.tabIndex = -1;
            arButton.setAttribute('inert', '');
            arButton.style.position = 'absolute';
            arButton.style.right = '16px';
            arButton.style.bottom = '16px';
        }
        if (exitArButton) {
            exitArButton.id = 'default-exit-webxr-ar-button-' + suffix;
            exitArButton.tabIndex = -1;
            exitArButton.setAttribute('aria-hidden', 'true');
            exitArButton.setAttribute('inert', '');
            exitArButton.style.display = 'flex';
            exitArButton.style.position = 'absolute';
            exitArButton.style.top = 'env(safe-area-inset-top, 16px)';
            exitArButton.style.right = '16px';
            exitArButton.style.width = '40px';
            exitArButton.style.height = '40px';
            exitArButton.style.boxSizing = 'border-box';
        }
        if (posterButton) {
            posterButton.id = 'default-poster-' + suffix;
            posterButton.style.width = '100%';
            posterButton.style.height = '100%';
            posterButton.style.display = 'block';
            posterButton.style.position = 'absolute';
            posterButton.style.border = 'none';
            posterButton.style.padding = '0';
            posterButton.style.backgroundSize = 'contain';
            posterButton.style.backgroundRepeat = 'no-repeat';
            posterButton.style.backgroundPosition = 'center';
            posterButton.style.backgroundColor = '#fff0';
        }
    }
    applyRepair();
    repairFrame = window.requestAnimationFrame(applyRepair);
    viewer.addEventListener('load', applyRepair);
    if (viewer.shadowRoot) {
        mutationObserver = new MutationObserver(applyRepair);
        mutationObserver.observe(viewer.shadowRoot, { childList: true, subtree: true });
    }
    return function cleanupAccessibilityRepair() {
        window.cancelAnimationFrame(repairFrame);
        viewer.removeEventListener('load', applyRepair);
        mutationObserver?.disconnect();
    };
}
function syncActiveModelMotion() {
    if (!activeModelViewer) {
        return;
    }
    if (document.visibilityState === 'hidden' || reducedMotionQuery.matches) {
        activeModelViewer.removeAttribute('auto-rotate');
    } else {
        activeModelViewer.setAttribute('auto-rotate', '');
    }
}
function disposeActive3DContent() {
    activeModelViewerCleanup?.();
    activeModelViewerCleanup = null;
    if (activeModelViewer) {
        activeModelViewer.removeAttribute('auto-rotate');
        activeModelViewer.removeAttribute('src');
        activeModelViewer.remove();
        activeModelViewer = null;
    }
    if (currentSTLRuntime) {
        currentSTLRuntime.dispose();
        currentSTLRuntime = null;
    }
    document.getElementById('modal-model-container')?.replaceChildren();
}
function open3DModal(modelUrl, title, type, trigger) {
    const modal = document.getElementById('model-modal');
    const modalTitle = document.getElementById('modal-model-title');
    const modalContainer = document.getElementById('modal-model-container');
    disposeActive3DContent();
    lastModelTrigger = trigger || document.activeElement;
    modalTitle.textContent = title;
    modalContainer.innerHTML = modelLoadingMarkup;
    if (typeof modal.showModal === 'function' && !modal.open) {
        modal.showModal();
    } else {
        modal.setAttribute('open', '');
    }
    document.body.style.overflow = 'hidden';
    if (type === 'glb') {
        modalContainer.innerHTML = '';
        const modelViewer = document.createElement('model-viewer');
        modelViewer.setAttribute('src', modelUrl);
        modelViewer.setAttribute('alt', title);
        modelViewer.setAttribute('camera-controls', '');
        modelViewer.setAttribute('shadow-intensity', '1');
        modelViewer.setAttribute('loading', 'eager');
        modelViewer.setAttribute('reveal', 'auto');
        modelViewer.className = 'model-viewer';
        modalContainer.appendChild(modelViewer);
        activeModelViewer = modelViewer;
        syncActiveModelMotion();
        modelViewer.addEventListener('error', function() {
            if (activeModelViewer !== modelViewer) {
                return;
            }
            disposeActive3DContent();
            modalContainer.innerHTML = modelErrorMarkup;
        }, { once: true });
        customElements.whenDefined('model-viewer').then(function() {
            if (activeModelViewer === modelViewer && modelViewer.isConnected) {
                activeModelViewerCleanup = repairModelViewerAccessibility(modelViewer);
            }
        });
    } else {
        modalContainer.innerHTML = '';
        const stlViewer = document.createElement('div');
        stlViewer.className = 'stl-viewer';
        stlViewer.id = 'modal-stl-viewer';
        stlViewer.innerHTML = modelLoadingMarkup;
        modalContainer.appendChild(stlViewer);
        currentSTLRuntime = loadSTLModel(modelUrl, 'modal-stl-viewer');
    }
    window.requestAnimationFrame(function() {
        modal.querySelector('.model-modal-close')?.focus();
    });
}
function close3DModal() {
    const modal = document.getElementById('model-modal');
    if (!modal || !modal.open) {
        return;
    }
    disposeActive3DContent();
    if (typeof modal.close === 'function') {
        modal.close();
    } else {
        modal.removeAttribute('open');
    }
    const lightbox = document.getElementById('lightbox');
    document.body.style.overflow = lightbox?.open ? 'hidden' : '';
    const trigger = lastModelTrigger;
    lastModelTrigger = null;
    if (trigger instanceof HTMLElement && trigger.isConnected) {
        trigger.focus();
    }
}
document.addEventListener('DOMContentLoaded', function() {
    collectGalleryPhotos();
    const lightbox = document.getElementById('lightbox');
    lightbox?.addEventListener('click', function(event) {
        if (event.target === lightbox) {
            closeLightbox();
        }
    });
    lightbox?.addEventListener('cancel', function(event) {
        event.preventDefault();
        closeLightbox();
    });
    document.querySelectorAll('.btn-view-3d').forEach(function(button) {
        button.addEventListener('click', function() {
            open3DModal(
                button.dataset.modelUrl || '',
                button.dataset.modelTitle || '3D-модель',
                button.dataset.modelType || 'glb',
                button
            );
        });
    });
    const modal = document.getElementById('model-modal');
    modal?.addEventListener('click', function(event) {
        if (event.target === modal) {
            close3DModal();
        }
    });
    modal?.addEventListener('cancel', function(event) {
        event.preventDefault();
        close3DModal();
    });
    modal?.querySelector('.model-modal-close')?.addEventListener('click', close3DModal);
});
document.addEventListener('visibilitychange', syncActiveModelMotion);
reducedMotionQuery.addEventListener?.('change', syncActiveModelMotion);
function loadSTLModel(modelPath, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        return null;
    }
    let disposed = false;
    let animationFrame = 0;
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0xb8e6d5);
    const initialWidth = Math.max(container.offsetWidth, 1);
    const initialHeight = Math.max(container.offsetHeight, 1);
    const camera = new THREE.PerspectiveCamera(
        75,
        initialWidth / initialHeight,
        0.1,
        1000
    );
    camera.position.z = 50;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    renderer.setSize(initialWidth, initialHeight);
    renderer.setClearColor(0xb8e6d5, 1);
    container.appendChild(renderer.domElement);
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.8);
    scene.add(ambientLight);
    const directionalLight1 = new THREE.DirectionalLight(0xffffff, 0.6);
    directionalLight1.position.set(5, 5, 5);
    scene.add(directionalLight1);
    const directionalLight2 = new THREE.DirectionalLight(0xffffff, 0.4);
    directionalLight2.position.set(-5, -5, -5);
    scene.add(directionalLight2);
    const directionalLight3 = new THREE.DirectionalLight(0xffffff, 0.3);
    directionalLight3.position.set(0, 5, 0);
    scene.add(directionalLight3);
    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;
    controls.autoRotate = !reducedMotionQuery.matches;
    controls.autoRotateSpeed = 2.0;
    const modelColors = {
        'Soil Moisture Sensor Board.stl': 0x4CAF50,
        'P280, 6-PIN-Version v4.stl': 0x2196F3,
        'MH-Z19B.stl': 0xFF9800,
        'YL-69.stl': 0x9C27B0
    };
    const fileName = modelPath.split('/').pop();
    const modelColor = modelColors[fileName] || 0x00BCD4;
    const loader = new THREE.STLLoader();
    const loadingDiv = container.querySelector('div');
    function disposeMaterial(material) {
        Object.keys(material).forEach(function(key) {
            const value = material[key];
            if (value?.isTexture && typeof value.dispose === 'function') {
                value.dispose();
            }
        });
        material.dispose();
    }
    function handleResize() {
        if (disposed || container.offsetWidth <= 0 || container.offsetHeight <= 0) {
            return;
        }
        camera.aspect = container.offsetWidth / container.offsetHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.offsetWidth, container.offsetHeight);
    }
    const runtime = {
        dispose: function() {
            if (disposed) {
                return;
            }
            disposed = true;
            window.cancelAnimationFrame(animationFrame);
            window.removeEventListener('resize', handleResize);
            controls.dispose();
            scene.traverse(function(object) {
                object.geometry?.dispose?.();
                if (Array.isArray(object.material)) {
                    object.material.forEach(disposeMaterial);
                } else if (object.material) {
                    disposeMaterial(object.material);
                }
            });
            renderer.dispose();
            renderer.forceContextLoss?.();
            renderer.domElement.remove();
        }
    };
    loader.load(
        modelPath,
        function(geometry) {
            if (disposed) {
                geometry.dispose();
                return;
            }
            if (loadingDiv && loadingDiv.parentNode === container) {
                container.removeChild(loadingDiv);
            }
            const material = new THREE.MeshPhongMaterial({
                color: modelColor,
                specular: 0x444444,
                shininess: 100,
                flatShading: false
            });
            const mesh = new THREE.Mesh(geometry, material);
            geometry.computeBoundingBox();
            const boundingBox = geometry.boundingBox;
            const center = new THREE.Vector3();
            boundingBox.getCenter(center);
            mesh.position.sub(center);
            const size = new THREE.Vector3();
            boundingBox.getSize(size);
            const maxDim = Math.max(size.x, size.y, size.z);
            const scale = 40 / maxDim;
            mesh.scale.set(scale, scale, scale);
            scene.add(mesh);
        },
        function(xhr) {
            if (!disposed && xhr.total > 0) {
                const percentComplete = (xhr.loaded / xhr.total * 100);
                if (loadingDiv) {
                    loadingDiv.textContent = 'Загрузка: ' + Math.round(percentComplete) + '%';
                }
            }
        },
        function() {
            runtime.dispose();
            if (currentSTLRuntime === runtime) {
                currentSTLRuntime = null;
            }
            container.innerHTML = modelErrorMarkup;
        }
    );
    function animate() {
        if (disposed) {
            return;
        }
        animationFrame = window.requestAnimationFrame(animate);
        if (document.visibilityState === 'visible' && container.isConnected) {
            controls.update();
            renderer.render(scene, camera);
        }
    }
    animate();
    window.addEventListener('resize', handleResize, { passive: true });
    return runtime;
}
document.addEventListener('keydown', function(e) {
    const lightbox = document.getElementById('lightbox');
    const isLightboxOpen = Boolean(lightbox?.open);
    if (isLightboxOpen && e.key === 'ArrowLeft') {
        showPreviousPhoto(e);
    }
    if (isLightboxOpen && e.key === 'ArrowRight') {
        showNextPhoto(e);
    }
});
</script>
<?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../../layout.php';
} catch(Throwable $e) {
    error_log("Ошибка БД: " . $e->getMessage());
    die("Ошибка при получении списка фотографий");
}
?>
