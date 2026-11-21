<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_context.php';
if (!defined('FITODOMIK_HOURLY_TIME_FORMAT')) {
    define('FITODOMIK_HOURLY_TIME_FORMAT', '%02d:00');
}
$isGuest = !isset($_SESSION['user_id']);
$user_id = $isGuest ? 0 : (int)$_SESSION['user_id'];
$selectedDeviceId = '';
if (!$isGuest) {
    try {
        $selectedDevice = fitodomikGetSelectedDevice($pdo, (int)$user_id);
        $selectedDeviceId = $selectedDevice['device_id'] ?? '';
    } catch (Exception $e) {
        error_log('farm-settings device context: ' . $e->getMessage());
    }
}
$stmt = $pdo->prepare("
    SELECT parameter_type, min_limit, max_limit, target_value, tolerance
    FROM alarm_thresholds
    WHERE user_id = ? AND device_id = ?
");
$stmt->execute([$user_id, $selectedDeviceId]);
$thresholds = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $thresholds[$row['parameter_type']] = $row;
}
$temp_settings = isset($thresholds['temperature']) ?
    ['temperature' => round($thresholds['temperature']['target_value'], 1), 'tolerance' => round($thresholds['temperature']['tolerance'], 1)] :
    ['temperature' => 25.0, 'tolerance' => 1.0];
$humidity_settings = isset($thresholds['humidity_soil']) ?
    ['humidity' => round($thresholds['humidity_soil']['target_value']), 'tolerance' => round($thresholds['humidity_soil']['tolerance'], 1)] :
    ['humidity' => 60, 'tolerance' => 1.0];
list($deviceWhere, $deviceParams) = fitodomikDeviceWhere($selectedDeviceId);
$stmt = $pdo->prepare("SELECT lamp_state, curtains_state FROM sensor_data WHERE user_id = ? {$deviceWhere} ORDER BY created_at DESC LIMIT 1");
$stmt->execute(array_merge([$user_id], $deviceParams));
$device_states = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device_states) {
    $device_states = ['lamp_state' => 0, 'curtains_state' => 0];
}
$temp_limits = isset($thresholds['temperature']) ?
    ['min_limit' => round($thresholds['temperature']['min_limit'], 1), 'max_limit' => round($thresholds['temperature']['max_limit'], 1)] :
    ['min_limit' => 15.0, 'max_limit' => 30.0];
$humidity_limits = isset($thresholds['humidity_soil']) ?
    ['min_limit' => round($thresholds['humidity_soil']['min_limit']), 'max_limit' => round($thresholds['humidity_soil']['max_limit'])] :
    ['min_limit' => 40, 'max_limit' => 60];
$co2_limits = isset($thresholds['co2']) ?
    ['min_limit' => round($thresholds['co2']['min_limit']), 'max_limit' => round($thresholds['co2']['max_limit'])] :
    ['min_limit' => 600, 'max_limit' => 2000];
