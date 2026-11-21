<?php
const DEVICE_STATUS_PARTIAL = 'Частично';
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Устройство ФитоДомик — умная теплица на Arduino, Raspberry Pi и Python';
$pageDescription = 'Как устроен ФитоДомик: Arduino, Raspberry Pi, датчики, реле, Python-приложение, сайт, Telegram-бот, динамические датчики, сценарии автоматизации и установка одной командой.';
$pageKeywords = 'ФитоДомик, умная теплица, Arduino, Raspberry Pi, Python, IoT, домашняя мини-ферма, автоматизация растений, Telegram бот, OpenCV анализ растений, датчики теплицы, BME280, MH-Z19B, влажность почвы, умный гроубокс, как запустить умную теплицу, установка ФитоДомик, Arduino Raspberry Pi теплица, умная мини-ферма своими руками, настройка ФитоДомик, smart greenhouse, indoor farm, plant monitoring system';
$pageImage = '/icon/og-image.jpg';
$pageSchemaType = ['SoftwareApplication', 'TechArticle'];
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$installLinux = 'curl -sSL https://fitodomik.ru/install | bash';
$installWindows = 'irm https://fitodomik.ru/install/windows | iex';
$systemFlow = [
    ['icon' => 'bi-broadcast-pin', 'title' => 'Датчики', 'text' => 'BME280, MH-Z19B, влажность почвы и дополнительные модули измеряют условия выращивания.'],
    ['icon' => 'bi-cpu', 'title' => 'Arduino', 'text' => 'Читает датчики, управляет реле и отправляет строки JSON, SENSORS, DEVICES и EXTRA_SENSORS.'],
    ['icon' => 'bi-pc-display', 'title' => 'Raspberry Pi', 'text' => 'Запускает desktop-приложение, камеру, очереди и обмен с Arduino по Serial.'],
    ['icon' => 'bi-window-sidebar', 'title' => 'Desktop', 'text' => 'Python/Tkinter показывает мониторинг, графики, настройки, сценарии и мастер датчиков.'],
    ['icon' => 'bi-cloud-arrow-up', 'title' => 'API', 'text' => 'Передаёт данные, фото, dynamic values, сценарии, настройки и команды в единый кабинет fitodomik.ru.'],
    ['icon' => 'bi-globe2', 'title' => 'Личный кабинет', 'text' => 'Хранит устройства, графики, статусы, сценарии, очередь команд и веб-настройки.'],
    ['icon' => 'bi-telegram', 'title' => 'Telegram', 'text' => 'Показывает статус, датчики, фото, диагностику и создаёт команды для Raspberry Pi.'],
];
$features = [
    ['icon' => 'bi-thermometer-sun', 'title' => 'Мониторинг микроклимата', 'items' => ['температура', 'влажность', 'давление', 'CO2', 'влажность почвы']],
    ['icon' => 'bi-toggles2', 'title' => 'Управление устройствами', 'items' => ['лампа', 'насос', 'вентилятор', 'шторы', 'дополнительные реле']],
    ['icon' => 'bi-graph-up', 'title' => 'Графики', 'items' => ['локальные графики', 'графики на сайте', 'dynamic charts только по подключённым датчикам']],
    ['icon' => 'bi-camera', 'title' => 'OpenCV-анализ растения', 'items' => ['фото', 'HSV-анализ', 'маска', 'отчёт', 'загрузка результата на сайт']],
    ['icon' => 'bi-telegram', 'title' => 'Telegram-бот', 'items' => ['/status', '/sensors', '/photo', '/analysis', '/devices', '/diagnostics', 'уведомления']],
    ['icon' => 'bi-diagram-3', 'title' => 'Сценарии автоматизации', 'items' => ['сухая почва -> насос', 'жарко -> вентилятор', 'мало света -> лампа']],
    ['icon' => 'bi-hdd-network', 'title' => 'Multi-device', 'items' => ['несколько устройств', 'device_id', 'device_token', 'выбор активного устройства']],
    ['icon' => 'bi-lightning-charge', 'title' => 'Установка одной командой', 'items' => ['Windows', 'Linux', 'Raspberry Pi']],
];
$dynamicSteps = [
    'Добавить датчик',
    'выбрать пин',
    'выбрать профиль',
    'калибровка',
    'GeneratedConfig.h',
    'Arduino',
    'EXTRA_SENSORS',
    'графики',
];
$scenarios = [
    ['condition' => 'Если влажность почвы ниже 30% больше 5 минут', 'action' => 'включить насос на 10 секунд'],
    ['condition' => 'Если температура выше 30°C', 'action' => 'включить вентилятор'],
    ['condition' => 'Если освещённость низкая', 'action' => 'включить лампу'],
    ['condition' => 'Если устройство давно не присылало данные', 'action' => 'отправить уведомление'],
];
$telegramCommands = ['/start', '/status', '/sensors', '/photo', '/analysis', '/devices', '/thresholds', '/schedule', '/events', '/diagnostics', '/settings'];
$comparisonRows = [
    ['Возможность', 'ФитоДомик', 'Обычный гроубокс', 'Arduino-набор', 'Wi-Fi датчик'],
    ['Датчики микроклимата', 'Да', DEVICE_STATUS_PARTIAL, 'Зависит от сборки', DEVICE_STATUS_PARTIAL],
    ['Автоматизация реле', 'Да', DEVICE_STATUS_PARTIAL, 'Нужно писать самому', 'Нет'],
    ['Сайт', 'Да', 'Иногда', 'Нет', 'Да'],
    ['Telegram', 'Да', 'Редко', 'Нет', 'Иногда'],
    ['Свои датчики', 'Да', 'Нет', 'Да', 'Нет'],
    ['Сценарии', 'Да', 'Редко', 'Нужно писать самому', 'Нет'],
    ['OpenCV-анализ', 'Да', 'Нет', 'Нет', 'Нет'],
    ['Установка одной командой', 'Да', 'Нет', 'Нет', 'Нет'],
];
$audiences = [
    ['icon' => 'bi-mortarboard', 'title' => 'Школьники и преподаватели', 'text' => 'Наглядный проект про датчики, программирование и автоматизацию.'],
    ['icon' => 'bi-house', 'title' => 'Домашнее выращивание', 'text' => 'Зелень, рассада и комнатные растения под наблюдением.'],
    ['icon' => 'bi-cpu', 'title' => 'IoT-обучение', 'text' => 'Arduino, Raspberry Pi, Python, PHP/MySQL, API и Telegram в одном проекте.'],
    ['icon' => 'bi-tools', 'title' => 'Эксперименты', 'text' => 'Свои датчики, профили, библиотеки и сценарии ухода.'],
    ['icon' => 'bi-tree', 'title' => 'Городское садоводство', 'text' => 'Компактная автоматизация для квартиры, класса или мастерской.'],
    ['icon' => 'bi-box-seam', 'title' => 'Мини-теплицы', 'text' => 'Контроль микроклимата и исполнительных устройств.'],
    ['icon' => 'bi-robot', 'title' => 'Кружки робототехники', 'text' => 'Реальная электроника, код, сайт и понятный результат.'],
    ['icon' => 'bi-layers', 'title' => 'Full-stack IoT демо', 'text' => 'Локальное приложение, Arduino, API, веб-кабинет и бот.'],
];
ob_start();
?>
<div class="device-page">
    <section class="device-hero reveal-section">
        <div class="device-hero__content">
            <span class="section-eyebrow">🌱 Устройство проекта</span>
            <h1>ФитоДомик — умная мини-ферма на Arduino, Raspberry Pi и Python</h1>
            <p class="device-hero__lead">Система автоматически следит за микроклиматом, управляет реле, строит графики, анализирует растение через OpenCV и позволяет управлять устройством через сайт и Telegram.</p>
            <div class="device-hero__actions">
                <a class="btn btn-primary site-btn" href="/pages/code/#install-title">Установить приложение</a>
                <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/pages/code/">Открыть код</a>
                <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/pages/components/">Посмотреть компоненты</a>
                <a class="btn btn-success site-btn" href="/farm.php">Открыть личный кабинет</a>
            </div>
        </div>
        <div class="device-hero__panel" aria-label="Кратко о составе ФитоДомика">
            <div><strong>Arduino</strong><span>датчики и реле</span></div>
            <div><strong>Raspberry Pi</strong><span>desktop и камера</span></div>
            <div><strong>Личный кабинет</strong><span>графики и команды</span></div>
            <div><strong>Telegram</strong><span>статус и уведомления</span></div>
        </div>
    </section>
    <section class="device-section reveal-section" id="quick-start-10-min">
        <div class="section-heading">
            <span class="section-eyebrow">🚀 Быстрый запуск</span>
            <h2>Как запустить ФитоДомик за 10 минут</h2>
            <p>Короткий путь для первого включения: установить desktop-приложение, подключить Arduino, пройти мастер первого запуска и проверить сайт с Telegram.</p>
        </div>
        <div class="device-card-grid device-card-grid--four">
            <?php foreach ([
                'Установить desktop-приложение одной командой',
                'Подключить Arduino и выбрать плату',
                'Запустить мастер первого запуска',
                'Проверить порт Arduino и Serial',
                'Проверить USB-камеру',
                'Привязать аккаунт через QR',
                'Выбрать стандартную или пустую конфигурацию',
                'Добавить датчики и проверить сайт/Telegram',
            ] as $index => $step): ?>
                <article class="device-card">
                    <h3><?= $escape(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></h3>
                    <p><?= $escape($step) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="install-grid mt-4">
            <article class="install-card">
                <div class="install-card__top">
                    <span class="install-card__icon"><i class="bi bi-terminal"></i></span>
                    <div>
                        <h3>Linux / Raspberry Pi</h3>
                        <p>Быстрая установка на Raspberry Pi OS и Linux.</p>
                    </div>
                </div>
                <div class="install-command">
                    <code><?= $escape($installLinux) ?></code>
                    <button type="button" class="btn btn-outline-primary install-copy" data-copy-command="<?= $escape($installLinux) ?>">
                        <i class="bi bi-clipboard me-1"></i><span class="copy-label">Копировать</span>
                    </button>
                </div>
            </article>
            <article class="install-card">
                <div class="install-card__top">
                    <span class="install-card__icon"><i class="bi bi-windows"></i></span>
                    <div>
                        <h3>Windows</h3>
                        <p>Запуск через PowerShell.</p>
                    </div>
                </div>
                <div class="install-command">
                    <code><?= $escape($installWindows) ?></code>
                    <button type="button" class="btn btn-outline-primary install-copy" data-copy-command="<?= $escape($installWindows) ?>">
                        <i class="bi bi-clipboard me-1"></i><span class="copy-label">Копировать</span>
                    </button>
                </div>
            </article>
        </div>
        <div class="device-hero__actions mt-4">
            <a class="btn btn-primary site-btn" href="/pages/code/#install-title">Скачать / установить</a>
            <a class="btn btn-success site-btn" href="/farm.php">Открыть личный кабинет</a>
            <a class="btn btn-outline-primary site-btn site-btn--ghost" href="/pages/code/">Посмотреть код</a>
        </div>
    </section>
    <section class="device-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">📡 Как работает система</span>
            <h2>От датчика до Telegram-команды</h2>
            <p>Каждая часть отвечает за свою задачу: Arduino работает рядом с железом, Raspberry Pi запускает приложение, а сайты и Telegram дают удалённый контроль.</p>
        </div>
        <div class="device-flow" aria-label="Датчики -> Arduino -> Raspberry Pi -> Desktop -> API -> личный кабинет fitodomik.ru -> Telegram">
            <?php foreach ($systemFlow as $node): ?>
                <article class="device-flow__node">
                    <span><i class="bi <?= $escape($node['icon']) ?>"></i></span>
                    <h3><?= $escape($node['title']) ?></h3>
                    <p><?= $escape($node['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="device-note">
            <strong>fitodomik.ru</strong> объединяет публичную документацию, компоненты, установщик, личный кабинет, данные устройств, графики, сценарии и очередь команд.
        </div>
    </section>
    <section class="device-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">⚙️ Возможности</span>
            <h2>Функции, которые уже складываются в единую IoT-систему</h2>
        </div>
        <div class="device-card-grid device-card-grid--four">
            <?php foreach ($features as $feature): ?>
                <article class="device-feature-card">
                    <span class="device-feature-card__icon"><i class="bi <?= $escape($feature['icon']) ?>"></i></span>
                    <h3><?= $escape($feature['title']) ?></h3>
                    <ul>
                        <?php foreach ($feature['items'] as $item): ?>
                            <li><?= $escape($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="device-section device-split reveal-section">
        <div>
            <span class="section-eyebrow">🧩 Динамические датчики</span>
            <h2>Можно начать с пустого устройства и добавить только нужные модули</h2>
            <p>В пустой конфигурации сначала видны только “Часы” и “Настройки”. Пользователь добавляет датчики через мастер: программа показывает свободные и занятые порты, даёт выбрать аналоговый, цифровой или I2C-профиль, импортировать JSON-профиль, подключить Arduino-библиотеку и откалибровать аналоговый датчик.</p>
            <p>После этого desktop создаёт <code>GeneratedConfig.h</code>, обновляет <code>ExtraSensors.h/cpp</code>, Arduino отправляет <code>EXTRA_SENSORS</code>, а данные появляются в приложении. При включённом <code>send_to_server</code> dynamic values уходят на сайт, попадают в графики текущего устройства и видны в Telegram через <code>/sensors</code>.</p>
        </div>
        <div class="device-pipeline">
            <?php foreach ($dynamicSteps as $index => $step): ?>
                <div class="device-pipeline__step">
                    <span><?= $escape(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                    <strong><?= $escape($step) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="device-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">⚙️ Сценарии автоматизации</span>
            <h2>Правила связывают реальные датчики и реле выбранного устройства</h2>
            <p>Сценарии создаются в desktop-приложении и доступны на сайте в <code>device-settings.php</code>. Срабатывания логируются, а сервер проверяет, что <code>sensor_uid</code> и <code>relay_uid</code> принадлежат текущему <code>device_id</code>.</p>
        </div>
        <div class="scenario-grid">
            <?php foreach ($scenarios as $scenario): ?>
                <article class="scenario-card">
                    <span>Если</span>
                    <h3><?= $escape($scenario['condition']) ?></h3>
                    <p><?= $escape($scenario['action']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="device-section device-split reveal-section">
        <div>
            <span class="section-eyebrow">🤖 Telegram-бот</span>
            <h2>Бот показывает данные только после привязки</h2>
            <p>Telegram привязывается к профилю через одноразовый код. Бот проверяет <code>telegram_user_id</code>, работает с выбранным устройством и не показывает данные непривязанным пользователям.</p>
            <p>Через очередь команд бот может попросить Raspberry Pi сделать фото, запустить анализ растения, выполнить ping, обновить пороги или расписание, переподключить Arduino и управлять реле.</p>
        </div>
        <div class="command-cloud" aria-label="Команды Telegram-бота">
            <?php foreach ($telegramCommands as $command): ?>
                <span><?= $escape($command) ?></span>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="device-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">📊 Сравнение</span>
            <h2>Чем ФитоДомик отличается от обычных решений</h2>
            <p>Стоимость зависит от выбранных компонентов, но архитектура проекта уже включает железо, desktop, сайт, Telegram и API.</p>
        </div>
        <div class="comparison-table-wrap">
            <table class="comparison-table">
                <thead>
                    <tr>
                        <?php foreach ($comparisonRows[0] as $heading): ?>
                            <th><?= $escape($heading) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($comparisonRows, 1) as $row): ?>
                        <tr>
                            <?php foreach ($row as $index => $cell): ?>
                                <?php if ($index === 0): ?>
                                    <th scope="row"><?= $escape($cell) ?></th>
                                <?php else: ?>
                                    <td><?= $escape($cell) ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="device-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">🌱 Для кого проект</span>
            <h2>Учебный, домашний и инженерный проект одновременно</h2>
        </div>
        <div class="device-card-grid device-card-grid--four">
            <?php foreach ($audiences as $audience): ?>
                <article class="audience-card">
                    <i class="bi <?= $escape($audience['icon']) ?>"></i>
                    <h3><?= $escape($audience['title']) ?></h3>
                    <p><?= $escape($audience['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="device-section reveal-section">
        <div class="section-heading">
            <span class="section-eyebrow">🌐 Связь сайтов</span>
            <h2>Три части работают вместе</h2>
        </div>
        <div class="site-links-grid">
            <article class="site-link-card">
                <h3>fitodomik.ru</h3>
                <p>Публичная витрина, документация, компоненты, галерея, код и установщик.</p>
            </article>
            <article class="site-link-card">
                <h3>Личный кабинет fitodomik.ru</h3>
                <p>REST API, хранение данных, графики, команды, сценарии и Telegram.</p>
            </article>
            <article class="site-link-card">
                <h3>desktop</h3>
                <p>Работает на Raspberry Pi, читает Arduino, отправляет данные, выполняет команды и анализирует растение.</p>
            </article>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../includes/install_copy_script.php'; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
?>
