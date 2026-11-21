<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/device_context.php';
require_once __DIR__ . '/../config/site.php';
const EVENT_DATETIME_FORMAT = 'Y-m-d H:i:s';
$isGuest = !isset($_SESSION['user_id']);
$user_id = $isGuest ? 0 : (int)$_SESSION['user_id'];
$guestEvents = [];
if ($isGuest) {
    $guestEvents = [
        [
            'id' => 1001,
            'user_id' => 1,
            'event_type' => 'temperature',
            'event_description' => 'Тревога: Повышение температуры выше допустимого предела (30.5°C)',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-2 hours'))
        ],
        [
            'id' => 1002,
            'user_id' => 1,
            'event_type' => 'humidity',
            'event_description' => 'Тревога: Снижение влажности ниже допустимого предела (35%)',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-5 hours'))
        ],
        [
            'id' => 1003,
            'user_id' => 1,
            'event_type' => 'device',
            'event_description' => 'Тревога: Отключение системы полива',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-1 day'))
        ],
        [
            'id' => 1004,
            'user_id' => 1,
            'event_type' => 'device',
            'event_description' => 'Система запущена',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-1 hour'))
        ],
        [
            'id' => 1005,
            'user_id' => 1,
            'event_type' => 'lighting',
            'event_description' => 'Установлен режим освещения: 16 часов в сутки',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-4 hours'))
        ],
        [
            'id' => 1006,
            'user_id' => 1,
            'event_type' => 'device',
            'event_description' => 'Перезагрузка контроллера',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-2 days'))
        ],
        [
            'id' => 1007,
            'user_id' => 1,
            'event_type' => 'device',
            'event_description' => 'Информационное событие: Установлена влажность 55%',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-3 hours'))
        ],
        [
            'id' => 1008,
            'user_id' => 1,
            'event_type' => 'device',
            'event_description' => 'Информационное событие: Включен экономичный режим',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-8 hours'))
        ],
        [
            'id' => 1009,
            'user_id' => 1,
            'event_type' => 'device',
            'event_description' => 'Информационное событие: Завершен цикл полива',
            'created_at' => date(EVENT_DATETIME_FORMAT, strtotime('-2 days 3 hours'))
        ]
    ];
}
$period = in_array($_GET['period'] ?? 'all', ['all','day','week','month'], true) ? ($_GET['period'] ?? 'all') : 'all';
$type = in_array($_GET['type'] ?? 'all', ['all','alarm','system','info'], true) ? ($_GET['type'] ?? 'all') : 'all';
$eventPage = max(1, min(10000, (int)($_GET['event_page'] ?? 1)));
$eventOffset = ($eventPage - 1) * 500;
$hasNextEvents = false;
$days = ['day'=>1,'week'=>7,'month'=>30][$period] ?? 0;
if ($isGuest) {
    $events = $guestEvents;
    $events = array_filter($events, static function($event) use($days,$type) {
        $age = time() - strtotime($event['created_at']);
        if ($days && ($age < 0 || $age > $days * 86400)) return false;
        $description = $event['event_description'];
        $alarm = preg_match('/тревога/iu', $description) === 1;
        if ($type === 'alarm') return in_array($event['event_type'],['temperature','humidity','device'],true) && $alarm;
        if ($type === 'system') return in_array($event['event_type'],['device','lighting'],true) && !$alarm;
        if ($type === 'info') return $event['event_type'] === 'device' && preg_match('/событие|режим|информац/iu', $description) === 1;
        return true;
    });
} else {
    $selectedDeviceId = fitodomikGetSelectedDeviceId($pdo, $user_id);
    $where = 'user_id = ?';
    $params = [$user_id];
    if ($selectedDeviceId !== '') {
        $where .= ' AND (device_id = ? OR device_id IS NULL)';
        $params[] = $selectedDeviceId;
    }
    if ($days) $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND created_at <= NOW()";
    $filters = [
        'alarm' => "event_type IN ('temperature','humidity','device') AND LOWER(event_description) LIKE '%тревога%'",
        'system' => "event_type IN ('device','lighting') AND LOWER(event_description) NOT LIKE '%тревога%'",
        'info' => "event_type='device' AND (LOWER(event_description) LIKE '%событие%' OR LOWER(event_description) LIKE '%режим%' OR LOWER(event_description) LIKE '%информац%')",
    ];
    if (isset($filters[$type])) $where .= ' AND (' . $filters[$type] . ')';
    $stmt = $pdo->prepare("SELECT id,event_type,event_description,created_at FROM event_log WHERE {$where} ORDER BY created_at DESC,id DESC LIMIT 501 OFFSET {$eventOffset}");
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasNextEvents = count($events) > 500;
    if ($hasNextEvents) array_pop($events);
}
$filteredEvents = $events;
function eventPageUrl($page) {
    $query = $_GET;
    $query['event_page'] = $page;
    return '?' . http_build_query($query) . '#event-log';
}
?>
<div class="accordion">
    <button type="button" class="accordion-header accordion-toggle-button"
         onclick="this.nextElementSibling.classList.toggle('active'); this.querySelector('.accordion-icon').classList.toggle('active');">
        <span>Журнал событий 📜</span>
        <span class="accordion-icon">▼</span>
    </button>
    <div class="accordion-content">
        <div class="event-log-container">
            <?php if ($isGuest) : ?>
            <div class="guest-notice">
                <p>Вы просматриваете данные в режиме гостя. Для доступа к полному журналу событий необходимо <a href="authentication/login.php">авторизоваться</a>.</p>
            </div>
            <?php endif; ?>
            <div class="event-filters">
                <div class="event-type-label">Тип события:</div>
                <button type="button" class="filter-button" onclick="setFilter('all')" aria-label="Показать все типы событий" title="Показать все типы событий">Все</button>
                <button type="button" class="filter-button" onclick="setFilter('alarm')" aria-label="Показать только тревоги" title="Показать только события типа 'Тревога'">Тревоги</button>
                <button type="button" class="filter-button" onclick="setFilter('system')" aria-label="Показать только системные события" title="Показать только системные события">Система</button>
                <button type="button" class="filter-button" onclick="setFilter('info')" aria-label="Показать только информационные события" title="Показать только информационные события">Информация</button>
            </div>
            <div class="event-period">
                <div class="period-label">Период:</div>
                <button type="button" class="period-button" onclick="setPeriod('all')">Все</button>
                <button type="button" class="period-button" onclick="setPeriod('day')" aria-label="Показать события за день" title="Показать события за последние 24 часа">День</button>
                <button type="button" class="period-button" onclick="setPeriod('week')" aria-label="Показать события за неделю" title="Показать события за последние 7 дней">Неделя</button>
                <button type="button" class="period-button" onclick="setPeriod('month')" aria-label="Показать события за месяц" title="Показать события за последние 30 дней">Месяц</button>
            </div>
            <div class="event-list">
                <?php if (empty($filteredEvents)) : ?>
                    <div class="event-item event-item-empty">
                        Нет событий для отображения.
                    </div>
                <?php else : ?>
                    <?php foreach ($filteredEvents as $event) : ?>
                        <div class="event-item <?php echo htmlspecialchars((string)$event['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <div class="event-time">
                                <?php echo date('d.m.Y H:i:s', strtotime($event['created_at'])); ?>
                            </div>
                            <div class="event-message"><?php echo htmlspecialchars((string)$event['event_description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <nav aria-label="Страницы событий">
                <?php if (!$isGuest && $eventPage > 1): ?><a href="<?php echo htmlspecialchars(eventPageUrl($eventPage-1),ENT_QUOTES); ?>">Назад</a><?php endif; ?>
                <span>До 500 событий на странице</span>
                <?php if ($hasNextEvents): ?><a href="<?php echo htmlspecialchars(eventPageUrl($eventPage+1),ENT_QUOTES); ?>">Далее</a><?php endif; ?>
            </nav>
            <div class="event-actions">
                <button type="button" class="clear-button" onclick="clearEventLog()" <?php echo $isGuest ? 'disabled title="Недоступно в гостевом режиме"' : ''; ?>
                        aria-label="Очистить журнал событий"
                        title="Удалить записи выбранного устройства">Очистить журнал</button>
            </div>
        </div>
    </div>
</div>
<!-- Модальное окно для шаринга -->
<dialog id="shareModal" class="modal" aria-labelledby="shareModalTitle">
    <div class="modal-content">
        <span class="close" aria-label="Закрыть окно" title="Закрыть">&times;</span>
        <h2 id="shareModalTitle">Поделиться доступом</h2>
        <div class="share-options">
            <div class="share-option">
                <h3>Код доступа</h3>
                <div class="share-input-container">
                    <label for="eventLogShareCode">Код доступа для предоставления гостевого просмотра:</label>
                    <input type="text" id="eventLogShareCode" class="form-control share-input" readonly
                           aria-label="Код доступа для гостя" title="Код доступа для предоставления гостевого просмотра">
                    <button class="copy-btn" onclick="copyText('eventLogShareCode')" aria-label="Копировать код доступа" title="Копировать код в буфер обмена">Копировать</button>
                </div>
            </div>
            <div class="share-option">
                <h3>Ссылка для гостя</h3>
                <div class="share-input-container">
                    <label for="eventLogShareLink">Ссылка для гостевого просмотра:</label>
                    <input type="text" id="eventLogShareLink" class="form-control share-input" readonly
                           aria-label="Ссылка для гостевого просмотра" title="Ссылка для гостевого просмотра вашей фермы">
                    <button class="copy-btn" onclick="copyText('eventLogShareLink')" aria-label="Копировать ссылку" title="Копировать ссылку в буфер обмена">Копировать</button>
                </div>
            </div>
        </div>
    </div>
</dialog>
<style>
    .filter-button, .period-button {
        background-color: var(--accent-surface);
        color: white;
        border: none;
        padding: 10px 20px;
        margin: 5px;
        border-radius: 20px;
        cursor: pointer;
        transition: background-color 0.3s;
        display: inline-block;
    }
    .filter-button.active, .period-button.active {
        background-color: var(--secondary-color);
    }
    .guest-notice {
        background-color: rgba(255, 193, 7, 0.2);
        border-left: 4px solid #f0a500;
        padding: 10px 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    .guest-notice p {
        margin: 0;
        color: var(--text-color);
    }
    .event-item {
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 5px;
        background: rgba(25, 135, 84, 0.85);
        color: #fff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .event-item.temperature,
    .event-item.humidity,
    .event-item.alarm {
        background: rgba(220, 53, 69, 0.85);
    }
    .event-item.device,
    .event-item.lighting {
        background: rgba(13, 110, 253, 0.85);
    }
    .event-item-empty {
        padding: 20px;
        text-align: center;
        background: var(--background-color);
        color: var(--text-color);
    }
    .event-actions {
        margin-top: 20px;
        text-align: right;
    }
    .clear-button {
        padding: 8px 15px;
        border: 0;
        border-radius: 5px;
        background: rgba(220, 53, 69, 0.85);
        color: #fff;
        cursor: pointer;
    }
    .clear-button:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }
    .guest-notice a {
        color: var(--secondary-color);
        text-decoration: none;
        font-weight: bold;
    }
    .guest-notice a:hover {
        text-decoration: underline;
    }
    .copy-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background-color: var(--accent-surface);
        color: white;
        padding: 10px 20px;
        border-radius: 5px;
        z-index: 1100;
        animation: fadeInOut 2s ease-in-out;
    }
    @keyframes fadeInOut {
        0% { opacity: 0; transform: translateY(-20px); }
        20% { opacity: 1; transform: translateY(0); }
        80% { opacity: 1; transform: translateY(0); }
        100% { opacity: 0; transform: translateY(-20px); }
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelector('.filter-button[onclick="setFilter(\'<?php echo htmlspecialchars($type, ENT_QUOTES); ?>\')"]').classList.add('active');
        document.querySelector('.period-button[onclick="setPeriod(\'<?php echo htmlspecialchars($period, ENT_QUOTES); ?>\')"]').classList.add('active');
    });
    function setFilter(type) {
        const url = new URL(window.location.href);
        url.searchParams.set('type',type);
        url.searchParams.delete('event_page');
        window.location.href=url.href;
    }
    function setPeriod(period) {
        const url = new URL(window.location.href);
        url.searchParams.set('period',period);
        url.searchParams.delete('event_page');
        window.location.href=url.href;
    }
    function clearEventLog() {
        const isGuest = <?php echo $isGuest ? 'true' : 'false'; ?>;
        if (isGuest) {
            alert('Очистка журнала недоступна в гостевом режиме');
            return;
        }
        if (confirm('Вы уверены, что хотите очистить журнал событий? Это действие нельзя отменить.')) {
            fetch('/api/clear-event-log.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({device_id: <?php echo json_encode($selectedDeviceId); ?>})
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelector('.event-list').innerHTML = '<div class="event-item event-item-empty">Нет событий для отображения.</div>';
                    alert('Журнал событий успешно очищен');
                } else {
                    alert('Ошибка при очистке журнала: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
                alert('Произошла ошибка при очистке журнала');
            });
        }
    }
    function copyText(elementId) {
        const copyInput = document.getElementById(elementId);
        copyInput.select();
        document.execCommand('copy');
        const notification = document.createElement('div');
        notification.className = 'copy-notification';
        notification.textContent = 'Скопировано в буфер обмена';
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.remove();
        }, 2000);
    }
</script>
