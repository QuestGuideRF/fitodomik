<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Новости ФитоДомика';
$pageDescription = 'Дневник разработки проекта ФитоДомик: датчики, автополив, освещение, веб-интерфейс fitodomik.ru, Telegram-предупреждения и тестирование умной мини-фермы.';
$pageKeywords = 'новости ФитоДомик, дневник проекта, умная мини-ферма, разработка, автополив, Raspberry Pi, Arduino';
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$formatDate = static function ($date): string {
    $timestamp = strtotime((string)$date);
    if (!$timestamp) {
        return '';
    }
    $months = [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];
    return date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp) . ' г., ' . date('H:i', $timestamp);
};
$renderParagraphs = static function ($content) use ($escape): string {
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", (string)$content));
    if ($normalized === '') {
        return '';
    }
    $paragraphs = preg_split("/\n{2,}/", $normalized) ?: [];
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $html .= '<p>' . nl2br($escape(trim($paragraph)), false) . '</p>';
    }
    return $html;
};
$posts = [];
$totalPosts = 0;
$perPage = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages = 1;
try {
    $totalPosts = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    $totalPages = max(1, (int)ceil($totalPosts / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;
    $stmt = $pdo->prepare("
        SELECT id, title, content, created_at
        FROM posts
        ORDER BY created_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Ошибка при получении новостей: ' . $e->getMessage());
    $posts = [];
    $loadError = true;
}
ob_start();
?>
<div class="news-page">
    <section class="page-hero reveal-section">
        <span class="section-eyebrow">Дневник проекта</span>
        <h1>Новости ФитоДомика</h1>
        <p>Содержательные этапы разработки умной мини-фермы: решения, которые уже появились в проекте, и направления, для которых пока подготовлена архитектура.</p>
    </section>
    <?php if (!empty($loadError)): ?>
        <div class="empty-state">
            <h2>Новости временно недоступны</h2>
            <p>Мы уже знаем о проблеме загрузки данных и проверяем подключение к базе.</p>
        </div>
    <?php elseif (empty($posts)): ?>
        <div class="empty-state">
            <h2>Скоро здесь появятся новости</h2>
            <p>Пока записей нет, но раздел уже готов для дневника разработки ФитоДомика.</p>
        </div>
    <?php else: ?>
        <div class="news-grid">
            <?php foreach ($posts as $post): ?>
                <article class="news-card reveal-section">
                    <time class="news-card__meta" datetime="<?= $escape(date('c', strtotime($post['created_at']))) ?>">
                        <i class="bi bi-calendar3"></i>
                        <?= $escape($formatDate($post['created_at'])) ?>
                    </time>
                    <h2><?= $escape($post['title']) ?></h2>
                    <div class="news-card__content">
                        <?= $renderParagraphs($post['content']) ?>
                    </div>
                    <div class="news-card__footer">
                        <span>Этап разработки</span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="news-pagination" aria-label="Страницы новостей">
                <?php if ($currentPage > 1): ?>
                    <a class="news-pagination__link" href="?page=<?= $currentPage - 1 ?>" rel="prev">Назад</a>
                <?php endif; ?>
                <span class="news-pagination__status">
                    Страница <?= $currentPage ?> из <?= $totalPages ?>
                </span>
                <?php if ($currentPage < $totalPages): ?>
                    <a class="news-pagination__link" href="?page=<?= $currentPage + 1 ?>" rel="next">Показать ещё</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
?>
