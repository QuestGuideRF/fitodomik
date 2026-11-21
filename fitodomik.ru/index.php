<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/qr_code.php';
$pageTitle = 'ФитоДомик — умная теплица и мини-ферма на Arduino, Raspberry Pi и Python';
$pageDescription = 'ФитоДомик — умная мини-ферма своими руками: Arduino, Raspberry Pi, датчики, автополив, реле, OpenCV/HSV-анализ растения, Telegram-бот, dynamic sensors и веб-кабинет на fitodomik.ru.';
$pageKeywords = 'ФитоДомик, умная теплица, умная мини-ферма своими руками, Raspberry Pi теплица, Arduino теплица, IoT теплица, автополив Arduino, мониторинг микроклимата растений, Telegram бот для теплицы, OpenCV анализ растений, датчики для теплицы, умный гроубокс, fitodomik.ru';
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$telegramUrl = 'https://t.me/FitoDomik';
$controlSiteUrl = '/farm.php';
$preloadHeroImage = true;
$heroStats = [
    ['value' => '24/7', 'label' => 'мониторинг условий'],
    ['value' => '3 шага', 'label' => 'понятный уход'],
    ['value' => 'онлайн', 'label' => 'контроль через сайт'],
];
$features = [
    [
        'icon' => 'bi-thermometer-sun',
        'title' => 'Контроль микроклимата',
        'text' => 'Датчики помогают следить за температурой, влажностью воздуха, влажностью почвы, CO2 и давлением, чтобы растение не оставалось без внимания.'
    ],
    [
        'icon' => 'bi-droplet-half',
        'title' => 'Автоматический полив',
        'text' => 'Система ориентируется на влажность почвы и включает полив тогда, когда растению действительно нужна вода.'
    ],
    [
        'icon' => 'bi-brightness-high',
        'title' => 'Умное освещение',
        'text' => 'Подсветка поддерживает понятный режим для зелени, рассады и комнатных растений даже в пасмурные дни.'
    ],
    [
        'icon' => 'bi-camera',
        'title' => 'Камера и OpenCV-анализ',
        'text' => 'USB-камера сохраняет фото, а HSV/OpenCV-анализ помогает отслеживать состояние зелёной массы и формировать отчёт.'
    ],
    [
        'icon' => 'bi-window-sidebar',
        'title' => 'Веб-интерфейс и Telegram',
        'text' => 'Показатели, фото, таймлапс, устройства и настройки доступны на сайте, а Telegram-бот присылает статус и уведомления.'
    ],
    [
        'icon' => 'bi-activity',
        'title' => 'Понятные статусы',
        'text' => 'Система помогает быстро заметить тревоги, офлайн-устройство, ошибку Arduino, проблему камеры или нестабильные условия.'
    ],
];
$architectureNodes = [
    [
        'title' => 'Датчики',
        'text' => 'Измеряют температуру, влажность, CO2, влажность почвы и давление.',
    ],
    [
        'title' => 'Arduino',
        'text' => 'Собирает показания и управляет четырьмя реле: светом, шторами, насосом и вентилятором.',
    ],
    [
        'title' => 'Raspberry Pi',
        'text' => 'Запускает desktop-приложение, камеру, OpenCV-анализ, локальные очереди и обмен с Arduino.',
    ],
    [
        'title' => 'API',
        'text' => 'Принимает данные, фото, настройки, пороги и очередь удалённых команд.',
    ],
    [
        'title' => 'Сайт',
        'text' => 'Показывает графики, фото, таймлапс, устройства, экспорт данных и настройки.',
    ],
    [
        'title' => 'Telegram',
        'text' => 'Отправляет статус, тревоги, отчёты и команды владельцу привязанного аккаунта.',
    ],
];
$projectParts = [
    [
        'kicker' => 'Устройство',
        'title' => 'Мини-ферма для растений',
        'text' => 'Компактный корпус, место для растения, резервуар воды, подсветка, датчики и аккуратная электроника внутри.'
    ],
    [
        'kicker' => 'Автоматизация',
        'title' => 'Полив, свет и условия',
        'text' => 'ФитоДомик снимает с пользователя рутину: проверяет влажность, включает нужные модули и помогает держать стабильный уход.'
    ],
    [
        'kicker' => 'Онлайн-контроль',
        'title' => 'Сайт, админка и журнал проекта',
        'text' => 'Данные, новости, компоненты и история разработки собраны на сайте, а устройства привязываются через QR и device_id/device_token.'
    ],
];
$steps = [
    [
        'number' => '01',
        'icon' => 'bi-flower1',
        'title' => 'Вы размещаете растение',
        'text' => 'Ставите растение в ФитоДомик, добавляете воду и выбираете подходящий режим ухода.'
    ],
    [
        'number' => '02',
        'icon' => 'bi-cpu',
        'title' => 'Система ухаживает автоматически',
        'text' => 'Датчики следят за влажностью, освещением и условиями выращивания. Автополив и подсветка включаются по необходимости.'
    ],
    [
        'number' => '03',
        'icon' => 'bi-phone',
        'title' => 'Вы контролируете всё онлайн',
        'text' => 'Состояние растения видно в личном кабинете fitodomik.ru, а если появится вопрос по проекту, можно написать автору напрямую.'
    ],
];
$audiences = [
    ['icon' => 'bi-house', 'title' => 'Для дома', 'text' => 'Чтобы выращивать зелень, рассаду и комнатные растения без постоянных проверок.'],
    ['icon' => 'bi-mortarboard', 'title' => 'Для учебы', 'text' => 'Как понятный технический проект про IoT, датчики, веб-интерфейс и автоматизацию.'],
    ['icon' => 'bi-lightbulb', 'title' => 'Для экспериментов', 'text' => 'Можно тестировать режимы полива, освещения, камеры и сценарии ухода.'],
    ['icon' => 'bi-calendar-check', 'title' => 'Для занятых', 'text' => 'ФитоДомик помогает не забывать про растение, когда день расписан по минутам.'],
];
ob_start();
?>
<div class="home-page">
    <section class="home-hero reveal-section">
        <div class="home-hero__content">
            <span class="section-eyebrow">Умная мини-ферма для дома</span>
            <h1>ФитоДомик — понятный уход за растениями без постоянного контроля</h1>
            <p class="home-hero__lead">
                Система сама следит за поливом, освещением и условиями выращивания, а вы видите состояние растений в личном кабинете fitodomik.ru.
            </p>
            <p class="home-hero__text">
                ФитоДомик помогает выращивать зелень, рассаду и домашние растения даже без постоянного ухода. Устройство объединяет датчики, автополив, освещение, камеру, Raspberry Pi, Arduino, OpenCV-анализ, Telegram-бота и удобный веб-интерфейс.
            </p>
            <div class="home-hero__actions">
                <a class="btn btn-primary btn-lg site-btn" href="/pages/device/">Как устроен ФитоДомик</a>
                <a class="btn btn-outline-primary btn-lg site-btn site-btn--ghost" href="/pages/info/">О проекте</a>
                <a class="btn btn-outline-primary btn-lg site-btn site-btn--ghost" href="<?= $escape($controlSiteUrl) ?>">Открыть личный кабинет</a>
            </div>
            <div class="home-hero__stats" aria-label="Коротко о ФитоДомике">
                <?php foreach ($heroStats as $stat): ?>
                    <div class="mini-stat">
                        <strong><?= $escape($stat['value']) ?></strong>
                        <span><?= $escape($stat['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="home-hero__visual" aria-label="Фото умной мини-фермы ФитоДомик">
            <div class="farm-photo-card">
                <div class="farm-photo-card__glow"></div>
                <picture>
                    <source
                        media="(min-width: 769px)"
                        type="image/png"
                        srcset="/static/images/hero.v15.png">
                    <source
                        type="image/avif"
                        srcset="/static/images/hero.v15.avif"
                        sizes="322px">
                    <source
                        type="image/webp"
                        srcset="/static/images/hero.v15.webp"
                        sizes="322px">
                    <img
                        class="farm-photo"
                        src="/static/images/hero.v15.png"
                        width="420"
                        height="525"
                        alt="ФитоДомик с датчиками, камерой, LED-лампой и баком для воды"
                        loading="eager"
                        decoding="async"
                        fetchpriority="high">
                </picture>
            </div>
        </div>
    </section>
    <section class="home-section reveal-section" id="about">
        <div class="section-heading">
            <span class="section-eyebrow">Что такое ФитоДомик</span>
            <h2>Это домашняя мини-ферма, которая берёт рутину на себя</h2>
            <p>Пользователь получает не просто коробку с электроникой, а понятную систему ухода: растение растёт в стабильных условиях, а все важные показатели видны онлайн.</p>
        </div>
        <div class="value-grid">
            <article class="value-card value-card--wide">
                <span class="value-card__icon"><i class="bi bi-tree"></i></span>
                <h3>Меньше ручной проверки</h3>
                <p>Не нужно каждый день вспоминать про полив, свет и состояние почвы. ФитоДомик помогает поддерживать базовый уход автоматически.</p>
            </article>
            <article class="value-card">
                <span class="value-card__icon"><i class="bi bi-graph-up-arrow"></i></span>
                <h3>Больше данных</h3>
                <p>Датчики превращают уход за растением в понятные показатели, а не в догадки.</p>
            </article>
            <article class="value-card">
                <span class="value-card__icon"><i class="bi bi-chat-dots"></i></span>
                    <h3>Быстрая связь</h3>
                    <p>Личный кабинет на fitodomik.ru помогает следить за проектом и состоянием фермы, а отдельная страница автора собирает контакты, проекты и исходный код.</p>
            </article>
        </div>
    </section>
    <section class="home-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">Что умеет система</span>
            <h2>Функции, которые действительно помогают растению</h2>
        </div>
        <div class="feature-grid">
            <?php foreach ($features as $feature): ?>
                <article class="feature-card">
                    <span class="feature-card__icon"><i class="bi <?= $escape($feature['icon']) ?>"></i></span>
                    <h3><?= $escape($feature['title']) ?></h3>
                    <p><?= $escape($feature['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="home-section reveal-section architecture-section">
        <div class="section-heading">
            <span class="section-eyebrow">Архитектура</span>
            <h2>От датчиков до Telegram-уведомлений</h2>
            <p>Актуальная схема проекта: датчики передают данные на Arduino, Raspberry Pi связывает железо с API, сайт показывает веб-кабинет, а Telegram-бот отправляет владельцу статус, фото и тревоги.</p>
        </div>
        <div class="architecture-flow" aria-label="Схема ФитоДомика: датчики, Arduino, Raspberry Pi, API, сайт и Telegram">
            <?php foreach ($architectureNodes as $index => $node): ?>
                <article class="architecture-node">
                    <span class="architecture-node__number"><?= $escape(str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                    <h3><?= $escape($node['title']) ?></h3>
                    <p><?= $escape($node['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="home-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">Что вы получаете</span>
            <h2>Проект собран вокруг понятного пользовательского сценария</h2>
        </div>
        <div class="project-parts">
            <?php foreach ($projectParts as $part): ?>
                <article class="project-part-card">
                    <span><?= $escape($part['kicker']) ?></span>
                    <h3><?= $escape($part['title']) ?></h3>
                    <p><?= $escape($part['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="home-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">Как это работает</span>
            <h2>Три шага от растения до онлайн-контроля</h2>
            <p>Сценарий специально сделан простым: установить растение, дать системе ухаживать и спокойно наблюдать за результатом.</p>
        </div>
        <div class="steps-grid">
            <?php foreach ($steps as $step): ?>
                <article class="step-card">
                    <div class="step-card__top">
                        <span class="step-card__number"><?= $escape($step['number']) ?></span>
                        <span class="step-card__icon"><i class="bi <?= $escape($step['icon']) ?>"></i></span>
                    </div>
                    <h3><?= $escape($step['title']) ?></h3>
                    <p><?= $escape($step['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="home-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">Для кого это</span>
            <h2>ФитоДомик полезен там, где хочется выращивать проще и осознаннее</h2>
        </div>
        <div class="audience-grid">
            <?php foreach ($audiences as $audience): ?>
                <article class="audience-card">
                    <i class="bi <?= $escape($audience['icon']) ?>"></i>
                    <h3><?= $escape($audience['title']) ?></h3>
                    <p><?= $escape($audience['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="home-cta reveal-section">
        <div>
            <span class="section-eyebrow">Связаться и посмотреть детали</span>
            <h2>Хотите узнать, как устроен ФитоДомик?</h2>
            <p>Посмотрите хронологию разработки, список компонентов или откройте личный кабинет для онлайн-контроля. Если хотите задать вопрос автору проекта, напишите в личный Telegram.</p>
            <div class="home-cta__actions">
                <a class="btn btn-primary site-btn" href="/pages/components/">Посмотреть компоненты</a>
                <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/pages/info/">О проекте</a>
                <a class="btn btn-success site-btn" href="<?= $escape($controlSiteUrl) ?>">Открыть личный кабинет</a>
                <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/author">Страница автора</a>
            </div>
        </div>
        <div class="home-cta__qr">
            <?= generateQrCode($telegramUrl, 180); ?>
            <span>QR-код для связи с автором</span>
        </div>
    </section>
    <style>
    .architecture-flow {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        position: relative;
    }
    .architecture-node {
        min-height: 190px;
        padding: 1.2rem;
        border-radius: 22px;
        border: 1px solid var(--border-color);
        background:
            linear-gradient(145deg, rgba(46, 204, 113, 0.12), transparent 55%),
            var(--card-bg);
        box-shadow: var(--shadow-soft);
    }
    .architecture-node__number {
        display: inline-flex;
        margin-bottom: 0.8rem;
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        background: var(--primary-soft);
        color: var(--primary-dark);
        font-weight: 800;
        letter-spacing: 0.04em;
    }
    .architecture-node h3 {
        margin: 0 0 0.55rem;
        color: var(--text-color);
    }
    .architecture-node p,
    .architecture-note {
        color: var(--muted-text);
    }
    .architecture-note {
        margin: 1rem 0 0;
        padding: 1rem;
        border-radius: 18px;
        background: var(--primary-soft);
    }
    </style>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/layout.php';
?>
