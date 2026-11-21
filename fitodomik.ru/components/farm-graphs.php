<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_dynamic_config.php';
$isGuest = !isset($_SESSION['user_id']);
$user_id = $isGuest ? 0 : (int)$_SESSION['user_id'];
$selectedDeviceId = '';
function farmGraphJson($value)
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
}
if (!$isGuest) {
    try {
        $selectedDevice = fitodomikGetSelectedDevice($pdo, (int)$user_id);
        $selectedDeviceId = $selectedDevice['device_id'] ?? '';
    } catch (Exception $e) {
        error_log('farm-graphs device context: ' . $e->getMessage());
    }
}
function getDayData($pdo, $user_id, $type, $device_id = '')
{
    global $isGuest;
    $data = [];
    if (!$isGuest && in_array($type, ['temperature', 'humidity', 'soil_moisture', 'co2', 'pressure'], true)) {
        list($deviceWhere, $deviceParams) = fitodomikDeviceWhere($device_id);
        $query = "SELECT
                    DATE_FORMAT(MAX(created_at), '%H:%i') as time,
                    ROUND(AVG($type), 2) as value
                 FROM sensor_data
                 WHERE user_id = ?
                 $deviceWhere
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 AND $type IS NOT NULL
                 GROUP BY DATE(created_at), HOUR(created_at), MINUTE(created_at)
                 ORDER BY MAX(created_at)";
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute(array_merge([$user_id], $deviceParams));
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ошибка при получении данных за день: " . $e->getMessage());
        }
    }
    return $data;
}
function getWeekData($pdo, $user_id, $type, $device_id = '')
{
    global $isGuest;
    $data = [];
    if (!$isGuest && in_array($type, ['temperature', 'humidity', 'soil_moisture', 'co2', 'pressure'], true)) {
        list($deviceWhere, $deviceParams) = fitodomikDeviceWhere($device_id);
        $query = "SELECT
                    DATE_FORMAT(MAX(created_at), '%Y-%m-%d %H:%i') as datetime,
                    ROUND(AVG($type), 2) as value
                 FROM sensor_data
                 WHERE user_id = ?
                 $deviceWhere
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 AND $type IS NOT NULL
                 GROUP BY DATE(created_at), HOUR(created_at)
                 ORDER BY MAX(created_at)";
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute(array_merge([$user_id], $deviceParams));
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ошибка при получении данных за неделю: " . $e->getMessage());
        }
    }
    return $data;
}
$dynamicChartSensors = [];
$dynamicDayData = [];
$dynamicWeekData = [];
$dynamicChartConfig = [];
$graphLimits = [];
if (!$isGuest && $selectedDeviceId !== '') {
    $limitStmt = $pdo->prepare('SELECT parameter_type,min_limit,max_limit FROM alarm_thresholds WHERE user_id=? AND device_id=?');
    $limitStmt->execute([$user_id,$selectedDeviceId]);
    foreach ($limitStmt->fetchAll(PDO::FETCH_ASSOC) as $threshold) {
        $key = $threshold['parameter_type'] === 'humidity_soil' ? 'soil_moisture' : $threshold['parameter_type'];
        $graphLimits[$key] = ['min'=>(float)$threshold['min_limit'],'max'=>(float)$threshold['max_limit']];
    }
}
if (!$isGuest && $selectedDeviceId !== '') {
    try {
        $dynamicChartSensors = array_values(array_filter(
            fitodomikDynamicListSensors($pdo, $user_id, $selectedDeviceId, false),
            static function ($sensor) {
                return !empty($sensor['show_in_charts']) && !empty($sensor['send_to_server']);
            }
        ));
        $colors = [
            ['rgb(255, 99, 132)', 'rgba(255, 99, 132, 0.2)'],
            ['rgb(54, 162, 235)', 'rgba(54, 162, 235, 0.2)'],
            ['rgb(75, 192, 192)', 'rgba(75, 192, 192, 0.2)'],
            ['rgb(153, 102, 255)', 'rgba(153, 102, 255, 0.2)'],
            ['rgb(255, 159, 64)', 'rgba(255, 159, 64, 0.2)'],
            ['rgb(46, 125, 50)', 'rgba(46, 125, 50, 0.2)'],
        ];
        $chartUids = array_column($dynamicChartSensors, 'sensor_uid');
        $dynamicDayData = fitodomikDynamicSeries($pdo, $user_id, $selectedDeviceId, $chartUids, 24, 600);
        $dynamicWeekData = fitodomikDynamicSeries($pdo, $user_id, $selectedDeviceId, $chartUids, 168, 1000);
        foreach ($dynamicChartSensors as $index => $sensor) {
            $uid = $sensor['sensor_uid'];
            $color = $colors[$index % count($colors)];
            $dynamicDayData[$uid] ??= [];
            $dynamicWeekData[$uid] ??= [];
            $dynamicChartConfig[$uid] = [
                'label' => $sensor['name'],
                'unit' => $sensor['unit'],
                'borderColor' => $color[0],
                'backgroundColor' => $color[1],
            ];
        }
    } catch (Exception $e) {
        error_log('farm-graphs dynamic charts: ' . $e->getMessage());
        $dynamicChartSensors = [];
        $dynamicDayData = [];
        $dynamicWeekData = [];
        $dynamicChartConfig = [];
    }
}
?>
<button type="button" class="graphs-header accordion-toggle-button"
     onclick="toggleGraphs()" aria-controls="graphsContent" aria-expanded="false">
    <span class="header-title">Графики</span>
    <span class="header-right-content">
        <span class="accordion-icon">▼</span>
    </span>
