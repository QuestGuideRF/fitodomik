<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/headers.php';
require_once '../config/site.php';
require_once '../config/theme.php';
const FITODOMIK_LOGIN_HOME_PATH = '/farm.php';
const FITODOMIK_LOGIN_PAGE_PATH = '/authentication/login.php';
const FITODOMIK_LOGIN_ICON_PATH = '/static/icon/apple-touch-icon.v10.png';
$siteUrl = fitodomikSiteUrl();
$theme = fitodomikCurrentTheme();
$redirect_target = fitodomikSafeRedirect(
    $_GET['redirect'] ?? $_POST['redirect'] ?? '',
    FITODOMIK_LOGIN_HOME_PATH
);
$encodedRedirectTarget = rawurlencode($redirect_target);
if (isLoggedIn()) {
    header('Location: ' . $redirect_target);
    exit();
}
$error = '';
$loginUsername = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fitodomikRequireCsrf();
    $usernameInput = $_POST['username'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $loginUsername = is_string($usernameInput) ? trim($usernameInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $rateLimitSubject = fitodomikClientAddress() . "\0" . strtolower($loginUsername);
    $retryAfter = fitodomikConsumeRateLimit($pdo, 'web-login', $rateLimitSubject, 10, 900, 900);
    if ($retryAfter > 0) {
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        $error = 'Слишком много попыток входа. Повторите позже.';
    }
    if ($error === '' && ($loginUsername === '' || $password === '')) {
        $error = 'Пожалуйста, заполните все поля';
    } elseif ($error === '' && (strlen($loginUsername) > 191 || strlen($password) > 4096)) {
        $error = 'Неверное имя пользователя или пароль';
    } elseif ($error === '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$loginUsername]);
        $user = $stmt->fetch();
        if (fitodomikAccountAdmitted($user) && password_verify($password, $user['password'])) {
            fitodomikClearRateLimit($pdo, 'web-login', $rateLimitSubject);
            session_regenerate_id(true);
            fitodomikFillSessionFromUser($user);
            if (!empty($_POST['remember_me'])) {
                fitodomikCreateRememberLogin($pdo, (int)$user['id']);
            } else {
                fitodomikClearRememberCookie();
            }
            header('Location: ' . $redirect_target);
            exit();
        } else {
            $error = 'Неверное имя пользователя или пароль';
        }
    }
}
$telegramError = '';
if (isset($_GET['telegram_error']) && is_string($_GET['telegram_error'])) {
    $allowedTelegramErrors = [
        'Нет токена входа',
        'Ссылка Telegram устарела. Запросите новую командой /login',
        'Ссылка Telegram уже использована',
        'Не удалось войти через Telegram',
    ];
    $telegramError = in_array($_GET['telegram_error'], $allowedTelegramErrors, true)
        ? $_GET['telegram_error']
        : 'Не удалось войти через Telegram';
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - ФитоДомик</title>
    <meta name="description" content="Вход в систему управления умной фермой ФитоДомик. Авторизуйтесь для доступа к полному функционалу управления вашей умной фермой.">
    <meta name="keywords" content="вход, логин, авторизация, умная ферма, фитодомик, управление фермой">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="<?php echo htmlspecialchars($siteUrl . FITODOMIK_LOGIN_PAGE_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:title" content="Вход в систему ФитоДомик">
    <meta property="og:description" content="Авторизуйтесь для доступа к полному функционалу управления вашей умной фермой ФитоДомик.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_LOGIN_PAGE_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_LOGIN_ICON_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="ФитоДомик">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Вход в систему ФитоДомик">
    <meta name="twitter:description" content="Авторизуйтесь для доступа к полному функционалу управления вашей умной фермой.">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_LOGIN_ICON_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/auth.v4.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v10.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v10.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v10.png">
    <link rel="manifest" href="/site.v10.webmanifest">
    <link rel="shortcut icon" href="/static/icon/favicon.v10.ico">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "Вход в систему - ФитоДомик",
        "description": "Страница авторизации для доступа к управлению умной фермой ФитоДомик",
        "publisher": {
            "@type": "Organization",
            "name": "ФитоДомик",
            "logo": {
                "@type": "ImageObject",
                "url": <?php echo json_encode($siteUrl . FITODOMIK_LOGIN_ICON_PATH, JSON_UNESCAPED_SLASHES); ?>
            }
        },
        "breadcrumb": {
            "@type": "BreadcrumbList",
            "itemListElement": [
                {
                    "@type": "ListItem",
                    "position": 1,
                    "name": "Главная",
                    "item": <?php echo json_encode($siteUrl . '/', JSON_UNESCAPED_SLASHES); ?>
                },
                {
                    "@type": "ListItem",
                    "position": 2,
                    "name": "Вход в систему",
                    "item": <?php echo json_encode($siteUrl . FITODOMIK_LOGIN_PAGE_PATH, JSON_UNESCAPED_SLASHES); ?>
                }
            ]
        },
        "mainEntity": {
            "@type": "LoginAction",
            "target": {
                "@type": "EntryPoint",
                "urlTemplate": <?php echo json_encode($siteUrl . FITODOMIK_LOGIN_PAGE_PATH, JSON_UNESCAPED_SLASHES); ?>,
                "actionPlatform": [
                    "http://schema.org/DesktopWebPlatform",
                    "http://schema.org/MobileWebPlatform"
                ]
            },
            "result": {
                "@type": "EntryPoint",
                "urlTemplate": <?php echo json_encode($siteUrl . FITODOMIK_LOGIN_HOME_PATH, JSON_UNESCAPED_SLASHES); ?>
            }
        }
    }
    </script>
</head>
<body class="auth-page auth-page--login" itemscope itemtype="https://schema.org/WebPage">
    <main class="auth-shell">
        <header class="auth-topbar">
            <a class="auth-brand" href="../index.php" aria-label="ФитоДомик — на главную">
                <img src="/static/icon/apple-touch-icon.v10.png" alt="" width="42" height="42">
                <span class="auth-brand-text">
                    <span class="auth-brand-name">ФитоДомик</span>
                    <span class="auth-brand-note">Управление умной фермой</span>
                </span>
            </a>
            <button id="theme-toggle" class="theme-toggle auth-theme-toggle" type="button" data-theme-toggle
                    aria-label="Переключить цветовую тему" title="Переключить цветовую тему">
                <span class="theme-icon" aria-hidden="true">◐</span>
            </button>
        </header>
        <section class="auth-card" aria-labelledby="login-title">
            <aside class="auth-context">
                <div class="auth-context-copy">
                    <p class="auth-kicker">Личный кабинет</p>
                    <h2>Ваша ферма всегда рядом</h2>
                    <p class="auth-context-description">
                        Следите за микроклиматом, переключайтесь между устройствами
                        и управляйте ФитоДомиком из одного кабинета.
                    </p>
                </div>
                <ul class="auth-benefits">
                    <li>Показания и графики по каждому устройству</li>
                    <li>Пороги, расписание и удалённые команды</li>
                    <li>Фото, таймлапс и Telegram-уведомления</li>
                </ul>
                <p class="auth-context-footnote">
                    Доступ к данным ограничен вашим аккаунтом и выбранным устройством.
                </p>
            </aside>
            <div class="auth-panel" itemprop="mainEntity" itemscope itemtype="https://schema.org/UserInteraction">
                <div class="auth-panel-header">
                    <h1 id="login-title" itemprop="name">Вход в аккаунт</h1>
                    <p>Введите никнейм и пароль, указанные при регистрации.</p>
                </div>
                <?php if ($error) : ?>
                    <div class="auth-alert auth-alert--error" role="alert">
                        <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['registered'])) : ?>
                    <output class="auth-alert auth-alert--success">
                        Регистрация успешно завершена. Теперь вы можете войти.
                    </output>
                <?php endif; ?>
                <?php if ($telegramError !== '') : ?>
                    <div class="auth-alert auth-alert--error" role="alert">
                        <?php echo htmlspecialchars($telegramError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" class="auth-form-modern"
                      itemscope itemtype="https://schema.org/LoginAction">
                    <?php echo fitodomikCsrfInput(); ?>
                    <input type="hidden" name="redirect"
                           value="<?php echo htmlspecialchars($redirect_target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <div class="auth-field">
                        <label for="username">Никнейм</label>
                        <input type="text" id="username" name="username"
                               value="<?php echo htmlspecialchars($loginUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                               maxlength="191" autocomplete="section-fitodomik username" autocapitalize="none"
                               spellcheck="false" enterkeyhint="next" placeholder="Например, fitodomik_user"
                               required autofocus itemprop="name">
                    </div>
                    <div class="auth-field">
                        <div class="auth-label-row">
                            <label for="password">Пароль</label>
                        </div>
                        <div class="auth-input-wrap">
                            <input type="password" id="password" name="password"
                                   maxlength="4096" autocomplete="section-fitodomik current-password" enterkeyhint="go"
                                   placeholder="Введите пароль" data-password-input required>
                            <button class="auth-password-toggle" type="button"
                                    data-password-toggle="password" aria-pressed="false">
                                Показать
                            </button>
                        </div>
                    </div>
                    <label class="auth-remember">
                        <input type="checkbox" name="remember_me" value="1" checked>
                        <span>Оставаться в аккаунте на этом устройстве</span>
                    </label>
                    <div class="auth-actions-modern">
                        <button type="submit" class="auth-submit">Войти в кабинет</button>
                        <p class="auth-switch">
                            Нет аккаунта?
                            <a href="register.php?redirect=<?php echo htmlspecialchars($encodedRedirectTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                               class="auth-inline-link" itemprop="potentialAction"
                               itemscope itemtype="https://schema.org/RegisterAction">
                                Зарегистрироваться
                            </a>
                        </p>
                    </div>
                    <meta itemprop="target" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_LOGIN_PAGE_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <meta itemprop="result" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_LOGIN_HOME_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                </form>
                <meta itemprop="dateModified" content="<?php echo date('c'); ?>">
            </div>
        </section>
        <footer class="auth-footer">
            <a href="../index.php">Вернуться на главную</a>
            <span>ФитоДомик · личный кабинет</span>
        </footer>
    </main>
    <script src="/static/js/theme.v20261009.js" defer></script>
    <script src="/static/js/auth.v4.js" defer></script>
</body>
</html>