$stmt = $pdo->prepare("
    SELECT time, curtains_schedule, lighting_schedule
    FROM schedule
    WHERE user_id = ? AND device_id = ?
    ORDER BY CASE
        WHEN time LIKE '0:%' THEN 0
        WHEN time LIKE '1:%' THEN 1
        WHEN time LIKE '2:%' THEN 2
        WHEN time LIKE '3:%' THEN 3
        WHEN time LIKE '4:%' THEN 4
        WHEN time LIKE '5:%' THEN 5
        WHEN time LIKE '6:%' THEN 6
        WHEN time LIKE '7:%' THEN 7
        WHEN time LIKE '8:%' THEN 8
        WHEN time LIKE '9:%' THEN 9
        WHEN time LIKE '10:%' THEN 10
        WHEN time LIKE '11:%' THEN 11
        WHEN time LIKE '12:%' THEN 12
        WHEN time LIKE '13:%' THEN 13
        WHEN time LIKE '14:%' THEN 14
        WHEN time LIKE '15:%' THEN 15
        WHEN time LIKE '16:%' THEN 16
        WHEN time LIKE '17:%' THEN 17
        WHEN time LIKE '18:%' THEN 18
        WHEN time LIKE '19:%' THEN 19
        WHEN time LIKE '20:%' THEN 20
        WHEN time LIKE '21:%' THEN 21
        WHEN time LIKE '22:%' THEN 22
        WHEN time LIKE '23:%' THEN 23
        ELSE 24
    END
");
$stmt->execute([$user_id, $selectedDeviceId]);
$scheduleRowsByHour = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $timeParts = explode(':', (string)($row['time'] ?? ''), 2);
    $hourText = (string)($timeParts[0] ?? '');
    if (!preg_match('/^(?:[01]?\d|2[0-3])$/', $hourText)) {
        continue;
    }
    $hour = (int)$hourText;
    if (!isset($scheduleRowsByHour[$hour])) {
        $scheduleRowsByHour[$hour] = $row;
    }
}
$scheduleData = [];
for ($hour = 0; $hour < 24; $hour++) {
    $row = $scheduleRowsByHour[$hour] ?? [
        'curtains_schedule' => 0,
        'lighting_schedule' => 0,
    ];
    $row['time'] = sprintf(FITODOMIK_HOURLY_TIME_FORMAT, $hour);
    $scheduleData[] = $row;
}
$curtainsActiveHours = 0;
$lightingActiveHours = 0;
foreach ($scheduleData as $row) {
    if ($row['curtains_schedule'] == 1) {
        $curtainsActiveHours++;
    }
    if ($row['lighting_schedule'] == 1) {
        $lightingActiveHours++;
    }
}
function fitodomikScheduleRowIsActive($row, $deviceType)
{
    $scheduleKey = $deviceType === 'lighting' ? 'lighting_schedule' : 'curtains_schedule';
    return isset($row[$scheduleKey]) && (int)$row[$scheduleKey] === 1;
}
function fitodomikAppendTimeRange(&$ranges, &$currentRange)
{
    if ($currentRange !== null) {
        $ranges[] = $currentRange;
        $currentRange = null;
    }
}
function getActiveTimeRanges($scheduleData, $deviceType)
{
    $ranges = [];
    $currentRange = null;
    usort($scheduleData, function ($a, $b) {
        $hourA = (int)explode('-', $a['time'])[0];
        $hourB = (int)explode('-', $b['time'])[0];
        return $hourA - $hourB;
    });
    foreach ($scheduleData as $row) {
        $hour = (int)explode('-', $row['time'])[0];
        if (!fitodomikScheduleRowIsActive($row, $deviceType)) {
            fitodomikAppendTimeRange($ranges, $currentRange);
            continue;
        }
        $isConsecutive = $currentRange !== null && $hour === $currentRange['end'] + 1;
        if (!$isConsecutive) {
            fitodomikAppendTimeRange($ranges, $currentRange);
            $currentRange = ['start' => $hour, 'end' => $hour];
            continue;
        }
        $currentRange['end'] = $hour;
    }
    fitodomikAppendTimeRange($ranges, $currentRange);
    return $ranges;
}
function formatTimeRange($range)
{
    if (!is_array($range) || !isset($range['start']) || !isset($range['end'])) {
        return 'Неизвестно';
    }
    $start = sprintf(FITODOMIK_HOURLY_TIME_FORMAT, (int)$range['start']);
    $end = sprintf("%02d:59", (int)$range['end']);
    return "$start - $end";
}
$lightingRanges = !empty($scheduleData) ? getActiveTimeRanges($scheduleData, 'lighting') : [];
$curtainsRanges = !empty($scheduleData) ? getActiveTimeRanges($scheduleData, 'curtains') : [];
function safeOutput($text)
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<div class="farm-settings-container">
    <button type="button" class="farm-settings-header accordion-toggle-button"
         onclick="toggleFarmSettings()">
        <span class="header-title">Настройка фермы</span>
        <span class="header-right-content">
            <span class="accordion-icon">▼</span>
        </span>
    </button>
    <div class="farm-settings-content" id="farmSettingsContent">
        <?php if ($isGuest) : ?>
        <div class="guest-notice">
            <p>Вы просматриваете данные в режиме гостя. Для сохранения изменений необходимо <a href="authentication/login.php">авторизоваться</a>.</p>
        </div>
        <?php endif; ?>
        <div class="settings-grid">
            <div class="settings-block temperature-block">
                <h3>Температура</h3>
                <div class="settings-row">
                    <div class="value-input">
                        <label for="temperature">Значение °C</label>
                        <input type="number" id="temperature" min="20" max="50" step="0.1"
                               value="<?php echo htmlspecialchars($temp_settings['temperature']); ?>" required
                               <?php echo $isGuest ? 'disabled' : ''; ?>
                               aria-label="Установка температуры"
                               title="Установите целевое значение температуры в градусах Цельсия"
                               placeholder="Целевая температура">
                    </div>
                    <div class="tolerance-input">
                        <label for="temperatureTolerance">Погрешность °C</label>
                        <input type="number" id="temperatureTolerance" min="1" max="5" step="0.1"
                               value="<?php echo htmlspecialchars($temp_settings['tolerance']); ?>" required
                               <?php echo $isGuest ? 'disabled' : ''; ?>
                               aria-label="Допустимое отклонение температуры"
                               title="Установите допустимое отклонение от целевой температуры"
                               placeholder="Погрешность">
                    </div>
                </div>
                <?php if (!$isGuest) : ?>
                <div class="button-center">
                    <button type="button" class="save-settings" onclick="saveTemperature()" aria-label="Сохранить настройки температуры" title="Сохранить настройки температуры">Сохранить</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="settings-block humidity-block">
                <h3>Влажность почвы</h3>
                <div class="settings-row">
                    <div class="value-input">
                        <label for="humidity">Значение %</label>
                        <input type="number" id="humidity" min="30" max="99"
                               value="<?php echo htmlspecialchars($humidity_settings['humidity']); ?>" required
                               <?php echo $isGuest ? 'disabled' : ''; ?>
                               aria-label="Установка влажности почвы"
                               title="Установите целевое значение влажности почвы в процентах"
                               placeholder="Целевая влажность почвы">
                    </div>
                    <div class="tolerance-input">
                        <label for="humidityTolerance">Погрешность %</label>
                        <input type="number" id="humidityTolerance" min="1" max="5" step="0.1"
                               value="<?php echo htmlspecialchars($humidity_settings['tolerance']); ?>" required
                               <?php echo $isGuest ? 'disabled' : ''; ?>
                               aria-label="Допустимое отклонение влажности почвы"
                               title="Установите допустимое отклонение от целевой влажности почвы"
                               placeholder="Погрешность">
                    </div>
                </div>
                <?php if (!$isGuest) : ?>
                <div class="button-center">
                    <button type="button" class="save-settings" onclick="saveHumidity()" aria-label="Сохранить настройки влажности почвы" title="Сохранить настройки влажности почвы">Сохранить</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="settings-block schedule-block">
                <h3>Расписание работы</h3>
                <div class="settings-row">
                    <div class="schedule-info">
                        <div class="schedule-item">
                            <div class="schedule-left">
                                <div class="schedule-icon">💡</div>
                                <div class="schedule-details">
                                    <h4>Освещение</h4>
                                    <div class="schedule-status">
                                        Сейчас: <?php echo safeOutput($device_states['lamp_state'] ? 'включено' : 'выключено'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="schedule-times">
                                <?php if (empty($lightingRanges)) : ?>
                                    <span class="no-schedule">Не настроено</span>
                                <?php else : ?>
                                    <?php foreach ($lightingRanges as $range) : ?>
                                        <span class="time-range"><?php echo safeOutput(formatTimeRange($range)); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="schedule-item">
                            <div class="schedule-left">
                                <div class="schedule-icon">🚪</div>
                                <div class="schedule-details">
                                    <h4>Шторы</h4>
                                    <div class="schedule-status">
                                        Сейчас: <?php echo safeOutput($device_states['curtains_state'] ? 'открыты' : 'закрыты'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="schedule-times">
                                <?php if (empty($curtainsRanges)) : ?>
                                    <span class="no-schedule">Не настроено</span>
                                <?php else : ?>
                                    <?php foreach ($curtainsRanges as $range) : ?>
                                        <span class="time-range"><?php echo safeOutput(formatTimeRange($range)); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="schedules-section">
            <button type="button" class="schedules-header accordion-toggle-button"
                 onclick="toggleSchedules()">
                <span class="header-title">Расписания</span>
                <span class="header-right-content">
                    <span class="accordion-icon">▼</span>
                </span>
            </button>
            <div class="schedules-content" id="schedulesContent">
                <div class="schedules-grid">
                    <div class="settings-block curtains-block">
                        <h3>Шторы</h3>
                        <div class="curtains-settings">
                            <div class="hours-display">
                                <span>Активно:</span>
                                <div class="hours-value" id="curtainsHours"><?php echo (int)$curtainsActiveHours; ?></div>
                                <span>ч</span>
                            </div>
                            <p class="schedule-title">Поднять в: зелёные часы. Опустить в: серые часы.</p>
                            <div class="hours-grid" id="curtainsGrid">
                                <?php foreach ($scheduleData as $row) : ?>
                                    <?php
                                    $hour = explode('-', $row['time'])[0];
                                    $isCurtainActive = $row['curtains_schedule'] == 1;
                                    $curtainClass = $isCurtainActive ? 'active' : 'inactive';
                                    ?>
                                <button type="button" class="hour-cell <?php echo safeOutput($curtainClass); ?>"
                                        data-time="<?php echo htmlspecialchars((string)$row['time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                        data-type="curtains"
                                        aria-label="<?php echo (int)$hour; ?>:00, шторы"
                                        <?php echo $isGuest ? 'disabled' : ''; ?>>
                                    <?php echo (int)$hour; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!$isGuest) : ?>
                            <div class="schedule-buttons">
                                <button type="button" class="save-schedule" onclick="saveCurtainsSchedule()">Сохранить</button>
                                <button type="button" class="reset-schedule" onclick="resetCurtainsSchedule()">Сбросить</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="settings-block lighting-block">
                        <h3>Освещение</h3>
                        <div class="lighting-settings">
                            <div class="hours-display">
                                <span>Активно:</span>
                                <div class="hours-value" id="lightingHours"><?php echo (int)$lightingActiveHours; ?></div>
                                <span>ч</span>
                            </div>
                            <p class="schedule-title">Включить в: зелёные часы. Выключить в: серые часы.</p>
                            <div class="hours-grid" id="lightingGrid">
                                <?php foreach ($scheduleData as $row) : ?>
                                    <?php
                                    $hour = explode('-', $row['time'])[0];
                                    $isLightingActive = $row['lighting_schedule'] == 1;
                                    $lightingClass = $isLightingActive ? 'active' : 'inactive';
                                    ?>
                                <button type="button" class="hour-cell <?php echo safeOutput($lightingClass); ?>"
                                        data-time="<?php echo htmlspecialchars((string)$row['time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                        data-type="lighting"
                                        aria-label="<?php echo (int)$hour; ?>:00, освещение"
                                        <?php echo $isGuest ? 'disabled' : ''; ?>>
                                    <?php echo (int)$hour; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!$isGuest) : ?>
                            <div class="schedule-buttons">
                                <button type="button" class="save-schedule" onclick="saveLightingSchedule()">Сохранить</button>
                                <button type="button" class="reset-schedule" onclick="resetLightingSchedule()">Сбросить</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
