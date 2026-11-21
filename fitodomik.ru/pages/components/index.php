<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/components_data.php';
$pageTitle = 'Компоненты ФитоДомика — Arduino, Raspberry Pi, датчики и интерактивная схема';
$pageDescription = 'Интерактивная схема компонентов ФитоДомика: Arduino Nano, Raspberry Pi 3B, BME280, MH-Z19B, YL-69, релейный модуль, USB-камера и 3D-модели.';
$pageKeywords = 'ФитоДомик, компоненты ФитоДомика, схема ФитоДомик, Arduino Nano, Raspberry Pi 3B, BME280, MH-Z19B, YL-69, реле Arduino, USB камера, умная теплица, IoT теплица, 3D модель Arduino, 3D модель датчика';
$pageImage = '/assets/images/components-sequence/27.webp';
$pageSchemaType = 'TechArticle';
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$interactiveComponents = fitodomikComponents();
$componentFrames = array_map(
    static function (int $index): string {
        return '/static/components/frame-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.v17.webp';
    },
    range(1, 27)
);
$pageStructuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'TechArticle',
    'headline' => $pageTitle,
    'description' => $pageDescription,
    'image' => fitodomikRuSiteUrl() . '/assets/images/components-sequence/27.webp',
    'url' => fitodomikRuSiteUrl() . '/pages/components/',
    'keywords' => $pageKeywords,
    'about' => array_map(static function (array $component): string {
        return $component['title'];
    }, $interactiveComponents),
];
try {
    $stmt = $pdo->prepare("
        SELECT name, quantity, price
        FROM components
        WHERE status = 'bought'
          AND price_type = 'custom'
          AND price IS NOT NULL
          AND price > 0
          AND name NOT LIKE '%DS3231%'
          AND name NOT LIKE '%RTC%'
        ORDER BY created_at DESC, name ASC
    ");
    $stmt->execute();
    $components = $stmt->fetchAll();
    $items = [];
    $grandTotal = 0;
    foreach ($components as $component) {
        $quantity = max(1, (int) ($component['quantity'] ?? 1));
        $price = (float) $component['price'];
        $lineTotal = $price * $quantity;
        $items[] = [
            'name' => $component['name'],
            'quantity' => $quantity,
            'price' => $price,
            'line_total' => $lineTotal,
        ];
        $grandTotal += $lineTotal;
    }
    $formatCurrency = static function ($value) {
        return number_format((float) $value, 0, ',', ' ') . ' ₽';
    };
    $wiringBlocks = [
        [
            'title' => 'Arduino Nano',
            'tag' => 'центр датчиков и реле',
            'items' => [
                'A6 -> YL-69, аналоговая влажность почвы',
                'A4 SDA / A5 SCL -> BME280 по I2C',
                'D2/D3 -> MH-Z19B по UART',
                'D7 -> реле 1: вентилятор',
                'D6 -> реле 2: насос',
                'D5 -> реле 3: шторы',
                'D4 -> реле 4: лампа',
            ],
        ],
        [
            'title' => 'Raspberry Pi 3B',
            'tag' => 'desktop и интернет',
            'items' => [
                'USB -> Arduino Nano, Serial 9600',
                'USB -> веб-камера для фото и анализа',
                'Wi-Fi/Ethernet -> fitodomik.ru API',
                'Локальные очереди хранят данные при недоступном сервере',
            ],
        ],
        [
            'title' => 'Питание и земля',
            'tag' => 'общая шина',
            'items' => [
                'GND датчиков, Arduino и модулей объединяется в общую землю',
                'BME280 подключается к 3.3V, если используется соответствующий модуль',
                'MH-Z19B и реле питаются от 5V по требованиям модулей',
                'Насос, лампа, вентилятор и шторы подключаются через контакты реле',
            ],
        ],
    ];
    ob_start();
    ?>
    <div class="expenses-page">
        <section class="expenses-hero">
            <span class="expenses-hero__eyebrow">Компоненты и подключение</span>
            <h1>Компоненты ФитоДомика и схема работы</h1>
            <p>Здесь собраны реальные компоненты ФитоДомика, их назначение, схема подключения, требования к питанию и ссылки на подробные описания и 3D-модели.</p>
        </section>
        <section class="components-scroll-section" aria-label="Схема компонентов ФитоДомика">
            <div
                class="components-scroll-scene"
                data-components-scroll
                data-frames='<?= $escape(json_encode($componentFrames, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'
                data-total="<?= $escape(count($componentFrames)) ?>">
                <div class="components-scroll-sticky">
                    <div class="components-progress" aria-hidden="true">
                        <span data-frame-progress></span>
                    </div>
                    <div class="components-frame-wrap">
                        <img
                            class="components-frame"
                            src="<?= $escape($componentFrames[0]) ?>"
                            alt="Схема компонентов ФитоДомика"
                            width="1440"
                            height="810"
                            data-frame-image>
                        <div class="components-hotspots" aria-label="Кликабельные компоненты финальной схемы">
                            <?php foreach ($interactiveComponents as $component): ?>
                                <a
                                    class="component-hotspot component-hotspot--<?= $escape($component['slug']) ?>"
                                    href="/pages/components/<?= $escape($component['slug']) ?>/"
                                    aria-label="<?= $escape($component['title']) ?>">
                                    <span><?= $escape($component['title']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="components-final-hint" data-final-hint>Нажмите на компонент, чтобы открыть описание и 3D-модель.</p>
                    <noscript>
                        <style>
                            .components-progress,
                            .components-frame-wrap,
                            .components-final-hint { display: none !important; }
                        </style>
                        <div class="components-noscript">
                            <img src="<?= $escape(end($componentFrames)) ?>" alt="Финальная схема компонентов ФитоДомика" width="1440" height="810">
                            <p>JavaScript выключен, поэтому показан финальный кадр схемы.</p>
                        </div>
                    </noscript>
                </div>
            </div>
        </section>
        <section class="component-link-grid" aria-label="Карточки компонентов схемы">
            <?php foreach ($interactiveComponents as $component): ?>
                <a class="component-link-card" href="/pages/components/<?= $escape($component['slug']) ?>/">
                    <span><?= $escape($component['title']) ?></span>
                    <p><?= $escape($component['short']) ?></p>
                    <strong>Описание и 3D-модель</strong>
                </a>
            <?php endforeach; ?>
        </section>
        <section class="wiring-section">
            <div class="section-heading">
                <span class="expenses-hero__eyebrow">Схема подключения</span>
                <h2>Как соединены датчики, контроллеры и реле</h2>
                <p>Ниже схема по актуальному проекту. Перед сборкой обязательно сверяйте питание модулей и общий GND.</p>
            </div>
            <div class="wiring-board" aria-label="Схема подключения компонентов ФитоДомика">
                <div class="wiring-column">
                    <div class="wiring-device wiring-device--sensor">
                        <strong>BME280</strong>
                        <span>температура, влажность, давление</span>
                        <small>SDA -> A4, SCL -> A5</small>
                    </div>
                    <div class="wiring-device wiring-device--sensor">
                        <strong>YL-69</strong>
                        <span>влажность почвы</span>
                        <small>AO -> A6</small>
                    </div>
                    <div class="wiring-device wiring-device--sensor">
                        <strong>MH-Z19B</strong>
                        <span>CO2</span>
                        <small>UART -> D2/D3</small>
                    </div>
                </div>
                <div class="wiring-core">
                    <span class="wiring-core__label">Arduino Nano</span>
                    <strong>Serial + датчики + реле</strong>
                    <small>9600 baud, команды 1/2/3/4, A+, A-, S</small>
                </div>
                <div class="wiring-column">
                    <div class="wiring-device wiring-device--relay">
                        <strong>4-канальное реле</strong>
                        <span>D7 вентилятор, D6 насос, D5 шторы, D4 лампа</span>
                        <small>управление силовыми устройствами</small>
                    </div>
                    <div class="wiring-device wiring-device--pi">
                        <strong>Raspberry Pi 3B</strong>
                        <span>USB -> Arduino Nano</span>
                        <small>desktop-приложение, API, очереди</small>
                    </div>
                    <div class="wiring-device wiring-device--camera">
                        <strong>USB веб-камера</strong>
                        <span>фото, OpenCV/HSV-анализ</span>
                        <small>подключается к Raspberry Pi</small>
                    </div>
                </div>
            </div>
            <div class="wiring-details">
                <?php foreach ($wiringBlocks as $block): ?>
                    <article class="wiring-card">
                        <span><?= $escape($block['tag']) ?></span>
                        <h3><?= $escape($block['title']) ?></h3>
                        <ul>
                            <?php foreach ($block['items'] as $item): ?>
                                <li><?= $escape($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php if (empty($items)): ?>
            <section class="expenses-empty">
                <div class="expenses-empty__card">
                    <h2>Покупки пока не добавлены</h2>
                    <p>Как только у товара появится реальная цена покупки, он появится в этом списке.</p>
                </div>
            </section>
        <?php else: ?>
            <section class="expenses-summary">
                <div class="expenses-summary__card">
                    <span class="expenses-summary__label">Куплено позиций</span>
                    <strong><?= $escape(count($items)) ?></strong>
                </div>
                <div class="expenses-summary__card expenses-summary__card--accent">
                    <span class="expenses-summary__label">Всего потрачено</span>
                    <strong><?= $escape($formatCurrency($grandTotal)) ?></strong>
                </div>
            </section>
            <section class="expenses-table-wrap">
                <div class="expenses-table-card">
                    <table class="expenses-table">
                        <thead>
                            <tr>
                                <th>Товар</th>
                                <th>Количество</th>
                                <th>Цена</th>
                                <th>Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= $escape($item['name']) ?></td>
                                    <td><?= $escape($item['quantity']) ?></td>
                                    <td><?= $escape($formatCurrency($item['price'])) ?></td>
                                    <td class="expenses-table__total"><?= $escape($formatCurrency($item['line_total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Всего потрачено:</td>
                                <td class="expenses-table__grand-total"><?= $escape($formatCurrency($grandTotal)) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
            <section class="expenses-cards">
                <?php foreach ($items as $item): ?>
                    <article class="expense-card">
                        <h2><?= $escape($item['name']) ?></h2>
                        <dl>
                            <div>
                                <dt>Количество</dt>
                                <dd><?= $escape($item['quantity']) ?></dd>
                            </div>
                            <div>
                                <dt>Цена</dt>
                                <dd><?= $escape($formatCurrency($item['price'])) ?></dd>
                            </div>
                            <div>
                                <dt>Итого</dt>
                                <dd class="expense-card__total"><?= $escape($formatCurrency($item['line_total'])) ?></dd>
                            </div>
                        </dl>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="expenses-footer-total">
                <div class="expenses-footer-total__card">
                    <span>Всего потрачено:</span>
                    <strong><?= $escape($formatCurrency($grandTotal)) ?></strong>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <style>
    .expenses-page {
        display: grid;
        gap: 1.5rem;
        color: var(--text-color);
    }
    .component-hotspot--usb-camera { left: 12.2%; top: 6%; width: 21%; height: 18%; }
    .component-hotspot--raspberry-pi-3b { left: 42%; top: 2.5%; width: 13.5%; height: 35%; }
    .component-hotspot--arduino-nano { left: 43.4%; top: 43.5%; width: 11.2%; height: 39%; }
    .component-hotspot--bme280 { left: 14%; top: 35.5%; width: 13.5%; height: 26%; }
    .component-hotspot--yl-69 { left: 10.6%; top: 66%; width: 17%; height: 23%; }
    .component-hotspot--mh-z19b { left: 70%; top: 64%; width: 22%; height: 22%; }
    .component-hotspot--relay-module { left: 72.5%; top: 16.5%; width: 20%; height: 24%; }
    .expenses-hero,
    .architecture-section,
    .wiring-section,
    .expenses-table-card,
    .expense-card,
    .expenses-summary__card,
    .expenses-footer-total__card,
    .expenses-empty__card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        box-shadow: var(--shadow-soft);
    }
    .expenses-hero {
        padding: 2rem;
        background:
            radial-gradient(circle at top left, rgba(183, 216, 121, 0.2), transparent 14rem),
            var(--card-bg);
    }
    .expenses-hero__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        margin-bottom: 0.9rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }
    .expenses-hero h1 {
        margin: 0;
        color: var(--text-color);
        font-size: clamp(2rem, 4vw, 3.2rem);
    }
    .expenses-hero p {
        margin: 0.85rem 0 0;
        max-width: 44rem;
        color: var(--muted-text);
        font-size: 1.02rem;
    }
    .section-heading {
        margin-bottom: 1.2rem;
    }
    .section-heading h2 {
        margin: 0;
        color: var(--text-color);
        font-size: clamp(1.55rem, 3vw, 2.35rem);
    }
    .section-heading p {
        margin: 0.75rem 0 0;
        max-width: 56rem;
        color: var(--muted-text);
    }
    .architecture-section,
    .wiring-section {
        padding: 1.5rem;
    }
    .architecture-flow {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
    }
    .architecture-node {
        position: relative;
        min-height: 185px;
        padding: 1.15rem;
        border-radius: 22px;
        border: 1px solid var(--border-color);
        background:
            linear-gradient(145deg, rgba(47, 143, 70, 0.13), transparent 58%),
            var(--input-bg);
    }
    .architecture-node:not(:last-child)::after {
        content: "";
        position: absolute;
        top: 50%;
        right: -0.72rem;
        width: 0.9rem;
        height: 0.9rem;
        border-top: 3px solid var(--primary-color);
        border-right: 3px solid var(--primary-color);
        transform: translateY(-50%) rotate(45deg);
        opacity: 0.65;
    }
    .architecture-node__number {
        display: inline-flex;
        margin-bottom: 0.8rem;
        padding: 0.34rem 0.65rem;
        border-radius: 999px;
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-weight: 800;
        letter-spacing: 0.04em;
    }
    .architecture-node h3 {
        margin: 0 0 0.55rem;
        color: var(--text-color);
        font-size: 1.08rem;
    }
    .architecture-node p {
        margin: 0;
        color: var(--muted-text);
        font-size: 0.94rem;
    }
    .wiring-board {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) 250px minmax(0, 1fr);
        gap: 1.1rem;
        align-items: center;
        margin: 1.2rem 0;
        padding: 1.25rem;
        border-radius: 24px;
        border: 1px solid var(--border-color);
        background:
            radial-gradient(circle at 50% 50%, rgba(47, 143, 70, 0.16), transparent 18rem),
            var(--input-bg);
        overflow: hidden;
    }
    .wiring-board::before,
    .wiring-board::after {
        content: "";
        position: absolute;
        left: 8%;
        right: 8%;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--primary-color), transparent);
        opacity: 0.25;
    }
    .wiring-board::before {
        top: 34%;
    }
    .wiring-board::after {
        bottom: 34%;
    }
    .wiring-column {
        display: grid;
        gap: 0.85rem;
        position: relative;
        z-index: 1;
    }
    .wiring-core,
    .wiring-device {
        position: relative;
        padding: 1rem;
        border-radius: 18px;
        border: 1px solid var(--border-color);
        background: var(--card-bg);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
    }
    .wiring-core {
        min-height: 210px;
        display: grid;
        align-content: center;
        justify-items: center;
        text-align: center;
        background:
            linear-gradient(160deg, rgba(18, 88, 146, 0.16), rgba(47, 143, 70, 0.16)),
            var(--card-bg);
    }
    .wiring-core__label {
        display: inline-flex;
        margin-bottom: 0.7rem;
        padding: 0.45rem 0.75rem;
        border-radius: 999px;
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-weight: 800;
    }
    .wiring-core strong,
    .wiring-device strong {
        display: block;
        color: var(--text-color);
        font-size: 1.05rem;
    }
    .wiring-core small,
    .wiring-device small,
    .wiring-device span {
        display: block;
        margin-top: 0.35rem;
        color: var(--muted-text);
    }
    .wiring-device--sensor {
        border-left: 5px solid #1976a3;
    }
    .wiring-device--relay {
        border-left: 5px solid #2f8f46;
    }
    .wiring-device--pi {
        border-left: 5px solid #b7791f;
    }
    .wiring-device--camera {
        border-left: 5px solid #7c3aed;
    }
    .wiring-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1rem;
    }
    .wiring-card {
        padding: 1.2rem;
        border-radius: 22px;
        border: 1px solid var(--border-color);
        background: var(--input-bg);
    }
    .wiring-card span {
        color: var(--primary-dark);
        font-weight: 800;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .wiring-card h3 {
        margin: 0.45rem 0 0.8rem;
        color: var(--text-color);
    }
    .wiring-card ul {
        display: grid;
        gap: 0.45rem;
        margin: 0;
        padding-left: 1.1rem;
        color: var(--muted-text);
    }
    .expenses-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }
    .expenses-summary__card {
        min-height: 96px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.4rem 1.5rem;
    }
    .expenses-summary__card--accent {
        background:
            linear-gradient(135deg, rgba(47, 143, 70, 0.12), rgba(183, 216, 121, 0.18)),
            var(--card-bg);
    }
    .expenses-summary__label {
        display: block;
        margin: 0;
        color: var(--muted-text);
        font-size: 0.92rem;
        font-weight: 700;
    }
    .expenses-summary strong,
    .expenses-footer-total strong {
        color: var(--text-color);
        font-size: clamp(1.5rem, 3vw, 2.1rem);
        line-height: 1.1;
    }
    .expenses-table-wrap {
        display: block;
    }
    .expenses-table-card {
        overflow: hidden;
    }
    .expenses-table {
        width: 100%;
        border-collapse: collapse;
    }
    .expenses-table thead th {
        padding: 1rem 1.25rem;
        background: var(--header-bg);
        border-bottom: 1px solid var(--border-color);
        color: var(--muted-text);
        font-size: 0.92rem;
        font-weight: 700;
        text-align: left;
    }
    .expenses-table tbody td,
    .expenses-table tfoot td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-color);
        vertical-align: middle;
    }
    .expenses-table tbody tr:hover {
        background: var(--hover-bg);
    }
    .expenses-table tbody tr:last-child td {
        border-bottom: 1px solid var(--border-color);
    }
    .expenses-table__total,
    .expenses-table__grand-total {
        font-weight: 800;
        white-space: nowrap;
    }
    .expenses-table tfoot td {
        background: rgba(47, 143, 70, 0.06);
        border-bottom: none;
        font-weight: 700;
    }
    .expenses-cards {
        display: none;
        gap: 1rem;
    }
    .expense-card {
        padding: 1.2rem;
    }
    .expense-card h2 {
        margin: 0 0 1rem;
        font-size: 1.1rem;
        color: var(--text-color);
    }
    .expense-card dl {
        display: grid;
        gap: 0;
        margin: 0;
    }
    .expense-card dl > div {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.72rem 0;
        border-top: 1px solid var(--border-color);
    }
    .expense-card dt {
        margin: 0 0 0.22rem;
        color: var(--muted-text);
        font-size: 0.88rem;
    }
    .expense-card dd {
        margin: 0;
        color: var(--text-color);
        font-weight: 700;
    }
    .expense-card__total {
        color: var(--primary-dark);
    }
    .expenses-footer-total__card,
    .expenses-empty__card {
        padding: 1.4rem 1.5rem;
    }
    .expenses-footer-total__card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        background:
            linear-gradient(135deg, rgba(47, 143, 70, 0.14), rgba(183, 216, 121, 0.18)),
            var(--card-bg);
    }
    .expenses-footer-total__card span {
        color: var(--muted-text);
        font-size: 1rem;
        font-weight: 700;
    }
    .expenses-empty__card h2 {
        margin: 0 0 0.6rem;
        font-size: 1.4rem;
        color: var(--text-color);
    }
    .expenses-empty__card p {
        margin: 0;
        color: var(--muted-text);
    }
    @media (max-width: 991.98px) {
        .expenses-table-wrap {
            display: none;
        }
        .expenses-cards {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .expense-card {
            min-width: 0;
        }
    }
    @media (max-width: 767.98px) {
        .expenses-hero,
        .architecture-section,
        .wiring-section,
        .expenses-summary__card,
        .expenses-footer-total__card,
        .expenses-empty__card {
            padding: 1.25rem;
            border-radius: 20px;
        }
        .architecture-flow,
        .wiring-board,
        .wiring-details {
            grid-template-columns: 1fr;
        }
        .architecture-node:not(:last-child)::after {
            top: auto;
            right: 50%;
            bottom: -0.74rem;
            transform: translateX(50%) rotate(135deg);
        }
        .wiring-board {
            padding: 1rem;
        }
        .wiring-core {
            min-height: 150px;
            order: -1;
        }
        .expenses-summary {
            grid-template-columns: 1fr;
        }
        .expenses-cards {
            grid-template-columns: 1fr;
        }
        .expenses-summary__card {
            min-height: 88px;
        }
        .expenses-footer-total__card {
            align-items: flex-start;
            flex-direction: column;
        }
    }
    </style>
    <script src="/static/js/components-scroll.v16.js" defer></script>
    <?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../../layout.php';
} catch (PDOException $e) {
    error_log('Ошибка при получении компонентов: ' . $e->getMessage());
    die('Произошла ошибка при загрузке расходов');
}
?>
