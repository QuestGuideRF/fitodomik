<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_dynamic_config.php';
if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$isGuest = !isset($_SESSION['user_id']);
$user_id = $isGuest ? 0 : (int)$_SESSION['user_id'];
$selectedDevice = null;
$selectedDeviceId = '';
if (!$isGuest) {
    try {
        $selectedDevice = fitodomikGetSelectedDevice($pdo, (int)$user_id);
        $selectedDeviceId = $selectedDevice['device_id'] ?? '';
    } catch (Exception $e) {
        error_log('farm-status device context: ' . $e->getMessage());
    }
}
list($deviceWhere, $deviceParams) = fitodomikDeviceWhere($selectedDeviceId);
$stmt = $pdo->prepare("SELECT * FROM farm_status WHERE user_id = ? {$deviceWhere} ORDER BY created_at DESC LIMIT 1");
$stmt->execute(array_merge([$user_id], $deviceParams));
$status = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$status) {
    $status = [
        'created_at' => date('Y-m-d H:i:s'),
        'photo' => '',
        'photo_analysis' => '',
        'comment' => $isGuest
            ? 'Авторизуйтесь, чтобы увидеть реальные данные вашей фермы.'
            : 'Для выбранного устройства пока нет фото и отчёта анализа.'
    ];
}
$sensorStmt = $pdo->prepare("
    SELECT created_at, UNIX_TIMESTAMP(created_at) AS measured_at_unix, temperature, humidity, soil_moisture, co2, pressure, curtains_state, lamp_state
    FROM sensor_data
    WHERE user_id = ? {$deviceWhere}
    ORDER BY created_at DESC
    LIMIT 1
");
$sensorStmt->execute(array_merge([$user_id], $deviceParams));
$latestSensors = $sensorStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$dynamicSensors = [];
$dynamicValues = [];
if (!$isGuest && $selectedDeviceId !== '') {
    try {
        $dynamicSensors = array_values(array_filter(
            fitodomikDynamicListSensors($pdo, $user_id, $selectedDeviceId, false),
            static function ($sensor) {
                return !empty($sensor['show_in_monitoring']) && !empty($sensor['send_to_server']);
            }
        ));
        $dynamicValues = fitodomikDynamicLatestValues($pdo, $user_id, $selectedDeviceId);
    } catch (Exception $e) {
        error_log('farm-status dynamic sensors: ' . $e->getMessage());
        $dynamicSensors = [];
        $dynamicValues = [];
    }
}
$lastUpdate = (int)($latestSensors['measured_at_unix'] ?? 0);
foreach ($dynamicValues as $row) { $lastUpdate = max($lastUpdate, (int)($row['measured_at_unix'] ?? 0)); }
$updateTime = !$lastUpdate ? 'нет измерений' : date('H:i', $lastUpdate) . (time()-$lastUpdate>300 ? ' · устарело' : '');
function sensorText($latestSensors, $key, $suffix = '', $decimals = 1)
{
    if (!isset($latestSensors[$key]) || $latestSensors[$key] === null) {
        return '--' . $suffix;
    }
    return number_format((float)$latestSensors[$key], $decimals, '.', '') . $suffix;
}
function dynamicSensorIcon($type)
{
    $type = strtolower((string)$type);
    $icon = '📟';
    if (strpos($type, 'temp') !== false) {
        $icon = '🌡️';
    } elseif (strpos($type, 'humid') !== false || strpos($type, 'soil') !== false) {
        $icon = '💧';
    } elseif (strpos($type, 'co2') !== false) {
        $icon = '🌍';
    } elseif (strpos($type, 'pressure') !== false) {
        $icon = '🌬️';
    } elseif (strpos($type, 'light') !== false || strpos($type, 'ldr') !== false) {
        $icon = '💡';
    }
    return $icon;
}
function binarySensorStateText($latestSensors, $key, $activeText, $inactiveText)
{
    if (!isset($latestSensors[$key])) {
        return '--';
    }
    return $latestSensors[$key] ? $activeText : $inactiveText;
}
function dynamicSensorText($sensor, $latestValues)
{
    $uid = $sensor['sensor_uid'] ?? '';
    $unit = trim((string)($sensor['unit'] ?? ''));
    if (!isset($latestValues[$uid])) {
        return '--' . ($unit !== '' ? ' ' . $unit : '');
    }
    $row = $latestValues[$uid];
    $value = $row['value'] ?? $row['raw_value'] ?? null;
    if ($value === null || $value === '') {
        return '--' . ($unit !== '' ? ' ' . $unit : '');
    }
    if (is_numeric($value)) {
        $value = number_format((float)$value, 2, '.', '');
        $value = rtrim(rtrim($value, '0'), '.');
    }
    $rowUnit = trim((string)($row['unit'] ?? $unit));
    return $value . ($rowUnit !== '' ? ' ' . $rowUnit : '');
}
function farmStatusPhotoExists($filename): bool
{
    $basename = basename(str_replace('\\', '/', (string)$filename));
    if ($basename === '') {
        return false;
    }
    $baseDir = dirname(__DIR__) . '/uploads/farm_photos';
    $baseReal = realpath($baseDir);
    $targetReal = $baseReal === false ? false : realpath($baseReal . DIRECTORY_SEPARATOR . $basename);
    return $baseReal !== false
        && $targetReal !== false
        && strpos($targetReal, $baseReal . DIRECTORY_SEPARATOR) === 0
        && is_file($targetReal)
        && !is_link($targetReal);
}
$status['photo'] = !empty($status['photo']) && farmStatusPhotoExists($status['photo'])
    ? basename((string)$status['photo'])
    : '';
$status['photo_analysis'] = !empty($status['photo_analysis']) && farmStatusPhotoExists($status['photo_analysis'])
    ? basename((string)$status['photo_analysis'])
    : '';
?>
<div class="accordion">
    <button type="button" class="accordion-header accordion-toggle-button"
         onclick="toggleFarmStatus()">
        <span class="header-title">Состояние фермы</span>
        <div class="header-right-content">
            <span class="last-update">Последнее обновление: <span id="lastUpdate"><?php echo h($updateTime); ?></span></span>
            <?php if (!$isGuest && $selectedDevice) : ?>
                <span class="last-update">Устройство: <?php echo h($selectedDevice['device_name']); ?></span>
            <?php endif; ?>
            <i class="bi bi-chevron-down accordion-icon" id="farmStatusIcon"></i>
        </div>
    </button>
    <div class="accordion-content active" id="farmStatusContent" itemscope itemtype="https://schema.org/Product">
        <meta itemprop="name" content="Умная ферма ФитоДомик">
        <meta itemprop="description" content="Система умного мониторинга и управления растениями с климат-контролем">
        <meta itemprop="productID" content="farm-<?php echo (int)$user_id; ?>">
        <meta itemprop="dateModified" content="<?php echo date('c', $lastUpdate); ?>">
        <?php if ($isGuest) : ?>
            <div class="guest-message">
                <p>Для просмотра фотографий и данных вашей фермы, пожалуйста, авторизуйтесь.</p>
            </div>
        <?php else : ?>
            <div class="farm-content-grid">
                <div class="farm-photos-column">
                    <div class="farm-photo-container" itemprop="image">
                        <h3>Оригинальное фото</h3>
                        <?php if (!empty($status['photo'])) : ?>
                            <img src="/media/farm_photos/<?php echo rawurlencode(basename((string)$status['photo'])); ?>" alt="Фото фермы" class="farm-photo" width="320" height="240">
                        <?php else : ?>
                            <div class="farm-photo-placeholder">
                                <span>Нет фото</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="farm-photo-container">
                        <h3>Анализ фото</h3>
                        <?php if (!empty($status['photo_analysis'])) : ?>
                            <img src="/media/farm_photos/<?php echo rawurlencode(basename((string)$status['photo_analysis'])); ?>" alt="Анализ фото" class="farm-photo" width="320" height="240">
                        <?php else : ?>
                            <div class="farm-photo-placeholder">
                                <span>Нет анализа</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="farm-info-column">
                    <div class="farm-status-text" itemprop="description">
                        <h3>Общее состояние системы</h3>
                        <p><?php
                        if ($isGuest) {
                            echo 'Для просмотра состояния вашей фермы, пожалуйста, авторизуйтесь.';
                        } else {
                            echo nl2br(htmlspecialchars($status['comment'] ?? 'Информация о состоянии системы отсутствует.'));
                        }
                        ?></p>
                    </div>
                    <div class="farm-sensors-grid" itemscope itemtype="https://schema.org/ItemList">
                        <meta itemprop="numberOfItems" content="<?php echo !empty($dynamicSensors) ? (int)count($dynamicSensors) : 7; ?>">
                        <meta itemprop="name" content="Параметры микроклимата умной фермы">
                        <?php if (!empty($dynamicSensors)) : ?>
                            <?php foreach ($dynamicSensors as $index => $sensor) : ?>
                                <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                                    <meta itemprop="position" content="<?php echo (int)$index + 1; ?>">
                                    <div class="sensor-icon"><?php echo h(dynamicSensorIcon($sensor['sensor_type'] ?? '')); ?></div>
                                    <div class="sensor-info">
                                        <h4 itemprop="name"><?php echo h($sensor['name']); ?></h4>
                                        <p itemprop="value" data-sensor-uid="<?php echo h($sensor['sensor_uid']); ?>" data-unit="<?php echo h($sensor['unit'] ?? ''); ?>"><?php echo h(dynamicSensorText($sensor, $dynamicValues)); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="1">
                            <div class="sensor-icon">🌡️</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">Температура</h4>
                                <p itemprop="value" data-sensor-key="temperature" data-unit="°C"><?php
                                if ($isGuest) {
                                    echo '-- °C';
                                } else {
                                    echo sensorText($latestSensors, 'temperature', '°C');
                                }
                                ?></p>
                                <meta itemprop="unitCode" content="CEL">
                            </div>
                        </div>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="2">
                            <div class="sensor-icon">💧</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">Влажность</h4>
                                <p itemprop="value" data-sensor-key="humidity" data-unit="%"><?php
                                if ($isGuest) {
                                    echo '--%';
                                } else {
                                    echo sensorText($latestSensors, 'humidity', '%');
                                }
                                ?></p>
                                <meta itemprop="unitCode" content="P1">
                            </div>
                        </div>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="3">
                            <div class="sensor-icon">🌱</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">Влажность почвы</h4>
                                <p itemprop="value" data-sensor-key="soil_moisture" data-unit="%"><?php
                                if ($isGuest) {
                                    echo '--%';
                                } else {
                                    echo sensorText($latestSensors, 'soil_moisture', '%');
                                }
                                ?></p>
                                <meta itemprop="unitCode" content="P1">
                            </div>
                        </div>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="4">
                            <div class="sensor-icon">🌍</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">CO₂</h4>
                                <p itemprop="value" data-sensor-key="co2" data-unit="ppm"><?php
                                if ($isGuest) {
                                    echo '-- ppm';
                                } else {
                                    echo isset($latestSensors['co2']) ? (int)$latestSensors['co2'] . ' ppm' : '-- ppm';
                                }
                                ?></p>
                                <meta itemprop="unitCode" content="PPM">
                            </div>
                        </div>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="5">
                            <div class="sensor-icon">🌬️</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">Давление</h4>
                                <p itemprop="value" data-sensor-key="pressure" data-unit="мм.рт.ст"><?php
                                if ($isGuest) {
                                    echo '-- мм.рт.ст';
                                } else {
                                    echo sensorText($latestSensors, 'pressure', ' мм.рт.ст');
                                }
                                ?></p>
                                <meta itemprop="unitCode" content="MMHG">
                            </div>
                        </div>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="6">
                            <div class="sensor-icon">🚪</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">Шторы</h4>
                                <p itemprop="value" data-sensor-key="curtains_state" data-unit=""><?php
                                if ($isGuest) {
                                    echo '--';
                                } else {
                                    echo binarySensorStateText($latestSensors, 'curtains_state', 'Открыты', 'Закрыты');
                                }
                                ?></p>
                            </div>
                        </div>
                        <div class="sensor-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/PropertyValue">
                            <meta itemprop="position" content="7">
                            <div class="sensor-icon">💡</div>
                            <div class="sensor-info">
                                <h4 itemprop="name">Освещение</h4>
                                <p itemprop="value" data-sensor-key="lamp_state" data-unit=""><?php
                                if ($isGuest) {
                                    echo '--';
                                } else {
                                    echo binarySensorStateText($latestSensors, 'lamp_state', 'Включено', 'Выключено');
                                }
                                ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
.accordion{margin-bottom:20px;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;background-color:var(--card-bg)}
.accordion-header{background-color:var(--accent-surface);padding:15px 20px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;color:white;border-radius:8px;-webkit-user-select:none;user-select:none}
.accordion-header:hover{background-color:var(--accent-surface-hover)}
.accordion-header h2{margin:0;font-size:18px;color:white}
.header-right-content{display:flex;align-items:center;gap:15px}
.last-update{color:#fff;font-weight:600}
.farm-status-text p{white-space:normal;overflow-wrap:anywhere}
.accordion-icon{transition:transform 0.3s ease}
.accordion-icon.rotate{transform:rotate(180deg)}
.accordion-content{display:none;padding:20px;background-color:var(--card-bg);border-radius:0 0 8px 8px}
.accordion-content.active{display:block}
.farm-content-grid{display:grid;grid-template-columns:350px 1fr;gap:20px}
.farm-photos-column{display:flex;flex-direction:column;gap:20px}
.farm-photo-container{background-color:var(--background);padding:10px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)}
.farm-photo-container h3{margin-top:0;margin-bottom:10px;color:var(--text-color);text-align:center;font-size:14px}
.farm-photo{width:100%;max-height:250px;object-fit:contain;border-radius:4px;display:block}
.farm-photo-placeholder{height:150px;display:flex;align-items:center;justify-content:center;background-color:var(--background);border:2px dashed var(--border-color);border-radius:4px;color:var(--text-color);font-size:14px}
.farm-info-column{display:flex;flex-direction:column;gap:20px}
.farm-status-text{background-color:var(--background);padding:15px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)}
.farm-status-text h3{margin-top:0;margin-bottom:10px;color:var(--text-color);font-size:16px}
.farm-status-text p{margin:0;color:var(--text-color);line-height:1.25;font-size:15px;white-space:pre-line}
.farm-sensors-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;padding:15px;background-color:var(--background);border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)}
.sensor-item{display:flex;align-items:center;gap:12px;min-height:84px;padding:14px;background-color:var(--card-bg);border-radius:6px;border:1px solid var(--border-color);box-sizing:border-box}
.sensor-icon{width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:20px;background-color:var(--background);border-radius:50%}
.sensor-info{flex:1}
.sensor-info h4{margin:0 0 4px 0;font-size:13px;color:var(--text-color);opacity:0.8}
.sensor-info p{margin:0;font-size:16px;font-weight:700;line-height:1.35;color:var(--text-color)}
.guest-message{text-align:center;padding:40px;background-color:var(--background);border-radius:8px;color:var(--text-color)}
@media (max-width:768px){
    .farm-content-grid{grid-template-columns:1fr}
    .sensor-item{padding:10px}
    .sensor-icon{width:28px;height:28px;font-size:18px}
    .farm-status-content.active{padding:15px}
    .farm-photo,.farm-photo-placeholder{max-height:200px}
    .sensor-info h4 {
        text-align: center;
    }
    .sensor-info p {
        text-align: center;
    }
    .temperature-block .settings-row,
    .humidity-block .settings-row {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .value-input,
    .tolerance-input {
        width: 100%;
        margin-bottom: 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .save-settings {
        width: 100%;
        margin-top: 10px;
        text-align: center;
    }
    .farm-sensors-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width:480px){
    .farm-sensors-grid {
        grid-template-columns: 1fr;
    }
    .sensor-item {
        display: flex;
        flex-direction: column;
        text-align: center;
        padding: 15px 10px;
    }
    .sensor-icon {
        margin: 0 auto 8px auto;
        width: 36px;
        height: 36px;
    }
    .value-input label,
    .tolerance-input label {
        margin-bottom: 5px;
    }
}
</style>
<script>
let farmUpdateTimer;
let farmStatusFailures = 0;
let farmPollingStopped = false;
function scheduleFarmStatusUpdate() {
    window.clearTimeout(farmUpdateTimer);
    if (!farmPollingStopped) {
        const delay = farmStatusFailures === 0
            ? 30000
            : Math.min(120000, 5000 * (2 ** farmStatusFailures));
        farmUpdateTimer = window.setTimeout(getFarmStatus, delay);
    }
}
function applyFarmStatus(data) {
    if (!data.success) {
        return;
    }
    if (data.device_id !== <?php echo json_encode($selectedDeviceId); ?>) return;
    document.querySelectorAll('[data-sensor-key]').forEach(element => {
        const value = (data.measurement_values || data.sensors)?.[element.dataset.sensorKey];
        element.textContent = value == null ? 'нет данных' : typeof value === 'boolean' ? (value ? 'включено' : 'выключено') : value + ' ' + element.dataset.unit;
    });
    document.querySelectorAll('[data-sensor-uid]').forEach(element => {
        const row = data.dynamic_values?.[element.dataset.sensorUid];
        element.textContent = row?.value == null ? 'нет данных' : row.value + ' ' + element.dataset.unit;
        const measured = row?.measured_at_unix ? new Date(Number(row.measured_at_unix) * 1000) : null;
        if (!measured || Date.now() - measured.getTime() > 300000) element.textContent += ' · устарело';
    });
    const originalContainer = document.querySelector('.farm-photo-container:first-child');
    if (data.photo && originalContainer) {
        const img = originalContainer.querySelector('img');
        if (img) {
            img.src = `/media/farm_photos/${encodeURIComponent(data.photo)}`;
        } else {
            const placeholder = originalContainer.querySelector('.farm-photo-placeholder');
            if (placeholder) {
                const newImg = document.createElement('img');
                newImg.src = `/media/farm_photos/${encodeURIComponent(data.photo)}`;
                newImg.alt = 'Фото фермы';
                newImg.className = 'farm-photo';
                newImg.loading = 'lazy';
                newImg.width = 320;
                newImg.height = 240;
                placeholder.replaceChildren(newImg);
            }
        }
    }
    const analysisContainer = document.querySelector('.farm-photo-container:nth-child(2)');
    if (data.photo_analysis && analysisContainer) {
        const img = analysisContainer.querySelector('img');
        if (img) {
            img.src = `/media/farm_photos/${encodeURIComponent(data.photo_analysis)}`;
        } else {
            const placeholder = analysisContainer.querySelector('.farm-photo-placeholder');
            if (placeholder) {
                const newImg = document.createElement('img');
                newImg.src = `/media/farm_photos/${encodeURIComponent(data.photo_analysis)}`;
                newImg.alt = 'Анализ фото';
                newImg.className = 'farm-photo';
                newImg.loading = 'lazy';
                newImg.width = 320;
                newImg.height = 240;
                placeholder.replaceChildren(newImg);
            }
        }
    }
    const commentElement = document.querySelector('.farm-status-text p');
    if (commentElement && data.comment) {
        commentElement.textContent = data.comment;
    }
    const lastUpdateElement = document.getElementById('lastUpdate');
    if (lastUpdateElement) {
        const lastMeasurement = Math.max(Number(data.measured_at_unix || 0), ...Object.values(data.dynamic_values || {}).map(row => Number(row.measured_at_unix || 0)));
        const measured = lastMeasurement ? new Date(lastMeasurement * 1000) : null;
        const stale = !measured || Date.now() - measured.getTime() > 300000;
        lastUpdateElement.textContent = measured
            ? measured.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) + (stale ? ' · устарело' : '')
            : 'нет измерений';
    }
}
async function getFarmStatus() {
    <?php if ($isGuest) : ?>
        return;
    <?php endif; ?>
    window.clearTimeout(farmUpdateTimer);
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch('/api/get-farm-status.json?device_id=' + encodeURIComponent(<?php echo json_encode($selectedDeviceId); ?>), {
            credentials: 'same-origin',
            cache: 'no-cache',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (response.status === 401) {
            farmPollingStopped = true;
            const lastUpdateElement = document.getElementById('lastUpdate');
            if (lastUpdateElement) {
                lastUpdateElement.textContent = 'нужен вход';
            }
            return;
        }
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        applyFarmStatus(await response.json());
        farmStatusFailures = 0;
    } catch (error) {
        farmStatusFailures += 1;
        const lastUpdateElement = document.getElementById('lastUpdate');
        if (lastUpdateElement) {
            lastUpdateElement.textContent = 'нет связи';
        }
    } finally {
        window.clearTimeout(timeout);
        scheduleFarmStatusUpdate();
    }
}
document.addEventListener('DOMContentLoaded', function() {
    getFarmStatus();
});
function toggleFarmStatus() {
    const content = document.getElementById('farmStatusContent');
    const icon = document.getElementById('farmStatusIcon');
    content.classList.toggle('active');
    icon.classList.toggle('rotate');
}
</script>
