<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/headers.php';
require_once 'config/device_context.php';
require_once 'config/theme.php';
if (!isLoggedIn()) {
    header('Location: /authentication/login.php?redirect=' . urlencode('/timelapse.php'));
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$period = ($_GET['period'] ?? 'week') === 'month' ? 'month' : 'week';
$theme = fitodomikCurrentTheme();
$days = $period === 'month' ? 30 : 7;
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * 500;
$afterId = max(0, (int)($_GET['after_id'] ?? 0));
$afterTime = trim((string)($_GET['after_time'] ?? ''));
$cursorValid = $afterId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $afterTime);
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));
$beforeTime = trim((string)($_GET['before_time'] ?? ''));
$reverse = !$cursorValid && $beforeId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $beforeTime);
$hasNext = false;
$frames = [];
$selectedDevice = null;
try {
    $selectedDevice = fitodomikRenderedDevice($pdo, $user_id, $_GET, false);
    if ($selectedDevice) {
        $params = [$user_id, $selectedDevice['device_id']];
        $cursorSql = '';
        $order = 'ASC';
        if ($cursorValid) {
            $cursorSql = ' AND (created_at > ? OR (created_at = ? AND id > ?))';
            array_push($params, $afterTime, $afterTime, $afterId);
            $offset = 0;
        } elseif ($reverse) {
            $cursorSql = ' AND (created_at < ? OR (created_at = ? AND id < ?))';
            array_push($params, $beforeTime, $beforeTime, $beforeId);
            $offset = 0;
            $order = 'DESC';
        }
        $stmt = $pdo->prepare("
            SELECT id, photo, created_at
            FROM farm_status
            WHERE user_id = ? AND device_id = ? AND photo IS NOT NULL AND photo <> ''
            AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            {$cursorSql}
            ORDER BY created_at {$order}, id {$order}
            LIMIT 501 OFFSET {$offset}
        ");
        $stmt->execute($params);
        $frames = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasNext = count($frames) > 500;
        if ($hasNext) array_pop($frames);
        if ($reverse) { $frames = array_reverse($frames); $hasNext = true; }
    }
} catch (Exception $e) {
    error_log('timelapse.php: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Таймлапс растения - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <style>
        .timelapse-page{max-width:1080px;margin:0 auto;padding:24px;color:var(--text-color)}
        .timelapse-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:18px;padding:20px;box-shadow:0 14px 34px rgba(0,0,0,.12)}
        .timelapse-top{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
        .timelapse-top h1{margin:0;color:var(--primary-color)}
        .timelapse-actions{display:flex;gap:10px;flex-wrap:wrap}
        .timelapse-actions a,.timelapse-actions button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:0;border-radius:10px;padding:10px 14px;background:var(--accent-surface);color:#fff;text-decoration:none;cursor:pointer;box-sizing:border-box}
        .timelapse-actions a.secondary,.timelapse-actions button.secondary{background:var(--button-bg);color:var(--text-color);border:1px solid var(--border-color)}
        .timelapse-view{background:var(--background);border:1px solid var(--border-color);border-radius:16px;min-height:360px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin:18px 0}
        .timelapse-view img{max-width:100%;max-height:68vh;object-fit:contain}
        .timelapse-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0}
        .timelapse-meta div{padding:12px;border-radius:12px;background:var(--background);border:1px solid var(--border-color)}
        .frame-strip{display:flex;gap:8px;overflow-x:auto;padding:8px 0}
        .frame-strip img{width:88px;height:64px;object-fit:cover;border-radius:8px;border:2px solid transparent;cursor:pointer}
        .frame-strip img.active{border-color:var(--accent-surface)}
        @media (max-width:640px){
            .timelapse-page{padding:12px}
            .timelapse-card{padding:14px;border-radius:14px}
            .timelapse-top{align-items:stretch}
            .timelapse-top h1{font-size:26px}
            .timelapse-actions{width:100%}
            .timelapse-actions a,.timelapse-actions button{width:100%;box-sizing:border-box;text-align:center}
            .timelapse-view{min-height:240px}
            .timelapse-meta{grid-template-columns:1fr}
        }
    </style>
</head>
<body>
<main class="timelapse-page">
    <section class="timelapse-card">
        <div class="timelapse-top">
            <div>
                <h1>Таймлапс растения</h1>
                <p>Устройство: <strong><?php echo htmlspecialchars((string)($selectedDevice['device_name'] ?? 'не выбрано'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong></p>
            </div>
            <div class="timelapse-actions">
                <a class="<?php echo $period === 'week' ? '' : 'secondary'; ?>" href="/timelapse.php?period=week&amp;device_id=<?php echo rawurlencode($selectedDevice['device_id'] ?? ''); ?>">За неделю</a>
                <a class="<?php echo $period === 'month' ? '' : 'secondary'; ?>" href="/timelapse.php?period=month&amp;device_id=<?php echo rawurlencode($selectedDevice['device_id'] ?? ''); ?>">За месяц</a>
                <a class="secondary" href="/farm.php">В кабинет</a>
            </div>
        </div>
        <div class="timelapse-meta">
            <div>Период: <?php echo $period === 'month' ? 'последние 30 дней' : 'последние 7 дней'; ?></div>
            <div>Кадров: <?php echo (int)count($frames); ?></div>
            <div>Первое фото: <?php echo $frames ? htmlspecialchars((string)$frames[0]['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'нет'; ?></div>
            <div>Последнее фото: <?php echo $frames ? htmlspecialchars((string)$frames[count($frames) - 1]['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'нет'; ?></div>
        </div>
        <?php if (count($frames) < 2) : ?>
            <p>Для таймлапса пока мало фотографий. Нужно хотя бы 2 кадра выбранного устройства.</p>
        <?php endif; ?>
        <div class="timelapse-view" id="timelapseView">
            <?php if ($frames) : ?>
                <img id="timelapseImage" src="/security/image.php?file=farm_photos/<?php echo htmlspecialchars($frames[0]['photo']); ?>" alt="Кадр таймлапса">
            <?php else : ?>
                <p>Фото для выбранного устройства не найдены.</p>
            <?php endif; ?>
        </div>
        <div class="timelapse-actions">
            <button type="button" onclick="playTimelapse()">Смотреть</button>
            <button type="button" class="secondary" onclick="pauseTimelapse()">Пауза</button>
            <button type="button" class="secondary" onclick="setSpeed(1)">1x</button>
            <button type="button" class="secondary" onclick="setSpeed(2)">2x</button>
            <button type="button" class="secondary" onclick="setSpeed(4)">4x</button>
        </div>
        <nav aria-label="Страницы кадров">
            <?php if ($page > 1 && $frames): $firstFrame=$frames[0]; ?>
                <a rel="prev" href="?<?php echo htmlspecialchars(http_build_query(['period'=>$period,'page'=>$page-1,'device_id'=>$selectedDevice['device_id'],'before_id'=>$firstFrame['id'],'before_time'=>$firstFrame['created_at']]), ENT_QUOTES, 'UTF-8'); ?>">Предыдущие кадры</a>
            <?php endif; ?>
            <span>Страница <?php echo $page; ?>, до 500 кадров</span>
            <?php if ($hasNext): $lastFrame = $frames[count($frames)-1]; ?>
                <a rel="next" href="?<?php echo htmlspecialchars(http_build_query(['period'=>$period,'page'=>$page+1,'device_id'=>$selectedDevice['device_id'],'after_id'=>$lastFrame['id'],'after_time'=>$lastFrame['created_at']]), ENT_QUOTES, 'UTF-8'); ?>">Следующие кадры</a>
            <?php endif; ?>
        </nav>
        <div class="frame-strip" id="frameStrip">
            <?php foreach ($frames as $index => $frame) : ?>
                <img loading="lazy" decoding="async" width="88" height="64" data-index="<?php echo (int)$index; ?>" src="/security/image.php?thumb=1&amp;file=farm_photos/<?php echo htmlspecialchars((string)$frame['photo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="Кадр <?php echo (int)$index + 1; ?>">
            <?php endforeach; ?>
        </div>
    </section>
</main>
<script src="/static/js/theme.v20261009.js" defer></script>
<script>
const frames = <?php echo json_encode(array_map(function ($row) {
    return '/security/image.php?file=farm_photos/' . $row['photo'];
               }, $frames), JSON_UNESCAPED_SLASHES); ?>;
let currentFrame = 0;
let timer = null;
let speed = 1;
function showFrame(index){
    if(!frames.length) return;
    currentFrame = (index + frames.length) % frames.length;
    const image = document.getElementById('timelapseImage');
    if(image) image.src = frames[currentFrame];
    if(frames.length > 1){ const next = new Image(); next.src = frames[(currentFrame + 1) % frames.length]; }
    document.querySelectorAll('#frameStrip img').forEach(img => img.classList.toggle('active', Number(img.dataset.index) === currentFrame));
}
function playTimelapse(){
    pauseTimelapse();
    timer = setInterval(() => showFrame(currentFrame + 1), Math.max(120, 700 / speed));
}
function pauseTimelapse(){
    if(timer) clearInterval(timer);
    timer = null;
}
function setSpeed(value){
    speed = value;
    if(timer) playTimelapse();
}
document.querySelectorAll('#frameStrip img').forEach(img => img.addEventListener('click', () => {
    pauseTimelapse();
    showFrame(Number(img.dataset.index));
}));
showFrame(0);
window.addEventListener('pagehide', pauseTimelapse);
document.addEventListener('visibilitychange', () => { if (document.hidden) pauseTimelapse(); });
</script>
</body>
</html>
