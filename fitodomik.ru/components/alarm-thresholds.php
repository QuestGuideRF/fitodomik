<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_context.php';
$isGuest = !isset($_SESSION['user_id']);
$user_id = $isGuest ? 0 : (int)$_SESSION['user_id'];
$selectedDevice = null;
$notificationSettings = null;
if (!$isGuest) {
    try {
        $selectedDevice = fitodomikGetSelectedDevice($pdo, (int)$user_id);
        if ($selectedDevice) {
            $notificationSettings = fitodomikGetOrCreateNotificationSettings($pdo, (int)$user_id, $selectedDevice['device_id']);
        }
    } catch (Exception $e) {
        error_log('alarm-thresholds notifications: ' . $e->getMessage());
    }
}
$stmt = $pdo->prepare("
    SELECT parameter_type, min_limit, max_limit, target_value, tolerance
    FROM alarm_thresholds
    WHERE user_id = ? AND device_id = ?
");
$stmt->execute([$user_id, (string)($selectedDevice['device_id'] ?? '')]);
$thresholds = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $thresholds[$row['parameter_type']] = $row;
}
if (!isset($thresholds['temperature'])) {
    $thresholds['temperature'] = [
        'min_limit' => 20.00,
        'max_limit' => 30.00,
        'target_value' => 25.00,
        'tolerance' => 2.00
    ];
}
if (!isset($thresholds['humidity_air'])) {
    $thresholds['humidity_air'] = [
        'min_limit' => 50.00,
        'max_limit' => 70.00,
        'target_value' => 60.00,
        'tolerance' => 5.00
    ];
}
if (!isset($thresholds['humidity_soil'])) {
    $thresholds['humidity_soil'] = [
        'min_limit' => 30.00,
        'max_limit' => 70.00,
        'target_value' => 50.00,
        'tolerance' => 5.00
    ];
}
if (!isset($thresholds['co2'])) {
    $thresholds['co2'] = [
        'min_limit' => 200.00,
        'max_limit' => 1000.00,
        'target_value' => 600.00,
        'tolerance' => 100.00
    ];
}
function formatNumberNoCommas($number)
{
    return round($number);
}
?>
<button type="button" class="graphs-header accordion-toggle-button" id="alarmHeader"
     onclick="toggleAlarmContent()">
    <span class="header-title">Пороги тревоги</span>
    <div class="header-right-content">
        <span class="accordion-icon" id="alarmIcon">▼</span>
    </div>
