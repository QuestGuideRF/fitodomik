<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = "Код проекта";
$pageDescription = "Ссылки на репозитории кода проекта ФитоДомик";
$pageKeywords = "код, репозиторий, GitHub, исходный код, ФитоДомик";
$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$shortDescription = static function ($value, int $limit = 220): string {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $limit) {
        return $text;
    }
    $slice = function_exists('mb_substr')
        ? mb_substr($text, 0, $limit - 1, 'UTF-8')
        : substr($text, 0, $limit - 1);
    return rtrim($slice) . '…';
};
try {
    $stmt = $pdo->query("
        SELECT id, title, url, description, created_at
        FROM code_links
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Ошибка при получении ссылок на код: " . $e->getMessage());
    $links = [];
}
ob_start();
?>
<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <h1 class="text-center mb-4">
                <i class="bi bi-code-slash text-primary"></i>
                Код проекта ФитоДомик
            </h1>
            <section class="install-panel mb-4" aria-labelledby="install-title">
                <div class="install-panel__heading">
                    <div>
                        <span class="install-eyebrow">
                            <i class="bi bi-lightning-charge-fill"></i>
                            Быстрый старт
                        </span>
                        <h2 id="install-title">Установка ФитоДомик</h2>
                        <p>
                            Установите приложение одной командой для Windows или Linux / Raspberry Pi.
                        </p>
                    </div>
                    <a href="/downloads/fitodomik-desktop.zip" class="btn btn-primary install-download" download>
                        <i class="bi bi-download me-2"></i>
                        Скачать установщик
                    </a>
                </div>
                <div class="install-grid">
                    <article class="install-card">
                        <div class="install-card__top">
                            <span class="install-card__icon" aria-hidden="true">
                                <i class="bi bi-terminal"></i>
                            </span>
                            <div>
                                <h3>Linux / Raspberry Pi</h3>
                                <p>Для Raspberry Pi OS и Linux-систем.</p>
                            </div>
                        </div>
                        <div class="install-command">
                            <code>curl -sSL https://fitodomik.ru/install | bash</code>
                            <button
                                type="button"
                                class="btn btn-outline-primary install-copy"
                                data-copy-command="curl -sSL https://fitodomik.ru/install | bash">
                                <i class="bi bi-clipboard me-1"></i>
                                <span class="copy-label">Копировать</span>
                            </button>
                        </div>
                    </article>
                    <article class="install-card">
                        <div class="install-card__top">
                            <span class="install-card__icon" aria-hidden="true">
                                <i class="bi bi-windows"></i>
                            </span>
                            <div>
                                <h3>Windows</h3>
                                <p>Запуск через PowerShell без ручной настройки.</p>
                            </div>
                        </div>
                        <div class="install-command">
                            <code>irm https://fitodomik.ru/install/windows | iex</code>
                            <button
                                type="button"
                                class="btn btn-outline-primary install-copy"
                                data-copy-command="irm https://fitodomik.ru/install/windows | iex">
                                <i class="bi bi-clipboard me-1"></i>
                                <span class="copy-label">Копировать</span>
                            </button>
                        </div>
                    </article>
                </div>
            </section>
            <p class="install-note mb-4">
                Установщик автоматически скачает проект, настроит окружение, зависимости, автозапуск и подготовит ФитоДомик к работе.
            </p>
            <div class="card mb-4">
                <div class="card-body text-center">
                    <p class="lead">
                        Здесь собраны ссылки на репозитории с исходным кодом проекта ФитоДомик.
                        Вы можете изучить код, внести свой вклад или использовать его в своих проектах.
                    </p>
                </div>
            </div>
            <?php if (empty($links)): ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle"></i>
                    <h4 class="alert-heading">Ссылки на код пока не добавлены</h4>
                    <p class="mb-0">
                        Администратор еще не добавил ссылки на репозитории с кодом.
                        Загляните позже!
                    </p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($links as $link): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100 link-card">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="link-icon me-3">
                                            <?php
                                            $url = strtolower((string)$link['url']);
                                            if (strpos($url, 'github.com') !== false) {
                                                echo '<i class="bi bi-github fs-2 text-primary"></i>';
                                            } elseif (strpos($url, 'gitlab.com') !== false) {
                                                echo '<i class="bi bi-git fs-2 text-warning"></i>';
                                            } elseif (strpos($url, 'bitbucket.org') !== false) {
                                                echo '<i class="bi bi-hdd-network fs-2 text-primary"></i>';
                                            } else {
                                                echo '<i class="bi bi-code-slash fs-2 text-primary"></i>';
                                            }
                                            ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h5 class="card-title mb-1">
                                                <?php echo $escape($link['title']); ?>
                                            </h5>
                                            <small class="text-muted">
                                                <?php echo $escape(date('d.m.Y', strtotime((string)$link['created_at']))); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php if ($link['description']): ?>
                                        <p class="card-text flex-grow-1">
                                            <?php echo $escape($shortDescription($link['description'])); ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="mt-auto">
                                        <a href="<?php echo $escape($link['url']); ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="btn btn-primary w-100">
                                            <i class="bi bi-box-arrow-up-right me-2"></i>
                                            Открыть GitHub
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-4">
                    <div class="project-contribution">
                        <i class="bi bi-heart-fill text-danger"></i>
                        <strong>Вклад в проект</strong>
                        <p class="mb-0 mt-2">
                            Если вы нашли ошибку или хотите предложить улучшение,
                            создайте issue или pull request в соответствующем репозитории.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<style>
.install-panel {
    padding: clamp(1.15rem, 2vw, 1.65rem);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    background:
        linear-gradient(135deg, rgba(47, 143, 70, 0.08), rgba(183, 216, 121, 0.1)),
        var(--card-bg);
    color: var(--text-color);
    box-shadow: var(--shadow-soft);
}
.install-panel__heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.15rem;
}
.install-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.45rem;
    color: var(--primary-dark);
    font-size: 0.86rem;
    font-weight: 800;
}
.install-panel h2 {
    margin: 0 0 0.35rem;
    font-size: clamp(1.45rem, 2.4vw, 2rem);
    letter-spacing: -0.03em;
}
.install-panel p {
    color: var(--muted-text);
}
.install-download {
    flex: 0 0 auto;
    white-space: nowrap;
}
.install-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.install-card {
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.42);
}
.install-card__top {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    margin-bottom: 0.9rem;
}
.install-card__icon {
    width: 2.65rem;
    height: 2.65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    border-radius: 14px;
    background: var(--primary-soft);
    color: var(--primary-dark);
    font-size: 1.35rem;
}
.install-card h3 {
    margin: 0 0 0.2rem;
    font-family: var(--font-body);
    font-size: 1rem;
    font-weight: 800;
    letter-spacing: 0;
}
.install-card p {
    margin: 0;
    font-size: 0.92rem;
}
.install-command {
    display: flex;
    align-items: stretch;
    gap: 0.65rem;
    padding: 0.65rem;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    background: var(--background-elevated);
}
.install-command code {
    display: block;
    flex: 1 1 auto;
    min-width: 0;
    padding: 0.55rem 0.65rem;
    border-radius: 12px;
    background: var(--content-bg);
    color: var(--text-color);
    font-size: 0.9rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
    white-space: normal;
}
.install-copy {
    flex: 0 0 auto;
    align-self: center;
    padding-inline: 0.9rem;
    font-size: 0.86rem;
}
.install-note {
    margin-top: -0.35rem;
    color: var(--muted-text);
    font-size: 0.95rem;
    text-align: center;
}
.link-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid var(--border-color);
    background: var(--card-bg);
    color: var(--text-color);
}
.link-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}
.link-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    border-radius: 10px;
    background-color: var(--primary-soft);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
    line-height: 1.3;
}
.card-text {
    color: var(--muted-text);
    font-size: 0.9rem;
    line-height: 1.5;
}
.text-muted {
    color: var(--muted-text) !important;
}
.btn-primary {
    font-weight: 500;
}
.btn-primary:hover {
    transform: translateY(-1px);
}
.project-contribution {
    padding: 1.1rem 1.25rem;
    border-radius: 18px;
    border: 1px solid var(--border-color);
    background:
        linear-gradient(135deg, rgba(47, 143, 70, 0.08), rgba(183, 216, 121, 0.12)),
        var(--card-bg);
    color: var(--text-color);
    box-shadow: var(--shadow-soft);
}
.project-contribution p {
    color: var(--muted-text);
}
[data-theme="dark"] .link-card {
    background-color: var(--card-bg);
    border-color: var(--border-color);
    color: var(--text-color);
}
[data-theme="dark"] .install-card {
    background: rgba(255, 255, 255, 0.045);
}
[data-theme="dark"] .install-command {
    background: rgba(255, 255, 255, 0.04);
}
[data-theme="dark"] .link-icon {
    background-color: var(--primary-soft);
}
[data-theme="dark"] .card-text {
    color: var(--muted-text);
}
@media (max-width: 768px) {
    .install-panel__heading {
        flex-direction: column;
    }
    .install-download {
        width: 100%;
    }
    .install-grid {
        grid-template-columns: 1fr;
    }
    .col-md-6 {
        margin-bottom: 1rem;
    }
    .link-card {
        margin-bottom: 0;
    }
}
@media (max-width: 520px) {
    .install-command {
        flex-direction: column;
    }
    .install-copy {
        width: 100%;
    }
}
</style>
<?php require __DIR__ . '/../../includes/install_copy_script.php'; ?>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
?>