.limits-section {
    margin-bottom: 20px;
    padding: 15px;
    background: var(--card-bg);
    border-radius: 8px;
}
.limits-section h4 {
    margin: 0 0 10px 0;
    color: var(--text-color);
}
.limit-inputs {
    display: flex;
    gap: 20px;
}
.limit-input {
    display: flex;
    align-items: center;
    gap: 10px;
}
.limit-input label {
    min-width: 80px;
}
.limit-input input {
    width: 100px;
    padding: 5px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--input-bg);
    color: var(--text-color);
}
.save-limits-btn {
    margin-top: 20px;
    padding: 10px 20px;
    background: var(--accent-surface);
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    transition: background 0.3s;
}
.save-limits-btn:hover {
    background: var(--accent-surface-hover);
}
[data-theme="dark"] .limits-section {
    background: var(--card-bg);
}
[data-theme="dark"] .limit-input input {
    background: var(--input-bg);
    color: var(--text-color);
    border-color: var(--border-color);
}
.guest-notice {
    background-color: rgba(255, 193, 7, 0.2);
    border-left: 4px solid #d99a00;
    padding: 10px 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}
.guest-notice p {
    margin: 0;
    color: var(--text-color);
}
.guest-notice a {
    color: var(--primary-color);
    text-decoration: none;
    font-weight: bold;
}
.guest-notice a:hover {
    text-decoration: underline;
}
button.disabled {
    background-color: #6b7280;
    cursor: not-allowed !important;
}
input:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
.save-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background-color: var(--accent-surface);
    color: white;
    padding: 15px 25px;
    border-radius: 5px;
    z-index: 1000;
    animation: fadeInOut 1s ease-in-out;
}
@keyframes fadeInOut {
    0% { opacity: 0; transform: translateY(-20px); }
    20% { opacity: 1; transform: translateY(0); }
    80% { opacity: 1; transform: translateY(0); }
    100% { opacity: 0; transform: translateY(-20px); }
}
.button-center {
    display: flex;
    justify-content: center;
    margin-top: 15px;
}
.schedules-section {
    margin-top: 20px;
    border-radius: 8px;
    overflow: hidden;
    background: var(--card-bg);
}
.schedules-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: var(--accent-surface);
    color: white;
    cursor: pointer;
}
.schedules-header h3 {
    margin: 0;
    font-size: 1.3rem;
}
.schedules-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}
.schedules-content.active {
    max-height: 2000px;
}
.schedules-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
    padding: 20px;
}
@media (min-width: 911px) {
    .schedules-grid {
        grid-template-columns: 1fr 1fr;
    }
}
.farm-settings-container {
    max-width: 1200px;
    margin: 0 auto;
}
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    justify-content: center;
}
.control-buttons {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
}
.control-item {
    width: 100%;
    max-width: 300px;
    text-align: center;
}
.control-btn {
    width: 100%;
}
.control-btn.green {
    background-color: var(--accent-surface);
}
.control-btn.red {
    background-color: #dc3545;
}
.device-status {
    margin-bottom: 8px;
    text-align: center;
}
.add-exception-btn, .save-settings {
    width: 100%;
    margin-top: 15px;
    padding: 10px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    color: white;
    transition: background-color 0.3s;
}
.add-exception-btn {
    background-color: var(--accent-surface);
}
.add-exception-btn:hover {
    background-color: var(--accent-surface-hover);
}
.save-settings {
    background-color: var(--accent-surface);
    margin-top: 10px;
}
.save-settings:hover {
    background-color: var(--accent-surface-hover);
}
.schedule-block {
    background: var(--card-bg);
    border-radius: 8px;
    padding: 15px;
    border: 1px solid var(--border-color);
}
.schedule-block h3 {
    margin: 0 0 15px 0;
    color: var(--text-color);
    font-size: 16px;
    text-align: center;
}
.schedule-info {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.schedule-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 15px;
    background: var(--card-bg);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.schedule-item > .schedule-times {
    flex-shrink: 0;
    margin-left: auto;
}
.schedule-icon {
    font-size: 24px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent-surface);
    border-radius: 50%;
    flex-shrink: 0;
}
.schedule-left {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 0 1 auto;
    min-width: 0;
}
.schedule-details {
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.schedule-details h4 {
    margin: 0;
    color: var(--text-color);
    font-size: 16px;
    font-weight: 600;
}
.schedule-status {
    margin: 0;
    font-size: 13px;
    color: var(--text-color);
    font-weight: 500;
}
.schedule-times {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    justify-content: flex-end;
    align-items: center;
    max-width: 300px;
    text-align: right;
}
.time-range {
    display: inline-block;
    padding: 3px 8px;
    background: var(--accent-surface);
    color: white;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    line-height: 1.2;
}
.no-schedule {
    color: var(--text-color);
    font-style: italic;
    font-size: 13px;
}
[data-theme="dark"] .schedule-block {
    background: var(--card-bg);
    border-color: var(--border-color);
}
[data-theme="dark"] .schedule-block h3 {
    color: var(--text-color);
}
[data-theme="dark"] .schedule-item {
    background: var(--card-bg);
    border-color: var(--border-color);
}
[data-theme="dark"] .schedule-icon {
    background: var(--accent-surface);
    color: white;
}
[data-theme="dark"] .schedule-details h4 {
    color: var(--text-color);
}
[data-theme="dark"] .schedule-status {
    color: var(--text-color);
}
[data-theme="dark"] .time-range {
    background: var(--accent-surface);
    color: white;
}
[data-theme="dark"] .no-schedule {
    color: var(--text-color);
}
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background-color: var(--accent-surface);
    color: white;
    padding: 15px 25px;
    border-radius: 5px;
    z-index: 1000;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease-in-out;
}
.notification.error {
    background-color: #b42318;
}
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}
.button-group {
    display: flex;
    flex-direction: column;
    margin-top: 15px;
    gap: 10px;
    align-items: center;
}
@media (max-width: 767px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
    .time-inputs {
        flex-direction: column;
        align-items: center;
    }
    .time-inputs input[type="time"] {
        margin-bottom: 10px;
        width: 100%;
    }
    .exception-item {
        flex-direction: column;
    }
    .exception-time {
        margin-bottom: 10px;
        width: 100%;
    }
    .remove-exception {
        width: 100%;
    }
    .schedule-item {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 10px;
    }
    .schedule-left {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    .schedule-details {
        text-align: center;
        gap: 5px;
    }
    .schedule-icon {
        width: 36px;
        height: 36px;
        font-size: 20px;
    }
    .schedule-details h4 {
        font-size: 15px;
        margin: 0;
    }
    .schedule-status {
        font-size: 12px;
        margin: 0;
    }
    .schedule-times {
        justify-content: center;
        gap: 4px;
        max-width: 100%;
    }
    .time-range {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 8px;
    }
    .no-schedule {
        font-size: 12px;
    }
}
@media (min-width: 768px) and (max-width: 1024px) {
    .schedule-info {
        gap: 15px;
    }
    .schedule-item {
        padding: 12px;
        gap: 12px;
    }
    .schedule-icon {
        width: 38px;
        height: 38px;
        font-size: 22px;
    }
    .time-range {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 10px;
    }
    .schedule-times {
        max-width: 200px;
        gap: 3px;
    }
    .schedule-left {
        gap: 10px;
    }
}
@media (max-width: 767px) {
    .button-group {
        align-items: center;
    }
    .button-group button {
        max-width: 80%;
    }
}
.required-hours {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 15px;
}
.required-hours label {
    margin-bottom: 5px;
}
.required-hours input[type="number"] {
    border: 1px solid var(--border-color);
    border-radius: 4px;
    padding: 8px;
    background: var(--input-bg);
    color: var(--text-color);
    font-size: 16px;
    width: 130px;
    text-align: center;
}
.hours-display {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-bottom: 12px;
    color: var(--secondary-text);
    font-weight: 700;
}
.hours-value {
    min-width: 28px;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 18px;
    font-weight: bold;
    color: var(--primary-color);
    pointer-events: none;
}
.schedule-title {
    text-align: center;
    margin: 15px 0;
    font-weight: normal;
    padding: 0 10px;
    line-height: 1.4;
}
.hours-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 20px;
}
.hour-cell {
    min-width: 0;
    padding: 10px;
    text-align: center;
    border: 1px solid transparent;
    border-radius: 5px;
    appearance: none;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    transition: all 0.2s ease;
}
.hour-cell.active {
    background-color: var(--accent-surface);
    color: white;
}
.hour-cell.inactive {
    background-color: var(--input-bg);
    color: var(--text-color);
    border-color: var(--border-color);
}
.hour-cell:hover {
    border-color: var(--primary-color);
    transform: translateY(-1px);
}
.hour-cell:focus-visible {
    outline: 3px solid rgba(var(--primary-rgb), 0.28);
    outline-offset: 2px;
}
.hour-cell:disabled {
    cursor: not-allowed;
    opacity: 0.78;
    transform: none;
}
.schedule-buttons {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 20px;
}
.save-schedule, .reset-schedule {
    flex: 1;
    min-height: 44px;
    padding: 12px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 16px;
    text-align: center;
    color: white;
    transition: background-color 0.3s;
}
.save-schedule {
    background-color: var(--accent-surface);
}
.save-schedule:hover {
    background-color: var(--accent-surface-hover);
}
.reset-schedule {
    background-color: #b42318;
}
.reset-schedule:hover {
    background-color: #8f1c13;
}
@media (max-width: 767px) {
    .hours-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
}
@media (min-width: 915px) and (max-width: 1025px) {
    .schedule-title {
        font-size: 14px;
        padding: 0 5px;
        word-wrap: break-word;
    }
    .hours-grid {
        gap: 5px;
    }
    .hour-cell {
        padding: 8px 5px;
        font-size: 14px;
    }
    .schedules-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
}
@media (max-width: 480px) {
    .hours-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}
