<?php
require_once 'config/error_handling.php';
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'security/security_bootstrap.php';
require_once 'config/device_context.php';
require_once 'config/site.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . fitodomikUrl('/authentication/login.php') . '?redirect=' . urlencode('/get_token.php'));
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$user_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(fitodomikCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <title>Доступ клиентов - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/qr-scanner.v5.css">
    <link rel="apple-touch-icon" sizes="180x180" href="security/image.php?file=icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="security/image.php?file=icon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="security/image.php?file=icon/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="mask-icon" href="security/image.php?file=icon/safari-pinned-tab.svg" color="#2E7D32">
    <meta name="msapplication-TileColor" content="#2E7D32">
    <meta name="theme-color" content="#2E7D32">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "Доступ клиентов - ФитоДомик",
        "description": "Подключение устройств и управление индивидуальными токенами клиентов ФитоДомика",
        "publisher": {
            "@type": "Organization",
            "name": "ФитоДомик",
            "logo": {
                "@type": "ImageObject",
                "url": <?php echo json_encode(fitodomikUrl('/security/image.php?file=icon/apple-touch-icon.png'), JSON_UNESCAPED_SLASHES); ?>
            }
        },
        "breadcrumb": {
            "@type": "BreadcrumbList",
            "itemListElement": [
                {
                    "@type": "ListItem",
                    "position": 1,
                    "name": "Главная",
                    "item": <?php echo json_encode(fitodomikUrl('/'), JSON_UNESCAPED_SLASHES); ?>
                },
                {
                    "@type": "ListItem",
                    "position": 2,
                    "name": "Доступ клиентов",
                    "item": <?php echo json_encode(fitodomikUrl('/get_token.php'), JSON_UNESCAPED_SLASHES); ?>
                }
            ]
        },
        "mainEntity": {
            "@type": "SoftwareApplication",
            "name": "Система управления умной фермой ФитоДомик",
            "applicationCategory": "BusinessApplication",
            "operatingSystem": "Все",
            "offers": {
                "@type": "Offer",
                "price": "0",
                "priceCurrency": "RUB"
            }
        }
    }
    </script>
    <style>
        :root {
            --primary-color: #1f7a3a;
            --primary-light: #dcfae6;
            --primary-dark: #17632f;
            --text-color: #1f2933;
            --bg-color: #f5f5f5;
            --card-bg: white;
            --shadow-color: rgba(0,0,0,0.1);
            --border-color: #d0d5dd;
        }
        [data-theme="dark"] {
            --primary-color: #8fd694;
            --primary-light: #214d2d;
            --primary-dark: #6fc17a;
            --text-color: #fff;
            --bg-color: #1a1a1a;
            --card-bg: #252525;
            --shadow-color: rgba(0,0,0,0.3);
            --border-color: #404040;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 30px;
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: 0 4px 20px var(--shadow-color);
            border: 1px solid var(--border-color);
            position: relative;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            position: relative;
        }
        h1 {
            color: var(--primary-color);
            font-size: 28px;
            margin: 0;
            flex-grow: 1;
            padding-right: 25px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .info-block {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding: 15px;
            background: var(--bg-color);
            border-radius: 8px;
            box-shadow: 0 2px 5px var(--shadow-color);
        }
        .label {
            font-weight: 500;
            color: var(--text-color);
            opacity: 0.8;
        }
        .value {
            font-weight: 400;
            color: var(--text-color);
            max-width: 350px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .device-status {
            display: flex;
            align-items: center;
            margin: 10px 0;
            padding: 10px;
            background: var(--bg-color);
            border-radius: 8px;
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 15px;
        }
        .status-on {
            background: var(--primary-light);
            box-shadow: 0 0 10px var(--primary-light);
        }
        .status-off {
            background: #b42318;
            box-shadow: 0 0 10px rgba(180, 35, 24, 0.5);
        }
        .theme-toggle {
            position: static;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            white-space: nowrap;
            box-shadow: 0 2px 5px var(--shadow-color);
            min-width: 160px;
            height: 42px;
            flex-shrink: 0;
        }
        .theme-toggle:hover {
            background: var(--primary-dark);
        }
        .theme-icon {
            font-size: 18px;
        }
        .footer-info {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-color);
            opacity: 0.7;
        }
        @media (max-width: 600px) {
            .container {
                margin: 20px;
                padding: 15px;
            }
            .header-container {
                flex-direction: column;
                align-items: stretch;
            }
            h1 {
                margin-bottom: 15px;
                text-align: center;
            }
            .theme-toggle {
                width: 100%;
            }
        }
    </style>
</head>
<body itemscope itemtype="https://schema.org/WebPage">
    <div class="container">
        <div class="header-container">
            <h1 itemprop="headline">ФитоДомик - Доступ клиентов</h1>
            <button class="theme-toggle" id="theme-toggle">
                <span class="theme-icon">🌓</span>
                <span data-theme-label>Светлая тема</span>
            </button>
        </div>
        <div class="info-block">
            <strong>Устройства</strong>
            <p>Привяжите устройство по QR-коду или откройте список уже подключённых устройств.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="qr-scan-trigger" type="button" data-qr-scanner-open>Сканировать QR</button>
                <a class="qr-scan-trigger" href="/devices.php" style="text-decoration:none;background:transparent;color:var(--primary-color)">Мои устройства</a>
            </div>
        </div>
        <?php
        try {
            $stmt = $pdo->prepare("SELECT id, username, first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) : ?>
                <div class="info-block" itemscope itemtype="https://schema.org/Person">
                    <div class="info-row">
                        <span class="label">ID пользователя:</span>
                        <span class="value" itemprop="identifier"><?php echo htmlspecialchars($user['id']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Имя пользователя:</span>
                        <span class="value" itemprop="alternateName"><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="label">Имя:</span>
                        <span class="value"><span itemprop="givenName"><?php echo htmlspecialchars($user['first_name']); ?></span> <span itemprop="familyName"><?php echo htmlspecialchars($user['last_name']); ?></span></span>
                    </div>
                    <a class="qr-scan-trigger" href="/authentication/settings.php#client-access">Индивидуальные токены клиентов</a>
                </div>
                <?php
                $selectedDevice = fitodomikGetSelectedDevice($pdo, (int)$user_id);
                $states = null;
                if ($selectedDevice) {
                    $stmt = $pdo->prepare("
                        SELECT lamp_state, curtains_state, created_at
                        FROM sensor_data
                        WHERE user_id = ? AND device_id = ?
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([(int)$user_id, $selectedDevice['device_id']]);
                    $states = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                $last_update = $states ? date('d.m.Y H:i:s', strtotime($states['created_at'])) : 'Нет данных';
                if ($states) : ?>
                    <div class="info-block" itemscope itemtype="https://schema.org/IoTSensor">
                        <h3 itemprop="name">Состояния устройств</h3>
                        <meta itemprop="dateModified" content="<?php echo date('c', strtotime($states['created_at'])); ?>">
                        <div class="device-status">
                            <div class="status-indicator <?php echo $states['lamp_state'] ? 'status-on' : 'status-off'; ?>"></div>
                            <span itemprop="value">Лампа: <?php echo $states['lamp_state'] ? 'Включена' : 'Выключена'; ?></span>
                        </div>
                        <div class="device-status">
                            <div class="status-indicator <?php echo $states['curtains_state'] ? 'status-on' : 'status-off'; ?>"></div>
                            <span itemprop="value">Шторы: <?php echo $states['curtains_state'] ? 'Закрыты' : 'Открыты'; ?></span>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="info-block">
                        <h3>Состояния устройств</h3>
                        <p>Нет данных о состоянии устройств</p>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="info-block">
                    <p>Пользователь не найден.</p>
                </div>
            <?php endif;
        } catch (Exception $e) {
            error_log('get_token.php: ' . $e->getMessage());
            echo '<div class="info-block"><p>Не удалось загрузить данные устройства.</p></div>';
        }
        ?>
        <div class="footer-info" itemprop="dateModified" content="<?php echo date('c'); ?>">
            Последнее обновление: <?php echo date('d.m.Y H:i:s'); ?>
        </div>
    </div>
    <script src="/static/js/device-qr-scanner.v5.js" defer></script>
    <script src="/assets/js/theme.js?rev=20261009" defer></script>
</body>
</html>
