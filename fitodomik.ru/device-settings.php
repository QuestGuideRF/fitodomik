<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/headers.php';
require_once 'config/site.php';
require_once 'config/theme.php';
require_once __DIR__ . '/config/device_dynamic_config.php';
require_once __DIR__ . '/config/device_commands.php';
require_once __DIR__ . '/config/device_setup_status.php';
const NO_DATA_TEXT = 'нет данных';
if (!isLoggedIn()) {
    header('Location: ' . fitodomikUrl('/authentication/login.php') . '?redirect=' . urlencode('/device-settings.php'));
    exit;
}
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function checkboxValue($key, $default = false)
{
    return (isset($_POST[$key]) || $default) ? 1 : 0;
}
function usedPinsFromConfig($sensors, $relays)
{
    $pins = [];
    foreach ($sensors as $sensor) {
        if (empty($sensor['enabled'])) {
            continue;
        }
        foreach (preg_split('/[\/,;\s]+/', (string)($sensor['pin'] ?? '')) as $pin) {
            $pin = strtoupper(trim($pin));
            if ($pin !== '' && $pin !== 'I2C' && $pin !== 'SPI') {
                $pins[$pin] = $sensor['name'] ?? $sensor['sensor_uid'];
            }
        }
    }
    foreach ($relays as $relay) {
        if (empty($relay['enabled'])) {
            continue;
        }
        $pin = strtoupper(trim((string)($relay['pin'] ?? '')));
        if ($pin !== '') {
            $pins[$pin] = $relay['name'] ?? $relay['relay_uid'];
        }
    }
    return $pins;
}
function scenarioConditionText($scenario, $sensorLabels)
{
    $conditions = $scenario['conditions'] ?? [];
    if (!$conditions) {
        return 'условия не заданы';
    }
    $parts = [];
    foreach ($conditions as $condition) {
        $operator = $condition['operator'] ?? '';
        if ($operator === 'time_between') {
            $parts[] = 'время ' . ($condition['from'] ?? '00:00') . '-' . ($condition['to'] ?? '23:59');
            continue;
        }
        $sensor = $sensorLabels[$condition['sensor_uid'] ?? ''] ?? ($condition['sensor_uid'] ?? 'датчик');
        if ($operator === 'no_data') {
            $parts[] = $sensor . ': ' . NO_DATA_TEXT;
        } elseif ($operator === 'between' || $operator === 'not_between') {
            $parts[] = $sensor . ' ' . $operator . ' ' . ($condition['value'] ?? '') . '..' . ($condition['value_to'] ?? '');
        } else {
            $parts[] = $sensor . ' ' . $operator . ' ' . ($condition['value'] ?? '');
        }
    }
    return implode('; ', $parts);
}
function scenarioActionText($scenario, $relayLabels)
{
    $actions = $scenario['actions'] ?? [];
    if (!$actions) {
        return 'действия не заданы';
    }
    $parts = [];
    foreach ($actions as $action) {
        $type = $action['type'] ?? '';
        if (in_array($type, ['relay_on', 'relay_off', 'relay_pulse'], true)) {
            $relay = $relayLabels[$action['relay_uid'] ?? ''] ?? ($action['relay_uid'] ?? 'реле');
            $parts[] = $relay . ' · ' . $type;
        } elseif ($type === 'send_notification' || $type === 'notify') {
            $parts[] = 'уведомление';
        } elseif ($type === 'create_command' || $type === 'device_command') {
            $parts[] = 'команда: ' . ($action['command_type'] ?? '');
        } else {
            $parts[] = $type;
        }
    }
    return implode('; ', $parts);
}
$user_id = (int)$_SESSION['user_id'];
$theme = fitodomikCurrentTheme();
$message = '';
$error = '';
$device = null;
$sensors = [];
$relays = [];
$scenarios = [];
$scenarioLogs = [];
$latestValues = [];
$setupStatus = null;
$commandHistory = [];
$allDevices = [];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fitodomikRequireCsrf();
    }
    $requestedDeviceId = trim((string)($_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['device_id'] ?? '') : ($_GET['device_id'] ?? '')));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedDeviceId === '') {
        throw new FitodomikInputException('Передайте device_id отображённого устройства');
    }
    if ($requestedDeviceId !== '') {
        $deviceStmt = $pdo->prepare("
            SELECT device_id, device_token, device_name, status, linked_at, last_seen_at, revoked_at
            FROM user_devices
            WHERE user_id = ? AND device_id = ? AND status = 'active'
            LIMIT 1
        ");
        $deviceStmt->execute([$user_id, $requestedDeviceId]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $device = fitodomikGetSelectedDevice($pdo, $user_id);
    }
    if (!$device) {
        throw new FitodomikNotFoundException('Активное устройство не выбрано');
    }
    $deviceId = $device['device_id'];
    $allDevices = fitodomikGetUserDevices($pdo, $user_id, false);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'update_sensor') {
            fitodomikDynamicUpsertSensor($pdo, $user_id, $deviceId, [
                'sensor_uid' => $_POST['sensor_uid'] ?? '',
                'name' => $_POST['name'] ?? '',
                'sensor_type' => $_POST['sensor_type'] ?? 'custom',
                'connection' => $_POST['connection'] ?? '',
                'pin' => $_POST['pin'] ?? '',
                'address' => $_POST['address'] ?? '',
                'unit' => $_POST['unit'] ?? '',
                'enabled' => checkboxValue('enabled'),
                'send_to_server' => checkboxValue('send_to_server'),
                'show_in_monitoring' => checkboxValue('show_in_monitoring'),
                'show_in_charts' => checkboxValue('show_in_charts'),
                'calibration' => fitodomikDecodeCalibration((string)($_POST['calibration_json'] ?? '{}')),
            ]);
            $message = 'Датчик сохранён';
        } elseif ($action === 'delete_sensor') {
            $uid = fitodomikDynamicUid($_POST['sensor_uid'] ?? '', '');
            $stmt = $pdo->prepare("DELETE FROM device_sensor_definitions WHERE user_id = ? AND device_id = ? AND sensor_uid = ?");
            $stmt->execute([$user_id, $deviceId, $uid]);
            $message = 'Датчик удалён';
        } elseif ($action === 'update_relay') {
            fitodomikDynamicUpsertRelay($pdo, $user_id, $deviceId, [
                'relay_uid' => $_POST['relay_uid'] ?? '',
                'name' => $_POST['name'] ?? '',
                'pin' => $_POST['pin'] ?? '',
                'type' => $_POST['relay_type'] ?? 'relay',
                'active_low' => checkboxValue('active_low'),
                'enabled' => checkboxValue('enabled'),
                'show_in_control' => checkboxValue('show_in_control'),
            ]);
            $message = 'Реле сохранено';
        } elseif ($action === 'delete_relay') {
            $uid = fitodomikDynamicUid($_POST['relay_uid'] ?? '', '');
            $stmt = $pdo->prepare("DELETE FROM device_relay_definitions WHERE user_id = ? AND device_id = ? AND relay_uid = ?");
            $stmt->execute([$user_id, $deviceId, $uid]);
            $message = 'Реле удалено';
        } elseif ($action === 'toggle_scenario') {
            $uid = fitodomikDynamicUid($_POST['scenario_id'] ?? '', '');
            $stmt = $pdo->prepare("UPDATE device_automation_scenarios SET enabled = ? WHERE user_id = ? AND device_id = ? AND scenario_id = ?");
            $stmt->execute([checkboxValue('enabled'), $user_id, $deviceId, $uid]);
            $message = 'Сценарий обновлён';
        } elseif ($action === 'save_scenario') {
            $scenarioId = fitodomikDynamicUid($_POST['scenario_id'] ?? '', '');
            $scenario = [
                'scenario_id' => $scenarioId,
                'name' => $_POST['scenario_name'] ?? '',
                'enabled' => checkboxValue('scenario_enabled'),
                'cooldown_seconds' => (int)($_POST['cooldown_seconds'] ?? 0),
                'conditions' => [[
                    'sensor_uid' => $_POST['condition_sensor_uid'] ?? '',
                    'operator' => $_POST['condition_operator'] ?? '',
                    'value' => $_POST['condition_value'] ?? null,
                    'value_to' => $_POST['condition_value_to'] ?? null,
                    'from' => $_POST['condition_from'] ?? '00:00',
                    'to' => $_POST['condition_to'] ?? '23:59',
                    'duration_seconds' => (int)($_POST['condition_duration_seconds'] ?? 0),
                ]],
                'actions' => [[
                    'type' => $_POST['action_type'] ?? '',
                    'relay_uid' => $_POST['action_relay_uid'] ?? '',
                    'duration_seconds' => (int)($_POST['action_duration_seconds'] ?? 3),
                    'message' => $_POST['action_message'] ?? '',
                    'command_type' => $_POST['action_command_type'] ?? '',
                ]],
            ];
            foreach (['conditions', 'actions'] as $key) {
                if (trim((string)($_POST[$key . '_json'] ?? '')) !== '') {
                    $items = json_decode((string)$_POST[$key . '_json'], true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 128) {
                        throw new FitodomikInputException('Условия и действия должны быть непустыми массивами (до 128 элементов)');
                    }
                    $scenario[$key] = $items;
                }
            }
            fitodomikDynamicUpsertScenario($pdo, $user_id, $deviceId, $scenario);
            $message = 'Сценарий сохранён';
        } elseif ($action === 'delete_scenario') {
            $uid = fitodomikDynamicUid($_POST['scenario_id'] ?? '', '');
            $stmt = $pdo->prepare("DELETE FROM device_automation_scenarios WHERE user_id = ? AND device_id = ? AND scenario_id = ?");
            $stmt->execute([$user_id, $deviceId, $uid]);
            $message = 'Сценарий удалён';
        } elseif ($action === 'command') {
            $command = trim((string)($_POST['command_type'] ?? 'sync_device_config'));
            $commandId = fitodomikCreateDeviceCommand($pdo, $user_id, $deviceId, $command, [
                'source' => 'site',
                'requested_by_user_id' => $user_id,
            ]);
            $message = 'Команда #' . $commandId . ' добавлена в очередь Raspberry Pi';
        }
    }
    $sensors = fitodomikDynamicListSensors($pdo, $user_id, $deviceId, true);
    $relays = fitodomikDynamicListRelays($pdo, $user_id, $deviceId, true);
    $scenarios = fitodomikDynamicListScenarios($pdo, $user_id, $deviceId, true);
    $scenarioLogs = fitodomikDynamicListScenarioLog($pdo, $user_id, $deviceId, '', 40);
    $latestValues = fitodomikDynamicLatestValues($pdo, $user_id, $deviceId);
    $setupStatus = fitodomikDeviceSetupStatus($pdo, $user_id, $deviceId);
    fitodomikExpireOldDeviceCommands($pdo);
    $commandStmt = $pdo->prepare("
        SELECT id, command_type, status, source, created_at, started_at, picked_at, finished_at,
               duration_ms, result_message, error_message, attempts
        FROM device_commands
        WHERE user_id = ? AND device_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $commandStmt->execute([$user_id, $deviceId]);
    $commandHistory = $commandStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('device-settings.php: ' . $e->getMessage());
    $error = $e->getMessage();
}
$usedPins = usedPinsFromConfig($sensors, $relays);
$digitalPins = ['D0','D1','D2','D3','D4','D5','D6','D7','D8','D9','D10','D11','D12','D13'];
$analogPins = ['A0','A1','A2','A3','A4','A5','A6','A7'];
$freeDigital = array_values(array_diff($digitalPins, array_keys($usedPins)));
$freeAnalog = array_values(array_diff($analogPins, array_keys($usedPins)));
$sensorLabels = [];
foreach ($sensors as $sensor) {
    if (!empty($sensor['enabled'])) {
        $sensorLabels[$sensor['sensor_uid']] = $sensor['name'];
    }
}
$relayLabels = [];
foreach ($relays as $relay) {
    if (!empty($relay['enabled'])) {
        $relayLabels[$relay['relay_uid']] = $relay['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo h($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(fitodomikCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <title>Настройки устройства - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/qr-scanner.v5.css">
    <script src="/static/js/theme.v20261009.js" defer></script>
    <style>
        body{margin:0;background:var(--background-color);color:var(--text-color);font-family:Arial,sans-serif}
        .wrap{width:100%;max-width:1120px;margin:0 auto;padding:28px 16px;box-sizing:border-box}
        .topbar{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
        h1{margin:0;color:var(--primary-color)}
        h2{margin:0 0 12px;color:var(--primary-color)}
        .card{min-width:0;max-width:100%;box-sizing:border-box;background:var(--card-bg);border:1px solid var(--border-color);border-radius:8px;padding:16px;margin:14px 0;box-shadow:0 8px 24px rgba(0,0,0,.08)}
        .summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
        .metric{min-width:0;overflow-wrap:anywhere;background:var(--input-bg);border:1px solid var(--border-color);border-radius:8px;padding:12px}
        .metric span{display:block;color:var(--secondary-text);font-size:13px;margin-bottom:4px}
        .setup-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;margin-top:12px}
        .setup-item{background:var(--input-bg);border:1px solid var(--border-color);border-radius:8px;padding:10px}
        .setup-item strong{display:block;margin-bottom:4px}
        .setup-ok{color:var(--primary-color)}
        .setup-warn{color:#b54708}
        .row-form{min-width:0;display:grid;grid-template-columns:1.3fr 1fr .7fr .7fr .8fr auto;gap:8px;align-items:center;padding:10px 0;border-top:1px solid var(--border-color)}
        .row-form:first-of-type{border-top:0}
        .row-form>*{min-width:0}
        .checks{display:flex;gap:8px;flex-wrap:wrap;font-size:13px;color:var(--secondary-text)}
        .checks label{display:inline-flex;align-items:center;gap:8px;min-width:0;min-height:44px;padding:4px 6px;box-sizing:border-box;cursor:pointer;line-height:1.25}
        .checks input[type="checkbox"]{appearance:none;-webkit-appearance:none;position:relative;flex:0 0 44px;width:44px;height:24px;margin:0;padding:0;border:1px solid var(--border-color);border-radius:999px;background:var(--button-bg);box-sizing:border-box;cursor:pointer;transition:background-color .2s,border-color .2s}
        .checks input[type="checkbox"]::before{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.28);transition:transform .2s}
        .checks input[type="checkbox"]:checked{border-color:var(--primary-color);background:var(--primary-color)}
        .checks input[type="checkbox"]:checked::before{transform:translateX(20px)}
        .checks input[type="checkbox"]:focus-visible{outline:3px solid rgba(31,122,58,.28);outline-offset:2px}
        input,select,textarea{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid var(--border-color);border-radius:8px;background:var(--input-bg);color:var(--text-color)}
        button,.button-link{border:0;border-radius:8px;padding:9px 13px;background:var(--accent-surface);color:#fff;cursor:pointer;text-decoration:none}
        button.secondary,.button-link.secondary{background:var(--button-bg);border:1px solid var(--border-color);color:var(--text-color)}
        button.danger{background:#b42318}
        .message{padding:12px;border-radius:8px;margin:12px 0;background:rgba(31,122,58,.14);color:var(--primary-color)}
        .error{padding:12px;border-radius:8px;margin:12px 0;background:#fee4e2;color:#b42318}
        .muted{color:var(--secondary-text);font-size:14px}
        .latest{font-size:13px;color:var(--secondary-text)}
        .actions{display:flex;gap:10px;flex-wrap:wrap}
        .scenario-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:10px}
        .scenario-card{background:var(--input-bg);border:1px solid var(--border-color);border-radius:8px;padding:14px;display:grid;gap:10px}
        .scenario-title{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
        .badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:12px;background:rgba(31,122,58,.14);color:var(--primary-color)}
        .badge.off{background:rgba(180,80,60,.14);color:#b42318}
        .wizard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
        .wizard-grid label{display:grid;gap:5px;color:var(--secondary-text);font-size:13px}
        .visually-hidden{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
        .wide{grid-column:1/-1}
        .inline-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .log-table-scroll{width:100%;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;border:1px solid var(--border-color);border-radius:8px;scrollbar-width:thin}
        .log-table-scroll:focus-visible{outline:3px solid rgba(31,122,58,.28);outline-offset:2px}
        .log-table{width:100%;min-width:680px;border-collapse:collapse;font-size:14px}
        .log-table th,.log-table td{border-bottom:1px solid var(--border-color);padding:8px;text-align:left;vertical-align:top}
        .log-table th{white-space:nowrap}
        .log-table td{overflow-wrap:anywhere}
        details summary{cursor:pointer;color:var(--primary-color);font-weight:700}
        .device-toolbar{display:grid;grid-template-columns:minmax(240px,1fr) auto;gap:12px;align-items:end;padding:14px;margin-bottom:14px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:8px}
        .device-toolbar label{display:grid;gap:6px;color:var(--secondary-text);font-size:13px;font-weight:700}
        .device-toolbar .actions{justify-content:flex-end}
        .settings-nav{position:sticky;top:0;z-index:20;display:flex;gap:8px;overflow-x:auto;padding:10px 0;margin-bottom:4px;background:var(--background-color);scrollbar-width:thin}
        .settings-nav a{flex:0 0 auto;padding:9px 12px;border:1px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text-color);text-decoration:none;font-weight:700}
        .settings-nav a:hover,.settings-nav a:focus{border-color:var(--primary-color);color:var(--primary-color)}
        section[id]{scroll-margin-top:72px}
        @media (max-width:860px){.row-form{grid-template-columns:1fr}.checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}button,.button-link{width:100%;box-sizing:border-box}}
        @media (max-width:640px){.device-toolbar{grid-template-columns:1fr}.device-toolbar .actions{justify-content:stretch}.settings-nav{margin-inline:-16px;padding-inline:16px}}
    </style>
</head>
<body>
<main class="wrap">
    <div class="topbar">
        <h1>Настройки устройства</h1>
        <div class="actions">
            <button class="qr-scan-trigger" type="button" data-qr-scanner-open>Сканировать QR</button>
            <a class="button-link secondary" href="/devices.php">Мои устройства</a>
            <a class="button-link secondary" href="/">На главную</a>
        </div>
    </div>
    <?php if ($allDevices) : ?>
        <form class="device-toolbar" method="POST" action="/api/select-device.php">
            <?php echo fitodomikCsrfInput(); ?>
            <input type="hidden" name="redirect" value="/device-settings.php">
            <label for="settings-device-picker">Редактируемое устройство
                <select id="settings-device-picker" name="device_id">
                    <?php foreach ($allDevices as $item) : ?>
                        <option value="<?php echo h($item['device_id']); ?>" <?php echo $device && $device['device_id'] === $item['device_id'] ? 'selected' : ''; ?>>
                            <?php echo h($item['device_name']); ?> · <?php echo h(substr($item['device_id'], 0, 12)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Переключить</button>
                <a class="button-link secondary" href="/authentication/settings.php">Уведомления и аккаунт</a>
            </div>
        </form>
    <?php endif; ?>
    <nav class="settings-nav" aria-label="Разделы настроек устройства">
        <a href="#general">Основное</a>
        <a href="#diagnostics">Диагностика</a>
        <a href="#sensors">Датчики</a>
        <a href="#relays">Реле</a>
        <a href="#commands">Команды</a>
        <a href="#automation-scenarios">Сценарии</a>
        <a href="/authentication/settings.php">Уведомления</a>
    </nav>
    <?php if ($message) :
        ?><div class="message"><?php echo htmlspecialchars((string)$message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php
    endif; ?>
    <?php if ($error) :
        ?><div class="error"><?php echo htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php
    endif; ?>
    <?php if ($device) : ?>
    <section class="card" id="general">
        <h2><?php echo h($device['device_name']); ?></h2>
        <div class="summary">
            <div class="metric"><span>device_id</span><?php echo h($device['device_id']); ?></div>
            <?php $lastSeenText = $device['last_seen_at'] ?: NO_DATA_TEXT; ?>
            <div class="metric"><span>Последняя связь</span><?php echo h($lastSeenText); ?></div>
            <div class="metric"><span>Датчики</span><?php echo count($sensors); ?></div>
            <div class="metric"><span>Реле</span><?php echo count($relays); ?></div>
            <div class="metric"><span>Первичная настройка</span><?php echo h($setupStatus['state'] ?? 'неизвестно'); ?></div>
            <div class="metric"><span>Свободные цифровые</span><?php echo h(implode(', ', $freeDigital) ?: 'нет'); ?></div>
            <div class="metric"><span>Свободные аналоговые</span><?php echo h(implode(', ', $freeAnalog) ?: 'нет'); ?></div>
        </div>
        <form method="POST" class="actions" style="margin-top:14px">
            <?php echo fitodomikCsrfInput(); ?>
            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
            <input type="hidden" name="action" value="command">
            <button type="submit" name="command_type" value="sync_device_config">Синхронизировать с Raspberry Pi</button>
            <button class="secondary" type="submit" name="command_type" value="request_device_config">Запросить конфигурацию</button>
            <button class="secondary" type="submit" name="command_type" value="apply_device_config">Отправить конфигурацию на устройство</button>
        </form>
    </section>
        <?php if ($setupStatus) : ?>
    <section class="card" id="diagnostics">
        <h2>Первичная настройка устройства</h2>
        <p class="muted">Статус считается по данным выбранного устройства: связь, sensor data, dynamic config, датчики, реле, сценарии, фото/анализ и Telegram.</p>
        <div class="setup-list">
            <?php
                $setupLabels = [
                    'linked' => 'Устройство привязано',
                    'last_seen' => 'Была связь',
                    'has_sensor_data' => 'Есть sensor/dynamic data',
                    'has_dynamic_config' => 'Есть dynamic config',
                    'has_sensors' => 'Датчики добавлены',
                    'has_relays' => 'Реле добавлены',
                    'has_scenarios' => 'Сценарии есть',
                    'has_photo_analysis' => 'Фото/анализ есть',
                    'telegram_enabled' => 'Telegram привязан',
                ];
                ?>
            <?php foreach ($setupLabels as $key => $label) : ?>
                <?php $ok = !empty($setupStatus['checks'][$key]); ?>
                <div class="setup-item">
                    <strong><?php echo h($label); ?></strong>
                    <span class="<?php echo $ok ? 'setup-ok' : 'setup-warn'; ?>"><?php echo $ok ? 'готово' : NO_DATA_TEXT; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
        <?php endif; ?>
    <section class="card" id="sensors">
        <h2>Датчики</h2>
        <?php if (!$sensors) : ?>
            <p class="muted">Датчики ещё не синхронизированы. Сохраните конфигурацию в desktop-приложении или отправьте команду синхронизации.</p>
        <?php endif; ?>
        <?php foreach ($sensors as $sensor) : ?>
            <?php
            $latest = $latestValues[$sensor['sensor_uid']] ?? null;
            $sensorFieldPrefix = 'sensor-' . substr(hash('sha256', (string)$sensor['sensor_uid']), 0, 12);
            ?>
            <form method="POST" class="row-form">
                <?php echo fitodomikCsrfInput(); ?>
                <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
                <input type="hidden" name="action" value="update_sensor">
                <input type="hidden" name="sensor_uid" value="<?php echo h($sensor['sensor_uid']); ?>">
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-name">Название датчика</label>
                <input id="<?php echo h($sensorFieldPrefix); ?>-name" name="name" value="<?php echo h($sensor['name']); ?>" maxlength="160">
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-type">Тип датчика</label>
                <input id="<?php echo h($sensorFieldPrefix); ?>-type" name="sensor_type" value="<?php echo h($sensor['sensor_type']); ?>" maxlength="80">
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-connection">Тип подключения датчика</label>
                <input id="<?php echo h($sensorFieldPrefix); ?>-connection" name="connection" value="<?php echo h($sensor['connection']); ?>" maxlength="32">
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-pin">Пин датчика</label>
                <input id="<?php echo h($sensorFieldPrefix); ?>-pin" name="pin" value="<?php echo h($sensor['pin']); ?>" maxlength="40" placeholder="пин">
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-address">Адрес датчика</label>
                <input id="<?php echo h($sensorFieldPrefix); ?>-address" name="address" value="<?php echo h($sensor['address'] ?? ''); ?>" maxlength="40" placeholder="адрес">
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-unit">Единица измерения датчика</label>
                <input id="<?php echo h($sensorFieldPrefix); ?>-unit" name="unit" value="<?php echo h($sensor['unit']); ?>" maxlength="32">
                <div class="checks">
                    <label for="<?php echo h($sensorFieldPrefix); ?>-enabled"><input id="<?php echo h($sensorFieldPrefix); ?>-enabled" type="checkbox" name="enabled" <?php echo $sensor['enabled'] ? 'checked' : ''; ?>> вкл</label>
                    <label for="<?php echo h($sensorFieldPrefix); ?>-server"><input id="<?php echo h($sensorFieldPrefix); ?>-server" type="checkbox" name="send_to_server" <?php echo $sensor['send_to_server'] ? 'checked' : ''; ?>> сайт</label>
                    <label for="<?php echo h($sensorFieldPrefix); ?>-monitoring"><input id="<?php echo h($sensorFieldPrefix); ?>-monitoring" type="checkbox" name="show_in_monitoring" <?php echo $sensor['show_in_monitoring'] ? 'checked' : ''; ?>> мониторинг</label>
                    <label for="<?php echo h($sensorFieldPrefix); ?>-charts"><input id="<?php echo h($sensorFieldPrefix); ?>-charts" type="checkbox" name="show_in_charts" <?php echo $sensor['show_in_charts'] ? 'checked' : ''; ?>> графики</label>
                </div>
                <label class="visually-hidden" for="<?php echo h($sensorFieldPrefix); ?>-calibration">Калибровка датчика в формате JSON</label>
                <textarea id="<?php echo h($sensorFieldPrefix); ?>-calibration" name="calibration_json" rows="2" placeholder='{"type":"linear_percent"}'><?php echo h(json_encode($sensor['calibration'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea>
                <div class="latest">
                    Последнее: <?php echo $latest ? h(($latest['value'] ?? $latest['raw_value']) . ' ' . ($latest['unit'] ?? $sensor['unit']) . ' · ' . $latest['measured_at']) : NO_DATA_TEXT; ?>
                </div>
                <div class="actions">
                    <button type="submit">Сохранить</button>
                    <button class="secondary" type="submit" name="action" value="delete_sensor" onclick="return confirm('Удалить датчик?')">Удалить</button>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
    <section class="card" id="commands">
        <h2>Команды и история</h2>
        <p class="muted">Команды создаются сайтом, Telegram, сценариями или desktop. Raspberry Pi забирает pending-команды через очередь и возвращает подробный результат.</p>
        <form method="POST" class="actions" style="margin-bottom:12px">
            <?php echo fitodomikCsrfInput(); ?>
            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
            <input type="hidden" name="action" value="command">
            <button type="submit" name="command_type" value="ping">Ping</button>
            <button class="secondary" type="submit" name="command_type" value="take_photo">Сделать фото</button>
            <button class="secondary" type="submit" name="command_type" value="analyze_plant">Анализ</button>
            <button class="secondary" type="submit" name="command_type" value="refresh_thresholds">Обновить пороги</button>
        </form>
        <div class="log-table-scroll" role="region" aria-label="История команд" tabindex="0">
        <table class="log-table">
            <thead><tr><th>Время</th><th>Источник</th><th>Команда</th><th>Статус</th><th>Длительность</th><th>Результат</th></tr></thead>
            <tbody>
                <?php if (!$commandHistory) :
                    ?><tr><td colspan="6" class="muted">Команд пока нет</td></tr><?php
                endif; ?>
                <?php foreach ($commandHistory as $command) : ?>
                    <tr>
                        <td><?php echo h($command['created_at']); ?></td>
                        <td><?php echo h($command['source']); ?></td>
                        <td><?php echo h($command['command_type']); ?></td>
                        <td><?php echo h($command['status']); ?></td>
                        <td><?php echo $command['duration_ms'] === null ? '—' : h($command['duration_ms'] . ' мс'); ?></td>
                        <td>
                            <?php echo h($command['result_message'] ?: $command['error_message'] ?: ''); ?>
                            <details>
                                <summary>подробнее</summary>
                                <div class="muted">
                                    picked_at: <?php echo h($command['picked_at'] ?: ''); ?><br>
                                    started_at: <?php echo h($command['started_at'] ?: ''); ?><br>
                                    finished_at: <?php echo h($command['finished_at'] ?: ''); ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <section class="card" id="relays">
        <h2>Реле</h2>
        <?php if (!$relays) :
            ?><p class="muted">Реле ещё не добавлены.</p><?php
        endif; ?>
        <?php foreach ($relays as $relay) : ?>
            <?php $relayFieldPrefix = 'relay-' . substr(hash('sha256', (string)$relay['relay_uid']), 0, 12); ?>
            <form method="POST" class="row-form">
                <?php echo fitodomikCsrfInput(); ?>
                <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
                <input type="hidden" name="action" value="update_relay">
                <input type="hidden" name="relay_uid" value="<?php echo h($relay['relay_uid']); ?>">
                <label class="visually-hidden" for="<?php echo h($relayFieldPrefix); ?>-name">Название реле</label>
                <input id="<?php echo h($relayFieldPrefix); ?>-name" name="name" value="<?php echo h($relay['name']); ?>" maxlength="160">
                <label class="visually-hidden" for="<?php echo h($relayFieldPrefix); ?>-type">Тип реле</label>
                <input id="<?php echo h($relayFieldPrefix); ?>-type" name="relay_type" value="<?php echo h($relay['type']); ?>" maxlength="40">
                <label class="visually-hidden" for="<?php echo h($relayFieldPrefix); ?>-pin">Пин реле</label>
                <input id="<?php echo h($relayFieldPrefix); ?>-pin" name="pin" value="<?php echo h($relay['pin']); ?>" maxlength="40">
                <div class="checks">
                    <label for="<?php echo h($relayFieldPrefix); ?>-enabled"><input id="<?php echo h($relayFieldPrefix); ?>-enabled" type="checkbox" name="enabled" <?php echo $relay['enabled'] ? 'checked' : ''; ?>> вкл</label>
                    <label for="<?php echo h($relayFieldPrefix); ?>-active-low"><input id="<?php echo h($relayFieldPrefix); ?>-active-low" type="checkbox" name="active_low" <?php echo $relay['active_low'] ? 'checked' : ''; ?>> active low</label>
                    <label for="<?php echo h($relayFieldPrefix); ?>-control"><input id="<?php echo h($relayFieldPrefix); ?>-control" type="checkbox" name="show_in_control" <?php echo $relay['show_in_control'] ? 'checked' : ''; ?>> управление</label>
                </div>
                <div class="latest">UID: <?php echo h($relay['relay_uid']); ?></div>
                <div class="actions">
                    <button type="submit">Сохранить</button>
                    <button class="secondary" type="submit" name="action" value="delete_relay" onclick="return confirm('Удалить реле?')">Удалить</button>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
    <section class="card" id="automation-scenarios">
        <h2>Сценарии автоматизации</h2>
        <p class="muted">Сценарии используют только датчики и реле выбранного устройства. После сохранения нажмите синхронизацию, чтобы Raspberry Pi забрал изменения быстрее.</p>
        <?php if (!$sensors || !$relays) : ?>
            <p class="muted">Для сценариев нужен хотя бы один датчик и одно реле устройства.</p>
        <?php endif; ?>
        <div class="scenario-grid">
            <?php if (!$scenarios) : ?>
                <div class="scenario-card">
                    <strong>Пока нет сценариев</strong>
                    <span class="muted">Создайте первый сценарий ниже.</span>
                </div>
            <?php endif; ?>
            <?php foreach ($scenarios as $scenario) : ?>
                <?php
                    $conditionText = scenarioConditionText($scenario, $sensorLabels);
                    $actionText = scenarioActionText($scenario, $relayLabels);
                    $scenarioJson = json_encode($scenario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                ?>
                <article class="scenario-card">
                    <div class="scenario-title">
                        <div>
                            <strong><?php echo h($scenario['name']); ?></strong><br>
                            <span class="muted"><?php echo h($scenario['scenario_id']); ?></span>
                        </div>
                        <span class="badge <?php echo $scenario['enabled'] ? '' : 'off'; ?>"><?php echo $scenario['enabled'] ? 'включён' : 'выключен'; ?></span>
                    </div>
                    <div class="muted">Если: <?php echo h($conditionText); ?></div>
                    <div class="muted">Тогда: <?php echo h($actionText); ?></div>
                    <div class="latest">Cooldown: <?php echo (int)$scenario['cooldown_seconds']; ?> сек · последнее: <?php echo h($scenario['last_triggered_at'] ?: 'нет'); ?></div>
                    <div class="inline-actions">
                        <form method="POST">
                            <?php echo fitodomikCsrfInput(); ?>
                            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
                            <input type="hidden" name="action" value="toggle_scenario">
                            <input type="hidden" name="scenario_id" value="<?php echo h($scenario['scenario_id']); ?>">
                            <?php if (!$scenario['enabled']) :
                                ?><input type="hidden" name="enabled" value="1"><?php
                            endif; ?>
                            <button class="secondary" type="submit"><?php echo $scenario['enabled'] ? 'Выключить' : 'Включить'; ?></button>
                        </form>
                        <button class="secondary" type="button" data-edit-scenario="<?php echo h($scenarioJson); ?>">Изменить</button>
                        <form method="POST" onsubmit="return confirm('Удалить сценарий?')">
                            <?php echo fitodomikCsrfInput(); ?>
                            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
                            <input type="hidden" name="action" value="delete_scenario">
                            <input type="hidden" name="scenario_id" value="<?php echo h($scenario['scenario_id']); ?>">
                            <button class="danger" type="submit">Удалить</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <form method="POST" id="scenario-wizard" style="margin-top:18px">
            <?php echo fitodomikCsrfInput(); ?>
            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">
            <input type="hidden" name="action" value="save_scenario">
            <input type="hidden" name="scenario_id" id="scenario_id" value="">
            <div class="wizard-grid">
                <label class="wide">Название сценария
                    <input name="scenario_name" id="scenario_name" value="Новый сценарий" maxlength="160">
                </label>
                <label>Датчик
                    <select name="condition_sensor_uid" id="condition_sensor_uid">
                        <?php foreach ($sensors as $sensor) : ?>
                            <?php if (empty($sensor['enabled'])) {
                                continue;
                            } ?>
                            <option value="<?php echo h($sensor['sensor_uid']); ?>"><?php echo h($sensor['name']); ?> · <?php echo h($sensor['unit']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Условие
                    <select name="condition_operator" id="condition_operator">
                        <option value="<">меньше</option>
                        <option value=">">больше</option>
                        <option value="=">равно</option>
                        <option value="between">между</option>
                        <option value="not_between">не между</option>
                        <option value="no_data"><?php echo NO_DATA_TEXT; ?></option>
                        <option value="time_between">время между</option>
                        <option value="value">значение есть</option>
                    </select>
                </label>
                <label>Значение
                    <input name="condition_value" id="condition_value" type="number" step="0.01" value="30">
                </label>
                <label>Верхняя граница
                    <input name="condition_value_to" id="condition_value_to" type="number" step="0.01" value="">
                </label>
                <label>Время от
                    <input name="condition_from" id="condition_from" type="time" value="08:00">
                </label>
                <label>Время до
                    <input name="condition_to" id="condition_to" type="time" value="20:00">
                </label>
                <label>Длительность условия, сек
                    <input name="condition_duration_seconds" id="condition_duration_seconds" type="number" min="0" max="86400" value="0">
                </label>
                <label>Действие
                    <select name="action_type" id="action_type">
                        <option value="relay_on">включить реле</option>
                        <option value="relay_off">выключить реле</option>
                        <option value="relay_pulse">импульс реле</option>
                        <option value="take_photo">сделать фото</option>
                        <option value="run_analysis">запустить анализ</option>
                        <option value="send_notification">отправить уведомление</option>
                        <option value="create_command">создать команду устройству</option>
                    </select>
                </label>
                <label>Реле
                    <select name="action_relay_uid" id="action_relay_uid">
                        <?php foreach ($relays as $relay) : ?>
                            <?php if (empty($relay['enabled'])) {
                                continue;
                            } ?>
                            <option value="<?php echo h($relay['relay_uid']); ?>"><?php echo h($relay['name']); ?> · <?php echo h($relay['pin']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Длительность действия, сек
                    <input name="action_duration_seconds" id="action_duration_seconds" type="number" min="1" max="3600" value="3">
                </label>
                <label>Cooldown, сек
                    <input name="cooldown_seconds" id="cooldown_seconds" type="number" min="0" max="86400" value="1800">
                </label>
                <label class="wide">Текст уведомления
                    <input name="action_message" id="action_message" value="Сработал сценарий" maxlength="500">
                </label>
                <label>Команда устройству
                    <select name="action_command_type" id="action_command_type">
                        <option value="sync_device_config">sync_device_config</option>
                        <option value="request_device_config">request_device_config</option>
                        <option value="apply_device_config">apply_device_config</option>
                        <option value="refresh_thresholds">refresh_thresholds</option>
                        <option value="take_photo">take_photo</option>
                        <option value="analyze_plant">analyze_plant</option>
                    </select>
                </label>
                <label>Состояние
                    <span class="checks"><label><input type="checkbox" name="scenario_enabled" id="scenario_enabled" checked> включён</label></span>
                </label>
            </div>
            <details id="scenario-arrays"><summary>Все условия и действия</summary>
                <p>Здесь можно изменить, добавить и удалить элементы сложного сценария. Простые поля изменяют первый элемент.</p>
                <label for="conditions_json">Условия (JSON)</label>
                <textarea id="conditions_json" name="conditions_json" rows="8" style="width:100%"></textarea>
                <label for="actions_json">Действия (JSON)</label>
                <textarea id="actions_json" name="actions_json" rows="8" style="width:100%"></textarea>
            </details>
            <div class="actions" style="margin-top:12px">
                <button type="submit">Сохранить сценарий</button>
                <button class="secondary" type="button" id="scenario-reset">Создать новый</button>
            </div>
        </form>
        <h3>Журнал сценариев</h3>
        <div class="log-table-scroll" role="region" aria-label="Журнал сценариев" tabindex="0">
        <table class="log-table">
            <thead><tr><th>Время</th><th>Сценарий</th><th>Событие</th><th>Сообщение</th></tr></thead>
            <tbody>
                <?php if (!$scenarioLogs) :
                    ?><tr><td colspan="4" class="muted">Журнал пока пуст</td></tr><?php
                endif; ?>
                <?php foreach ($scenarioLogs as $event) : ?>
                    <tr>
                        <td><?php echo h($event['created_at']); ?></td>
                        <td><?php echo h($event['scenario_id']); ?></td>
                        <td><?php echo h($event['event_type']); ?></td>
                        <td><?php echo h($event['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
</main>
<script src="/static/js/device-qr-scanner.v5.js" defer></script>
<script>
(() => {
    const form = document.getElementById('scenario-wizard');
    if (!form) return;
    const byId = (id) => document.getElementById(id);
    const defaults = {
        scenario_id: '',
        scenario_name: 'Новый сценарий',
        condition_operator: '<',
        condition_value: '30',
        condition_value_to: '',
        condition_from: '08:00',
        condition_to: '20:00',
        condition_duration_seconds: '0',
        action_type: 'relay_pulse',
        action_duration_seconds: '3',
        cooldown_seconds: '1800',
        action_message: 'Сработал сценарий',
        action_command_type: 'sync_device_config'
    };
    function setValue(id, value) {
        const field = byId(id);
        if (field) field.value = value == null ? '' : value;
    }
    function resetWizard() {
        setValue('conditions_json', ''); setValue('actions_json', '');
        Object.entries(defaults).forEach(([id, value]) => setValue(id, value));
        const enabled = byId('scenario_enabled');
        if (enabled) enabled.checked = true;
        form.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
    document.querySelectorAll('[data-edit-scenario]').forEach((button) => {
        button.addEventListener('click', () => {
            let scenario;
            try {
                scenario = JSON.parse(button.getAttribute('data-edit-scenario') || '{}');
            } catch (error) {
                return;
            }
            setValue('conditions_json', JSON.stringify(scenario.conditions || [], null, 2));
            setValue('actions_json', JSON.stringify(scenario.actions || [], null, 2));
            const condition = Array.isArray(scenario.conditions) && scenario.conditions.length ? scenario.conditions[0] : {};
            const action = Array.isArray(scenario.actions) && scenario.actions.length ? scenario.actions[0] : {};
            setValue('scenario_id', scenario.scenario_id || '');
            setValue('scenario_name', scenario.name || defaults.scenario_name);
            setValue('condition_sensor_uid', condition.sensor_uid || byId('condition_sensor_uid')?.value || '');
            setValue('condition_operator', condition.operator || defaults.condition_operator);
            setValue('condition_value', condition.value ?? defaults.condition_value);
            setValue('condition_value_to', condition.value_to ?? '');
            setValue('condition_from', condition.from || defaults.condition_from);
            setValue('condition_to', condition.to || defaults.condition_to);
            setValue('condition_duration_seconds', condition.duration_seconds || 0);
            setValue('action_type', action.type || defaults.action_type);
            setValue('action_relay_uid', action.relay_uid || byId('action_relay_uid')?.value || '');
            setValue('action_duration_seconds', action.duration_seconds || defaults.action_duration_seconds);
            setValue('cooldown_seconds', scenario.cooldown_seconds ?? defaults.cooldown_seconds);
            setValue('action_message', action.message || defaults.action_message);
            setValue('action_command_type', action.command_type || defaults.action_command_type);
            const enabled = byId('scenario_enabled');
            if (enabled) enabled.checked = scenario.enabled !== false;
            form.scrollIntoView({behavior: 'smooth', block: 'start'});
        });
    });
    form.addEventListener('input', (event) => {
        const key = event.target.name.startsWith('condition_') ? 'conditions' : event.target.name.startsWith('action_') ? 'actions' : null;
        if (!key) return;
        const field = byId(key + '_json');
        if (!field.value.trim()) return;
        try {
            const items = JSON.parse(field.value);
            if (!Array.isArray(items) || !items.length) return;
            const prefix = key === 'conditions' ? 'condition_' : 'action_';
            const item = {...items[0]};
            form.querySelectorAll('[name^="' + prefix + '"]').forEach(control => {
                item[control.name.slice(prefix.length)] = control.value;
            });
            items[0] = item;
            field.value = JSON.stringify(items, null, 2);
        } catch (error) { /* Keep invalid JSON visible; server rejects without saving. */ }
    });
    document.getElementById('scenario-reset')?.addEventListener('click', resetWizard);
})();
</script>
</body>
</html>