</button>
<div class="graphs-content" id="graphsContent">
    <?php if ($isGuest) : ?>
    <div class="guest-notice-container">
        <div class="guest-notice">
            <p>Для просмотра реальных графиков вашей фермы необходимо <a href="authentication/login.php">авторизоваться</a>.</p>
        </div>
    </div>
    <?php endif; ?>
    <div class="data-info-container initially-hidden" id="dataInfoContainer">
        <div class="data-info">
            <p>Пустой график означает отсутствие данных за выбранный период.</p>
        </div>
    </div>
    <div class="graph-controls">
        <div class="graph-type-selector">
            <a class="timelapse-btn" href="/timelapse.php?period=week">Таймлапс</a>
        </div>
        <div class="graph-type-selector">
            <?php if (!empty($dynamicChartSensors)) : ?>
                <?php foreach ($dynamicChartSensors as $index => $sensor) : ?>
                    <button type="button" class="graph-btn <?php echo $index === 0 ? 'active' : ''; ?>" data-type="<?php echo htmlspecialchars($sensor['sensor_uid']); ?>"><?php echo htmlspecialchars($sensor['name']); ?></button>
                <?php endforeach; ?>
            <?php else : ?>
                <button type="button" class="graph-btn active" data-type="temperature">Температура</button>
                <button type="button" class="graph-btn" data-type="humidity">Влажность</button>
                <button type="button" class="graph-btn" data-type="soil_moisture">Влажность почвы</button>
                <button type="button" class="graph-btn" data-type="co2">CO₂</button>
                <button type="button" class="graph-btn" data-type="pressure">Давление</button>
            <?php endif; ?>
        </div>
        <div class="period-selector">
            <button type="button" class="period-btn active" data-period="day">За день</button>
            <button type="button" class="period-btn" data-period="week">За неделю</button>
        </div>
    </div>
    <div class="graph-display">
        <canvas id="farmChart"></canvas>
    </div>
    <div class="error-display initially-hidden">
        <h4>Обнаруженные проблемы:</h4>
        <ul id="errorList"></ul>
    </div>
