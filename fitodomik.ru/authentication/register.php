<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/headers.php';
require_once '../config/site.php';
require_once '../config/theme.php';
require_once '../config/pending_registration.php';
require_once '../config/telegram_bot_features.php';
const FITODOMIK_REGISTER_HOME_PATH = '/farm.php';
const FITODOMIK_REGISTER_PAGE_PATH = '/authentication/register.php';
const FITODOMIK_REGISTER_ICON_PATH = '/static/icon/apple-touch-icon.v10.png';
$siteUrl = fitodomikSiteUrl();
$theme = fitodomikCurrentTheme();
$redirect_target = fitodomikSafeRedirect(
    $_GET['redirect'] ?? $_POST['redirect'] ?? '',
    FITODOMIK_REGISTER_HOME_PATH
);
$encodedRedirectTarget = rawurlencode($redirect_target);
if (isLoggedIn()) {
    header('Location: ' . $redirect_target);
    exit();
}
$errors = [];
$first_name = '';
$last_name = '';
$registrationUsername = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fitodomikRequireCsrf();
    $retryAfter = fitodomikConsumeRateLimit($pdo, 'web-register', fitodomikClientAddress(), 10, 3600, 3600);
    if ($retryAfter > 0) {
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        $errors[] = 'Слишком много попыток регистрации. Повторите позже.';
    }
    $firstNameInput = $_POST['first_name'] ?? '';
    $lastNameInput = $_POST['last_name'] ?? '';
    $usernameInput = $_POST['username'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $confirmPasswordInput = $_POST['confirm_password'] ?? '';
    $first_name = is_string($firstNameInput) ? trim($firstNameInput) : '';
    $last_name = is_string($lastNameInput) ? trim($lastNameInput) : '';
    $registrationUsername = is_string($usernameInput) ? trim($usernameInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirm_password = is_string($confirmPasswordInput) ? $confirmPasswordInput : '';
    if ($first_name === '') {
        $errors[] = "Имя обязательно для заполнения";
    }
    if ($last_name === '') {
        $errors[] = "Фамилия обязательна для заполнения";
    }
    if ($registrationUsername === '') {
        $errors[] = "Никнейм обязателен для заполнения";
    }
    if ($password === '') {
        $errors[] = "Пароль обязателен для заполнения";
    } elseif (strlen($password) < 8) {
        $errors[] = "Пароль должен содержать не менее 8 символов";
    } elseif (strlen($password) > 128) {
        $errors[] = "Пароль не должен превышать 128 символов";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Пароли не совпадают";
    }
    if ($registrationUsername !== '' && !preg_match('/^\w{3,20}$/D', $registrationUsername)) {
        $errors[] = "Никнейм должен содержать только латинские буквы, цифры и знак подчеркивания, длина от 3 до 20 символов";
    }
    $nameLength = function ($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    };
    if ($nameLength($first_name) > 50 || $nameLength($last_name) > 50) {
        $errors[] = "Имя и фамилия не должны превышать 50 символов";
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $first_name . $last_name)) {
        $errors[] = "Имя и фамилия содержат недопустимые управляющие символы";
    }
    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$registrationUsername]);
        if ($stmt->fetch()) {
            $errors[] = 'Не удалось продолжить регистрацию. Проверьте данные или попробуйте другой никнейм.';
        } else {
            $pendingUser = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'username' => $registrationUsername,
                'password' => $passwordHash,
                'redirect' => $redirect_target,
                'registration_started_at' => time(),
            ];
            try {
                $pdo->beginTransaction();
                $user = fitodomikCreateUserFromPendingRegistration($pdo, $pendingUser);
                $telegramLink = fitodomikTgCreateLinkCode($pdo, (int)$user['id']);
                $pdo->commit();
                session_regenerate_id(true);
                fitodomikFillSessionFromUser($user);
                try {
                    fitodomikCreateRememberLogin($pdo, (int)$user['id']);
                } catch (Exception $e) {
                    error_log('register remember login: ' . $e->getMessage());
                }
                $_SESSION['telegram_registration_link'] = [
                    'user_id' => (int)$user['id'],
                    'deep_token' => (string)$telegramLink['deep_token'],
                    'created_at' => time(),
                    'redirect' => $redirect_target,
                ];
                unset($_SESSION['temp_user']);
                $_SESSION['registration_redirect'] = $redirect_target;
                header("Location: telegram_verify.php?redirect=" . urlencode($redirect_target));
                exit();
            } catch (FitodomikInputException | FitodomikConflictException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('register account creation: ' . $e->getMessage());
                $errors[] = 'Не удалось создать аккаунт. Повторите попытку позже.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - ФитоДомик</title>
    <meta name="description" content="Регистрация в системе управления умной фермой ФитоДомик. Создайте аккаунт для доступа к полному функционалу управления вашей умной фермой.">
    <meta name="keywords" content="регистрация, создать аккаунт, умная ферма, фитодомик, управление фермой, новый пользователь">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="<?php echo htmlspecialchars($siteUrl . FITODOMIK_REGISTER_PAGE_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:title" content="Регистрация в системе ФитоДомик">
    <meta property="og:description" content="Создайте аккаунт для доступа к полному функционалу управления вашей умной фермой ФитоДомик.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_REGISTER_PAGE_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_REGISTER_ICON_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="ФитоДомик">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Регистрация в системе ФитоДомик">
    <meta name="twitter:description" content="Создайте аккаунт для доступа к полному функционалу управления вашей умной фермой.">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($siteUrl . FITODOMIK_REGISTER_ICON_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
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
        "name": "Регистрация в системе ФитоДомик",
        "description": "Страница регистрации для доступа к управлению умной фермой ФитоДомик",
        "publisher": {
            "@type": "Organization",
            "name": "ФитоДомик",
            "logo": {
                "@type": "ImageObject",
                "url": <?php echo json_encode($siteUrl . FITODOMIK_REGISTER_ICON_PATH, JSON_UNESCAPED_SLASHES); ?>
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
                    "name": "Регистрация",
                    "item": <?php echo json_encode($siteUrl . FITODOMIK_REGISTER_PAGE_PATH, JSON_UNESCAPED_SLASHES); ?>
                }
            ]
        },
        "mainEntity": {
            "@type": "RegisterAction",
            "target": {
                "@type": "EntryPoint",
                "urlTemplate": <?php echo json_encode($siteUrl . FITODOMIK_REGISTER_PAGE_PATH, JSON_UNESCAPED_SLASHES); ?>,
                "actionPlatform": [
                    "http://schema.org/DesktopWebPlatform",
                    "http://schema.org/MobileWebPlatform"
                ]
            },
            "result": {
                "@type": "EntryPoint",
                "urlTemplate": <?php echo json_encode($siteUrl . '/authentication/telegram_verify.php', JSON_UNESCAPED_SLASHES); ?>
            }
        }
    }
    </script>
