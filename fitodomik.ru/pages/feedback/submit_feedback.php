<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/feedback_service.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /pages/feedback/');
    exit;
}
try {
    fitodomikRequireCsrf();
    fitodomikSubmitFeedback($pdo, $_POST, $_FILES);
    $_SESSION['feedback_success'] = 'Сообщение успешно отправлено.';
} catch (FitodomikFeedbackValidationException | FitodomikImageUploadException $e) {
    $_SESSION['feedback_error'] = $e->getMessage();
} catch (Throwable $e) {
    error_log('Legacy feedback endpoint failed: ' . $e->getMessage());
    $_SESSION['feedback_error'] = 'Не удалось отправить сообщение. Попробуйте ещё раз.';
}
header('Location: /pages/feedback/');
exit;
