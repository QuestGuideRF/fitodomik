<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/telegram.php';
require_once '../config/telegram_bot_features.php';
require_once '../config/site.php';
require_once '../config/headers.php';
require_once '../config/theme.php';
header('Cache-Control: private, no-cache');
header('Referrer-Policy: no-referrer');
$redirect_target = fitodomikSafeRedirect(
    $_GET['redirect'] ?? $_SESSION['registration_redirect'] ?? '',
    '/farm.php'
);
$registrationLink = $_SESSION['telegram_registration_link'] ?? null;
$registrationUserId = is_array($registrationLink) ? (int)($registrationLink['user_id'] ?? 0) : 0;
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($registrationUserId < 1 || $registrationUserId !== $sessionUserId) {
    header('Location: register.php?redirect=' . urlencode($redirect_target));
    exit;
}
$telegramStatus = fitodomikTgGetLinkStatus($pdo, $registrationUserId);
if (is_array($telegramStatus['link'])) {
    unset($_SESSION['telegram_registration_link'], $_SESSION['registration_redirect']);
    header('Location: ' . $redirect_target);
    exit;
}
$telegramCode = trim((string)($telegramStatus['code']['code'] ?? ''));
$deepToken = trim((string)($registrationLink['deep_token'] ?? ''));
$linkIsReady = preg_match('/^[A-F0-9]{8}$/D', $telegramCode) === 1
    && preg_match('/^[a-f0-9]{32}$/D', $deepToken) === 1;
$skipError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fitodomikRequireCsrf();
    if (($_POST['action'] ?? '') !== 'skip_telegram') {
        http_response_code(400);
        $skipError = 'Неизвестное действие. Обновите страницу и повторите попытку.';
    } else {
        $retryAfter = fitodomikConsumeRateLimit(
            $pdo,
            'web-register-without-telegram',
            fitodomikClientAddress(),
            5,
            900,
            900
        );
        if ($retryAfter > 0) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            $skipError = 'Слишком много попыток. Повторите регистрацию позже.';
        } else {
            try {
                $revoke = $pdo->prepare(
                    "UPDATE telegram_link_codes SET status = 'revoked' WHERE user_id = ? AND status = 'pending'"
                );
                $revoke->execute([$registrationUserId]);
                unset($_SESSION['telegram_registration_link'], $_SESSION['registration_redirect']);
                header('Location: ' . $redirect_target);
                exit;
            } catch (Exception $e) {
                error_log('telegram_verify registration without Telegram: ' . $e->getMessage());
                $skipError = 'Не удалось продолжить без Telegram. Обновите страницу и повторите попытку.';
            }
        }
    }
}
$botUsername = TELEGRAM_BOT_USERNAME;
$manualInstruction = $linkIsReady ? '/link ' . $telegramCode : '';
$botProfileUrl = 'https://t.me/' . rawurlencode($botUsername);
$botStartUrl = $linkIsReady
    ? $botProfileUrl . '?start=' . rawurlencode('link_' . $deepToken)
    : $botProfileUrl;
