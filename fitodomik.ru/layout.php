<?php
require_once __DIR__ . '/includes/config.php';
if (empty($skipUserSession)) {
    require_once __DIR__ . '/includes/session.php';
}
require_once __DIR__ . '/includes/debug.php';
require_once __DIR__ . '/includes/seo_tags.php';
require_once __DIR__ . '/includes/analytics.php';
require_once __DIR__ . '/config/theme.php';
$theme = fitodomikCurrentTheme();
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$isLocalAssetPath = static function ($value): bool {
    return is_string($value)
        && preg_match('#^/[A-Za-z0-9][A-Za-z0-9/_.-]*$#', $value) === 1;
};
$assetVersion = '17';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$currentPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$isHomePage = $currentPath === '/' || $currentPath === '/index.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header_remove('Pragma');
header_remove('Expires');
$pageCanonical = isset($pageCanonical) ? trim((string)$pageCanonical) : '';
$absoluteUrl = $pageCanonical !== ''
    ? $pageCanonical
    : fitodomikRuSiteUrl() . '/' . ltrim($currentPath, '/');
$pageTitle = $pageTitle ?? $seo_default_title;
$pageDescription = $pageDescription ?? $seo_default_description;
$pageKeywords = $pageKeywords ?? getAllKeywords();
$pageImage = $pageImage ?? $seo_default_image;
$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$pageScripts = isset($pageScripts) && is_array($pageScripts) ? $pageScripts : [];
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$defaultSchema = [
    '@context' => 'https://schema.org',
    '@type' => $pageSchemaType ?? 'SoftwareApplication',
    'name' => 'ФитоДомик',
    'applicationCategory' => 'IoTApplication',
    'operatingSystem' => 'Raspberry Pi OS, Windows, Linux, Web, Telegram',
    'description' => $pageDescription,
    'url' => $absoluteUrl,
    'image' => $pageImage,
    'sameAs' => [
        'https://github.com/QuestGuideRF/fitodomik',
    ],
    'keywords' => $pageKeywords,
];
$schemaData = $pageStructuredData ?? $defaultSchema;
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo $escape($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php fitodomikRenderAnalytics(); ?>
    <title><?php echo $escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo $escape($pageDescription); ?>">
    <meta name="keywords" content="<?php echo $escape($pageKeywords); ?>">
    <?php if (!empty($pageRobots)): ?>
    <meta name="robots" content="<?php echo $escape($pageRobots); ?>">
    <?php endif; ?>
    <meta name="google-site-verification" content="your-verification-code">
    <link rel="search" type="application/opensearchdescription+xml" title="ФитоДомик" href="/opensearch.xml">
    <link rel="canonical" href="<?php echo $escape($absoluteUrl); ?>">
    <meta property="og:title" content="<?php echo $escape($pageTitle); ?>">
    <meta property="og:description" content="<?php echo $escape($pageDescription); ?>">
    <meta property="og:image" content="<?php echo $escape($pageImage); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $escape($absoluteUrl); ?>">
    <meta property="og:site_name" content="ФитоДомик">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $escape($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo $escape($pageDescription); ?>">
    <meta name="twitter:image" content="<?php echo $escape($pageImage); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v<?php echo $assetVersion; ?>.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v<?php echo $assetVersion; ?>.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v<?php echo $assetVersion; ?>.png">
    <link rel="icon" type="image/x-icon" href="/static/icon/favicon.v<?php echo $assetVersion; ?>.ico">
    <link rel="manifest" href="/site.v<?php echo $assetVersion; ?>.webmanifest">
    <meta name="msapplication-TileColor" content="#2ecc71">
    <?php if (!empty($preloadHeroImage)): ?>
    <link rel="preload" as="image" href="/static/images/hero.v<?php echo $assetVersion; ?>.avif" type="image/avif" media="(max-width: 768px)" fetchpriority="high">
    <link rel="preload" as="image" href="/static/images/hero.v<?php echo $assetVersion; ?>.png" type="image/png" media="(min-width: 769px)" fetchpriority="high">
    <?php endif; ?>
    <?php if ($isHomePage): ?>
    <style id="critical-home-css"><?php echo file_get_contents(__DIR__ . '/assets/css/critical-home.css'); ?></style>
    <?php else: ?>
    <style id="critical-navigation-css">
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; }
    .container { width: 100%; margin-inline: auto; padding-inline: 12px; }
    .navbar { position: relative; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; padding-block: 0.5rem; }
    .navbar > .container { display: flex; flex-wrap: inherit; align-items: center; justify-content: space-between; }
    .navbar-brand { display: inline-flex; align-items: center; text-decoration: none; white-space: nowrap; }
    .navbar-nav { display: flex; flex-direction: column; margin: 0; padding-left: 0; list-style: none; }
    .navbar-collapse { flex-basis: 100%; flex-grow: 1; align-items: center; }
    .collapse:not(.show) { display: none; }
    @media (min-width: 576px) { .container { max-width: 540px; } }
    @media (min-width: 768px) { .container { max-width: 720px; } }
    @media (min-width: 992px) {
        .container { max-width: 960px; }
        .navbar-expand-lg { flex-wrap: nowrap; justify-content: flex-start; }
        .navbar-expand-lg .navbar-nav { flex-direction: row; }
        .navbar-expand-lg .navbar-collapse { display: flex !important; flex-basis: auto; }
        .navbar-expand-lg .navbar-toggler { display: none; }
    }
    @media (min-width: 1200px) { .container { max-width: 1140px; } }
    @media (min-width: 992px) and (max-width: 1399.98px) {
        .site-navbar > .container { max-width: none; padding-inline: clamp(1rem, 2vw, 2rem); }
        .site-navbar .navbar-brand { margin-right: 0.65rem; font-size: 1rem; }
        .site-navbar .navbar-collapse { min-width: 0; }
        .site-navbar .navbar-nav { flex-wrap: nowrap; }
        .site-navbar .nav-link { padding: 0.42rem 0.5rem; font-size: 0.84rem; white-space: nowrap; }
        .site-navbar__actions { flex: 0 0 auto; white-space: nowrap; }
        .site-navbar__actions .btn { padding: 0.35rem 0.6rem; font-size: 0.84rem; }
        .site-navbar__actions .theme-toggle { width: 2.15rem; height: 2.15rem; padding: 0; }
    }
    </style>
    <?php endif; ?>
    <?php if (!$isHomePage): ?>
    <link rel="preload" href="/static/css/bootstrap.v<?php echo $assetVersion; ?>.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="/static/css/bootstrap.v<?php echo $assetVersion; ?>.css" rel="stylesheet"></noscript>
    <?php endif; ?>
    <?php if ($isHomePage): ?>
    <link rel="preload" href="/static/css/style.v<?php echo $assetVersion; ?>.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="/static/css/style.v<?php echo $assetVersion; ?>.css" rel="stylesheet"></noscript>
    <?php else: ?>
    <link href="/static/css/style.v<?php echo $assetVersion; ?>.css" rel="stylesheet">
    <?php endif; ?>
    <?php if (!$isHomePage): ?>
    <link rel="stylesheet" href="/static/css/bootstrap-icons.v<?php echo $assetVersion; ?>.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="/static/css/bootstrap-icons.v<?php echo $assetVersion; ?>.css"></noscript>
    <?php else: ?>
    <noscript><link rel="stylesheet" href="/static/css/bootstrap-icons.v<?php echo $assetVersion; ?>.css"></noscript>
    <?php endif; ?>
    <?php foreach ($pageStylesheets as $stylesheet): ?>
        <?php if ($isLocalAssetPath($stylesheet)): ?>
    <link rel="stylesheet" href="<?php echo $escape($stylesheet); ?>">
        <?php endif; ?>
    <?php endforeach; ?>
    <script type="application/ld+json">
    <?php
    echo json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ?>
    </script>
</head>
<body>
    <?php fitodomikRenderAnalyticsNoScript(); ?>
    <nav class="navbar navbar-expand-lg navbar-dark site-navbar" aria-label="Основная навигация">
        <div class="container">
            <a class="navbar-brand" href="/">
                <span>ФитоДомик</span>
            </a>
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarNav"
                aria-controls="navbarNav"
                aria-expanded="false"
                aria-label="Открыть меню">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/news/') !== false ? 'active' : ''; ?>" href="/pages/news/">Новости</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/info/') !== false ? 'active' : ''; ?>" href="/pages/info/">О проекте</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/device/') !== false ? 'active' : ''; ?>" href="/pages/device/">Устройство</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/galery/') !== false ? 'active' : ''; ?>" href="/pages/galery/">Галерея</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/components/') !== false ? 'active' : ''; ?>" href="/pages/components/">Компоненты</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/code/') !== false ? 'active' : ''; ?>" href="/pages/code/">Код</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($currentPath, '/pages/feedback/') !== false ? 'active' : ''; ?>" href="/pages/feedback/">Обратная связь</a>
                    </li>
                    <?php if ($isAdmin): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/">Админ-панель</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center site-navbar__actions">
                    <button class="btn btn-outline-light me-2 theme-toggle" id="themeToggle" type="button" aria-label="Переключить тему">
                        <?php if ($isHomePage): ?>
                        <span class="theme-toggle__symbol" data-theme-icon aria-hidden="true">☾</span>
                        <?php else: ?>
                        <i class="bi bi-moon-fill" data-theme-icon aria-hidden="true"></i>
                        <?php endif; ?>
                    </button>
                    <?php if ($isLoggedIn): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" data-bs-display="static">
                                <?php echo $escape($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end site-profile-menu">
                                <li><a class="dropdown-item" href="/farm.php">Личный кабинет</a></li>
                                <li><a class="dropdown-item" href="/profile/">Профиль</a></li>
                                <?php if ($isAdmin): ?>
                                    <li><a class="dropdown-item" href="/admin/">Админ-панель</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="/auth/logout.php">
                                        <?php echo fitodomikCsrfInput(); ?>
                                        <button class="dropdown-item" type="submit">Выйти</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="/authentication/login.php" class="btn btn-outline-light me-2">Войти</a>
                        <a href="/authentication/register.php" class="btn btn-light">Регистрация</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <main class="container site-main">
        <?php
        if (isset($content)) {
            echo $content;
        }
        ?>
    </main>
    <footer class="footer site-footer">
        <div class="container site-footer__inner">
            <div class="site-footer__brand">
                <strong>ФитоДомик</strong>
                <span>Проект умной мини-фермы</span>
            </div>
            <nav class="site-footer__links" aria-label="Ссылки проекта">
                <a href="/author">
                    <i class="bi bi-person-circle" aria-hidden="true"></i>
                    <span>Страница автора</span>
                </a>
                <a href="https://github.com/QuestGuideRF" target="_blank" rel="noopener">
                    <i class="bi bi-github" aria-hidden="true"></i>
                    <span>GitHub</span>
                </a>
            </nav>
        </div>
    </footer>
    <script src="/static/js/bootstrap.v<?php echo $assetVersion; ?>.js" defer></script>
    <script src="/static/js/theme.v<?php echo $assetVersion; ?>.js" defer></script>
    <?php foreach ($pageScripts as $script): ?>
        <?php if ($isLocalAssetPath($script)): ?>
    <script src="<?php echo $escape($script); ?>" defer></script>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($isHomePage): ?>
    <script src="/static/js/deferred-icons.v<?php echo $assetVersion; ?>.js" data-stylesheet="/static/css/bootstrap-icons.v<?php echo $assetVersion; ?>.css" defer></script>
    <?php endif; ?>
</body>
</html>