</button>
<div class="graphs-content" id="alarmContent" itemscope itemtype="https://schema.org/ControlAction">
    <meta itemprop="name" content="Настройка порогов тревоги">
    <meta itemprop="description" content="Настройка допустимых пределов параметров микроклимата для умной фермы">
    <meta itemprop="controlledProperty" content="EnvironmentalConditions">
    <?php if ($isGuest) : ?>
    <div class="guest-notice">
        <p>Настройка порогов тревог доступна только для авторизованных пользователей. Пожалуйста, <a href="authentication/login.php">авторизуйтесь</a> для доступа.</p>
    </div>
    <?php else : ?>
        <?php if ($selectedDevice && $notificationSettings) : ?>
    <form id="notification-settings-form" class="notification-settings-form">
        <input type="hidden" name="device_id" value="<?php echo htmlspecialchars($selectedDevice['device_id']); ?>">
        <div class="notification-header">
            <div>
                <h3>Настройки Telegram</h3>
                <p>Устройство: <strong><?php echo htmlspecialchars($selectedDevice['device_name']); ?></strong></p>
            </div>
            <button type="submit" class="btn btn-save notification-save">Сохранить уведомления</button>
        </div>
        <div class="notification-grid">
            <label><input type="checkbox" name="telegram_enabled" <?php echo $notificationSettings['telegram_enabled'] ? 'checked' : ''; ?>> Telegram-уведомления</label>
            <label><input type="checkbox" name="notify_soil_moisture" <?php echo $notificationSettings['notify_soil_moisture'] ? 'checked' : ''; ?>> Уведомлять о влажности почвы</label>
            <label><input type="checkbox" name="notify_temperature" <?php echo $notificationSettings['notify_temperature'] ? 'checked' : ''; ?>> Уведомлять о температуре</label>
            <label><input type="checkbox" name="notify_camera" <?php echo $notificationSettings['notify_camera'] ? 'checked' : ''; ?>> Уведомлять о проблемах с камерой</label>
            <label><input type="checkbox" name="send_photos_telegram" <?php echo $notificationSettings['send_photos_telegram'] ? 'checked' : ''; ?>> Отправлять фото в Telegram</label>
            <label><input type="checkbox" name="send_daily_report" <?php echo $notificationSettings['send_daily_report'] ? 'checked' : ''; ?>> Ежедневный отчёт</label>
        </div>
        <div class="notification-fields">
            <label>Время ежедневного отчёта
                <input type="time" name="daily_report_time" value="<?php echo htmlspecialchars($notificationSettings['daily_report_time']); ?>">
            </label>
            <label>Проверять тревоги каждые, минут
                <input type="number" name="alert_check_interval_minutes" min="1" max="1440" value="<?php echo (int)$notificationSettings['alert_check_interval_minutes']; ?>">
            </label>
            <label>Повторять тревогу не чаще, минут
                <input type="number" name="alert_repeat_cooldown_minutes" min="1" max="10080" value="<?php echo (int)$notificationSettings['alert_repeat_cooldown_minutes']; ?>">
            </label>
        </div>
        <div id="notificationSettingsStatus" class="notification-status"></div>
    </form>
        <?php else : ?>
        <div class="guest-notice">
            <p>Чтобы настроить Telegram-уведомления, привяжите и выберите устройство.</p>
        </div>
        <?php endif; ?>
    <form id="limits-form" class="alarm-settings-form" itemprop="potentialAction" itemscope itemtype="https://schema.org/UpdateAction">
        <meta itemprop="target" content="api/save-limits.php">
        <meta itemprop="actionStatus" content="PotentialActionStatus">
        <div class="form-group alarm-threshold-card" itemprop="object" itemscope itemtype="https://schema.org/PropertyValue">
            <meta itemprop="propertyID" content="temperature">
            <h3 itemprop="name">Температура воздуха (°C)</h3>
            <p class="current-limits">Текущие пороги: <span itemprop="minValue"><?php echo formatNumberNoCommas($thresholds['temperature']['min_limit']); ?></span> - <span itemprop="maxValue"><?php echo formatNumberNoCommas($thresholds['temperature']['max_limit']); ?></span></p>
            <div class="input-row alarm-threshold-row">
                <div class="input-group alarm-threshold-field">
                    <label for="temp-min">Минимум:</label>
                    <input type="text" id="temp-min" name="temp-min" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['temperature']['min_limit']); ?>" required title="Минимальное значение температуры" aria-label="Минимальное значение температуры" placeholder="Введите минимум">
                </div>
                <div class="input-group alarm-threshold-field">
                    <label for="temp-max">Максимум:</label>
                    <input type="text" id="temp-max" name="temp-max" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['temperature']['max_limit']); ?>" required title="Максимальное значение температуры" aria-label="Максимальное значение температуры" placeholder="Введите максимум">
                </div>
            </div>
            <meta itemprop="unitCode" content="CEL">
        </div>
        <div class="form-group alarm-threshold-card" itemprop="object" itemscope itemtype="https://schema.org/PropertyValue">
            <meta itemprop="propertyID" content="humidity_air">
            <h3 itemprop="name">Влажность воздуха (%)</h3>
            <p class="current-limits">Текущие пороги: <span itemprop="minValue"><?php echo formatNumberNoCommas($thresholds['humidity_air']['min_limit']); ?></span> - <span itemprop="maxValue"><?php echo formatNumberNoCommas($thresholds['humidity_air']['max_limit']); ?></span></p>
            <div class="input-row alarm-threshold-row">
                <div class="input-group alarm-threshold-field">
                    <label for="humidity-min">Минимум:</label>
                    <input type="text" id="humidity-min" name="humidity-min" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['humidity_air']['min_limit']); ?>" required title="Минимальное значение влажности воздуха" aria-label="Минимальное значение влажности воздуха" placeholder="Введите минимум">
                </div>
                <div class="input-group alarm-threshold-field">
                    <label for="humidity-max">Максимум:</label>
                    <input type="text" id="humidity-max" name="humidity-max" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['humidity_air']['max_limit']); ?>" required title="Максимальное значение влажности воздуха" aria-label="Максимальное значение влажности воздуха" placeholder="Введите максимум">
                </div>
            </div>
            <meta itemprop="unitCode" content="P1">
        </div>
        <div class="form-group alarm-threshold-card" itemprop="object" itemscope itemtype="https://schema.org/PropertyValue">
            <meta itemprop="propertyID" content="humidity_soil">
            <h3 itemprop="name">Влажность почвы (%)</h3>
            <p class="current-limits">Текущие пороги: <span itemprop="minValue"><?php echo formatNumberNoCommas($thresholds['humidity_soil']['min_limit']); ?></span> - <span itemprop="maxValue"><?php echo formatNumberNoCommas($thresholds['humidity_soil']['max_limit']); ?></span></p>
            <div class="input-row alarm-threshold-row">
                <div class="input-group alarm-threshold-field">
                    <label for="soil-moisture-min">Минимум:</label>
                    <input type="text" id="soil-moisture-min" name="soil-moisture-min" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['humidity_soil']['min_limit']); ?>" required title="Минимальное значение влажности почвы" aria-label="Минимальное значение влажности почвы" placeholder="Введите минимум">
                </div>
                <div class="input-group alarm-threshold-field">
                    <label for="soil-moisture-max">Максимум:</label>
                    <input type="text" id="soil-moisture-max" name="soil-moisture-max" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['humidity_soil']['max_limit']); ?>" required title="Максимальное значение влажности почвы" aria-label="Максимальное значение влажности почвы" placeholder="Введите максимум">
                </div>
            </div>
            <meta itemprop="unitCode" content="P1">
        </div>
        <div class="form-group alarm-threshold-card" itemprop="object" itemscope itemtype="https://schema.org/PropertyValue">
            <meta itemprop="propertyID" content="co2">
            <h3 itemprop="name">CO2 (ppm)</h3>
            <p class="current-limits">Текущие пороги: <span itemprop="minValue"><?php echo formatNumberNoCommas($thresholds['co2']['min_limit']); ?></span> - <span itemprop="maxValue"><?php echo formatNumberNoCommas($thresholds['co2']['max_limit']); ?></span></p>
            <div class="input-row alarm-threshold-row">
                <div class="input-group alarm-threshold-field">
                    <label for="co2-min">Минимум:</label>
                    <input type="text" id="co2-min" name="co2-min" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['co2']['min_limit']); ?>" required title="Минимальное значение CO2" aria-label="Минимальное значение CO2" placeholder="Введите минимум">
                </div>
                <div class="input-group alarm-threshold-field">
                    <label for="co2-max">Максимум:</label>
                    <input type="text" id="co2-max" name="co2-max" pattern="[0-9]+" value="<?php echo formatNumberNoCommas($thresholds['co2']['max_limit']); ?>" required title="Максимальное значение CO2" aria-label="Максимальное значение CO2" placeholder="Введите максимум">
                </div>
            </div>
            <meta itemprop="unitCode" content="PPM">
        </div>
        <button type="submit" class="btn btn-save" itemprop="instrument">Сохранить</button>
        <meta itemprop="dateModified" content="<?php echo date('c'); ?>">
    </form>
    <?php endif; ?>
