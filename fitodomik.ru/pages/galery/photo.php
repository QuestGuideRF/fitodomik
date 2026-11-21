<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/gallery_helpers.php';
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
try {
    $photo = null;
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM photos WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_GET['id']]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$photo && isset($_GET['slug']) && trim((string)$_GET['slug']) !== '') {
        $stmt = $pdo->prepare("SELECT * FROM photos WHERE slug = ? LIMIT 1");
        $stmt->execute([galleryCleanText($_GET['slug'])]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$photo || !galleryPhotoFileExists($photo)) {
        http_response_code(404);
        $pageTitle = 'Фото не найдено — ФитоДомик';
        ob_start();
        ?>
        <div class="py-5 text-center">
            <h1>Фото не найдено</h1>
            <p class="lead">Возможно, фотография была удалена или ссылка устарела.</p>
            <a class="btn btn-success" href="/pages/galery/">Вернуться в галерею</a>
        </div>
        <?php
        $content = ob_get_clean();
        require_once __DIR__ . '/../../layout.php';
        exit;
    }
    $photoTitle = galleryPhotoTitle($photo);
    $contentAlt = galleryPhotoAlt($photo);
    $photoDescription = galleryPhotoDescription($photo);
    $photoKeywords = galleryPhotoKeywords($photo);
    $photoImage = galleryPhotoImageUrl($photo, true);
    $photoPage = galleryPhotoPageUrl($photo, true);
    $pageTitle = $photoTitle . ' — галерея ФитоДомик';
    $pageDescription = $photoDescription;
    $pageKeywords = $photoKeywords;
    $pageImage = $photoImage;
    $pageStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'ImageObject',
    ] + galleryBuildImageSchema($photo);
    if ((int)($photo['is_indexable'] ?? 1) === 0) {
        $pageRobots = 'noindex, follow';
    }
    ob_start();
?>
<article class="photo-page" itemscope itemtype="https://schema.org/ImageObject">
    <nav class="photo-breadcrumb" aria-label="Навигация">
        <a href="/pages/galery/">Галерея</a>
        <span>/</span>
        <span><?php echo $escape($photoTitle); ?></span>
    </nav>
    <header class="photo-header">
        <h1 itemprop="name"><?php echo $escape($photoTitle); ?></h1>
        <p itemprop="description"><?php echo nl2br($escape($photoDescription)); ?></p>
    </header>
    <figure class="photo-figure">
        <a href="<?php echo $escape(galleryPhotoImageUrl($photo)); ?>" itemprop="contentUrl">
            <img src="<?php echo $escape(galleryPhotoImageUrl($photo)); ?>"
                 alt="<?php echo $escape($contentAlt); ?>"
                 title="<?php echo $escape($photoTitle); ?>"
                 loading="eager"
                 decoding="async"
                 <?php echo !empty($photo['image_width']) ? 'width="' . (int)$photo['image_width'] . '"' : ''; ?>
                 <?php echo !empty($photo['image_height']) ? 'height="' . (int)$photo['image_height'] . '"' : ''; ?>>
        </a>
        <figcaption itemprop="caption">
            <?php echo $escape($contentAlt); ?>
        </figcaption>
    </figure>
    <section class="photo-meta">
        <?php if (!empty($photo['created_at'])): ?>
            <div>
                <strong>Дата:</strong>
                <time itemprop="datePublished" datetime="<?php echo $escape(date('c', strtotime((string)$photo['created_at']))); ?>">
                    <?php echo $escape(date('d.m.Y H:i', strtotime((string)$photo['created_at']))); ?>
                </time>
            </div>
        <?php endif; ?>
        <div>
            <strong>Темы:</strong>
            <?php foreach (array_filter(array_map('trim', explode(',', $photoKeywords))) as $keyword): ?>
                <span class="photo-keyword"><?php echo $escape($keyword); ?></span>
            <?php endforeach; ?>
        </div>
    </section>
    <div class="photo-actions">
        <a class="btn btn-success" href="/pages/galery/">Назад в галерею</a>
        <a class="btn btn-outline-success" href="<?php echo $escape(galleryPhotoImageUrl($photo)); ?>">Открыть изображение</a>
    </div>
    <meta itemprop="url" content="<?php echo $escape($photoPage); ?>">
    <meta itemprop="thumbnailUrl" content="<?php echo $escape($photoImage); ?>">
</article>
<style>
.photo-page {
    max-width: 1040px;
    margin: 0 auto;
    padding: 2rem 0 3rem;
}
.photo-breadcrumb {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    color: var(--text-color);
    opacity: 0.78;
}
.photo-breadcrumb a {
    color: var(--primary-dark);
    font-weight: 700;
    text-decoration: none;
}
.photo-header {
    text-align: center;
    margin-bottom: 2rem;
}
.photo-header h1 {
    color: var(--text-color);
    font-weight: 800;
}
.photo-header p {
    max-width: 760px;
    margin: 0.75rem auto 0;
    color: var(--text-color);
    opacity: 0.8;
    line-height: 1.6;
}
.photo-figure {
    overflow: hidden;
    border: 1px solid var(--border-color);
    border-radius: 18px;
    background: var(--card-bg);
    box-shadow: 0 18px 55px rgba(38, 82, 48, 0.12);
}
.photo-figure img {
    display: block;
    width: 100%;
    height: auto;
    max-height: 75vh;
    object-fit: contain;
    background: var(--card-bg);
}
.photo-figure figcaption {
    padding: 1rem 1.25rem;
    color: var(--text-color);
    text-align: center;
}
.photo-meta {
    display: grid;
    gap: 1rem;
    margin-top: 1.5rem;
    padding: 1.25rem;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    background: var(--card-bg);
    color: var(--text-color);
}
.photo-keyword {
    display: inline-flex;
    margin: 0.25rem 0.25rem 0 0;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    background: rgba(47, 143, 70, 0.12);
    color: var(--text-color);
    font-size: 0.9rem;
}
.photo-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: center;
    margin-top: 1.75rem;
}
</style>
<?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../../layout.php';
} catch (Throwable $e) {
    error_log('Gallery photo page failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Не удалось загрузить страницу изображения';
}
