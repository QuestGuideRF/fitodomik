<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/feedback_service.php';
$pageTitle = 'Обратная связь — ФитоДомик';
$pageDescription = 'Частые вопросы о ФитоДомике и безопасная форма связи с автором проекта.';
$pageKeywords = 'ФитоДомик, обратная связь, FAQ, помощь, установка, Arduino, Raspberry Pi';
$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$error = '';
$success = '';
$isAuthorTopic = ($_GET['topic'] ?? '') === 'author';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        fitodomikRequireCsrf();
        fitodomikSubmitFeedback($pdo, $_POST, $_FILES);
        $_SESSION['feedback_success'] = 'Сообщение успешно отправлено.';
        header('Location: /pages/feedback/');
        exit;
    } catch (FitodomikFeedbackValidationException | FitodomikImageUploadException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Feedback submission failed: ' . $e->getMessage());
        $error = 'Не удалось отправить сообщение. Попробуйте ещё раз.';
    }
}
if (!empty($_SESSION['feedback_success'])) {
    $success = (string)$_SESSION['feedback_success'];
    unset($_SESSION['feedback_success']);
}
if (!empty($_SESSION['feedback_error'])) {
    $error = (string)$_SESSION['feedback_error'];
    unset($_SESSION['feedback_error']);
}
$faq = [];
$faqLoadFailed = false;
try {
    $stmt = $pdo->query("
        SELECT m.id, m.message, r.reply_text
        FROM feedback_messages m
        INNER JOIN feedback_replies r
            ON r.id = (
                SELECT r2.id
                FROM feedback_replies r2
                WHERE r2.message_id = m.id
                  AND r2.reply_text IS NOT NULL
                  AND TRIM(r2.reply_text) <> ''
                ORDER BY r2.id DESC
                LIMIT 1
            )
        WHERE m.is_faq = 1
        ORDER BY m.created_at DESC, m.id DESC
    ");
    $faq = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('FAQ loading failed: ' . $e->getMessage());
    $faqLoadFailed = true;
}
ob_start();
?>
<div class="container feedback-page py-4">
    <section class="page-hero reveal-section">
        <span class="section-eyebrow">Помощь и связь</span>
        <h1>Обратная связь</h1>
        <p>Сначала проверьте ответы на частые вопросы. Если нужного ответа нет, отправьте сообщение через форму — технические детали исключения в браузер не выводятся.</p>
    </section>
    <?php if ($success !== ''): ?>
        <output class="alert alert-success" aria-live="polite" style="display:block"><?= $escape($success) ?></output>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
    <?php endif; ?>
    <section class="feedback-section reveal-section" aria-labelledby="faq-title">
        <div class="section-heading">
            <span class="section-eyebrow">FAQ</span>
            <h2 id="faq-title">Часто задаваемые вопросы</h2>
        </div>
        <?php if ($faqLoadFailed || empty($faq)): ?>
            <div class="empty-state">
                <p>Ответы на частые вопросы скоро появятся.</p>
            </div>
        <?php else: ?>
            <div class="feedback-faq-grid">
                <?php foreach ($faq as $item): ?>
                    <article class="feedback-faq-card">
                        <h3><?= $escape($item['message']) ?></h3>
                        <p><?= nl2br($escape($item['reply_text']), false) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="feedback-section feedback-form-section reveal-section" aria-labelledby="feedback-form-title">
        <div class="section-heading">
            <span class="section-eyebrow">Написать автору</span>
            <h2 id="feedback-form-title"><?= $isAuthorTopic ? 'Сообщение автору проекта' : 'Отправить сообщение' ?></h2>
            <p>Поля с именем, email и сообщением обязательны. Изображение можно приложить для пояснения ошибки или вопроса.</p>
        </div>
        <form method="post" enctype="multipart/form-data" class="feedback-form">
            <?= fitodomikCsrfInput() ?>
            <div class="feedback-form__row">
                <div>
                    <label for="name" class="form-label">Ваше имя</label>
                    <input type="text" class="form-control" id="name" name="name" maxlength="100" autocomplete="name" required value="<?= $escape($_POST['name'] ?? '') ?>">
                </div>
                <div>
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="255" autocomplete="email" required value="<?= $escape($_POST['email'] ?? '') ?>">
                </div>
            </div>
            <div>
                <label for="type" class="form-label">Тип сообщения</label>
                <select class="form-select" id="type" name="type" required>
                    <?php foreach (['question' => 'Вопрос', 'suggestion' => 'Предложение', 'error' => 'Сообщить об ошибке'] as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= ($_POST['type'] ?? 'question') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="message" class="form-label">Сообщение</label>
                <textarea class="form-control" id="message" name="message" rows="6" maxlength="5000"<?= $isAuthorTopic ? ' placeholder="Вопрос автору ФитоДомика"' : '' ?> required><?= $escape($_POST['message'] ?? '') ?></textarea>
            </div>
            <div>
                <label for="image" class="form-label">Изображение (необязательно)</label>
                <input type="file" class="form-control" id="image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp">
                <div class="form-text">JPEG, PNG, GIF или WebP, не более 10 МБ.</div>
            </div>
            <button type="submit" class="btn btn-primary site-btn">Отправить сообщение</button>
        </form>
    </section>
    <section class="feedback-contact reveal-section">
        <div>
            <span class="section-eyebrow">Контакты</span>
            <h2>Другой способ связи</h2>
            <p>Короткий вопрос о проекте можно отправить автору в Telegram.</p>
        </div>
        <a class="btn btn-outline-primary site-btn site-btn--ghost" href="https://t.me/FitoDomik" target="_blank" rel="noopener noreferrer">Открыть Telegram</a>
    </section>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../layout.php';
?>
