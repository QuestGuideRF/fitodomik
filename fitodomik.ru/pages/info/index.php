<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'О проекте ФитоДомик';
$pageDescription = 'История, цель и учебная ценность проекта ФитоДомик — умной мини-фермы на Arduino, Raspberry Pi и Python.';
$pageKeywords = 'ФитоДомик, о проекте, история проекта, учебный IoT проект, Arduino, Raspberry Pi, умная мини-ферма';
$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$projectValues = [
    [
        'icon' => 'bi-flower1',
        'title' => 'Практическая цель',
        'text' => 'Снять с пользователя повторяющиеся проверки полива, света и микроклимата, оставив понятный контроль состояния растения.',
    ],
    [
        'icon' => 'bi-mortarboard',
        'title' => 'Учебная ценность',
        'text' => 'Показать на одном работающем проекте связь электроники, Python, PHP/MySQL, API, компьютерного зрения и интерфейсов.',
    ],
    [
        'icon' => 'bi-tools',
        'title' => 'Открытая разработка',
        'text' => 'Фиксировать реальные решения, ошибки и улучшения, а исходный код и устройство проекта оставлять доступными для изучения.',
    ],
];
$milestones = [
    [
        'date' => '2025',
        'title' => 'Идея и первый прототип',
        'text' => 'Проект начался как учебная мини-ферма с датчиками, автополивом, освещением и веб-интерфейсом.',
    ],
    [
        'date' => '2025',
        'title' => 'Аппаратная основа',
        'text' => 'Собрана актуальная схема на Arduino Nano, Raspberry Pi 3B, BME280, MH-Z19B, датчике почвы, реле и USB-камере.',
    ],
    [
        'date' => '2026',
        'title' => 'Desktop и анализ растения',
        'text' => 'Python/Tkinter-приложение объединило Serial, мониторинг, графики, фото, OpenCV/HSV-анализ и локальные очереди.',
    ],
    [
        'date' => '2026',
        'title' => 'Устройства и автоматизация',
        'text' => 'Появились multi-device, QR-привязка, динамические датчики, сценарии и очередь удалённых команд.',
    ],
    [
        'date' => '2026',
        'title' => 'Единый сайт',
        'text' => 'Публичные материалы, авторизация, личный кабинет и API объединены на fitodomik.ru с общей темой и одной учётной записью.',
    ],
];
ob_start();
?>
<div class="info-page">
    <section class="page-hero reveal-section">
        <span class="section-eyebrow">О проекте</span>
        <h1>ФитоДомик вырос из учебной идеи в единую IoT-систему</h1>
        <p>Цель проекта — сделать уход за растением понятнее и одновременно показать, как в реальном устройстве работают вместе датчики, автоматика, локальное приложение и веб-сервисы.</p>
    </section>
    <section class="about-summary reveal-section" aria-labelledby="about-purpose-title">
        <div class="section-heading">
            <span class="section-eyebrow">Зачем создан ФитоДомик</span>
            <h2 id="about-purpose-title">Практический проект, который можно изучать и развивать</h2>
            <p>ФитоДомик не заменяет знания об уходе за растениями, а помогает собирать данные, автоматизировать рутину и вовремя замечать изменения условий.</p>
        </div>
        <div class="about-value-grid">
            <?php foreach ($projectValues as $value): ?>
                <article class="about-value-card">
                    <span class="about-value-card__icon" aria-hidden="true"><i class="bi <?= $escape($value['icon']) ?>"></i></span>
                    <h3><?= $escape($value['title']) ?></h3>
                    <p><?= $escape($value['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="about-history reveal-section" aria-labelledby="about-history-title">
        <div class="section-heading">
            <span class="section-eyebrow">Ключевые этапы</span>
            <h2 id="about-history-title">Короткая история развития</h2>
            <p>Здесь оставлены только основные поворотные точки. Подробные изменения и новые функции собраны отдельно в дневнике проекта.</p>
        </div>
        <div class="about-milestones">
            <?php foreach ($milestones as $index => $milestone): ?>
                <article class="about-milestone">
                    <span class="about-milestone__number"><?= $escape(str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                    <time><?= $escape($milestone['date']) ?></time>
                    <h3><?= $escape($milestone['title']) ?></h3>
                    <p><?= $escape($milestone['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="about-history__action">
            <a class="btn btn-primary site-btn" href="/pages/news/">Все новости проекта</a>
        </div>
    </section>
    <section class="about-next reveal-section">
        <div>
            <span class="section-eyebrow">Продолжение</span>
            <h2>Проект остаётся развивающимся</h2>
            <p>Новые датчики и сценарии добавляются только после проверки реальной пользы и совместимости. Идеи, которые ещё не реализованы, в новостях отмечаются как разработка или план.</p>
        </div>
        <div class="about-next__links">
            <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/pages/device/">Как устроена система</a>
            <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/pages/code/">Исходный код и установка</a>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
?>