</div>
<script>
const dayData = {
<?php if (!empty($dynamicChartSensors)) : ?>
    <?php foreach ($dynamicDayData as $uid => $rows) : ?>
        <?php echo farmGraphJson($uid); ?>: <?php echo farmGraphJson($rows); ?>,
    <?php endforeach; ?>
<?php else : ?>
    temperature: <?php echo farmGraphJson(getDayData($pdo, $user_id, 'temperature', $selectedDeviceId)); ?>,
    humidity: <?php echo farmGraphJson(getDayData($pdo, $user_id, 'humidity', $selectedDeviceId)); ?>,
    soil_moisture: <?php echo farmGraphJson(getDayData($pdo, $user_id, 'soil_moisture', $selectedDeviceId)); ?>,
    co2: <?php echo farmGraphJson(getDayData($pdo, $user_id, 'co2', $selectedDeviceId)); ?>,
    pressure: <?php echo farmGraphJson(getDayData($pdo, $user_id, 'pressure', $selectedDeviceId)); ?>
<?php endif; ?>
};
const weekData = {
<?php if (!empty($dynamicChartSensors)) : ?>
    <?php foreach ($dynamicWeekData as $uid => $rows) : ?>
        <?php echo farmGraphJson($uid); ?>: <?php echo farmGraphJson($rows); ?>,
    <?php endforeach; ?>
<?php else : ?>
    temperature: <?php echo farmGraphJson(getWeekData($pdo, $user_id, 'temperature', $selectedDeviceId)); ?>,
    humidity: <?php echo farmGraphJson(getWeekData($pdo, $user_id, 'humidity', $selectedDeviceId)); ?>,
    soil_moisture: <?php echo farmGraphJson(getWeekData($pdo, $user_id, 'soil_moisture', $selectedDeviceId)); ?>,
    co2: <?php echo farmGraphJson(getWeekData($pdo, $user_id, 'co2', $selectedDeviceId)); ?>,
    pressure: <?php echo farmGraphJson(getWeekData($pdo, $user_id, 'pressure', $selectedDeviceId)); ?>
<?php endif; ?>
};
const chartConfig = {
<?php if (!empty($dynamicChartSensors)) : ?>
    <?php foreach ($dynamicChartConfig as $uid => $config) : ?>
        <?php echo farmGraphJson($uid); ?>: <?php echo farmGraphJson($config); ?>,
    <?php endforeach; ?>
<?php else : ?>
    temperature: {
        label: 'Температура',
        unit: '°C',
        borderColor: 'rgb(255, 99, 132)',
        backgroundColor: 'rgba(255, 99, 132, 0.2)'
    },
    humidity: {
        label: 'Влажность',
        unit: '%',
        borderColor: 'rgb(54, 162, 235)',
        backgroundColor: 'rgba(54, 162, 235, 0.2)'
    },
    soil_moisture: {
        label: 'Влажность почвы',
        unit: '%',
        borderColor: 'rgb(139, 69, 19)',
        backgroundColor: 'rgba(139, 69, 19, 0.2)'
    },
    co2: {
        label: 'CO₂',
        unit: 'ppm',
        borderColor: 'rgb(75, 192, 192)',
        backgroundColor: 'rgba(75, 192, 192, 0.2)'
    },
    pressure: {
        label: 'Давление',
        unit: 'мм рт. ст.',
        borderColor: 'rgb(153, 102, 255)',
        backgroundColor: 'rgba(153, 102, 255, 0.2)'
    }
<?php endif; ?>
};
let currentChart = null;
let currentType = Object.keys(chartConfig)[0] || 'temperature';
let currentPeriod = 'day';
let chartJsLoadPromise = null;
let chartsInitialized = false;
function loadChartJs() {
    if (window.Chart) {
        return Promise.resolve();
    }
    if (chartJsLoadPromise) {
        return chartJsLoadPromise;
    }
    chartJsLoadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = '/static/js/lib/chart.umd.min.v6.js';
        script.onload = resolve;
        script.onerror = () => reject(new Error('Chart.js не загрузился'));
        document.body.appendChild(script);
    });
    return chartJsLoadPromise;
}
function showChartLoadError(message) {
    const errorDisplay = document.querySelector('.graphs-content .error-display');
    const errorList = document.getElementById('errorList');
    if (!errorDisplay || !errorList) return;
    errorList.innerHTML = '';
    const li = document.createElement('li');
    li.textContent = message;
    errorList.appendChild(li);
    errorDisplay.style.display = 'block';
}
function updateChart() {
    if (!window.Chart) {
        return;
    }
    const chartCanvas = document.getElementById('farmChart');
    if (!chartCanvas) {
        return;
    }
    const ctx = chartCanvas.getContext('2d');
    const config = chartConfig[currentType];
    if (!config) {
        showChartLoadError('Датчики для графиков ещё не добавлены');
        return;
    }
    const data = currentPeriod === 'day' ? dayData[currentType] : weekData[currentType];
    const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
    const dataInfoContainer = document.getElementById('dataInfoContainer');
    if (currentChart) {
        currentChart.destroy();
    }
    if (!data || data.length === 0) {
        const errorDisplay = document.querySelector('.error-display');
        const errorList = document.getElementById('errorList');
        errorList.innerHTML = '<li>Нет данных для выбранного периода</li>';
        errorDisplay.style.display = 'block';
        dataInfoContainer.style.display = 'block';
        currentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Нет данных'],
                datasets: [{
                    label: config.label,
                    data: [],
                    backgroundColor: 'rgba(200, 200, 200, 0.2)',
                    borderColor: 'rgba(200, 200, 200, 1)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: `${config.label} ${currentPeriod === 'day' ? 'за день' : 'за неделю'} - Данные отсутствуют`,
                        color: isDarkTheme ? '#fff' : '#666',
                        font: {
                            size: 16
                        }
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });
        return;
    } else {
        dataInfoContainer.style.display = 'none';
    }
    let chartData;
    if (currentPeriod === 'day') {
        chartData = {
            labels: data.map(item => item.time),
            datasets: [{
                label: config.label,
                data: data.map(item => item.value),
                borderColor: config.borderColor,
                backgroundColor: 'transparent',
                tension: 0.4,
                fill: false
            }]
        };
    } else {
        chartData = {
            labels: data.map(item => item.datetime),
            datasets: [{
                label: config.label,
                data: data.map(item => item.value),
                borderColor: config.borderColor,
                backgroundColor: 'transparent',
                tension: 0.4,
                fill: false
            }]
        };
    }
    currentChart = new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: `${config.label} ${currentPeriod === 'day' ? 'за день' : 'за неделю'}`,
                    color: isDarkTheme ? '#fff' : '#666',
                    font: {
                        size: 16
                    }
                },
                legend: {
                    display: currentPeriod === 'day',
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        color: isDarkTheme ? '#fff' : '#666',
                        font: {
                            size: 12
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grid: {
                        color: isDarkTheme ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                    },
                    ticks: {
                        color: isDarkTheme ? '#fff' : '#666'
                    },
                    title: {
                        display: true,
                        text: config.unit,
                        color: isDarkTheme ? '#fff' : '#666'
                    }
                },
                x: {
                    grid: {
                        color: isDarkTheme ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                    },
                    ticks: {
                        color: isDarkTheme ? '#fff' : '#666',
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            elements: {
                point: {
                    radius: 3,
                    hoverRadius: 5
                },
                line: {
                    tension: 0.4
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });
    updateErrors(data);
}
function updateErrors(data) {
    const errorList = document.getElementById('errorList');
    const errorDisplay = document.querySelector('.error-display');
    const limits = <?php echo farmGraphJson($graphLimits); ?>;
    const errors = new Set();
    data.forEach(item => {
        const value = parseFloat(item.value);
        if (!limits[currentType] || !Number.isFinite(value)) return;
        switch(currentType) {
            case 'temperature':
                if (value < limits.temperature.min || value > limits.temperature.max) {
                    errors.add(`Температура ${value}°C выходит за пределы (${limits.temperature.min}°C - ${limits.temperature.max}°C)`);
                }
                break;
            case 'humidity':
                if (value < limits.humidity.min || value > limits.humidity.max) {
                    errors.add(`Влажность ${value}% выходит за пределы (${limits.humidity.min}% - ${limits.humidity.max}%)`);
                }
                break;
            case 'soil_moisture':
                if (value < limits.soil_moisture.min || value > limits.soil_moisture.max) {
                    errors.add(`Влажность почвы ${value}% выходит за пределы (${limits.soil_moisture.min}% - ${limits.soil_moisture.max}%)`);
                }
                break;
            case 'co2':
                if (value < limits.co2.min || value > limits.co2.max) {
                    errors.add(`CO₂ ${value}ppm выходит за пределы (${limits.co2.min}ppm - ${limits.co2.max}ppm)`);
                }
                break;
            default:
                break;
        }
    });
    errorList.innerHTML = '';
    if (errors.size > 0) {
        errors.forEach(error => {
            const li = document.createElement('li');
            li.textContent = error;
            errorList.appendChild(li);
        });
        errorDisplay.style.display = 'block';
    } else {
        errorDisplay.style.display = 'none';
    }
}
function shiftColor(color, degree) {
    if (color.startsWith('rgb')) {
        const matches = color.match(/\d+/g);
        return `rgba(${matches[0]}, ${matches[1]}, ${matches[2]}, ${0.6 - degree/360})`;
    }
    return color;
}
function initCharts() {
    if (chartsInitialized) {
        updateChart();
        return;
    }
    chartsInitialized = true;
    document.querySelectorAll('.graph-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const activeButton = document.querySelector('.graph-btn.active');
            if (activeButton) activeButton.classList.remove('active');
            this.classList.add('active');
            currentType = this.dataset.type;
            updateChart();
        });
    });
    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const activeButton = document.querySelector('.period-btn.active');
            if (activeButton) activeButton.classList.remove('active');
            this.classList.add('active');
            currentPeriod = this.dataset.period;
            updateChart();
        });
    });
    updateChart();
}
function handleDataErrors() {
    console.log('Checking data availability...');
    return true;
}
document.addEventListener('themeChanged', function(e) {
    if (currentChart) {
        updateChart();
    }
});
async function toggleGraphs() {
    const content = document.getElementById('graphsContent');
    const icon = document.querySelector('.graphs-header .accordion-icon');
    const toggle = document.querySelector('.graphs-header');
    content.classList.toggle('active');
    icon.classList.toggle('rotate');
    if (toggle) {
        toggle.setAttribute('aria-expanded', content.classList.contains('active') ? 'true' : 'false');
    }
    if (!content.classList.contains('active')) {
        return;
    }
    try {
        await loadChartJs();
        if (content.classList.contains('active')) {
            initCharts();
        }
    } catch (error) {
        console.warn(error.message);
        showChartLoadError('Не удалось загрузить библиотеку графиков. Попробуйте обновить страницу.');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const graphsContent = document.getElementById('graphsContent');
    const graphsIcon = document.querySelector('.graphs-header .accordion-icon');
    if (graphsContent && graphsIcon) {
    }
});
</script>
<style>
.graphs-header {
    width: 100%;
    margin-top: 20px;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    border: 0;
    border-radius: 8px 8px 0 0;
    background: var(--accent-surface);
    color: white;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    font: inherit;
    text-align: left;
    transition: background-color 0.2s ease;
}
.graphs-header:hover {
    background: var(--accent-surface-hover);
}
.graphs-header:focus-visible {
    outline: 3px solid rgba(var(--primary-rgb), 0.28);
    outline-offset: 2px;
}
.graphs-header .header-title {
    font-size: 18px;
    font-weight: 700;
}
.header-right-content {
    display: inline-flex;
    align-items: center;
}
.accordion-icon {
    transition: transform 0.3s ease;
}
.accordion-icon.rotate {
    transform: rotate(180deg);
}
.graphs-content {
    display: none;
    padding: 20px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    border-top: 0;
    border-radius: 0 0 8px 8px;
    background: var(--card-bg);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.08);
}
.graphs-content.active {
    display: block;
}
.graph-controls {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 12px;
    align-items: start;
    margin-bottom: 20px;
}
.graph-type-selector,
.period-selector {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    min-width: 0;
}
.graph-btn,
.period-btn,
.timelapse-btn {
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 42px;
    min-height: 42px;
    padding: 9px 14px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--button-bg);
    color: var(--text-color);
    cursor: pointer;
    font: inherit;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.25;
    text-align: center;
    text-decoration: none;
    white-space: nowrap;
    transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}
