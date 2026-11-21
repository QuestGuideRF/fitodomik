<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/headers.php';
require_once 'config/site.php';
require_once 'config/theme.php';
require_once __DIR__ . '/config/device_setup_status.php';
require_once __DIR__ . '/api/device-pair-common.php';
if (!isLoggedIn()) {
    header('Location: ' . fitodomikUrl('/authentication/login.php') . '?redirect=' . urlencode('/devices.php'));
    exit;
}
$message = '';
$error = '';
$theme = fitodomikCurrentTheme();
$user_id = (int)$_SESSION['user_id'];
$user = null;
function deviceIsOnline($lastSeenAt)
{
    $timestamp = $lastSeenAt ? strtotime($lastSeenAt) : false;
    return $timestamp !== false && $timestamp >= time() - 300;
}
function shortDeviceId($deviceId)
{
    $deviceId = (string)$deviceId;
    return strlen($deviceId) > 18
        ? substr($deviceId, 0, 10) . '...' . substr($deviceId, -6)
        : $deviceId;
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fitodomikRequireCsrf();
        $device_id = trim((string)($_POST['device_id'] ?? ''));
        $action = $_POST['action'] ?? '';
        if ($device_id === '') {
            $error = 'Устройство не выбрано';
        } elseif ($action === 'select') {
            if (fitodomikSelectDevice($pdo, $user_id, $device_id)) {
                $message = 'Устройство выбрано';
            } else {
                $error = 'Не удалось выбрать устройство';
            }
        } elseif ($action === 'rename') {
            $name = trim((string)($_POST['device_name'] ?? ''));
            if ($name === '') {
                $name = 'FitoDomik';
            } elseif (function_exists('mb_substr')) {
                $name = mb_substr($name, 0, 120, 'UTF-8');
            } else {
                $name = substr($name, 0, 120);
            }
            $stmt = $pdo->prepare("UPDATE user_devices SET device_name = ? WHERE user_id = ? AND device_id = ?");
            $stmt->execute([$name, $user_id, $device_id]);
            $message = 'Название устройства обновлено';
        } elseif ($action === 'unlink') {
            fitodomikUnlinkDevice($pdo, $user_id, $device_id);
            if (($_SESSION['selected_device_id'] ?? '') === $device_id) {
                unset($_SESSION['selected_device_id']);
            }
            $message = 'Устройство отвязано';
        }
    }
    $stmt = $pdo->prepare("
        SELECT device_id, device_name, status, linked_at, revoked_at, last_seen_at
        FROM user_devices
        WHERE user_id = ?
        ORDER BY linked_at DESC
    ");
    $stmt->execute([$user_id]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $selectedDevice = fitodomikGetSelectedDevice($pdo, $user_id);
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('devices.php: ' . $e->getMessage());
    $devices = [];
    $selectedDevice = null;
    $error = 'Ошибка сервера. Попробуйте ещё раз.';
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(fitodomikCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <title>Мои устройства - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/qr-scanner.v5.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v10.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v10.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v10.png">
    <link rel="manifest" href="/site.v10.webmanifest">
    <link rel="shortcut icon" href="/static/icon/favicon.v10.ico">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background-color);
            color: var(--text-color);
            font-family: Arial, sans-serif;
        }
        .devices-wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 32px 16px;
        }
        .devices-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        h1 {
            margin: 0;
            color: var(--primary-color);
        }
        .device-row {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
        }
        .device-row.revoked {
            opacity: 0.65;
        }
        .device-title {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .device-meta {
            color: var(--secondary-text);
            font-size: 14px;
            margin-bottom: 12px;
            overflow-wrap: anywhere;
        }
        .device-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .device-name-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 220px;
            flex: 1 1 260px;
        }
        .device-name-field label {
            color: var(--secondary-text);
            font-size: 13px;
            font-weight: 700;
        }
        input {
            min-width: 220px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-color);
        }
        button,
        .button-link {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            background: var(--accent-surface);
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-weight: 700;
        }
        button.secondary,
        .button-link.secondary {
            background: var(--button-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        .message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 14px;
        }
        .success {
            background: rgba(31,122,58,.14);
            color: var(--primary-color);
        }
        .error {
            background: #fee4e2;
            color: #b42318;
        }
        .devices-empty {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 18px;
        }
        .active-device-panel {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: center;
            padding: 18px;
            margin-bottom: 18px;
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--primary-color);
            border-radius: 8px;
            background: var(--card-bg);
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
        }
        .active-device-label {
            margin-bottom: 5px;
            color: var(--secondary-text);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .active-device-name {
            margin: 0 0 8px;
            font-size: 24px;
            color: var(--primary-color);
        }
        .active-device-meta,
        .device-state {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            color: var(--secondary-text);
            font-size: 14px;
        }
        .status-dot {
            width: 9px;
            height: 9px;
            flex: 0 0 9px;
            border-radius: 50%;
            background: #9ca3af;
        }
        .status-dot.online {
            background: #2ea65f;
            box-shadow: 0 0 0 4px rgba(46,166,95,.14);
        }
        .device-row.selected {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(var(--primary-rgb), .12);
        }
        .device-title span:last-child {
            color: var(--secondary-text);
            font-size: 13px;
        }
        .remote-panel {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 18px;
            margin: 18px 0;
            box-shadow: 0 10px 28px rgba(0,0,0,.08);
        }
        .remote-panel h2 {
            margin: 0 0 8px;
            color: var(--primary-color);
        }
        .remote-panel p {
            color: var(--secondary-text);
        }
        .command-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin: 12px 0 18px;
        }
        .command-grid button {
            width: 100%;
            min-height: 46px;
        }
        .command-grid .danger {
            background: #b42318;
        }
        .command-grid .safe {
            background: #dcfae6;
            color: #067647;
        }
        .command-history {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }
        .command-history th,
        .command-history td {
            padding: 8px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: top;
        }
        .command-status {
            min-height: 22px;
            color: var(--secondary-text);
            margin-top: 8px;
        }
        @media (max-width: 720px) {
            .active-device-panel {
                grid-template-columns: 1fr;
            }
            .device-actions {
                flex-direction: column;
            }
            input,
            button,
            .button-link {
                width: 100%;
                box-sizing: border-box;
            }
            .device-name-field {
                width: 100%;
                min-width: 0;
            }
            .command-history {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <div class="header-left">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if ($user && !empty($user['avatar'])) : ?>
                            <img src="/media/avatars/<?php echo rawurlencode(basename((string)$user['avatar'])); ?>" alt="Фото профиля" width="40" height="40">
                        <?php else : ?>
                            <div class="avatar-placeholder">
                                <span><?php echo $user ? htmlspecialchars(strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Ф'; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-dropdown">
                        <button class="profile-button">Профиль</button>
                        <div class="dropdown-content">
                            <a href="/authentication/profile.php">Профиль</a>
                            <a href="/authentication/settings.php">Настройки</a>
                            <a href="/devices.php">Устройства</a>
                            <a href="/authentication/logout.php">Выйти</a>
                        </div>
                    </div>
                </div>
            </div>
            <h1 class="site-title">ФитоДомик</h1>
            <div class="header-right">
                <?php if (!empty($devices)) : ?>
                    <form class="device-switcher" method="POST" action="/api/select-device.php">
                        <?php echo fitodomikCsrfInput(); ?>
                        <label for="devices_header_device_id">Устройство</label>
                        <select id="devices_header_device_id" name="device_id" onchange="this.form.submit()">
                            <?php foreach ($devices as $device) : ?>
                                <?php if ($device['status'] !== 'active') {
                                    continue;
                                } ?>
                                <option value="<?php echo htmlspecialchars($device['device_id']); ?>" <?php echo ($selectedDevice && $selectedDevice['device_id'] === $device['device_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($device['device_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему">
                    <span class="theme-icon">◐</span>
                </button>
            </div>
        </div>
    </header>
    <main class="devices-wrap">
        <div class="devices-header">
            <h1>Мои устройства</h1>
            <div class="device-actions">
                <button class="qr-scan-trigger" type="button" data-qr-scanner-open>Сканировать QR</button>
                <a class="button-link secondary" href="/export-data.php">Экспорт CSV</a>
                <?php if ($selectedDevice) : ?>
                    <a class="button-link secondary" href="/device-settings.php?device_id=<?php echo htmlspecialchars($selectedDevice['device_id']); ?>">Настройки устройства</a>
                <?php endif; ?>
                <a class="button-link secondary" href="/">На главную</a>
            </div>
        </div>
        <?php if ($selectedDevice) : ?>
            <?php $selectedOnline = deviceIsOnline($selectedDevice['last_seen_at'] ?? null); ?>
            <section class="active-device-panel" aria-label="Активное устройство">
                <div>
                    <div class="active-device-label">Активное устройство</div>
                    <h2 class="active-device-name"><?php echo htmlspecialchars($selectedDevice['device_name']); ?></h2>
                    <div class="active-device-meta">
                        <span class="device-state">
                            <span class="status-dot <?php echo $selectedOnline ? 'online' : ''; ?>"></span>
                            <?php echo $selectedOnline ? 'Online' : 'Offline'; ?>
                        </span>
                        <span>ID: <?php echo htmlspecialchars(shortDeviceId($selectedDevice['device_id'])); ?></span>
                        <span>Последняя связь: <?php echo htmlspecialchars($selectedDevice['last_seen_at'] ?: 'нет данных'); ?></span>
                    </div>
                </div>
                <div class="device-actions">
                    <a class="button-link" href="/device-settings.php?device_id=<?php echo htmlspecialchars($selectedDevice['device_id']); ?>">Открыть настройки</a>
                    <button class="secondary" type="button" onclick="document.getElementById('devices_header_device_id')?.focus()">Переключить</button>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($message) : ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error) : ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (empty($devices)) : ?>
            <div class="devices-empty">
                <strong>Пока нет привязанных устройств.</strong>
                <p>Отсканируйте QR-код из приложения или введите код привязки вручную.</p>
                <button class="qr-scan-trigger" type="button" data-qr-scanner-open>Сканировать QR</button>
            </div>
        <?php endif; ?>
        <?php foreach ($devices as $device) : ?>
            <?php
            $isSelected = $selectedDevice && $selectedDevice['device_id'] === $device['device_id'];
            $isOnline = deviceIsOnline($device['last_seen_at'] ?? null);
            $deviceNameInputId = 'device_name_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$device['device_id']);
            if ($isSelected) {
                $deviceStatusText = 'Выбрано';
            } elseif ($device['status'] === 'active') {
                $deviceStatusText = 'Активно';
            } else {
                $deviceStatusText = 'Отвязано';
            }
            ?>
            <section class="device-row <?php echo $device['status'] === 'revoked' ? 'revoked' : ''; ?> <?php echo $isSelected ? 'selected' : ''; ?>">
                <div class="device-title">
                    <span><?php echo htmlspecialchars($device['device_name']); ?></span>
                    <span><?php echo $deviceStatusText; ?></span>
                </div>
                <div class="device-meta">
                    <span class="device-state">
                        <span class="status-dot <?php echo $isOnline ? 'online' : ''; ?>"></span>
                        <?php echo $isOnline ? 'Online' : 'Offline'; ?>
                    </span>
                    · ID: <?php echo htmlspecialchars(shortDeviceId($device['device_id'])); ?>
                    · последняя связь: <?php echo htmlspecialchars($device['last_seen_at'] ?: 'нет данных'); ?>
                    · привязано: <?php echo htmlspecialchars($device['linked_at']); ?>
                </div>
                <?php if ($device['status'] === 'active') : ?>
                    <?php $setup = fitodomikDeviceSetupStatus($pdo, $user_id, $device['device_id']); ?>
                    <div class="device-meta">
                        Первичная настройка: <?php echo htmlspecialchars($setup['state']); ?>
                        · датчики: <?php echo (int)$setup['counts']['dynamic_sensors']; ?>
                        · реле: <?php echo (int)$setup['counts']['dynamic_relays']; ?>
                        · сценарии: <?php echo (int)$setup['counts']['scenarios']; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" class="device-actions">
                    <?php echo fitodomikCsrfInput(); ?>
                    <input type="hidden" name="device_id" value="<?php echo htmlspecialchars($device['device_id']); ?>">
                    <div class="device-name-field">
                        <label for="<?php echo htmlspecialchars($deviceNameInputId); ?>">Название устройства</label>
                        <input id="<?php echo htmlspecialchars($deviceNameInputId); ?>" type="text" name="device_name" value="<?php echo htmlspecialchars($device['device_name']); ?>" maxlength="120" placeholder="Название устройства" autocomplete="off">
                    </div>
                    <?php if ($device['status'] === 'active') : ?>
                        <button type="submit" name="action" value="select">Выбрать</button>
                        <a class="button-link secondary" href="/device-settings.php?device_id=<?php echo htmlspecialchars($device['device_id']); ?>">Настройки устройства</a>
                    <?php endif; ?>
                    <button type="submit" name="action" value="rename">Переименовать</button>
                    <?php if ($device['status'] === 'active') : ?>
                        <button class="secondary" type="submit" name="action" value="unlink">Отвязать</button>
                    <?php endif; ?>
                </form>
            </section>
        <?php endforeach; ?>
        <?php if ($selectedDevice) : ?>
            <section class="remote-panel" id="remote-commands">
                <h2>Удалённые команды</h2>
                <p>
                    Команды добавляются в очередь выбранного устройства
                    <strong><?php echo htmlspecialchars($selectedDevice['device_name']); ?></strong>.
                    Raspberry Pi заберёт их при следующем опросе и вернёт результат.
                </p>
                <h3>Основные действия</h3>
                <div class="command-grid">
                    <button class="safe" type="button" data-command="take_photo">Сделать фото сейчас</button>
                    <button class="safe" type="button" data-command="analyze_plant">Анализ растения</button>
                    <button type="button" data-command="ping">Проверить связь</button>
                    <button type="button" data-command="refresh_thresholds">Обновить пороги</button>
                    <button type="button" data-command="refresh_schedule">Обновить расписание</button>
                    <button type="button" data-command="upload_thresholds_to_arduino">Загрузить настройки в Arduino</button>
                    <button type="button" data-command="reconnect_arduino">Переподключить Arduino</button>
                </div>
                <h3>Тест устройств</h3>
                <div class="command-grid">
                    <button type="button" data-command="device_test" data-payload='{"device":"lamp"}'>Тест лампы</button>
                    <button type="button" data-command="device_test" data-payload='{"device":"fan"}'>Тест вентилятора</button>
                    <button class="danger" type="button" data-command="device_test" data-payload='{"device":"pump"}' data-confirm="Насос включится на короткое время. Продолжить?">Тест насоса</button>
                    <button type="button" data-command="device_test" data-payload='{"device":"curtains"}'>Тест штор</button>
                </div>
                <h3>Реле</h3>
                <div class="command-grid">
                    <?php for ($relay = 1; $relay <= 4; $relay++) : ?>
                        <button type="button" data-command="relay_on" data-payload='{"relay":<?php echo (int)$relay; ?>}' data-confirm="Включить реле <?php echo (int)$relay; ?>?">Реле <?php echo (int)$relay; ?> вкл</button>
                        <button type="button" data-command="relay_off" data-payload='{"relay":<?php echo (int)$relay; ?>}' data-confirm="Выключить реле <?php echo (int)$relay; ?>?">Реле <?php echo (int)$relay; ?> выкл</button>
                        <button class="danger" type="button" data-command="relay_pulse" data-payload='{"relay":<?php echo (int)$relay; ?>,"seconds":3}' data-confirm="Подать импульс на реле <?php echo (int)$relay; ?>?">Реле <?php echo (int)$relay; ?> импульс</button>
                    <?php endfor; ?>
                </div>
                <div class="command-status" id="remote-command-status">Готово</div>
                <h3>История команд</h3>
                <table class="command-history">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Команда</th>
                            <th>Статус</th>
                            <th>Создана</th>
                            <th>Результат</th>
                        </tr>
                    </thead>
                    <tbody id="command-history-body">
                        <tr><td colspan="5">Загрузка истории...</td></tr>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
    <script src="/static/js/theme.v20261009.js" defer></script>
    <script src="/static/js/device-qr-scanner.v5.js" defer></script>
    <script>
        const commandStatus = document.getElementById('remote-command-status');
        const commandHistoryBody = document.getElementById('command-history-body');
        function setCommandStatus(message) {
            if (commandStatus) {
                commandStatus.textContent = message;
            }
        }
        async function createDeviceCommand(command, payload, confirmText) {
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            setCommandStatus('Отправляю команду...');
            try {
                const response = await fetch('/api/device-command-create.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({device_id: <?php echo json_encode($selectedDevice['device_id'] ?? ''); ?>, command_type: command, payload: payload || {}})
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Команда не создана');
                }
                setCommandStatus('Команда #' + data.command_id + ' добавлена в очередь');
                refreshCommandHistory();
            } catch (error) {
                setCommandStatus('Ошибка: ' + error.message);
            }
        }
        async function refreshCommandHistory() {
            if (!commandHistoryBody) {
                return;
            }
            try {
                const response = await fetch('/api/device-command-list.php?limit=12&device_id=' + encodeURIComponent(<?php echo json_encode($selectedDevice['device_id'] ?? ''); ?>), {credentials: 'same-origin'});
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось загрузить историю');
                }
                const rows = data.commands || [];
                if (!rows.length) {
                    commandHistoryBody.innerHTML = '<tr><td colspan="5">Команд пока нет</td></tr>';
                    return;
                }
                commandHistoryBody.innerHTML = rows.map((item) => {
                    const result = item.result_message || item.error_message || '';
                    return `<tr>
                        <td>${item.command_id}</td>
                        <td>${escapeHtml(item.command_type)}</td>
                        <td>${escapeHtml(item.status)}</td>
                        <td>${escapeHtml(item.created_at || '')}</td>
                        <td>${escapeHtml(result)}</td>
                    </tr>`;
                }).join('');
            } catch (error) {
                commandHistoryBody.innerHTML = '<tr><td colspan="5">Ошибка загрузки истории: ' + escapeHtml(error.message) + '</td></tr>';
            }
        }
        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }
        document.querySelectorAll('[data-command]').forEach((button) => {
            button.addEventListener('click', () => {
                let payload = {};
                const rawPayload = button.getAttribute('data-payload');
                if (rawPayload) {
                    try {
                        payload = JSON.parse(rawPayload);
                    } catch (error) {
                        payload = {};
                    }
                }
                createDeviceCommand(button.dataset.command, payload, button.dataset.confirm || '');
            });
        });
        refreshCommandHistory();
        setInterval(refreshCommandHistory, 30000);
    </script>
</body>
</html>
