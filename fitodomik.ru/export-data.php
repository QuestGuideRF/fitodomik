<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/headers.php';
require_once __DIR__ . '/config/site.php';
require_once __DIR__ . '/config/device_context.php';
const EXPORT_SELECT_PREFIX = 'SELECT ';
if (!isLoggedIn()) {
    header('Location: ' . fitodomikUrl('/authentication/login.php') . '?redirect=' . urlencode('/export-data.php'));
    exit;
}
function exportDateRange()
{
    $period = trim((string)($_GET['period'] ?? 'week'));
    $today = date('Y-m-d');
    $range = [date('Y-m-d', strtotime('-7 days')), $today, 'неделя'];
    if ($period === 'day') {
        $range = [$today, $today, 'день'];
    } elseif ($period === 'month') {
        $range = [date('Y-m-d', strtotime('-30 days')), $today, 'месяц'];
    } elseif ($period === 'custom') {
        $from = trim((string)($_GET['from'] ?? $today));
        $to = trim((string)($_GET['to'] ?? $today));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $today;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $today;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $range = [$from, $to, 'период'];
    }
    return $range;
}
function exportCsv($filename, $headers, $rows)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('exportCsvCell', $headers), ';');
    foreach ($rows as $row) {
        fputcsv($out, array_map('exportCsvCell', $row), ';');
    }
    fclose($out);
    exit;
}
function exportCsvCell($value)
{
    if (!is_string($value)) {
        return $value;
    }
    if (preg_match('/^[\t\r ]*[=+\-@]/u', $value) === 1) {
        return "'" . $value;
    }
    return $value;
}
function exportQueryRows($pdo, $sql, $params, $columns)
{
    $pdo->setAttribute((defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')), false);
    $base = preg_replace('/ ORDER BY created_at ASC\s*$/D', '', $sql);
    $cursor = null;
    do {
        $seek = $cursor ? ' AND (created_at > ? OR (created_at = ? AND id > ?))' : '';
        $queryParams = $cursor ? array_merge($params, [$cursor['created_at'],$cursor['created_at'],$cursor['id']]) : $params;
        $stmt = $pdo->prepare($base . $seek . ' ORDER BY created_at ASC,id ASC LIMIT 1000');
        $stmt->execute($queryParams);
        $count=0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $count++; $cursor=$row; $line=[];
            foreach ($columns as $column) {
                $value=$row[$column]??'';
                if (in_array($column,['photo','photo_analysis'],true) && $value!=='') {
                    $value=fitodomikUrl('/security/image.php?file='.rawurlencode('farm_photos/'.basename((string)$value)));
                }
                $line[]=$value;
            }
            yield $line;
        }
        $stmt->closeCursor();
    } while ($count===1000 && !connection_aborted());
}
function exportDownloadUrl($download, $from, $to)
{
    $period = trim((string)($_GET['period'] ?? 'week'));
    if (!in_array($period, ['day', 'week', 'month', 'custom'], true)) {
        $period = 'week';
    }
    return '?' . http_build_query([
        'period' => $period,
        'from' => $from,
        'to' => $to,
        'download' => $download,
        'device_id' => (string)($GLOBALS['device_id'] ?? ''),
    ], '', '&', PHP_QUERY_RFC3986);
}
$user_id = (int)$_SESSION['user_id'];
$selectedDevice = null;
$devices = [];
$error = '';
try {
    $devices = fitodomikGetUserDevices($pdo, $user_id, false);
    $selectedDevice = fitodomikRenderedDevice($pdo, $user_id, $_GET, false);
    if (!$selectedDevice) {
        throw new FitodomikNotFoundException('Устройство не выбрано');
    }
    $device_id = $selectedDevice['device_id'];
    // Authentication/device context is captured; a long download must not
    // hold the session lock and block controls in other tabs.
    session_write_close();
    [$from, $to, $periodLabel] = exportDateRange();
    $download = trim((string)($_GET['download'] ?? ''));
    if ($download !== '') {
        $safeDevice = preg_replace('/[^A-Za-z0-9_-]/', '_', $device_id);
        if ($download === 'sensor_data') {
            $columns = ['id', 'created_at', 'temperature', 'humidity', 'soil_moisture', 'light_level', 'co2', 'pressure', 'lamp_state', 'curtains_state'];
            $rows = exportQueryRows(
                $pdo,
                EXPORT_SELECT_PREFIX . implode(',', $columns) . " FROM sensor_data WHERE user_id = ? AND device_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY created_at ASC",
                [$user_id, $device_id, $from, $to],
                $columns
            );
            exportCsv("fitodomik_{$safeDevice}_sensors_{$from}_{$to}.csv", $columns, $rows);
        }
        if ($download === 'event_log') {
            $columns = ['id', 'created_at', 'event_type', 'event_description'];
            $rows = exportQueryRows(
                $pdo,
                EXPORT_SELECT_PREFIX . implode(',', $columns) . " FROM event_log WHERE user_id = ? AND device_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY created_at ASC",
                [$user_id, $device_id, $from, $to],
                $columns
            );
            exportCsv("fitodomik_{$safeDevice}_events_{$from}_{$to}.csv", $columns, $rows);
        }
        if ($download === 'photos') {
            $columns = ['id', 'created_at', 'photo', 'photo_analysis', 'comment'];
            $rows = exportQueryRows(
                $pdo,
                "SELECT " . implode(',', $columns) . " FROM farm_status WHERE user_id = ? AND device_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY) AND ((photo IS NOT NULL AND photo <> '') OR (photo_analysis IS NOT NULL AND photo_analysis <> '')) ORDER BY created_at ASC",
                [$user_id, $device_id, $from, $to],
                $columns
            );
            exportCsv("fitodomik_{$safeDevice}_photos_{$from}_{$to}.csv", $columns, $rows);
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Экспорт данных - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="manifest" href="/site.webmanifest">
    <style>
        body { margin: 0; background: var(--background-color); color: var(--text-color); font-family: Arial, sans-serif; }
        .export-wrap { max-width: 980px; margin: 0 auto; padding: 32px 16px; }
        .export-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 18px; margin: 14px 0; box-shadow: 0 12px 30px rgba(0,0,0,.08); }
        .export-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .export-actions a, .export-card button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 10px 14px; border: 0; border-radius: 10px; background: var(--accent-surface); color: #fff; text-decoration: none; cursor: pointer; }
        .export-actions a.secondary { background: var(--button-bg); color: var(--text-color); border: 1px solid var(--border-color); }
        .range-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 10px; align-items: end; }
        label { display: block; color: var(--secondary-text); font-size: 14px; margin-bottom: 4px; }
        select, input { width: 100%; box-sizing: border-box; padding: 10px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-color); }
        .error { background: #fee4e2; color: #b42318; padding: 12px; border-radius: 10px; }
        @media (max-width: 760px) { .range-grid { grid-template-columns: 1fr; } .export-actions a, .export-card button { width: 100%; box-sizing: border-box; } }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <h1 class="site-title">ФитоДомик</h1>
            <div class="header-right">
                <a class="nav-link" href="/devices.php">Устройства</a>
                <a class="nav-link" href="/">Главная</a>
                <button class="theme-toggle" type="button" aria-label="Переключить тему"><span class="theme-icon">🌙</span></button>
            </div>
        </div>
    </header>
    <main class="export-wrap">
        <h1>Экспорт данных</h1>
        <?php if ($error) : ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php else : ?>
            <section class="export-card">
                <h2><?php echo htmlspecialchars($selectedDevice['device_name']); ?></h2>
                <p>CSV-экспорт формируется только по выбранному устройству: <strong><?php echo htmlspecialchars($selectedDevice['device_id']); ?></strong>.</p>
                <form method="GET" class="range-grid">
                    <div>
                        <label for="period">Период</label>
                        <select id="period" name="period">
                            <option value="day" <?php echo ($_GET['period'] ?? '') === 'day' ? 'selected' : ''; ?>>Сегодня</option>
                            <option value="week" <?php echo ($_GET['period'] ?? 'week') === 'week' ? 'selected' : ''; ?>>Неделя</option>
                            <option value="month" <?php echo ($_GET['period'] ?? '') === 'month' ? 'selected' : ''; ?>>Месяц</option>
                            <option value="custom" <?php echo ($_GET['period'] ?? '') === 'custom' ? 'selected' : ''; ?>>Свой диапазон</option>
                        </select>
                    </div>
                    <div>
                        <label for="from">С даты</label>
                        <input id="from" name="from" type="date" value="<?php echo htmlspecialchars($from); ?>">
                    </div>
                    <div>
                        <label for="to">По дату</label>
                        <input id="to" name="to" type="date" value="<?php echo htmlspecialchars($to); ?>">
                    </div>
                    <button type="submit">Применить</button>
                </form>
                <div class="export-actions">
                    <a href="<?php echo htmlspecialchars(exportDownloadUrl('sensor_data', $from, $to), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Скачать датчики CSV</a>
                    <a href="<?php echo htmlspecialchars(exportDownloadUrl('event_log', $from, $to), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Скачать события CSV</a>
                    <a href="<?php echo htmlspecialchars(exportDownloadUrl('photos', $from, $to), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Скачать список фото CSV</a>
                    <a class="secondary" href="/devices.php">Вернуться к устройствам</a>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <script src="/static/js/theme.v20261009.js" defer></script>
</body>
</html>
