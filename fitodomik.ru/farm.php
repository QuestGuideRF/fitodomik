<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'security/security_bootstrap.php';
require_once 'config/device_context.php';
require_once 'config/site.php';
require_once 'config/theme.php';
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow', true);
}
const FITODOMIK_INDEX_PATH = '/farm.php';
const FITODOMIK_INDEX_APPLE_ICON_PATH = '/static/icon/apple-touch-icon.v10.png';
$siteUrl = fitodomikSiteUrl();
$theme = fitodomikCurrentTheme();
$user = null;
$devices = [];
$selectedDevice = null;
function fitodomikFarmLocalUploadExists(string $directory, $filename): bool
{
    $basename = basename(str_replace('\\', '/', (string)$filename));
    if ($basename === '') {
        return false;
    }
    $baseDir = __DIR__ . '/uploads/' . $directory;
    $baseReal = realpath($baseDir);
    $targetReal = $baseReal === false ? false : realpath($baseReal . DIRECTORY_SEPARATOR . $basename);
    return $baseReal !== false
        && $targetReal !== false
        && strpos($targetReal, $baseReal . DIRECTORY_SEPARATOR) === 0
        && is_file($targetReal)
        && !is_link($targetReal);
}
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    try {
        $devices = fitodomikGetUserDevices($pdo, (int)$_SESSION['user_id'], false);
        $selectedDevice = fitodomikGetSelectedDevice($pdo, (int)$_SESSION['user_id']);
    } catch (Exception $e) {
        error_log('index.php device context: ' . $e->getMessage());
        $devices = [];
        $selectedDevice = null;
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(fitodomikCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <title>ФитоДомик - веб-кабинет умной мини-фермы</title>
    <meta name="description" content="ФитоДомик - веб-кабинет умной мини-фермы: графики микроклимата, фото растения, OpenCV-анализ, Telegram-уведомления, QR-привязка устройств и удалённые команды для Raspberry Pi и Arduino.">
    <meta name="keywords" content="умная ферма, мини-ферма, умная теплица, домашняя теплица, автоматический полив, мониторинг растений, Raspberry Pi теплица, Arduino теплица, IoT ферма, CO2 мониторинг растений, OpenCV анализ растения, Telegram уведомления, удалённое управление теплицей, графики микроклимата">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_INDEX_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="ФитоДомик - веб-кабинет умной мини-фермы">
    <meta property="og:description" content="Графики, фото, таймлапс, Telegram-уведомления, QR-привязка устройств и удалённые команды для Raspberry Pi/Arduino фермы.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_INDEX_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_INDEX_APPLE_ICON_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="ФитоДомик">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="ФитоДомик - веб-кабинет умной мини-фермы">
    <meta name="twitter:description" content="Мониторинг микроклимата, фото растения, OpenCV-анализ, Telegram-уведомления и удалённые команды.">
    <meta name="twitter:image" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_INDEX_APPLE_ICON_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/preset-modes.v8.css">
    <script src="/static/js/theme.v20261009.js" defer></script>
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v10.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v10.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v10.png">
    <link rel="manifest" href="/site.v10.webmanifest">
    <link rel="shortcut icon" href="/static/icon/favicon.v10.ico">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "ФитоДомик - веб-кабинет умной мини-фермы",
        "applicationCategory": "IoTApplication",
        "description": "Веб-кабинет умной мини-фермы: датчики температуры и влажности, CO2-мониторинг, графики микроклимата, фото растения, OpenCV-анализ, Telegram-бот и удалённое управление Raspberry Pi/Arduino устройствами",
        "operatingSystem": "Web, Raspberry Pi OS, Telegram",
        "keywords": "умная ферма, мини-ферма, Raspberry Pi теплица, Arduino теплица, OpenCV анализ растения, Telegram уведомления, удалённое управление теплицей",
        "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "RUB"
        },
        "publisher": {
            "@type": "Organization",
            "name": "ФитоДомик",
            "logo": {
                "@type": "ImageObject",
                "url": <?php echo json_encode(fitodomikUrl(FITODOMIK_INDEX_APPLE_ICON_PATH), JSON_UNESCAPED_SLASHES); ?>
            }
        },
        "screenshot": [
            {
                "@type": "ImageObject",
                "url": <?php echo json_encode(fitodomikUrl('/security/image.php?file=images/dashboard.jpg'), JSON_UNESCAPED_SLASHES); ?>,
                "caption": "Панель управления ФитоДомик"
            }
        ],
        "potentialAction": {
            "@type": "ViewAction",
            "target": <?php echo json_encode(fitodomikUrl(FITODOMIK_INDEX_PATH), JSON_UNESCAPED_SLASHES); ?>
        }
    }
    </script>
    <style>
        .device-switcher {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-right: 12px;
            padding: 6px 10px;
            border: 1px solid var(--border-color);
            border-radius: 999px;
            background: var(--card-bg);
            color: var(--text-color);
        }
        .device-switcher label {
            font-size: 13px;
            color: var(--secondary-text);
            white-space: nowrap;
        }
        .device-switcher select {
            max-width: 230px;
            border: 0;
            outline: none;
            background: var(--input-bg);
            color: var(--text-color);
            font-weight: 600;
            border-radius: 8px;
            padding: 4px 8px;
        }
        .device-switcher select option {
            background: var(--input-bg);
            color: var(--text-color);
        }
        .device-switcher-empty {
            font-size: 13px;
            color: var(--secondary-text);
            margin-right: 12px;
        }
        @media (max-width: 820px) {
            .header-content {
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px 12px;
            }
            .site-title {
                order: 1;
                width: 100%;
            }
            .header-left {
                order: 2;
            }
            .header-right {
                order: 3;
                width: 100%;
                justify-content: space-between;
            }
            .device-switcher {
                flex: 1;
                margin-right: 8px;
                border-radius: 12px;
            }
            .device-switcher select {
                width: 100%;
                max-width: none;
            }
        }
    </style>
