<?php
$stats = [
    'total' => 0,
    'answered' => 0,
    'unanswered' => 0,
    'faq' => 0
];
$messages = [];
$error = null;
$success = null;
$stmt = $pdo->query("SELECT cooldown_time FROM settings WHERE id = 1");
$settings = $stmt->fetch();
$cooldown_time = $settings['cooldown_time'] ?? 600;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'reply':
                $messageId = (int)($_POST['message_id'] ?? 0);
                $stmt = $pdo->prepare("INSERT INTO feedback_replies (message_id, reply_text) VALUES (?, ?)");
                $stmt->execute([$messageId, $_POST['reply_text']]);
                $stmt = $pdo->prepare("UPDATE feedback_messages SET is_answered = 1 WHERE id = ?");
                $stmt->execute([$messageId]);
                $success = "Ответ успешно отправлен";
                break;
            case 'toggle_faq':
                $messageId = (int)($_POST['message_id'] ?? 0);
                $isFaq = isset($_POST['is_faq']) && (int)$_POST['is_faq'] === 1 ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE feedback_messages SET is_faq = ? WHERE id = ?");
                $stmt->execute([$isFaq, $messageId]);
                $success = "Статус FAQ успешно обновлен";
                break;
            case 'delete':
                $messageId = (int)($_POST['message_id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM feedback_messages WHERE id = ?");
                $stmt->execute([$messageId]);
                $success = "Сообщение успешно удалено";
                break;
            default:
                $error = 'Неизвестное действие';
                break;
        }
    } catch(PDOException $e) {
        error_log('Admin feedback action failed: ' . $e->getMessage());
        $error = 'Не удалось выполнить действие';
    }
}
if (!isset($error)) {
    try {
        $stats = [
            'total' => $pdo->query("SELECT COUNT(*) FROM feedback_messages")->fetchColumn(),
            'answered' => $pdo->query("SELECT COUNT(*) FROM feedback_messages WHERE is_answered = 1")->fetchColumn(),
            'unanswered' => $pdo->query("SELECT COUNT(*) FROM feedback_messages WHERE is_answered = 0")->fetchColumn(),
            'faq' => $pdo->query("SELECT COUNT(*) FROM feedback_messages WHERE is_faq = 1")->fetchColumn()
        ];
    } catch(PDOException $e) {
        $error = "Ошибка при получении статистики: " . $e->getMessage();
    }
}
if (!isset($error)) {
    try {
        $stmt = $pdo->query("
            SELECT m.*, r.reply_text
            FROM feedback_messages m
            LEFT JOIN feedback_replies r ON m.id = r.message_id
            ORDER BY m.created_at DESC
        ");
        $messages = $stmt->fetchAll();
    } catch(PDOException $e) {
        $error = "Ошибка при получении списка сообщений: " . $e->getMessage();
    }
}
?>
<style>
body {
    background-color: var(--bg-color);
    color: var(--text-color);
}
.card {
    background-color: var(--card-bg);
    border-color: var(--border-color);
    color: var(--text-color);
}
.admin-reply {
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    color: var(--text-color);
    padding: 1rem;
    border-radius: 8px;
    margin-top: 1rem;
}
.admin-reply h6 {
    color: var(--text-color);
    margin-bottom: 0.5rem;
}
.modal-content {
    background-color: var(--card-bg);
    color: var(--text-color);
    border-color: var(--border-color);
}
.modal-header {
    border-bottom-color: var(--border-color);
}
.modal-footer {
    border-top-color: var(--border-color);
}
.modal-title {
    color: var(--text-color);
}
.form-control {
    background-color: var(--card-bg);
    border-color: var(--border-color);
    color: var(--text-color);
}
.form-control:focus {
    background-color: var(--card-bg);
    color: var(--text-color);
}
.badge {
    color: var(--text-color);
}
.btn-outline-secondary {
    color: var(--text-color);
    border-color: var(--border-color);
}
.btn-outline-secondary:hover {
    background-color: var(--hover-bg);
    color: var(--text-color);
}
.text-muted {
    color: var(--muted-text) !important;
}
.message-image {
    background-color: var(--card-bg);
    border-color: var(--border-color);
}
.card {
    border: 1px solid var(--border-color);
    background-color: var(--card-bg);
    margin-bottom: 1rem;
}
.card-body {
    color: var(--text-color);
}
.message-card {
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    margin-bottom: 1rem;
    border-radius: 8px;
}
.message-card .card-title {
    color: var(--text-color);
}
.message-content {
    background-color: rgba(255, 255, 255, 0.05);
    padding: 1rem;
    border-radius: 6px;
    margin: 0.5rem 0;
}
.form-label {
    color: var(--text-color);
}
.text-muted {
    color: var(--muted-text) !important;
}
.btn-outline-secondary {
    color: var(--text-color);
    border-color: var(--border-color);
}
.btn-outline-secondary:hover {
    background-color: var(--hover-bg);
    color: var(--text-color);
    border-color: var(--border-color);
}
.stats-card {
    transition: transform 0.2s ease;
}
.stats-card:hover {
    transform: translateY(-2px);
}
.bg-primary, .bg-success, .bg-warning, .bg-info {
    background-color: transparent !important;
    border: 1px solid currentColor;
}
.bg-primary {
    color: var(--bs-primary, #0d6efd);
}
.bg-success {
    color: var(--bs-success, #198754);
}
.bg-warning {
    color: #856404;
}
.bg-info {
    color: #087990;
}
.message-image {
    max-width: 300px;
    margin: 1rem 0;
}
.message-image img {
    width: 100%;
    height: auto;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.feedback-image {
    max-height: 200px;
}
.message-file {
    padding: 0.5rem;
    background-color: var(--card-bg);
    border-radius: 8px;
}
.reply-card {
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.5rem;
}
.reply-text {
    margin-bottom: 0.5rem;
}
</style>
<div class="container">
    <h1 class="mb-4">Управление обратной связью</h1>
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stats-card bg-primary">
                <div class="card-body">
                    <h5 class="card-title">Всего сообщений</h5>
                    <h2><?php echo $stats['total']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card bg-success">
                <div class="card-body">
                    <h5 class="card-title">Отвеченные</h5>
                    <h2><?php echo $stats['answered']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card bg-warning">
                <div class="card-body">
                    <h5 class="card-title">Ожидают ответа</h5>
                    <h2><?php echo $stats['unanswered']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card bg-info">
                <div class="card-body">
                    <h5 class="card-title">В FAQ</h5>
                    <h2><?php echo $stats['faq']; ?></h2>
                </div>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Настройки</h5>
            <form method="POST" id="settingsForm">
                <?php echo fitodomikCsrfInput(); ?>
                <input type="hidden" name="action" value="update_cooldown">
                <div class="row align-items-end">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="cooldown_time" class="form-label">Время задержки между сообщениями (в секундах)</label>
                            <input type="number" class="form-control" id="cooldown_time" name="cooldown_time" value="<?php echo $cooldown_time; ?>" min="0" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <button type="submit" class="btn btn-primary me-2">Сохранить настройки</button>
                            <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">Отмена</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Список сообщений</h5>
            <?php foreach ($messages as $message): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title"><?php echo htmlspecialchars($message['name'] ?? ''); ?></h5>
                                <p class="card-text"><?php echo nl2br(htmlspecialchars($message['message'] ?? '')); ?></p>
                                <p class="text-muted small">
                                    <?php if (!empty($message['email'])): ?>
                                        <?php echo htmlspecialchars($message['email']); ?> |
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($message['ip_address'] ?? ''); ?> |
                                    <?php echo date('d.m.Y H:i', strtotime($message['created_at'] ?? '')); ?>
                                </p>
                            </div>
                            <div class="text-end">
                                <?php if ($message['is_answered'] ?? false): ?>
                                    <span class="badge bg-success">Отвечено</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Ожидает ответа</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($message['image_path'])): ?>
                            <div class="mt-3">
                                <?php
                                $image_path = $message['image_path'];
                                if (strpos($image_path, 'uploads/') === 0) {
                                    $image_path = '/' . $image_path;
                                }
                                ?>
                                <img src="<?php echo htmlspecialchars($image_path); ?>"
                                     class="img-fluid rounded feedback-image"
                                     data-fallback-src="/assets/images/no-image.jpg"
                                     alt="Вложение к обращению">
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($message['reply_text'])): ?>
                            <div class="admin-reply">
                                <h6>Ответ администратора:</h6>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($message['reply_text'])); ?></p>
                            </div>
                        <?php endif; ?>
                        <div class="mt-3">
                            <button type="button" class="btn btn-primary btn-sm reply-btn"
                                    data-message-id="<?php echo $message['id']; ?>"
                                    data-message="<?php echo htmlspecialchars($message['message'] ?? ''); ?>">
                                Ответить
                            </button>
                            <button type="button" class="btn btn-success btn-sm mark-faq-btn"
                                    data-message-id="<?php echo $message['id']; ?>">
                                <?php echo ($message['is_faq'] ?? false) ? 'Убрать из FAQ' : 'Добавить в FAQ'; ?>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Вы уверены, что хотите удалить это сообщение?');">
                                <?php echo fitodomikCsrfInput(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ответ на сообщение</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo fitodomikCsrfInput(); ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="message_id" id="replyMessageId">
                    <div class="mb-3">
                        <label for="replyText" class="form-label">Ваш ответ:</label>
                        <textarea class="form-control" id="replyText" name="reply_text" rows="5" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Отправить ответ</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="faqModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Добавить в FAQ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo fitodomikCsrfInput(); ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="toggle_faq">
                    <input type="hidden" name="message_id" id="faqMessageId">
                    <input type="hidden" name="is_faq" value="1">
                    <p>Вы уверены, что хотите добавить это сообщение в FAQ?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-success">Добавить в FAQ</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function resetForm() {
    document.getElementById('settingsForm').reset();
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.feedback-image').forEach(image => {
        image.addEventListener('error', function handleImageError() {
            this.removeEventListener('error', handleImageError);
            this.src = this.dataset.fallbackSrc;
        });
    });
    document.querySelectorAll('.reply-btn').forEach(button => {
        button.addEventListener('click', function() {
            const messageId = this.dataset.messageId;
            const messageText = this.dataset.message;
            document.getElementById('replyMessageId').value = messageId;
            document.getElementById('replyText').value = messageText;
            new bootstrap.Modal(document.getElementById('replyModal')).show();
        });
    });
    document.querySelectorAll('.mark-faq-btn').forEach(button => {
        button.addEventListener('click', function() {
            const messageId = this.dataset.messageId;
            const isFaq = this.textContent.trim() === 'Убрать из FAQ';
            document.getElementById('faqMessageId').value = messageId;
            document.querySelector('input[name="is_faq"]').value = isFaq ? '0' : '1';
            const modal = document.getElementById('faqModal');
            const modalTitle = modal.querySelector('.modal-title');
            const modalButton = modal.querySelector('.btn-success');
            modalTitle.textContent = isFaq ? 'Убрать из FAQ' : 'Добавить в FAQ';
            modalButton.textContent = isFaq ? 'Убрать из FAQ' : 'Добавить в FAQ';
            new bootstrap.Modal(modal).show();
        });
    });
});
</script>
