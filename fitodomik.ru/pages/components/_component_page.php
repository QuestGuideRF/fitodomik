<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/components_data.php';
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$component = fitodomikComponent((string) ($componentSlug ?? ''));
if (!$component) {
    http_response_code(404);
    $pageTitle = 'Компонент не найден — ФитоДомик';
    $pageDescription = 'Компонент ФитоДомика не найден.';
    ob_start();
    ?>
    <div class="component-detail-page">
        <a class="component-back-link" href="/pages/components/">← Назад к схеме</a>
        <section class="component-detail-hero">
            <span class="expenses-hero__eyebrow">Компонент не найден</span>
            <h1>Такого компонента нет в схеме</h1>
            <p>Вернитесь к интерактивной схеме и выберите один из доступных компонентов ФитоДомика.</p>
        </section>
    </div>
    <?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../../layout.php';
    return;
}
$pageTitle = $component['seo_title'];
$pageDescription = $component['seo_description'];
$pageKeywords = $component['keywords'];
$pageImage = '/assets/images/components-sequence/27.webp';
$pageSchemaType = 'TechArticle';
$pageStructuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'TechArticle',
    'headline' => $component['seo_title'],
    'description' => $component['seo_description'],
    'image' => fitodomikRuSiteUrl() . '/assets/images/components-sequence/27.webp',
    'url' => fitodomikRuSiteUrl() . '/pages/components/' . $component['slug'] . '/',
    'keywords' => $component['keywords'],
    'about' => [
        '@type' => 'Thing',
        'name' => $component['title'],
    ],
];
$modelUrl = (string) ($component['model'] ?? '');
$modelFile = $modelUrl !== '' ? __DIR__ . '/../..' . $modelUrl : '';
$modelExists = $modelFile !== '' && is_file($modelFile);
ob_start();
?>
<div class="component-detail-page">
    <a class="component-back-link" href="/pages/components/">← Назад к схеме</a>
    <section class="component-detail-hero">
        <span class="expenses-hero__eyebrow">Компонент ФитоДомика</span>
        <h1><?= $escape($component['title']) ?></h1>
        <p><?= $escape($component['short']) ?></p>
    </section>
    <div class="component-detail-layout">
        <div class="component-detail-stack">
            <section class="component-detail-card">
                <h2>Для чего используется</h2>
                <p><?= $escape($component['usage']) ?></p>
            </section>
            <section class="component-detail-card">
                <h2>За что отвечает</h2>
                <p><?= $escape($component['responsibility']) ?></p>
            </section>
            <section class="component-detail-card">
                <h2>Как подключается</h2>
                <p><?= $escape($component['connection']) ?></p>
            </section>
            <section class="component-detail-card">
                <h2>Используемые пины</h2>
                <ul>
                    <?php foreach ($component['pins'] as $pin): ?>
                        <li><?= $escape($pin) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="component-detail-card">
                <h2>Роль в системе</h2>
                <p><?= $escape($component['role']) ?></p>
            </section>
            <section class="component-detail-card">
                <h2>Краткая карточка</h2>
                <dl class="component-specs">
                    <dt>Проект</dt>
                    <dd>ФитоДомик</dd>
                    <dt>Компонент</dt>
                    <dd><?= $escape($component['title']) ?></dd>
                    <dt>Раздел</dt>
                    <dd>Интерактивная схема компонентов</dd>
                    <dt>3D-модель</dt>
                    <dd><?= $modelExists ? 'Доступна' : 'Скоро будет добавлена' ?></dd>
                </dl>
            </section>
        </div>
        <aside class="component-model-card">
            <h2>3D-модель</h2>
            <?php if ($modelExists): ?>
                <div class="model-viewer-container component-model-viewer-container">
                    <model-viewer
                        class="model-viewer component-model-viewer"
                        src="<?= $escape($modelUrl) ?>"
                        alt="3D-модель <?= $escape($component['title']) ?>"
                        auto-rotate
                        camera-controls
                        camera-orbit="0deg 75deg auto"
                        min-camera-orbit="auto auto 70%"
                        max-camera-orbit="auto auto 180%"
                        field-of-view="24deg"
                        shadow-intensity="1"
                        loading="eager"
                        reveal="auto"
                        exposure="1"
                        environment-image="neutral"
                        skybox-image="">
                        <div slot="poster" class="component-model-poster">
                            <div>
                                <strong>Загрузка 3D-модели...</strong>
                                <span>Если модель не появилась, обновите страницу.</span>
                            </div>
                        </div>
                    </model-viewer>
                </div>
            <?php else: ?>
                <div class="component-model-fallback">
                    <strong>3D-модель скоро будет добавлена</strong>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php if ($modelExists): ?>
<script type="module" src="/assets/js/model-viewer.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('model-viewer.component-model-viewer').forEach(function (viewer) {
        viewer.addEventListener('error', function () {
            var parent = viewer.parentElement;
            if (parent) {
                parent.innerHTML = '<div class="component-model-fallback"><strong>Ошибка загрузки 3D-модели</strong><span>Проверьте доступность файла в папке /3d/.</span></div>';
            }
        });
    });
});
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