.graph-btn:hover,
.period-btn:hover {
    border-color: var(--primary-color);
    background: var(--hover-color);
}
.graph-btn:focus-visible,
.period-btn:focus-visible,
.timelapse-btn:focus-visible {
    outline: 3px solid rgba(var(--primary-rgb), 0.24);
    outline-offset: 2px;
}
.graph-btn.active,
.period-btn.active {
    border-color: var(--accent-surface);
    background: var(--accent-surface);
    color: #ffffff;
}
.timelapse-btn {
    border-color: var(--secondary-color);
    color: var(--secondary-color);
}
.timelapse-btn:hover {
    border-color: var(--secondary-color);
    background: rgba(23, 105, 170, 0.1);
}
.graph-display {
    position: relative;
    width: 100%;
    height: clamp(300px, 48vw, 420px);
    min-height: 300px;
    padding: 16px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--background);
    box-sizing: border-box;
}
.graph-display canvas {
    max-width: 100%;
    max-height: 100%;
}
.error-display {
    margin-top: 16px;
    padding: 14px 16px;
    border: 1px solid rgba(180, 35, 24, 0.28);
    border-radius: 8px;
    background: rgba(180, 35, 24, 0.08);
    color: #a8241a;
}
[data-theme="dark"] .error-display {
    color: #ffaaa3;
}
.error-display h4 {
    margin: 0 0 8px;
    color: inherit;
}
.error-display ul {
    margin: 0;
    padding-left: 20px;
}
.error-display li + li {
    margin-top: 5px;
}
.guest-notice-container {
    width: 100%;
    margin-bottom: 20px;
    box-sizing: border-box;
}
.guest-notice {
    background-color: rgba(255, 193, 7, 0.2);
    border-left: 4px solid #d39e00;
    padding: 10px 15px;
    border-radius: 4px;
    width: 100%;
    box-sizing: border-box;
}
.guest-notice p {
    margin: 0;
    color: var(--text-color);
    white-space: normal;
    overflow-wrap: break-word;
}
.guest-notice a {
    color: var(--primary-color);
    text-decoration: none;
    font-weight: bold;
}
.guest-notice a:hover {
    text-decoration: underline;
}
.data-info-container {
    width: 100%;
    margin-bottom: 20px;
    box-sizing: border-box;
}
.data-info {
    background-color: rgba(13, 110, 253, 0.1);
    border-left: 4px solid #0d6efd;
    padding: 10px 15px;
    border-radius: 4px;
    width: 100%;
    box-sizing: border-box;
}
.data-info p {
    margin: 0;
    color: var(--text-color);
    white-space: normal;
    overflow-wrap: break-word;
}
.initially-hidden {
    display: none;
}
@media (max-width: 900px) {
    .graph-controls {
        grid-template-columns: 1fr;
    }
    .graph-type-selector,
    .period-selector {
        width: 100%;
    }
    .graph-btn,
    .period-btn,
    .timelapse-btn {
        flex: 1 1 auto;
    }
}
@media (max-width: 520px) {
    .graphs-header {
        padding: 13px 15px;
    }
    .graphs-content {
        padding: 14px;
    }
    .graph-controls {
        gap: 10px;
        margin-bottom: 14px;
    }
    .graph-btn,
    .period-btn,
    .timelapse-btn {
        height: auto;
        min-height: 44px;
        min-width: calc(50% - 4px);
        white-space: normal;
    }
    .graph-display {
        height: 320px;
        min-height: 320px;
        padding: 10px;
    }
}
</style>