</head>
<body itemscope itemtype="https://schema.org/WebPage">
    <header class="main-header">
        <div class="header-content">
            <div class="header-left">
                <div class="user-info" itemprop="author" itemscope itemtype="https://schema.org/Person">
                    <div class="user-avatar">
                        <?php if ($user && !empty($user['avatar']) && fitodomikFarmLocalUploadExists('avatars', $user['avatar'])) : ?>
                            <img src="/media/avatars/<?php echo rawurlencode(basename((string)$user['avatar'])); ?>" alt="Фото профиля" width="40" height="40" itemprop="image">
                        <?php else : ?>
                            <div class="avatar-placeholder">
                                <span itemprop="name"><?php echo $user ? htmlspecialchars(strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Г'; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-dropdown">
                        <button class="profile-button">Профиль</button>
                        <div class="dropdown-content">
                            <?php if (!$user) : ?>
                                <a href="authentication/login.php">Войти</a>
                                <a href="authentication/register.php">Регистрация</a>
                            <?php else : ?>
                                <a href="authentication/profile.php">Профиль</a>
                                <a href="authentication/settings.php">Настройки</a>
                                <a href="devices.php">Устройства</a>
                                <a href="authentication/logout.php">Выйти</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <h1 class="site-title" itemprop="headline">ФитоДомик</h1>
            <div class="header-right">
                <?php if ($user) : ?>
                    <?php if (!empty($devices)) : ?>
                        <form class="device-switcher" method="POST" action="/api/select-device.php">
                            <?php echo fitodomikCsrfInput(); ?>
                            <label for="header_device_id">Устройство</label>
                            <select id="header_device_id" name="device_id" onchange="this.form.submit()">
                                <?php foreach ($devices as $device) : ?>
                                    <option value="<?php echo htmlspecialchars($device['device_id']); ?>" <?php echo ($selectedDevice && $selectedDevice['device_id'] === $device['device_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($device['device_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php else : ?>
                        <span class="device-switcher-empty">Нет привязанных устройств</span>
                    <?php endif; ?>
                <?php endif; ?>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему">
                    <span class="theme-icon">◐</span>
                </button>
            </div>
        </div>
    </header>
    <main class="container" itemprop="mainContentOfPage">
        <?php
        $components = [
            'components/farm-status.php',
            'components/farm-settings.php',
            'components/farm-graphs.php',
            'components/alarm-thresholds.php',
            'components/preset-modes.php',
            'components/planting-calendar.php',
            'components/event-log.php'
        ];
        $componentTitles = [
            'components/farm-status.php' => 'Состояние фермы',
            'components/farm-settings.php' => 'Настройка фермы',
            'components/farm-graphs.php' => 'Графики',
            'components/alarm-thresholds.php' => 'Пороги тревоги',
            'components/preset-modes.php' => 'Предустановленные режимы',
            'components/planting-calendar.php' => 'Календарь посадки',
            'components/event-log.php' => 'Журнал событий',
        ];
        foreach ($components as $component) {
            if (file_exists($component)) {
                try {
                    include_once $component;
                } catch (Throwable $e) {
                    error_log('farm.php component failed ' . $component . ': ' . $e->getMessage());
                    $componentTitle = $componentTitles[$component] ?? 'Раздел';
                    ?>
                    <div class="graphs-header">
                        <h2><?php echo htmlspecialchars($componentTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
                    </div>
                    <div class="graphs-content active">
                        <p>Раздел временно недоступен. Остальные блоки кабинета продолжают работать.</p>
                    </div>
                    <?php
                }
            }
        }
        ?>
    </main>
    <footer itemprop="contentInfo">
        <meta itemprop="dateModified" content="<?php echo date('c'); ?>">
    </footer>
</body>
</html>
