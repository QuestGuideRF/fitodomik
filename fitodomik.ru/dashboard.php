<?php
require_once 'config/database.php';
require_once 'config/session.php';
if (!isLoggedIn()) {
    header('Location: /authentication/login.php?redirect=%2Fdashboard.php', true, 302);
    exit;
}
require_once 'security/headers.php';
require_once 'config/site.php';
const FITODOMIK_DASHBOARD_APPLE_ICON_PATH = '/security/image.php?file=icon/apple-touch-icon.png';
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ФитоДомик - Панель управления</title>
    <meta name="description" content="Панель управления умной фермой ФитоДомик. Контролируйте показатели в реальном времени, настраивайте автоматизацию и управляйте вашей умной фермой.">
    <meta name="keywords" content="панель управления, умная ферма, фитодомик, мониторинг, автоматизация, выращивание растений, iot">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo htmlspecialchars(fitodomikUrl('/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="ФитоДомик - Панель управления">
    <meta property="og:description" content="Персональная панель управления умной фермой ФитоДомик. Мониторинг показателей и управление всеми системами.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars(fitodomikUrl('/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_DASHBOARD_APPLE_ICON_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="ФитоДомик">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="ФитоДомик - Панель управления">
    <meta name="twitter:description" content="Персональная панель управления умной фермой ФитоДомик.">
    <meta name="twitter:image" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_DASHBOARD_APPLE_ICON_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <script src="/static/js/theme.v20261009.js" defer></script>
    <link rel="apple-touch-icon" sizes="180x180" href="security/image.php?file=icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="security/image.php?file=icon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="security/image.php?file=icon/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="shortcut icon" href="security/image.php?file=icon/favicon.ico">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "ФитоДомик - Панель управления",
        "applicationCategory": "IoT, SmartHome, FarmManagement",
        "description": "Персональная панель управления умной фермой ФитоДомик для мониторинга показателей и управления системами",
        "operatingSystem": "All",
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
                "url": <?php echo json_encode(fitodomikUrl(FITODOMIK_DASHBOARD_APPLE_ICON_PATH), JSON_UNESCAPED_SLASHES); ?>
            }
        }
    }
    </script>
    <script src="/assets/js/theme.js?rev=20261009" defer></script>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <div class="user-info" style="flex-grow: 1; display: flex; align-items: center;">
                <div class="user-avatar">
                    <?php if (!empty($user['avatar'])) : ?>
                        <img src="security/image.php?file=avatars/<?php echo htmlspecialchars($user['avatar']); ?>" alt="Фото профиля" loading="lazy" width="40" height="40">
                    <?php else : ?>
                        <div class="avatar-placeholder">
                            <span><?php echo strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <h1 class="site-title" style="color: #1f7a3a; text-align: center; flex-grow: 2;">ФитоДомик</h1>
            <button type="button" class="theme-toggle" style="flex-grow: 1; display: flex; justify-content: flex-end;">
                <span class="theme-icon">☀️</span>
            </button>
        </div>
    </header>
    <main class="container">
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
        foreach ($components as $component) {
            if (file_exists($component)) {
                include_once $component;
            }
        }
        ?>
    </main>
    <footer>
    </footer>
</body>
</html>
