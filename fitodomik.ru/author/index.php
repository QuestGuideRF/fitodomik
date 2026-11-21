<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/migrations/merge_author_site.php';
require_once __DIR__ . '/includes/author_repository.php';
const AUTHOR_HTTPS_SCHEME = 'https://';
$authorContent = fitodomikAuthorFallbackContent();
try {
    fitodomikEnsureAuthorSiteMigration($pdo);
    $loadedAuthorContent = fitodomikLoadAuthorContent($pdo);
    if (!empty($loadedAuthorContent['profile'])) {
        $authorContent = $loadedAuthorContent;
    }
} catch (Throwable $e) {
    error_log('Author page data loading failed: ' . $e->getMessage());
}
$profile = $authorContent['profile'];
$projects = $authorContent['projects'];
$skills = $authorContent['skills'];
$milestones = $authorContent['milestones'];
$links = $authorContent['links'];
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = static function ($value): string {
    $url = trim((string)$value);
    if ($url !== '' && str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return $url;
    }
    if (str_starts_with($url, 'mailto:')) {
        return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) ? $url : '#';
    }
    return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, AUTHOR_HTTPS_SCHEME) ? $url : '#';
};
$stackItems = static function ($value): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
};
$pageTitle = 'Автор проекта ФитоДомик — Вячеслав Домнин';
$pageDescription = 'Публичная страница Домнина Вячеслава Игоревича: биография, навыки, проекты, GitHub и контакты автора ФитоДомика.';
$pageKeywords = 'Домнин Вячеслав Игоревич, Вячеслав Домнин, legendaSVD, автор ФитоДомика, программист, Python, C++, Arduino, Raspberry Pi';
$pageCanonical = AUTHOR_HTTPS_SCHEME . 'fitodomik.ru/author';
$pageImage = AUTHOR_HTTPS_SCHEME . 'fitodomik.ru' . (string)$profile['avatar_path'];
$pageStylesheets = ['/author/assets/css/author.v4.css'];
$pageScripts = ['/author/assets/js/author.v1.js'];
$skipUserSession = true;
$sameAs = [];
foreach ($links as $link) {
    $url = (string)($link['url'] ?? '');
    if (str_starts_with($url, AUTHOR_HTTPS_SCHEME)) {
        $sameAs[] = $url;
    }
}
$pageStructuredData = [
    '@context' => AUTHOR_HTTPS_SCHEME . 'schema.org',
    '@type' => 'Person',
    'name' => (string)$profile['full_name'],
    'alternateName' => (string)$profile['public_handle'],
    'url' => $pageCanonical,
    'image' => $pageImage,
    'description' => $pageDescription,
    'jobTitle' => 'Программист и разработчик проектов',
    'knowsAbout' => ['PHP', 'Python', 'C++', 'JavaScript', 'Arduino', 'Raspberry Pi', 'MySQL'],
    'sameAs' => $sameAs,
    'worksFor' => [
        '@type' => 'Project',
        'name' => 'ФитоДомик',
        'url' => AUTHOR_HTTPS_SCHEME . 'fitodomik.ru/',
    ],
];
ob_start();
?>
<div class="author-page">
    <section class="author-hero" aria-labelledby="author-title">
        <img
            class="author-hero__image"
            src="<?= $escape($profile['avatar_path']) ?>"
            width="637"
            height="685"
            alt="Домнин Вячеслав Игоревич, автор проекта ФитоДомик"
            loading="eager"
            fetchpriority="high"
            decoding="async">
        <div class="author-hero__shade" aria-hidden="true"></div>
        <div class="author-hero__content">
            <span class="author-kicker">Автор проекта ФитоДомик</span>
            <h1 id="author-title"><?= $escape($profile['full_name']) ?></h1>
            <p class="author-hero__tagline"><?= $escape($profile['tagline']) ?></p>
            <p class="author-hero__intro"><?= $escape($profile['intro']) ?></p>
            <div class="author-hero__actions">
                <a class="btn btn-light site-btn" href="#projects"><i class="bi bi-grid" aria-hidden="true"></i><span>Проекты</span></a>
                <a class="btn btn-outline-light site-btn" href="/pages/feedback/?topic=author#feedback-form-title"><i class="bi bi-chat-dots" aria-hidden="true"></i><span>Связаться</span></a>
                <a class="btn btn-outline-light site-btn author-hero__return" href="/"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Вернуться к ФитоДомику</span></a>
            </div>
            <div class="author-hero__meta" aria-label="Краткая информация">
                <span><i class="bi bi-geo-alt" aria-hidden="true"></i><?= $escape($profile['location_text']) ?></span>
                <span><i class="bi bi-circle-fill" aria-hidden="true"></i><?= $escape($profile['status_text']) ?></span>
            </div>
        </div>
    </section>
    <nav class="author-anchor-nav" aria-label="Разделы страницы автора">
        <a href="#about">Обо мне</a>
        <a href="#journey">Путь</a>
        <a href="#projects">Проекты</a>
        <a href="#skills">Навыки</a>
        <a href="#contacts">Контакты</a>
        <a href="/">ФитоДомик</a>
    </nav>
    <section class="author-section" id="about" data-author-reveal aria-labelledby="author-about-title">
        <div class="author-section__heading">
            <span class="author-kicker">Обо мне</span>
            <h2 id="author-about-title">Разработка как практическая работа над продуктом</h2>
        </div>
        <div class="author-about-grid">
            <div class="author-copy-card">
                <p><?= $escape($profile['biography']) ?></p>
            </div>
            <aside class="author-focus-card" aria-label="Основные направления">
                <strong>Основной фокус</strong>
                <ul>
                    <li>Fullstack-разработка и API</li>
                    <li>Python, C++ и автоматизация</li>
                    <li>Arduino, Raspberry Pi и датчики</li>
                    <li>Развитие собственных цифровых продуктов</li>
                </ul>
            </aside>
        </div>
    </section>
    <section class="author-section" id="journey" data-author-reveal aria-labelledby="author-journey-title">
        <div class="author-section__heading">
            <span class="author-kicker">Путь</span>
            <h2 id="author-journey-title">От первой страницы до IoT-систем</h2>
        </div>
        <div class="author-timeline">
            <?php foreach ($milestones as $milestone): ?>
                <article class="author-timeline__item">
                    <span><?= $escape($milestone['period_text']) ?></span>
                    <h3><?= $escape($milestone['title']) ?></h3>
                    <p><?= $escape($milestone['summary']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="author-section" id="projects" data-author-reveal aria-labelledby="author-projects-title">
        <div class="author-section__heading">
            <span class="author-kicker">Проекты</span>
            <h2 id="author-projects-title">Системы, которые развиваются на практике</h2>
        </div>
        <div class="author-project-grid">
            <?php foreach ($projects as $project): ?>
                <article class="author-project-card">
                    <span class="author-project-card__status"><?= $escape($project['status_text']) ?></span>
                    <h3><?= $escape($project['title']) ?></h3>
                    <p><?= $escape($project['summary']) ?></p>
                    <div class="author-stack" aria-label="Технологии проекта">
                        <?php foreach ($stackItems($project['stack_text']) as $stack): ?>
                            <span><?= $escape($stack) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="author-project-card__links">
                        <?php if (!empty($project['live_url'])): ?>
                            <a href="<?= $escape($safeUrl($project['live_url'])) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>Открыть проект</span></a>
                        <?php endif; ?>
                        <?php if (!empty($project['repo_url'])): ?>
                            <a href="<?= $escape($safeUrl($project['repo_url'])) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-github" aria-hidden="true"></i><span>Исходный код</span></a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="author-section" id="skills" data-author-reveal aria-labelledby="author-skills-title">
        <div class="author-section__heading">
            <span class="author-kicker">Навыки</span>
            <h2 id="author-skills-title">Стек для веба, автоматизации и устройств</h2>
        </div>
        <div class="author-skill-grid">
            <?php foreach ($skills as $skill): ?>
                <article class="author-skill-card">
                    <span><?= $escape($skill['category_name']) ?></span>
                    <h3><?= $escape($skill['title']) ?></h3>
                    <p><?= $escape($skill['summary']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="author-section author-contact-section" id="contacts" data-author-reveal aria-labelledby="author-contacts-title">
        <div class="author-section__heading">
            <span class="author-kicker">Контакты</span>
            <h2 id="author-contacts-title">Связаться или посмотреть код</h2>
            <p>Для вопросов о ФитоДомике используйте общую форму сайта или открытые профили автора.</p>
        </div>
        <div class="author-contact-grid">
            <?php foreach ($links as $link): ?>
                <?php $href = $safeUrl($link['url']); $external = str_starts_with($href, AUTHOR_HTTPS_SCHEME); ?>
                <a class="author-contact-card" href="<?= $escape($href) ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <i class="bi <?= $escape($link['icon_name']) ?>" aria-hidden="true"></i>
                    <span><?= $escape($link['label']) ?></span>
                    <strong><?= $escape($link['display_value']) ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="author-final" data-author-reveal aria-label="Переход к проекту ФитоДомик">
        <div>
            <span class="author-kicker">Главный проект</span>
            <h2>Посмотрите, как устроен ФитоДомик</h2>
            <p>Архитектура, компоненты, код и история разработки собраны на основном сайте проекта.</p>
        </div>
        <div class="author-final__actions">
            <a class="btn btn-primary site-btn" href="/pages/device/"><i class="bi bi-cpu" aria-hidden="true"></i><span>Устройство</span></a>
            <a class="btn btn-outline-primary site-btn" href="/pages/code/"><i class="bi bi-code-slash" aria-hidden="true"></i><span>Код проекта</span></a>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
require_once dirname(__DIR__) . '/layout.php';