</div>
<style>
.graphs-content{display:none;max-width:100%;min-width:0;padding:20px;background:var(--card-bg);margin-top:20px;border-radius:8px;box-sizing:border-box}
.graphs-content.active{display:block}
.graphs-header{margin-top:20px}
.accordion-icon{transition:transform 0.3s ease}
.accordion-icon.rotate{transform:rotate(180deg)}
.alarm-settings-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:stretch;min-width:0}
.alarm-settings-form .alarm-threshold-card{display:flex;min-width:0;min-height:180px;height:100%;margin-bottom:20px;padding:18px;flex-direction:column;border:1px solid var(--border-color);border-radius:14px;background:var(--background);box-sizing:border-box}
.alarm-settings-form .alarm-threshold-card h3{margin:0 0 10px;font-size:16px;color:var(--text-color);overflow-wrap:anywhere}
.alarm-settings-form .alarm-threshold-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:auto;min-width:0}
.alarm-settings-form .alarm-threshold-field{display:flex;min-width:0;width:100%;flex-direction:column}
.alarm-settings-form .btn-save{grid-column:1 / -1;justify-self:end;float:none;width:auto}
.alarm-settings-form .alarm-threshold-field label{display:block;margin-bottom:5px;color:var(--text-color)}
.alarm-settings-form .alarm-threshold-field input{width:100%;max-width:100%;min-width:0;min-height:44px;padding:8px;border:1px solid var(--border-color);background:var(--input-bg);color:var(--text-color);box-sizing:border-box}
[data-theme="light"] .alarm-settings-form .alarm-threshold-field input{background:var(--input-bg)}
.current-limits{margin:5px 0;color:var(--secondary-text)}
.btn-save{background-color:var(--accent-surface);color:white;padding:12px 24px;border:none;border-radius:8px;font-size:16px;cursor:pointer;margin-top:20px;width:200px;float:right}
.guest-notice{background-color:rgba(255,193,7,0.2);border-left:4px solid #f0a500;padding:10px 15px;margin-bottom:20px;border-radius:4px}
.guest-notice p{margin:0;color:var(--text-color)}
.guest-notice a{color:var(--secondary-color);font-weight:700;text-decoration:none}
.guest-notice a:hover{text-decoration:underline}
button[disabled]{background-color:#9ca3af;cursor:not-allowed}
@media (max-width:900px){.alarm-settings-form{grid-template-columns:minmax(0,1fr)}}
@media (max-width:720px){.alarm-settings-form .alarm-threshold-card{margin-bottom:0;min-height:0}.alarm-settings-form .btn-save{width:100%;max-width:100%;min-height:44px;margin-top:12px}.current-limits{font-size:0.9rem;margin-bottom:15px}.graphs-content{padding:14px}}
@media (max-width:420px){.alarm-settings-form .alarm-threshold-row{grid-template-columns:minmax(0,1fr)}}
.alarm-settings-form .alarm-threshold-card h3{font-size:1.1rem;margin-bottom:15px;font-weight:600}
.alarm-settings-form .alarm-threshold-field label{font-size:0.95rem;margin-bottom:8px}
.alarm-settings-form .alarm-threshold-field input{font-size:1rem;padding:10px}
.current-limits{font-size:0.9rem;opacity:0.8;margin:10px 0}
.btn-save{transition:background-color 0.2s ease;font-weight:500}
.btn-save:hover:not([disabled]){background-color:var(--accent-surface-hover)}
.btn-save:active:not([disabled]){transform:translateY(1px)}
[data-theme="dark"] .alarm-settings-form .alarm-threshold-field input{background:var(--input-bg);color:var(--text-color);border-color:var(--border-color)}
[data-theme="dark"] .current-limits{color:var(--secondary-text)}
.notification-settings-form{padding:18px;margin-bottom:22px;border:1px solid var(--border-color);border-radius:14px;background:var(--background);box-sizing:border-box}
.notification-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}
.notification-header h3{margin:0 0 6px;color:var(--text-color)}
.notification-header p{margin:0;color:var(--secondary-text)}
.notification-save{float:none;width:auto;margin:0}
.notification-grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:10px;margin-bottom:14px;align-items:stretch}
.notification-grid label{display:flex;align-items:center;gap:8px;min-height:48px;padding:10px;border-radius:10px;background:var(--card-bg);border:1px solid var(--border-color);color:var(--text-color);box-sizing:border-box}
.notification-fields{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px}
.notification-fields label{display:flex;flex-direction:column;gap:6px;color:var(--text-color);font-weight:600}
.notification-fields input{padding:9px;border:1px solid var(--border-color);border-radius:8px;background:var(--input-bg);color:var(--text-color)}
.notification-status{margin-top:10px;color:var(--secondary-text);font-weight:600}
@media (max-width:900px){.notification-grid,.notification-fields{grid-template-columns:repeat(2,minmax(180px,1fr))}}
@media (max-width:640px){.notification-header{flex-direction:column}.notification-save{width:100%}.notification-grid,.notification-fields{grid-template-columns:1fr}}
</style>
<script>
document.getElementById('notification-settings-form')?.addEventListener('submit',async function(e){
    e.preventDefault();
    const form=e.currentTarget;
    const status=document.getElementById('notificationSettingsStatus');
    const data=Object.fromEntries(new FormData(form).entries());
    ['telegram_enabled','notify_soil_moisture','notify_temperature','notify_camera','send_photos_telegram','send_daily_report'].forEach(function(key){
        data[key]=form.elements[key]?.checked ? 1 : 0;
    });
    status.textContent='Сохраняю настройки...';
    try{
        const response=await fetch('api/save_device_notification_settings.php',{
            method:'POST',
            headers:{
                'Content-Type':'application/json',
                'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''
            },
            body:JSON.stringify(data)
        });
        const result=await response.json();
        status.textContent=result.success ? 'Настройки уведомлений сохранены' : (result.message || 'Ошибка сохранения уведомлений');
    }catch(error){
        status.textContent='Ошибка связи с сервером';
        console.error(error);
    }
});
document.getElementById('limits-form')?.addEventListener('submit',async function(e){
    e.preventDefault();
    const tempMinInput=document.getElementById('temp-min').value;
    const tempMaxInput=document.getElementById('temp-max').value;
    const humidityMinInput=document.getElementById('humidity-min').value;
    const humidityMaxInput=document.getElementById('humidity-max').value;
    const soilMoistureMinInput=document.getElementById('soil-moisture-min').value;
    const soilMoistureMaxInput=document.getElementById('soil-moisture-max').value;
    const co2MinInput=document.getElementById('co2-min').value;
    const co2MaxInput=document.getElementById('co2-max').value;
    if(!/^\d+$/.test(tempMinInput)||!/^\d+$/.test(tempMaxInput)||!/^\d+$/.test(humidityMinInput)||!/^\d+$/.test(humidityMaxInput)||!/^\d+$/.test(soilMoistureMinInput)||!/^\d+$/.test(soilMoistureMaxInput)||!/^\d+$/.test(co2MinInput)||!/^\d+$/.test(co2MaxInput)){
        alert('Пожалуйста, введите только целые числа без дробных частей, букв и специальных символов');
        return;
    }
    const tempMin=parseInt(tempMinInput);
    const tempMax=parseInt(tempMaxInput);
    const humidityMin=parseInt(humidityMinInput);
    const humidityMax=parseInt(humidityMaxInput);
    const soilMoistureMin=parseInt(soilMoistureMinInput);
    const soilMoistureMax=parseInt(soilMoistureMaxInput);
    const co2Min=parseInt(co2MinInput);
    const co2Max=parseInt(co2MaxInput);
    if(tempMin>=tempMax){
        alert('Минимальная температура должна быть меньше максимальной');
        return;
    }
    if(humidityMin>=humidityMax){
        alert('Минимальная влажность воздуха должна быть меньше максимальной');
        return;
    }
    if(soilMoistureMin>=soilMoistureMax){
        alert('Минимальная влажность почвы должна быть меньше максимальной');
        return;
    }
    if(co2Min>=co2Max){
        alert('Минимальный уровень CO2 должен быть меньше максимального');
        return;
    }
    const data={
        temperature:{min:tempMin,max:tempMax},
        humidity:{min:humidityMin,max:humidityMax},
        soil_moisture:{min:soilMoistureMin,max:soilMoistureMax},
        co2:{min:co2Min,max:co2Max}
    };
    try{
        const response=await fetch('api/save-limits.php',{
            method:'POST',
            headers:{
                'Content-Type':'application/json',
                'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''
            },
            body:JSON.stringify(data)
        });
        const result=await response.json();
        if(result.success){
            alert('Настройки сохранены');
            document.querySelectorAll('.current-limits').forEach(function(el,index){
                if(index===0){
                    el.textContent='Текущие пороги: '+tempMin+' - '+tempMax;
                }else if(index===1){
                    el.textContent='Текущие пороги: '+humidityMin+' - '+humidityMax;
                }else if(index===2){
                    el.textContent='Текущие пороги: '+soilMoistureMin+' - '+soilMoistureMax;
                }else if(index===3){
                    el.textContent='Текущие пороги: '+co2Min+' - '+co2Max;
                }
            });
        }else{
            alert('Ошибка при сохранении: '+(result.error||'Неизвестная ошибка'));
            console.error(result.error);
        }
    }catch(error){
        alert('Ошибка при сохранении');
        console.error('Error:',error);
    }
});
document.addEventListener('DOMContentLoaded',function(){
    const numberInputs=document.querySelectorAll('input[pattern="[0-9]+"]');
    numberInputs.forEach(function(input){
        input.addEventListener('input',function(e){
            this.value=this.value.replace(/[^\d]/g,'');
        });
        input.addEventListener('paste',function(e){
            const pastedText=(e.clipboardData||window.clipboardData).getData('text');
            const cleanedText=pastedText.replace(/[^\d]/g,'');
            e.preventDefault();
            this.value=cleanedText;
        });
    });
    const farmSettingsContent=document.getElementById('farmSettingsContent');
    if(farmSettingsContent){
        const farmSettingsIcon=document.querySelector('.farm-settings-header .accordion-icon');
        function checkFarmSettingsState(){
            if(farmSettingsContent.classList.contains('active')){
                farmSettingsIcon.classList.add('rotate');
            }else{
                farmSettingsIcon.classList.remove('rotate');
            }
        }
        checkFarmSettingsState();
        const observer=new MutationObserver(function(mutations){
            mutations.forEach(function(mutation){
                if(mutation.attributeName==='class'){
                    checkFarmSettingsState();
                }
            });
        });
        observer.observe(farmSettingsContent,{attributes:true});
    }
});
function toggleAlarmContent(){
    const content=document.getElementById('alarmContent');
    const icon=document.getElementById('alarmIcon');
    content.classList.toggle('active');
    icon.classList.toggle('rotate');
}
</script>