</style>
<script>
function toggleFarmSettings() {
    const content = document.getElementById('farmSettingsContent');
    const icon = document.querySelector('.farm-settings-header .accordion-icon');
    content.classList.toggle('active');
    icon?.classList.toggle('rotate');
}
const isGuest = <?php echo $isGuest ? 'true' : 'false'; ?>;
function saveTemperature(suppressNotifications = false) {
    if (isGuest) {
        alert('Для сохранения настроек необходимо авторизоваться');
        return;
    }
    const temperature = parseFloat(document.getElementById('temperature').value);
    const tolerance = parseFloat(document.getElementById('temperatureTolerance').value);
    if (temperature < 20 || temperature > 50) {
        alert('Температура должна быть от 20 до 50°C');
        return;
    }
    if (tolerance < 1 || tolerance > 5) {
        alert('Погрешность должна быть от 1 до 5°C');
        return;
    }
    const temperatureVal = parseFloat(temperature.toFixed(1));
    const toleranceVal = parseFloat(tolerance.toFixed(1));
    const minLimit = parseFloat((temperatureVal - toleranceVal).toFixed(1));
    const maxLimit = parseFloat((temperatureVal + toleranceVal).toFixed(1));
    fetch('/api/save-limits.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            device_id: <?php echo json_encode($selectedDeviceId); ?>,
            temperature: {
                min: minLimit,
                max: maxLimit,
                target: temperatureVal,
                tolerance: toleranceVal
            }
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (!suppressNotifications) {
                const notification = document.createElement('div');
                notification.className = 'save-notification';
                notification.textContent = 'Температура установлена';
                document.body.appendChild(notification);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else {
            alert('Ошибка при установке температуры: ' + (data.message || 'Неизвестная ошибка'));
        }
    })
    .catch(error => {
        alert('Ошибка при установке температуры: ' + error.message);
    });
}
function saveHumidity(suppressNotifications = false) {
    if (isGuest) {
        alert('Для сохранения настроек необходимо авторизоваться');
        return;
    }
    const humidity = parseFloat(document.getElementById('humidity').value);
    const tolerance = parseFloat(document.getElementById('humidityTolerance').value);
    if (humidity < 30 || humidity > 99) {
        alert('Влажность почвы должна быть от 30 до 99%');
        return;
    }
    if (tolerance < 1 || tolerance > 5) {
        alert('Погрешность должна быть от 1 до 5%');
        return;
    }
    const humidityVal = Math.round(humidity);
    const toleranceVal = parseFloat(tolerance.toFixed(1));
    const minLimit = Math.round(humidityVal - toleranceVal);
    const maxLimit = Math.round(humidityVal + toleranceVal);
    fetch('/api/save-limits.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            device_id: <?php echo json_encode($selectedDeviceId); ?>,
            humidity_soil: {
                min: minLimit,
                max: maxLimit,
                target: humidityVal,
                tolerance: toleranceVal
            }
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (!suppressNotifications) {
                const notification = document.createElement('div');
                notification.className = 'save-notification';
                notification.textContent = 'Влажность почвы установлена';
                document.body.appendChild(notification);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else {
            alert('Ошибка при установке влажности почвы: ' + (data.message || 'Неизвестная ошибка'));
        }
    })
    .catch(error => {
        alert('Ошибка при установке влажности почвы: ' + error.message);
    });
}
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.remove();
    }, 3000);
}
function toggleSchedules() {
    const content = document.getElementById('schedulesContent');
    const icon = document.querySelector('.schedules-header .accordion-icon');
    content.classList.toggle('active');
    if (icon) {
        icon?.classList.toggle('rotate');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    initializeHourGrid('curtainsGrid', 'curtains');
    initializeHourGrid('lightingGrid', 'lighting');
    updateHoursCount('curtains');
    updateHoursCount('lighting');
});
function initializeHourGrid(gridId, type) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    const cells = grid.querySelectorAll('.hour-cell');
    cells.forEach(cell => {
        cell.addEventListener('click', function() {
        if (isGuest) {
                alert('Для изменения расписания необходимо авторизоваться');
            return;
        }
            this.classList.toggle('active');
            this.classList.toggle('inactive');
            updateHoursCount(type);
        });
    });
}
function updateHoursCount(type) {
    const gridId = type === 'curtains' ? 'curtainsGrid' : 'lightingGrid';
    const counterId = type === 'curtains' ? 'curtainsHours' : 'lightingHours';
    const grid = document.getElementById(gridId);
    if (!grid) return;
    const activeCells = grid.querySelectorAll('.hour-cell.active');
    const counter = document.getElementById(counterId);
    if (counter) {
        counter.textContent = activeCells.length;
    }
}
function resetCurtainsSchedule() {
    if (isGuest) {
        alert('Для сброса расписания необходимо авторизоваться');
        return;
    }
    const grid = document.getElementById('curtainsGrid');
    if (!grid) return;
    const cells = grid.querySelectorAll('.hour-cell');
    cells.forEach(cell => {
        cell.classList.remove('active');
        cell.classList.add('inactive');
    });
    cells.forEach(cell => {
        const cellTime = cell.dataset.time;
        const hour = parseInt(cellTime.split(':')[0]);
        if (hour >= 7 && hour <= 21) {
            cell.classList.add('active');
            cell.classList.remove('inactive');
        }
    });
    updateHoursCount('curtains');
    saveCurtainsSchedule(true);
}
function resetLightingSchedule() {
    if (isGuest) {
        alert('Для сброса расписания необходимо авторизоваться');
        return;
    }
    const grid = document.getElementById('lightingGrid');
    if (!grid) return;
    const cells = grid.querySelectorAll('.hour-cell');
    cells.forEach(cell => {
        cell.classList.remove('active');
        cell.classList.add('inactive');
    });
    cells.forEach(cell => {
        const cellTime = cell.dataset.time;
        const hour = parseInt(cellTime.split(':')[0]);
        if (hour >= 7 && hour <= 19) {
            cell.classList.add('active');
            cell.classList.remove('inactive');
        }
    });
    updateHoursCount('lighting');
    saveLightingSchedule(true);
}
function saveCurtainsSchedule(silentMode = false) {
    if (isGuest) {
        alert('Для сохранения расписания необходимо авторизоваться');
        return;
    }
    return saveSchedule('curtainsGrid', 'curtains', silentMode);
}
function saveLightingSchedule(silentMode = false) {
    if (isGuest) {
        alert('Для сохранения расписания необходимо авторизоваться');
        return;
    }
    return saveSchedule('lightingGrid', 'lighting', silentMode);
}
function saveSchedule(gridId, type, silentMode = false) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    const cells = grid.querySelectorAll('.hour-cell');
    const scheduleData = [];
    cells.forEach(cell => {
        scheduleData.push({
            time: cell.dataset.time,
            active: cell.classList.contains('active') ? 1 : 0,
            type: type
        });
    });
    const saveButton = document.querySelector(`.${type}-settings .save-schedule`);
    if (saveButton && !silentMode) {
        const originalText = saveButton.textContent;
        saveButton.textContent = 'Сохранение...';
        saveButton.disabled = true;
        setTimeout(() => {
            saveButton.textContent = originalText;
            saveButton.disabled = false;
        }, 2000);
    }
    return fetch('/api/save-schedule.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({
            device_id: <?php echo json_encode($selectedDeviceId); ?>,
            scheduleData: scheduleData,
            type: type
        })
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (!silentMode) {
                showNotification(`Расписание ${type === 'curtains' ? 'штор' : 'освещения'} сохранено`);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else {
            if (!silentMode) {
                showNotification(data.message || `Ошибка при сохранении расписания ${type === 'curtains' ? 'штор' : 'освещения'}`, 'error');
            }
            console.error('Ошибка сохранения расписания:', data);
        }
        return data.success === true;
    })
    .catch(error => {
        if (!silentMode) {
            showNotification(`Ошибка при сохранении расписания: ${error.message}`, 'error');
        }
        console.error('Ошибка запроса при сохранении расписания:', error);
        return false;
    });
}
</script>