$siteUrl = fitodomikSiteUrl();
$theme = fitodomikCurrentTheme();
function telegramVerifyEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo telegramVerifyEscape($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение Telegram - ФитоДомик</title>
    <meta name="description" content="Подтверждение Telegram для регистрации в личном кабинете ФитоДомик.">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo telegramVerifyEscape($siteUrl . '/authentication/telegram_verify.php'); ?>">
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/auth.v4.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v10.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v10.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v10.png">
    <link rel="manifest" href="/site.v10.webmanifest">
    <link rel="shortcut icon" href="/static/icon/favicon.v10.ico">
</head>
<body class="auth-page auth-page--telegram">
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
        <section class="auth-card auth-card--telegram" aria-labelledby="telegram-title">
            <aside class="auth-context">
                <div class="auth-context-copy">
                    <p class="auth-kicker">Последний шаг</p>
                    <h2>Подключите Telegram сейчас или позже</h2>
                    <p class="auth-context-description">
                        Telegram нужен для уведомлений и быстрых команд.
                    </p>
                </div>
                <ol class="telegram-progress">
                    <li class="is-complete">Аккаунт создан</li>
                    <li class="is-current">Telegram — необязательный шаг</li>
                    <li>Автоматический вход в кабинет</li>
                </ol>
                <p class="auth-context-footnote">
                    Ссылка не содержит API-токен или токен устройства.
                </p>
            </aside>
            <div class="auth-panel telegram-verify-panel">
                <div class="auth-panel-header">
                    <p class="telegram-bot-label">@<?php echo telegramVerifyEscape($botUsername); ?></p>
                    <h1 id="telegram-title">Подключить Telegram?</h1>
                    <p>Это добавит уведомления и управление через бота. Шаг можно пропустить и выполнить позже в настройках аккаунта.</p>
                </div>
                <?php if ($skipError !== '') : ?>
                    <div class="auth-alert auth-alert--error" role="alert">
                        <?php echo telegramVerifyEscape($skipError); ?>
                    </div>
                <?php endif; ?>
                <?php if ($linkIsReady) : ?>
                    <a class="auth-submit telegram-open-button"
                       href="<?php echo telegramVerifyEscape($botStartUrl); ?>"
                       target="_blank" rel="noopener noreferrer" data-telegram-open>
                        Открыть Telegram и подтвердить
                    </a>
                <?php else : ?>
                    <div class="auth-alert auth-alert--error" role="alert">
                        Срок действия кода истёк. Создайте новый код в настройках аккаунта.
                    </div>
                <?php endif; ?>
                <output class="telegram-status" id="verification-status" data-state="waiting"
                        aria-live="polite" aria-atomic="true">
                    <span class="telegram-status-indicator" aria-hidden="true"></span>
                    <span data-status-text>Ждём подтверждение от Telegram-бота</span>
                </output>
                <?php if ($linkIsReady) : ?>
                    <details class="telegram-fallback">
                        <summary>Не открывается Telegram?</summary>
                        <div class="telegram-fallback-content">
                            <p>
                                Откройте
                                <a class="auth-inline-link" href="<?php echo telegramVerifyEscape($botProfileUrl); ?>"
                                   target="_blank" rel="noopener noreferrer">@<?php echo telegramVerifyEscape($botUsername); ?></a>
                                вручную и отправьте команду одним сообщением:
                            </p>
                            <div class="telegram-code-row">
                                <code id="telegram-manual-code"><?php echo telegramVerifyEscape($manualInstruction); ?></code>
                                <button type="button" class="telegram-copy-button"
                                        data-copy-target="telegram-manual-code">Скопировать команду</button>
                            </div>
                            <p class="telegram-copy-result" data-copy-result aria-live="polite"></p>
                        </div>
                    </details>
                <?php endif; ?>
                <form method="POST" class="telegram-skip-form">
                    <?php echo fitodomikCsrfInput(); ?>
                    <input type="hidden" name="action" value="skip_telegram">
                    <button type="submit" class="auth-secondary-link telegram-skip-button">
                        Продолжить без Telegram
                    </button>
                    <p>Аккаунт уже создан. Подключить бота можно позже в настройках.</p>
                </form>
                <div class="telegram-secondary-actions">
                    <a href="settings.php" class="auth-secondary-link">Настройки аккаунта</a>
                    <a href="../index.php" class="auth-inline-link">На главную</a>
                </div>
            </div>
        </section>
        <footer class="auth-footer">
            <span>Код регистрации действует 15 минут</span>
            <span>ФитоДомик · Telegram</span>
        </footer>
    </main>
    <div id="verification-data"
         data-check-url="/api/check_verification.php"
         data-redirect="<?php echo telegramVerifyEscape($redirect_target); ?>"
         hidden></div>
    <script src="/static/js/theme.v20261009.js" defer></script>
    <script src="/static/js/telegram-verify.v2.js" defer></script>
</body>
</html>
