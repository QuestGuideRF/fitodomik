<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/device_context.php';
require_once __DIR__ . '/../../includes/plant_catalog.php';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isLoggedIn = isset($_SESSION['user_id']);
$selectedDeviceId = '';
if ($isLoggedIn) {
    try {
        $selectedDeviceId = fitodomikGetSelectedDeviceId($pdo, (int)$_SESSION['user_id']);
        $requestedDeviceId = trim((string)($_GET['device_id'] ?? ''));
        if ($requestedDeviceId !== '') {
            $deviceCheck = $pdo->prepare("\n                SELECT 1 FROM user_devices\n                WHERE user_id = ? AND device_id = ? AND status = 'active'\n                LIMIT 1\n            ");
            $deviceCheck->execute([(int)$_SESSION['user_id'], $requestedDeviceId]);
            if ($deviceCheck->fetchColumn() !== false) {
                $selectedDeviceId = $requestedDeviceId;
            }
        }
    } catch (Throwable $e) {
        error_log('plant catalog selected device: ' . $e->getMessage());
    }
}
$requestedPlant = trim((string)($_GET['plant'] ?? ''));
$plant = null;
$catalog = null;
$categories = [];
$loadError = null;
try {
    $categories = fitodomikPlantCatalogCategories($pdo);
    if ($requestedPlant !== '') {
        $plant = fitodomikPlantCatalogItem($pdo, $requestedPlant);
        if ($plant === null) {
            http_response_code(404);
        }
    } else {
        $catalog = fitodomikPlantCatalogList($pdo, $_GET);
    }
} catch (Throwable $e) {
    error_log('plant catalog page: ' . $e->getMessage());
    $loadError = 'Каталог временно недоступен';
}
$pageTitle = $plant ? $plant['name_ru'] . ' — каталог растений ФитоДомика' : 'Каталог растений — ФитоДомик';
$pageDescription = $plant
    ? (string)$plant['short_description']
    : 'Каталог готовых режимов ФитоДомика: поиск растений, рекомендации по температуре, влажности почвы и освещению.';