</head>
<body class="auth-page auth-page--register">
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
        <section class="auth-card auth-card--register" aria-labelledby="register-title">
            <aside class="auth-context">
                <div class="auth-context-copy">
                    <p class="auth-kicker">Новый аккаунт</p>
                    <h2>Подключите свой ФитоДомик</h2>
                    <p class="auth-context-description">
                        Создайте аккаунт, при желании подключите Telegram
                        и привяжите первое устройство по безопасному QR-коду.
                    </p>
                </div>
                <ul class="auth-benefits">
                    <li>Несколько устройств в одном аккаунте</li>
                    <li>История показаний, графики и фотографии</li>
                    <li>Удалённые команды и Telegram-уведомления</li>
                </ul>
                <p class="auth-context-footnote">
                    Привязка первого устройства выполняется после создания аккаунта.
                </p>
            </aside>
            <div class="auth-panel">
                <div class="auth-panel-header">
                    <h1 id="register-title">Создание аккаунта</h1>
                    <p>Заполните данные. На следующем шаге Telegram можно подключить или пропустить.</p>
                </div>
                <?php if (!empty($errors)) : ?>
                    <div class="auth-alert auth-alert--error" role="alert">
                        <ul>
                            <?php foreach ($errors as $error) : ?>
                                <li><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" class="auth-form-modern">
                    <?php echo fitodomikCsrfInput(); ?>
                    <input type="hidden" name="redirect"
                           value="<?php echo htmlspecialchars($redirect_target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <div class="auth-fields-grid">
                        <div class="auth-field">
                            <label for="first_name">Имя</label>
                            <input type="text" id="first_name" name="first_name" maxlength="50"
                                   value="<?php echo htmlspecialchars($first_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                   autocomplete="given-name" enterkeyhint="next" placeholder="Ваше имя"
                                   required autofocus>
                        </div>
                        <div class="auth-field">
                            <label for="last_name">Фамилия</label>
                            <input type="text" id="last_name" name="last_name" maxlength="50"
                                   value="<?php echo htmlspecialchars($last_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                   autocomplete="family-name" enterkeyhint="next" placeholder="Ваша фамилия"
                                   required>
                        </div>
                        <div class="auth-field auth-field--full">
                            <div class="auth-label-row">
                                <label for="username">Никнейм</label>
                                <span class="auth-field-hint" id="username-hint">3–20 символов</span>
                            </div>
                            <input type="text" id="username" name="username"
                                   value="<?php echo htmlspecialchars($registrationUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                   pattern="\w{3,20}" maxlength="20"
                                   autocomplete="section-fitodomik username" autocapitalize="none" spellcheck="false"
                                   enterkeyhint="next" placeholder="Латинские буквы, цифры, _"
                                   aria-describedby="username-hint"
                                   title="Только латинские буквы, цифры и знак подчеркивания, длина от 3 до 20 символов"
                                   required>
                        </div>
                        <div class="auth-field">
                            <div class="auth-label-row">
                                <label for="password">Пароль</label>
                                <span class="auth-field-hint">Минимум 8 символов</span>
                            </div>
                            <div class="auth-input-wrap">
                                <input type="password" id="password" name="password"
                                       minlength="8" maxlength="128" autocomplete="section-fitodomik new-password"
                                       enterkeyhint="next" placeholder="Придумайте пароль"
                                       data-password-input data-password-primary required>
                                <button class="auth-password-toggle" type="button"
                                        data-password-toggle="password" aria-pressed="false">
                                    Показать
                                </button>
                            </div>
                        </div>
                        <div class="auth-field">
                            <label for="confirm_password">Повтор пароля</label>
                            <div class="auth-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password"
                                       minlength="8" maxlength="128" autocomplete="section-fitodomik new-password"
                                       enterkeyhint="go" placeholder="Повторите пароль"
                                       data-password-input data-password-confirmation
                                       aria-describedby="password-status" required>
                                <button class="auth-password-toggle" type="button"
                                        data-password-toggle="confirm_password" aria-pressed="false">
                                    Показать
                                </button>
                            </div>
                            <span class="auth-field-status" id="password-status" data-password-status>
                                Повторите пароль без изменений.
                            </span>
                        </div>
                    </div>
                    <div class="auth-actions-modern">
                        <button type="submit" class="auth-submit">Продолжить регистрацию</button>
                        <p class="auth-switch">
                            Уже есть аккаунт?
                            <a href="login.php?redirect=<?php echo htmlspecialchars($encodedRedirectTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                               class="auth-inline-link">Войти</a>
                        </p>
                    </div>
                </form>
            </div>
        </section>
        <footer class="auth-footer">
            <a href="../index.php">Вернуться на главную</a>
            <span>ФитоДомик · новый аккаунт</span>
        </footer>
    </main>
    <script src="/static/js/theme.v20261009.js" defer></script>
    <script src="/static/js/auth.v4.js" defer></script>
</body>
</html>
