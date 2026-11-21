<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/telegram_bot_features.php';
function verificationResponse($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    verificationResponse([
        'verified' => false,
        'message' => 'Метод не разрешён',
    ], 405);
}
try {
    $registrationLink = $_SESSION['telegram_registration_link'] ?? null;
    $registrationUserId = is_array($registrationLink) ? (int)($registrationLink['user_id'] ?? 0) : 0;
    if ($registrationUserId < 1 || $registrationUserId !== (int)($_SESSION['user_id'] ?? 0)) {
        verificationResponse([
            'verified' => false,
            'message' => 'Нет активной регистрации. Вернитесь на страницу регистрации.',
        ]);
    }
    $status = fitodomikTgGetLinkStatus($pdo, $registrationUserId);
    if (is_array($status['link'])) {
        $redirect = fitodomikSafeRedirect(
            $registrationLink['redirect'] ?? $_SESSION['registration_redirect'] ?? '',
            '/farm.php'
        );
        unset($_SESSION['telegram_registration_link'], $_SESSION['registration_redirect']);
        verificationResponse([
            'verified' => true,
            'message' => 'Telegram успешно подключён.',
            'redirect' => $redirect,
        ]);
    }
    if (!is_array($status['code'])) {
        verificationResponse([
            'verified' => false,
            'terminal' => true,
            'can_continue_without_telegram' => true,
            'message' => 'Срок действия кода истёк. Подключите Telegram позже в настройках аккаунта.',
        ]);
    }
    verificationResponse([
        'verified' => false,
        'message' => 'Ожидаем команду с кодом в Telegram-боте',
    ]);
} catch (Exception $e) {
    error_log('check_verification.php: ' . $e->getMessage());
    verificationResponse([
        'verified' => false,
        'message' => 'Ошибка сервера при проверке Telegram',
    ], 500);
}