$pageCanonical = 'https://fitodomik.ru/pages/plant-catalog/' . ($plant ? '?plant=' . rawurlencode((string)$plant['slug']) : '');
$pageStylesheets = ['/static/css/plant-catalog.v1.css'];
$pageScripts = ['/static/js/plant-catalog.v1.js'];
$pageSchemaType = $plant ? 'Thing' : 'CollectionPage';
$pageStructuredData = $plant ? [
    '@context' => 'https://schema.org',
    '@type' => 'Thing',
    'name' => $plant['name_ru'],
    'alternateName' => $plant['name_latin'],
    'description' => $plant['short_description'],
    'image' => 'https://fitodomik.ru' . $plant['image_path'],
    'url' => $pageCanonical,
] : [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Каталог растений ФитоДомика',
    'description' => $pageDescription,
    'url' => $pageCanonical,
];
$detailUrl = static function (string $slug) use ($selectedDeviceId): string {
    $params = ['plant' => $slug];
    if ($selectedDeviceId !== '') {
        $params['device_id'] = $selectedDeviceId;
    }
    return '/pages/plant-catalog/?' . http_build_query($params);
};
$formatRange = static function ($min, $max, string $suffix = ''): string {
    if ($min === null || $max === null) {
        return 'Не применяется';
    }
    return rtrim(rtrim(number_format((float)$min, 1, '.', ''), '0'), '.') . '–'
        . rtrim(rtrim(number_format((float)$max, 1, '.', ''), '0'), '.') . $suffix;
};
$formatDays = static function ($min, $max): string {
    if ($min === null || $max === null) {
        return 'Многолетний цикл';
    }
    return (int)$min . '–' . (int)$max . ' дней';
};
ob_start();
?>
<div
    class="plant-catalog-page"
    data-catalog-root
    data-list-api="/api/plant-catalog-list.php"
    data-add-api="/api/plant-catalog-add-mode.php"
    data-csrf-token="<?= $escape($isLoggedIn ? fitodomikCsrfToken() : '') ?>"
    data-device-id="<?= $escape($selectedDeviceId) ?>">
    <?php if ($loadError): ?>
        <section class="plant-catalog-state" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <h1><?= $escape($loadError) ?></h1>
            <p>Попробуйте обновить страницу через несколько минут.</p>
        </section>
    <?php elseif ($requestedPlant !== '' && $plant === null): ?>
        <section class="plant-catalog-state">
            <i class="bi bi-search" aria-hidden="true"></i>
            <h1>Растение не найдено</h1>
            <p>Возможно, профиль отключён или адрес изменился.</p>
            <a class="plant-button plant-button--primary" href="/pages/plant-catalog/">Открыть каталог</a>
        </section>
    <?php elseif ($plant): ?>
        <nav class="plant-breadcrumbs" aria-label="Навигация каталога">
            <a href="/farm.php#presetModesHeader"><i class="bi bi-arrow-left" aria-hidden="true"></i> Мои режимы</a>
            <a href="/pages/plant-catalog/">Каталог растений</a>
        </nav>
        <article class="plant-detail">
            <div class="plant-detail__media">
                <img src="<?= $escape($plant['image_path']) ?>" alt="<?= $escape($plant['name_ru']) ?> — <?= $escape($plant['name_latin']) ?>" width="640" height="420">
                <a class="plant-photo-credit" href="<?= $escape($plant['image_source_url']) ?>" target="_blank" rel="noopener noreferrer">
                    Фото: <?= $escape($plant['image_author']) ?> · <?= $escape($plant['image_license']) ?>
                </a>
            </div>
            <div class="plant-detail__intro">
                <span class="plant-badge"><?= $escape($plant['category_name']) ?></span>
                <h1><?= $escape($plant['name_ru']) ?></h1>
                <p class="plant-latin"><?= $escape($plant['name_latin']) ?></p>
                <p><?= $escape($plant['full_description']) ?></p>
                <div class="plant-detail__facts">
                    <span><i class="bi bi-bar-chart" aria-hidden="true"></i> <?= $escape(fitodomikPlantCatalogDifficultyLabel((string)$plant['difficulty'])) ?></span>
                    <span><i class="bi bi-clock-history" aria-hidden="true"></i> <?= $escape($formatDays($plant['harvest_days_min'], $plant['harvest_days_max'])) ?></span>
                    <span><i class="bi bi-house" aria-hidden="true"></i> <?= (int)$plant['suitable_indoor'] === 1 ? 'Подходит для дома' : 'Нужно больше пространства' ?></span>
                    <span><i class="bi bi-cpu" aria-hidden="true"></i> <?= (int)$plant['suitable_fitodomik'] === 1 ? 'Подходит для ФитоДомика' : 'Только расширенная установка' ?></span>
                </div>
                <div class="plant-detail__actions">
                    <?php if ($isLoggedIn): ?>
                        <button class="plant-button plant-button--primary" type="button" data-add-mode data-plant="<?= $escape($plant['slug']) ?>">
                            <i class="bi bi-plus-circle" aria-hidden="true"></i> Добавить режим
                        </button>
                    <?php else: ?>
                        <a class="plant-button plant-button--primary" href="/authentication/login.php?redirect=<?= rawurlencode($detailUrl((string)$plant['slug'])) ?>">
                            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Войти и добавить
                        </a>
                    <?php endif; ?>
                    <a class="plant-button plant-button--secondary" href="/pages/plant-catalog/">Продолжить поиск</a>
                </div>
                <p class="plant-device-note">
                    <?= $selectedDeviceId !== '' ? 'При добавлении будет сохранено выбранное устройство. Сам режим останется независимой редактируемой копией.' : 'Режим будет сохранён в аккаунт и применится к устройству, выбранному в кабинете.' ?>
                </p>
            </div>
        </article>
        <p class="plant-recommendation-note">
            Параметры являются базовыми рекомендациями. Условия могут отличаться в зависимости от сорта, стадии роста и используемого оборудования.
        </p>
        <div class="plant-detail-grid">
            <section class="plant-info-panel">
                <h2><i class="bi bi-sliders" aria-hidden="true"></i> Настройки режима</h2>
                <dl class="plant-specs">
                    <div><dt>Температура</dt><dd><?= $escape($formatRange($plant['temperature_min'], $plant['temperature_max'], ' °C')) ?></dd></div>
                    <div><dt>Влажность воздуха</dt><dd><?= $escape($formatRange($plant['air_humidity_min'], $plant['air_humidity_max'], '%')) ?></dd></div>
                    <div><dt>Влажность почвы</dt><dd><?= $escape($formatRange($plant['soil_moisture_min'], $plant['soil_moisture_max'], '%')) ?></dd></div>
                    <div><dt>Свет</dt><dd><?= $escape($formatRange($plant['light_hours_min'], $plant['light_hours_max'], ' ч/сутки')) ?></dd></div>
                    <div><dt>Интенсивность</dt><dd><?= $escape($plant['light_intensity']) ?></dd></div>
                    <div><dt>Прорастание</dt><dd><?= $escape($formatDays($plant['germination_days_min'], $plant['germination_days_max'])) ?></dd></div>
                </dl>
            </section>
            <section class="plant-info-panel">
                <h2><i class="bi bi-droplet" aria-hidden="true"></i> Полив и воздух</h2>
                <h3>Полив</h3><p><?= $escape($plant['watering_notes']) ?></p>
                <h3>Вентиляция</h3><p><?= $escape($plant['ventilation_notes']) ?></p>
                <h3>CO₂</h3><p><?= $escape($plant['co2_notes']) ?></p>
            </section>
            <section class="plant-info-panel">
                <h2><i class="bi bi-flower1" aria-hidden="true"></i> Выращивание</h2>
                <h3>Ёмкость</h3><p><?= $escape($plant['container_notes']) ?></p>
                <h3>Особый уход</h3><p><?= $escape($plant['care_notes']) ?></p>
                <h3>Период покоя</h3><p><?= $escape($plant['dormancy_notes']) ?></p>
            </section>
            <section class="plant-info-panel">
                <h2><i class="bi bi-activity" aria-hidden="true"></i> Признаки проблем</h2>
                <h3>Недостаток воды</h3><p><?= $escape($plant['underwater_signs']) ?></p>
                <h3>Перелив</h3><p><?= $escape($plant['overwater_signs']) ?></p>
                <h3>Возможные сложности</h3><p><?= $escape($plant['challenges']) ?></p>
            </section>
            <section class="plant-info-panel plant-info-panel--wide">
                <h2><i class="bi bi-journal-text" aria-hidden="true"></i> Дополнительная информация</h2>
                <div class="plant-copy-columns">
                    <div><h3>Интересный факт</h3><p><?= $escape($plant['interesting_fact']) ?></p></div>
                    <div><h3>Урожай и вкус</h3><p><?= $escape($plant['harvest_notes']) ?> <?= $escape($plant['taste_notes']) ?></p></div>
                    <div><h3>Пищевой профиль</h3><p><?= $escape($plant['vitamins']) ?></p></div>
                    <div><h3>Предупреждение</h3><p><?= $escape($plant['warnings']) ?></p></div>
                </div>
            </section>
            <section class="plant-info-panel plant-info-panel--wide">
                <h2><i class="bi bi-link-45deg" aria-hidden="true"></i> Источники рекомендаций</h2>
                <ul class="plant-source-list">
                    <?php foreach ($plant['source_urls'] as $sourceUrl): ?>
                        <li><a href="<?= $escape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $escape((string)parse_url($sourceUrl, PHP_URL_HOST)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    <?php else: ?>
        <?php $pagination = $catalog['pagination']; $filters = $catalog['filters']; ?>
        <header class="plant-catalog-header">
            <div>
                <span class="plant-catalog-kicker">Готовые настройки выращивания</span>
                <h1>Каталог растений</h1>
                <p>Найдите профиль, проверьте рекомендации и добавьте независимую копию режима в свой аккаунт.</p>
            </div>
            <a class="plant-button plant-button--secondary" href="/farm.php#presetModesHeader"><i class="bi bi-arrow-left" aria-hidden="true"></i> К моим режимам</a>
        </header>
        <form class="plant-filters" data-catalog-form method="get" action="/pages/plant-catalog/">
            <?php if ($selectedDeviceId !== ''): ?>
                <input type="hidden" name="device_id" value="<?= $escape($selectedDeviceId) ?>">
            <?php endif; ?>
            <label class="plant-search-field">
                <span>Поиск</span>
                <span class="plant-input-wrap"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" value="<?= $escape($filters['q']) ?>" placeholder="Базилик, Ocimum, зелень" maxlength="80" autocomplete="off"></span>
            </label>
            <label><span>Категория</span><select name="category"><option value="">Все растения</option><?php foreach ($categories as $category): ?><option value="<?= $escape($category['slug']) ?>" <?= $filters['category'] === $category['slug'] ? 'selected' : '' ?>><?= $escape($category['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Сложность</span><select name="difficulty"><option value="">Любая</option><option value="easy" <?= $filters['difficulty'] === 'easy' ? 'selected' : '' ?>>Просто</option><option value="medium" <?= $filters['difficulty'] === 'medium' ? 'selected' : '' ?>>Средне</option><option value="hard" <?= $filters['difficulty'] === 'hard' ? 'selected' : '' ?>>Сложно</option></select></label>
            <label><span>Сортировка</span><select name="sort"><option value="popular" <?= $filters['sort'] === 'popular' ? 'selected' : '' ?>>Популярные</option><option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>По названию</option><option value="fast" <?= $filters['sort'] === 'fast' ? 'selected' : '' ?>>Быстрее урожай</option><option value="easy" <?= $filters['sort'] === 'easy' ? 'selected' : '' ?>>Сначала простые</option><option value="new" <?= $filters['sort'] === 'new' ? 'selected' : '' ?>>Сначала новые</option></select></label>
            <details class="plant-filter-details">
                <summary>Дополнительные фильтры</summary>
                <div class="plant-filter-details__grid">
                    <label><span>Урожай не дольше</span><select name="harvest_max"><option value="0">Не важно</option><option value="30" <?= $filters['harvest_max'] === 30 ? 'selected' : '' ?>>30 дней</option><option value="60" <?= $filters['harvest_max'] === 60 ? 'selected' : '' ?>>60 дней</option><option value="90" <?= $filters['harvest_max'] === 90 ? 'selected' : '' ?>>90 дней</option><option value="180" <?= $filters['harvest_max'] === 180 ? 'selected' : '' ?>>180 дней</option></select></label>
                    <label><span>Свет не более</span><select name="light_max"><option value="0">Не важно</option><option value="12" <?= $filters['light_max'] === 12 ? 'selected' : '' ?>>12 часов</option><option value="14" <?= $filters['light_max'] === 14 ? 'selected' : '' ?>>14 часов</option><option value="16" <?= $filters['light_max'] === 16 ? 'selected' : '' ?>>16 часов</option></select></label>
                    <label><span>Температура комнаты</span><select name="temperature"><option value="0">Не важно</option><?php foreach ([18, 20, 22, 24, 26, 28] as $temperature): ?><option value="<?= $temperature ?>" <?= $filters['temperature'] === $temperature ? 'selected' : '' ?>><?= $temperature ?> °C</option><?php endforeach; ?></select></label>
                    <div class="plant-checks"><label><input type="checkbox" name="indoor" value="1" <?= $filters['indoor'] ? 'checked' : '' ?>> Для дома</label><label><input type="checkbox" name="edible" value="1" <?= $filters['edible'] ? 'checked' : '' ?>> Съедобные</label><label><input type="checkbox" name="decorative" value="1" <?= $filters['decorative'] ? 'checked' : '' ?>> Декоративные</label><label><input type="checkbox" name="fast" value="1" <?= $filters['fast'] ? 'checked' : '' ?>> Быстрорастущие</label></div>
                </div>
            </details>
            <div class="plant-filter-actions"><button class="plant-button plant-button--primary" type="submit">Применить</button><a class="plant-button plant-button--ghost" href="/pages/plant-catalog/<?= $selectedDeviceId !== '' ? '?device_id=' . rawurlencode($selectedDeviceId) : '' ?>">Сбросить</a></div>
        </form>
        <div class="plant-results-heading"><p data-result-count>Найдено растений: <strong><?= (int)$pagination['total'] ?></strong></p><output data-loading-status aria-live="polite" aria-atomic="true"></output></div>
        <div class="plant-grid" data-catalog-grid>
            <?php foreach ($catalog['items'] as $item): ?>
                <article class="plant-card">
                    <a class="plant-card__image-link" href="<?= $escape($detailUrl((string)$item['slug'])) ?>" aria-label="Подробнее: <?= $escape($item['name_ru']) ?>"><img src="<?= $escape($item['image_path']) ?>" alt="<?= $escape($item['name_ru']) ?> — <?= $escape($item['name_latin']) ?>" width="640" height="420" loading="lazy" decoding="async"></a>
                    <div class="plant-card__body"><div class="plant-card__badges"><span><?= $escape($item['category_name']) ?></span><span><?= $escape(fitodomikPlantCatalogDifficultyLabel((string)$item['difficulty'])) ?></span></div><h2><a href="<?= $escape($detailUrl((string)$item['slug'])) ?>"><?= $escape($item['name_ru']) ?></a></h2><p class="plant-latin"><?= $escape($item['name_latin']) ?></p><p><?= $escape($item['short_description']) ?></p><dl class="plant-card__specs"><div><dt>Температура</dt><dd><?= $escape($formatRange($item['temperature_min'], $item['temperature_max'], ' °C')) ?></dd></div><div><dt>Свет</dt><dd><?= $escape($formatRange($item['light_hours_min'], $item['light_hours_max'], ' ч')) ?></dd></div><div><dt>До зрелости</dt><dd><?= $escape($formatDays($item['harvest_days_min'], $item['harvest_days_max'])) ?></dd></div></dl></div>
                    <div class="plant-card__actions"><a class="plant-button plant-button--secondary" href="<?= $escape($detailUrl((string)$item['slug'])) ?>">Подробнее</a><?php if ($isLoggedIn): ?><button class="plant-button plant-button--primary" type="button" data-add-mode data-plant="<?= $escape($item['slug']) ?>">Добавить режим</button><?php else: ?><a class="plant-button plant-button--primary" href="/authentication/login.php?redirect=<?= rawurlencode($detailUrl((string)$item['slug'])) ?>">Добавить режим</a><?php endif; ?></div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="plant-empty" data-empty-state <?= $pagination['total'] > 0 ? 'hidden' : '' ?>><i class="bi bi-search" aria-hidden="true"></i><h2>Ничего не найдено</h2><p>Попробуйте убрать часть фильтров или изменить запрос.</p></div>
        <nav class="plant-pagination" data-pagination aria-label="Страницы каталога">
            <?php if ($pagination['pages'] > 1): ?>
                <?php $query = $_GET; ?>
                <?php if ($pagination['page'] > 1): $query['page'] = $pagination['page'] - 1; ?><a class="plant-button plant-button--secondary" href="?<?= $escape(http_build_query($query)) ?>">Назад</a><?php endif; ?>
                <span>Страница <?= (int)$pagination['page'] ?> из <?= (int)$pagination['pages'] ?></span>
                <?php if ($pagination['page'] < $pagination['pages']): $query['page'] = $pagination['page'] + 1; ?><a class="plant-button plant-button--primary" href="?<?= $escape(http_build_query($query)) ?>">Показать ещё</a><?php endif; ?>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <dialog class="plant-mode-dialog" data-mode-dialog>
        <form method="dialog">
            <button class="plant-dialog-close" value="cancel" aria-label="Закрыть"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <div data-dialog-content></div>
        </form>
    </dialog>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
