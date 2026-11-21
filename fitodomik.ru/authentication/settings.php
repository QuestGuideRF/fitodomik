<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/headers.php';
require_once '../config/device_context.php';
require_once '../config/telegram_bot_features.php';
require_once '../config/theme.php';
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
$theme = fitodomikCurrentTheme();
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function shortDate($value)
{
    if (!$value) {
        return 'нет данных';
    }
    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }
    return date('d.m.Y H:i', $time);
}
function settingsResult(string $success = '', string $error = ''): array
{
    return ['success' => $success, 'error' => $error];
}
function settingsSelectDevice(PDO $pdo, int $userId, string $deviceId): array
{
    if (!fitodomikSelectDevice($pdo, $userId, $deviceId)) {
        return settingsResult('', 'Не удалось выбрать устройство');
    }
    return settingsResult('Устройство выбрано. Главная страница будет показывать его данные.');
}
function settingsRenameDevice(PDO $pdo, int $userId, string $deviceId): array
{
    $deviceName = fitodomikNormalizeDeviceName($_POST['device_name'] ?? '');
    $stmt = $pdo->prepare("
        UPDATE user_devices
        SET device_name = ?
        WHERE user_id = ? AND device_id = ? AND status = 'active'
    ");
    $stmt->execute([$deviceName, $userId, $deviceId]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("
            SELECT 1
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $check->execute([$userId, $deviceId]);
        if (!$check->fetchColumn()) {
            return settingsResult('', 'Активное устройство не найдено');
        }
    }
    return settingsResult('Название устройства обновлено');
}
function settingsUnlinkDevice(PDO $pdo, int $userId, string $deviceId): array
{
    $changed = fitodomikUnlinkDevice($pdo, $userId, $deviceId);
    if ($changed !== 1) {
        return settingsResult('', 'Активное устройство не найдено');
    }
    if (($_SESSION['selected_device_id'] ?? '') === $deviceId) {
        unset($_SESSION['selected_device_id']);
    }
    return settingsResult('Устройство отвязано');
}
function settingsHandleDeviceAction(PDO $pdo, int $userId, string $action): array
{
    $deviceId = trim((string)($_POST['device_id'] ?? ''));
    $result = settingsResult('', 'Устройство не выбрано');
    if ($deviceId === '') {
        return $result;
    }
    if ($action === 'device_select') {
        $result = settingsSelectDevice($pdo, $userId, $deviceId);
    } elseif ($action === 'device_rename') {
        $result = settingsRenameDevice($pdo, $userId, $deviceId);
    } else {
        $result = settingsUnlinkDevice($pdo, $userId, $deviceId);
    }
    return $result;
}
function settingsHandleTelegramAction(PDO $pdo, int $userId, string $action): array
{
    $result = settingsResult('', 'Не удалось выполнить действие Telegram');
    try {
        if ($action === 'telegram_create_link') {
            $linkCredentials = fitodomikTgCreateLinkCode($pdo, $userId);
            $code = (string)$linkCredentials['code'];
            $_SESSION['telegram_deep_link_token'] = (string)$linkCredentials['deep_token'];
            $result = settingsResult(
                'Код привязки Telegram создан: ' . $code
                . '. Откройте бота по кнопке или отправьте код вручную в течение 15 минут.'
            );
        } elseif ($action === 'telegram_unlink') {
            fitodomikTgUnlinkUser($pdo, $userId);
            $result = settingsResult('Telegram отвязан от профиля');
        } else {
            $status = fitodomikTgGetLinkStatus($pdo, $userId);
            if (empty($status['link']['telegram_chat_id'])) {
                $result = settingsResult('', 'Telegram не привязан');
            } else {
                fitodomikBotSendMessage(
                    $status['link']['telegram_chat_id'],
                    "Тестовое уведомление ФитоДомик\n\nЕсли вы получили это сообщение, сайт и Telegram-бот работают корректно."
                );
                $result = settingsResult('Тестовое сообщение отправлено через сайт');
            }
        }
    } catch (Exception $e) {
        error_log('settings.php telegram action: ' . $e->getMessage());
    }
    return $result;
}
function settingsTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function settingsValidateProfileIdentity(PDO $pdo, int $userId, array $input): string
{
    $error = '';
    if ($input['first_name'] === '' || $input['last_name'] === '' || $input['username'] === '') {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } elseif (settingsTextLength($input['first_name']) > 100 || settingsTextLength($input['last_name']) > 100) {
        $error = 'Имя и фамилия не должны превышать 100 символов';
    } elseif (!preg_match('/^\w{3,20}$/', $input['username'])) {
        $error = 'Никнейм должен содержать 3–20 латинских букв, цифр или знаков подчёркивания';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $stmt->execute([$input['username'], $userId]);
        if ($stmt->fetchColumn()) {
            $error = 'Этот никнейм уже занят';
        }
    }
    return $error;
}
function settingsValidateProfilePassword(array $user, array $input): string
{
    $error = '';
    if ($input['current_password'] !== '') {
        if (!password_verify($input['current_password'], (string)$user['password'])) {
            $error = 'Неверный текущий пароль';
        } elseif ($input['new_password'] !== $input['confirm_password']) {
            $error = 'Новые пароли не совпадают';
        } elseif (strlen($input['new_password']) < 8) {
            $error = 'Новый пароль должен содержать не менее 8 символов';
        }
    } elseif ($input['new_password'] !== '' || $input['confirm_password'] !== '') {
        $error = 'Для смены пароля укажите текущий пароль';
    }
    return $error;
}
function settingsValidateProfile(PDO $pdo, int $userId, array $user, array $input): string
{
    $error = settingsValidateProfileIdentity($pdo, $userId, $input);
    if ($error === '') {
        $error = settingsValidateProfilePassword($user, $input);
    }
    return $error;
}
function settingsSaveProfile(PDO $pdo, int $userId, array $input): void
{
    if ($input['current_password'] !== '') {
        $hashedPassword = password_hash($input['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, username = ?, password = ?
            WHERE id = ?
        ");
        $stmt->execute([$input['first_name'], $input['last_name'], $input['username'], $hashedPassword, $userId]);
        fitodomikRevokeRememberLogins($pdo, $userId);
        return;
    }
    $stmt = $pdo->prepare("
        UPDATE users
        SET first_name = ?, last_name = ?, username = ?
        WHERE id = ?
    ");
    $stmt->execute([$input['first_name'], $input['last_name'], $input['username'], $userId]);
}
function settingsHandleProfile(PDO $pdo, int $userId, array $user): array
{
    $input = [
        'first_name' => trim((string)($_POST['first_name'] ?? '')),
        'last_name' => trim((string)($_POST['last_name'] ?? '')),
        'username' => trim((string)($_POST['username'] ?? '')),
        'current_password' => (string)($_POST['current_password'] ?? ''),
        'new_password' => (string)($_POST['new_password'] ?? ''),
        'confirm_password' => (string)($_POST['confirm_password'] ?? ''),
    ];
    $error = settingsValidateProfile($pdo, $userId, $user, $input);
    if ($error !== '') {
        return settingsResult('', $error);
    }
    try {
        settingsSaveProfile($pdo, $userId, $input);
        return settingsResult('Настройки профиля сохранены');
    } catch (PDOException $e) {
        error_log('settings.php profile save: ' . $e->getMessage());
        return settingsResult('', 'Ошибка при обновлении данных');
    }
}
function settingsHandleClientAction(PDO $pdo, int $userId, array $user, string $action): array
{
    if (!password_verify((string)($_POST['client_password'] ?? ''), (string)$user['password'])) {
        return settingsResult('', 'Для изменения доступа клиента введите текущий пароль');
    }
    try {
        if ($action === 'client_grant_create') {
            $_SESSION['new_client_token'] = fitodomikCreateClientGrant($pdo, $userId, trim((string)($_POST['device_id'] ?? '')));
            $result = settingsResult('Индивидуальный токен создан. Скопируйте его: повторно он не отображается.');
        } else {
            $grantId = filter_var($_POST['client_grant_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $revoked = $grantId !== false && fitodomikRevokeClientGrant($pdo, $userId, $grantId);
            $result = $revoked ? settingsResult('Доступ клиента отозван') : settingsResult('', 'Активный клиент не найден');
        }
        return $result;
    } catch (Exception $e) {
        error_log('settings.php client access: ' . $e->getMessage());
        return settingsResult('', 'Не удалось изменить доступ клиента');
    }
}
function settingsHandleAction(PDO $pdo, int $userId, array $user, string $action): array
{
    if (in_array($action, ['client_grant_create', 'client_grant_revoke'], true)) {
        $result = settingsHandleClientAction($pdo, $userId, $user, $action);
    } elseif (in_array($action, ['device_select', 'device_rename', 'device_unlink'], true)) {
        $result = settingsHandleDeviceAction($pdo, $userId, $action);
    } elseif (in_array($action, ['telegram_create_link', 'telegram_send_test', 'telegram_unlink'], true)) {
        $result = settingsHandleTelegramAction($pdo, $userId, $action);
    } else {
        $result = settingsHandleProfile($pdo, $userId, $user);
    }
    return $result;
}
$user_id = (int)$_SESSION['user_id'];
$error = '';
$success = '';
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fitodomikRequireCsrf();
    $action = (string)($_POST['settings_action'] ?? 'profile');
    $result = settingsHandleAction($pdo, $user_id, $user, $action);
    $success = $result['success'];
    $error = $result['error'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
$newClientToken = (string)($_SESSION['new_client_token'] ?? '');
unset($_SESSION['new_client_token']);
$clientGrants = [];
$devices = [];
$selectedDevice = null;
$telegramStatus = ['link' => null, 'code' => null];
try {
    $devices = fitodomikGetUserDevices($pdo, $user_id, true);
    $grants = $pdo->prepare("SELECT g.id, g.origin_device_id, g.created_at, d.device_name
        FROM account_client_grants g JOIN user_devices d ON d.user_id=g.user_id AND d.device_id=g.origin_device_id
        WHERE g.user_id=? AND g.revoked_at IS NULL AND d.status='active' ORDER BY g.created_at DESC, g.id DESC");
    $grants->execute([$user_id]);
    $clientGrants = $grants->fetchAll(PDO::FETCH_ASSOC);
    $selectedDevice = fitodomikGetSelectedDevice($pdo, $user_id);
    $telegramStatus = fitodomikTgGetLinkStatus($pdo, $user_id);
} catch (Exception $e) {
    error_log('settings.php devices: ' . $e->getMessage());
    if ($error === '') {
        $error = 'Не удалось загрузить устройства и токен';
    }
}
$activeDevicesCount = 0;
foreach ($devices as $device) {
    if ($device['status'] === 'active') {
        $activeDevicesCount++;
    }
}
$selectedDeviceId = (string)($selectedDevice['device_id'] ?? '');
$telegramLink = is_array($telegramStatus['link'] ?? null) ? $telegramStatus['link'] : null;
$telegramCode = is_array($telegramStatus['code'] ?? null)
    ? trim((string)($telegramStatus['code']['code'] ?? ''))
    : '';
$telegramDeepLinkToken = trim((string)($_SESSION['telegram_deep_link_token'] ?? ''));
if ($telegramLink !== null
    || $telegramCode === ''
    || preg_match('/^[a-f0-9]{32}$/D', $telegramDeepLinkToken) !== 1
) {
    unset($_SESSION['telegram_deep_link_token']);
    $telegramDeepLinkToken = '';
}
$telegramBotUrl = 'https://t.me/' . rawurlencode(TELEGRAM_BOT_USERNAME);
if ($telegramDeepLinkToken !== '') {
    $telegramBotUrl .= '?start=' . rawurlencode('link_' . $telegramDeepLinkToken);
}
$telegramUsername = '';
$telegramProfileValue = 'Telegram не привязан';
if ($telegramLink !== null) {
    $telegramUsername = trim((string)($telegramLink['telegram_username'] ?? ''));
    if ($telegramUsername === '') {
        $telegramUsername = 'без username';
    }
    $telegramProfileValue = '@' . $telegramUsername . ' / ID ' . (string)$telegramLink['telegram_user_id'];
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo h($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo h(fitodomikCsrfToken()); ?>">
    <title>Настройки - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/qr-scanner.v5.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v10.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v10.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v10.png">
    <link rel="manifest" href="/site.v10.webmanifest">
    <link rel="shortcut icon" href="/static/icon/favicon.v10.ico">
    <style>
        .settings-page {
            min-height: 100vh;
            padding: 24px;
            background: var(--background-color);
            color: var(--text-color);
            box-sizing: border-box;
        }
        .settings-page *,
        .settings-page *::before,
        .settings-page *::after {
            box-sizing: border-box;
        }
        .settings-shell {
            width: 100%;
            max-width: 1180px;
            min-width: 0;
            margin: 0 auto;
        }
        .settings-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }
        .settings-actions {
            display: flex;
            max-width: 100%;
            min-width: 0;
            gap: 10px;
            flex-wrap: wrap;
        }
        .settings-link,
        .settings-button,
        .settings-actions .qr-scan-trigger {
            display: inline-flex;
            max-width: 100%;
            min-width: 0;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            text-decoration: none;
            cursor: pointer;
            font-weight: 700;
            text-align: center;
            white-space: normal;
        }
        .settings-visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .settings-button.primary,
        .settings-link.primary {
            border-color: var(--accent-surface);
            background: var(--accent-surface);
            color: #fff;
        }
        .settings-hero {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 18px;
            align-items: center;
            padding: 26px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            box-shadow: 0 18px 46px rgba(16, 24, 40, 0.12);
            margin-bottom: 18px;
        }
        .settings-hero h1 {
            margin: 0 0 8px;
            color: var(--primary-color);
            font-size: 30px;
        }
        .settings-hero p {
            margin: 0;
            color: var(--secondary-text);
            line-height: 1.5;
            max-width: 720px;
        }
        .settings-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }
        .active-device-switch {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) auto;
            gap: 8px;
            width: 100%;
            margin-top: 2px;
        }
        .active-device-switch select {
            min-width: 0;
            padding: 10px 11px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-color);
        }
        .settings-pill {
            padding: 10px 13px;
            border-radius: 8px;
            background: rgba(var(--primary-rgb), 0.10);
            color: var(--primary-color);
            font-weight: 700;
            white-space: nowrap;
        }
        .settings-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(360px, 0.9fr);
            gap: 18px;
            align-items: start;
        }
        .settings-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            padding: 20px;
            box-shadow: 0 10px 30px rgba(16, 24, 40, 0.08);
            margin-bottom: 18px;
        }
        .settings-card h2 {
            margin: 0 0 14px;
            color: var(--text-color);
            font-size: 22px;
        }
        .settings-card p {
            color: var(--secondary-text);
            line-height: 1.5;
        }
        .form-row {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
        }
        .form-row label {
            font-weight: 700;
            color: var(--text-color);
        }
        .form-row input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-color);
            font-size: 15px;
        }
        .token-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
        }
        .token-row input {
            font-family: Consolas, monospace;
            letter-spacing: 0.02em;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-color);
            caret-color: var(--text-color);
        }
        .token-row input[readonly] {
            background: var(--input-bg);
            color: var(--text-color);
            -webkit-text-fill-color: var(--text-color);
            opacity: 1;
        }
        .telegram-status-box {
            display: grid;
            gap: 12px;
            padding: 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--background);
        }
        .telegram-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 8px;
            background: rgba(var(--primary-rgb), 0.12);
            color: var(--primary-color);
            font-family: Consolas, monospace;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.12em;
        }
        .telegram-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .device-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 12px;
            background: var(--background);
        }
        .device-card.selected {
            border-color: var(--accent-surface);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.14);
        }
        .device-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            font-weight: 800;
        }
        .device-status {
            color: var(--primary-color);
            white-space: nowrap;
        }
        .device-meta {
            display: grid;
            gap: 4px;
            margin-bottom: 12px;
            color: var(--secondary-text);
            font-size: 13px;
            overflow-wrap: anywhere;
        }
        .device-actions {
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 8px;
            align-items: center;
        }
        .device-actions input {
            min-width: 0;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-color);
        }
        .small-button {
            border: 0;
            border-radius: 10px;
            padding: 10px 12px;
            background: var(--accent-surface);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            white-space: nowrap;
        }
        .small-button.secondary {
            background: var(--button-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        .small-button.danger {
            background: #b42318;
        }
        .message-box {
            padding: 13px 15px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-weight: 700;
        }
        .message-box.error {
            background: #fee4e2;
            color: #b42318;
        }
        .message-box.success {
            background: #dcfae6;
            color: #067647;
        }
        .telegram-change-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.72);
            z-index: 1000;
            -webkit-backdrop-filter: blur(5px);
            backdrop-filter: blur(5px);
        }
        .telegram-change-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .telegram-change-content {
            width: min(92vw, 520px);
            padding: 24px;
            border-radius: 18px;
            background: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
        }
        .telegram-step {
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 12px;
            padding: 12px;
            border-radius: 12px;
            background: var(--background);
            margin-bottom: 10px;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-surface);
            color: #fff;
            font-weight: 800;
        }
        .step-title {
            font-weight: 800;
            margin-bottom: 4px;
        }
        .step-description {
            color: var(--secondary-text);
            line-height: 1.4;
        }
        @media (max-width: 900px) {
            .settings-hero,
            .settings-grid {
                grid-template-columns: 1fr;
            }
            .settings-pills {
                justify-content: flex-start;
            }
        }
        @media (max-width: 620px) {
            .active-device-switch {
                grid-template-columns: 1fr;
            }
            .settings-page {
                min-height: 100svh;
                padding: 14px 14px 32px;
            }
            .settings-topbar {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                align-items: stretch;
                max-width: 100%;
                min-width: 0;
            }
            .settings-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
                max-width: 100%;
                min-width: 0;
            }
            .settings-topbar > .settings-link,
            .settings-actions > .settings-link,
            .settings-actions > .settings-button,
            .settings-actions > .qr-scan-trigger {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                min-height: 44px;
                padding-inline: 10px;
            }
            .settings-actions > .theme-toggle {
                width: 44px;
                max-width: 44px;
                min-width: 44px;
                height: 44px;
                justify-self: start;
            }
            .form-row,
            .device-actions,
            .token-row {
                grid-template-columns: 1fr;
            }
            .small-button,
            .settings-link,
            .settings-button {
                width: 100%;
                max-width: 100%;
            }
            .telegram-actions {
                flex-direction: column;
            }
            .telegram-actions > * {
                width: 100%;
                min-height: 44px;
                box-sizing: border-box;
            }
            .telegram-status-box,
            .settings-card {
                min-width: 0;
                overflow-wrap: anywhere;
            }
        }
        @media (max-width: 340px) {
            .settings-actions {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>
<body>
    <main class="settings-page">
        <div class="settings-shell">
            <div class="settings-topbar">
                <a class="settings-link" href="../index.php">На главную</a>
                <div class="settings-actions">
                    <button class="qr-scan-trigger" type="button" data-qr-scanner-open>Сканировать QR</button>
                    <a class="settings-link" href="profile.php">Профиль</a>
                    <a class="settings-link" href="../devices.php">Все устройства</a>
                    <button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему"><span class="theme-icon">◐</span></button>
                </div>
            </div>
            <section class="settings-hero">
                <div>
                    <h1>Настройки аккаунта</h1>
                    <p>Здесь хранятся данные профиля, индивидуальные токены клиентов и список устройств. Выбранное устройство управляет тем, какие фото, датчики и графики показываются на главной странице.</p>
                </div>
                <div class="settings-pills">
                    <span class="settings-pill">Устройств: <?php echo (int)$activeDevicesCount; ?></span>
                    <span class="settings-pill">Выбрано: <?php echo h($selectedDevice['device_name'] ?? 'нет'); ?></span>
                    <?php if ($activeDevicesCount > 0) : ?>
                        <form class="active-device-switch" method="POST">
                            <?php echo fitodomikCsrfInput(); ?>
                            <input type="hidden" name="settings_action" value="device_select">
                            <select name="device_id" aria-label="Активное устройство">
                                <?php foreach ($devices as $device) : ?>
                                    <?php if ($device['status'] !== 'active') {
                                        continue;
                                    }
                                    $selectedAttribute = $selectedDeviceId === (string)$device['device_id'] ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo h($device['device_id']); ?>" <?php echo $selectedAttribute; ?>>
                                        <?php echo h($device['device_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="settings-button primary" type="submit">Переключить</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php if ($error) : ?>
                <div class="message-box error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($success) : ?>
                <div class="message-box success"><?php echo htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="settings-grid">
                <div>
                    <section class="settings-card">
                        <h2>Профиль</h2>
                        <form method="POST" action="">
                            <?php echo fitodomikCsrfInput(); ?>
                            <input type="hidden" name="settings_action" value="profile">
                            <div class="form-row">
                                <label for="first_name">Имя</label>
                                <input type="text" id="first_name" name="first_name" autocomplete="given-name" value="<?php echo h($user['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-row">
                                <label for="last_name">Фамилия</label>
                                <input type="text" id="last_name" name="last_name" autocomplete="family-name" value="<?php echo h($user['last_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-row">
                                <label for="username">Никнейм</label>
                                <input type="text" id="username" name="username" autocomplete="username" value="<?php echo h($user['username'] ?? ''); ?>" required>
                            </div>
                            <div class="form-row">
                                <label for="telegram_profile">Telegram</label>
                                <div class="token-row">
                                    <input type="text" id="telegram_profile" autocomplete="off" value="<?php echo h($telegramProfileValue); ?>" readonly>
                                    <button type="button" class="small-button secondary" onclick="openTelegramChange()">Инструкция</button>
                                </div>
                            </div>
                            <div class="form-row">
                                <label for="current_password">Текущий пароль</label>
                                <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                            </div>
                            <div class="form-row">
                                <label for="new_password">Новый пароль</label>
                                <input type="password" id="new_password" name="new_password" autocomplete="new-password">
                            </div>
                            <div class="form-row">
                                <label for="confirm_password">Повтор пароля</label>
                                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                            </div>
                            <button type="submit" class="settings-button primary">Сохранить профиль</button>
                        </form>
                    </section>
                    <section class="settings-card">
                        <h2>Telegram-бот</h2>
                        <p>Привязка нужна, чтобы бот показывал только ваши устройства, фото, анализ и тревоги. Данные чужих аккаунтов бот не выдаёт.</p>
                        <div class="telegram-status-box">
                            <?php if ($telegramLink !== null) : ?>
                                <div><strong>Статус:</strong> Telegram привязан</div>
                                <div><strong>Пользователь:</strong> @<?php echo h($telegramUsername); ?>, ID <?php echo h($telegramLink['telegram_user_id']); ?></div>
                                <div><strong>Последняя активность:</strong> <?php echo h(shortDate($telegramLink['last_seen_at'])); ?></div>
                            <?php else : ?>
                                <div><strong>Статус:</strong> Telegram не привязан</div>
                                <div>Нажмите «Создать код», затем отправьте этот код боту <strong>@<?php echo h(TELEGRAM_BOT_USERNAME); ?></strong>.</div>
                            <?php endif; ?>
                            <?php if (!empty($telegramStatus['code'])) : ?>
                                <div>Активный код привязки:</div>
                                <div class="telegram-code"><?php echo h($telegramStatus['code']['code']); ?></div>
                                <div>Код действует до <?php echo h(shortDate($telegramStatus['code']['expires_at'])); ?>.</div>
                            <?php endif; ?>
                            <div class="telegram-actions">
                                <form method="POST">
                                    <?php echo fitodomikCsrfInput(); ?>
                                    <input type="hidden" name="settings_action" value="telegram_create_link">
                                    <button type="submit" class="small-button">Создать код привязки</button>
                                </form>
                                <a href="<?php echo h($telegramBotUrl); ?>" target="_blank" rel="noopener noreferrer" class="settings-link primary">Открыть бота</a>
                                <?php if ($telegramLink !== null) : ?>
                                    <form method="POST">
                                        <?php echo fitodomikCsrfInput(); ?>
                                        <input type="hidden" name="settings_action" value="telegram_send_test">
                                        <button type="submit" class="small-button">Отправить тест</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Отвязать Telegram от профиля?')">
                                        <?php echo fitodomikCsrfInput(); ?>
                                        <input type="hidden" name="settings_action" value="telegram_unlink">
                                        <button type="submit" class="small-button danger">Отвязать Telegram</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
                <section class="settings-card" id="client-access">
                    <h2>Доступ клиентов</h2>
                    <?php if ($newClientToken !== '') : ?>
                        <div class="form-row">
                            <label for="newClientToken">Новый индивидуальный токен</label>
                            <input id="newClientToken" type="text" value="<?php echo h($newClientToken); ?>" readonly autocomplete="off">
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <?php echo fitodomikCsrfInput(); ?>
                        <input type="hidden" name="settings_action" value="client_grant_create">
                        <div class="form-row">
                            <label for="clientOrigin">Устройство клиента</label>
                            <select id="clientOrigin" name="device_id" required>
                            <option value="">Выберите устройство</option>
                            <?php foreach ($devices as $device) : ?>
                                <?php if ($device['status'] === 'active') : ?>
                                    <option value="<?php echo h($device['device_id']); ?>"><?php echo h($device['device_name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="clientPassword">Текущий пароль</label>
                            <input id="clientPassword" name="client_password" type="password" autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="small-button">Создать токен клиента</button>
                    </form>
                    <?php foreach ($clientGrants as $grant) : ?>
                            <div class="device-meta"><?php echo h($grant['device_name']); ?> · #<?php echo (int)$grant['id']; ?> · <?php echo h(shortDate($grant['created_at'])); ?></div>
                            <form method="POST" onsubmit="return confirm('Отозвать доступ только этого клиента?')">
                                <?php echo fitodomikCsrfInput(); ?>
                                <input type="hidden" name="settings_action" value="client_grant_revoke">
                                <input type="hidden" name="client_grant_id" value="<?php echo (int)$grant['id']; ?>">
                                <div class="form-row">
                                    <label for="revokePassword<?php echo (int)$grant['id']; ?>">Текущий пароль</label>
                                    <input id="revokePassword<?php echo (int)$grant['id']; ?>" name="client_password" type="password" autocomplete="current-password" required>
                                </div>
                                <button type="submit" class="small-button danger">Отозвать доступ</button>
                            </form>
                    <?php endforeach; ?>
                </section>
                <section class="settings-card">
                    <h2>Устройства</h2>
                    <p>Можно привязать несколько Raspberry Pi или desktop-приложений к одному аккаунту. На главной странице отображаются данные выбранного устройства.</p>
                    <?php if (empty($devices)) : ?>
                        <div class="device-card">Пока нет привязанных устройств. Откройте приложение ФитоДомик и привяжите аккаунт через логин/пароль или QR.</div>
                    <?php endif; ?>
                    <?php foreach ($devices as $device) : ?>
                        <?php
                        $isSelected = $selectedDeviceId === (string)$device['device_id'];
                        $isActive = $device['status'] === 'active';
                        $cardClass = $isSelected ? 'selected' : '';
                        if ($isSelected) {
                            $deviceStatusText = 'выбрано';
                        } elseif ($isActive) {
                            $deviceStatusText = 'активно';
                        } else {
                            $deviceStatusText = 'отвязано';
                        }
                        $disabledAttribute = $isActive ? '' : 'disabled';
                        $deviceNameInputId = 'device-name-' . substr(
                            hash('sha256', (string)$device['device_id']),
                            0,
                            12
                        );
                        ?>
                        <article class="device-card <?php echo $cardClass; ?>">
                            <div class="device-head">
                                <span><?php echo h($device['device_name']); ?></span>
                                <span class="device-status"><?php echo $deviceStatusText; ?></span>
                            </div>
                            <div class="device-meta">
                                <span>ID: <?php echo h($device['device_id']); ?></span>
                                <span>Привязано: <?php echo h(shortDate($device['linked_at'])); ?></span>
                                <span>Последняя связь: <?php echo h(shortDate($device['last_seen_at'])); ?></span>
                            </div>
                            <form method="POST" class="device-actions">
                                <?php echo fitodomikCsrfInput(); ?>
                                <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
                                <label class="settings-visually-hidden" for="<?php echo $deviceNameInputId; ?>">Название устройства</label>
                                <input type="text" id="<?php echo $deviceNameInputId; ?>" name="device_name" autocomplete="off" value="<?php echo h($device['device_name']); ?>" maxlength="120" <?php echo $disabledAttribute; ?>>
                                <?php if ($isActive) : ?>
                                    <button class="small-button secondary" type="submit" name="settings_action" value="device_select">Выбрать</button>
                                    <button class="small-button" type="submit" name="settings_action" value="device_rename">Переименовать</button>
                                    <button class="small-button danger" type="submit" name="settings_action" value="device_unlink" onclick="return confirm('Отвязать это устройство?')">Отвязать</button>
                                <?php else : ?>
                                    <button class="small-button secondary" type="button" disabled>Устройство отвязано</button>
                                <?php endif; ?>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </section>
            </div>
        </div>
    </main>
    <div id="telegramChangeModal" class="telegram-change-modal">
        <div class="telegram-change-content">
            <h2>Смена Telegram</h2>
            <div class="telegram-step">
                <div class="step-number">1</div>
                <div>
                    <div class="step-title">Откройте Telegram-бота</div>
                    <div class="step-description">Перейдите к боту <strong>@<?php echo h(TELEGRAM_BOT_USERNAME); ?></strong> в Telegram.</div>
                </div>
            </div>
            <div class="telegram-step">
                <div class="step-number">2</div>
                <div>
                    <div class="step-title">Создайте код на сайте</div>
                    <div class="step-description">В блоке Telegram-бот нажмите «Создать код привязки». Код действует 15 минут.</div>
                </div>
            </div>
            <div class="telegram-step">
                <div class="step-number">3</div>
                <div>
                    <div class="step-title">Отправьте код боту</div>
                    <div class="step-description">Откройте <strong>@<?php echo h(TELEGRAM_BOT_USERNAME); ?></strong> и отправьте код сообщением или командой <code>/link КОД</code>.</div>
                </div>
            </div>
            <div class="settings-actions">
                <a href="<?php echo h($telegramBotUrl); ?>" target="_blank" rel="noopener noreferrer" class="settings-link primary">Открыть бота</a>
                <button type="button" class="settings-button" onclick="closeTelegramChange()">Закрыть</button>
            </div>
        </div>
    </div>
    <script src="/static/js/theme.v20261009.js" defer></script>
    <script src="/static/js/device-qr-scanner.v5.js" defer></script>
    <script>
        function openTelegramChange() {
            document.getElementById('telegramChangeModal').classList.add('active');
        }
        function closeTelegramChange() {
            document.getElementById('telegramChangeModal').classList.remove('active');
        }
        document.getElementById('telegramChangeModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTelegramChange();
            }
        });
    </script>
</body>
</html>
