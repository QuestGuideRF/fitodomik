<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/telegram_bot_common.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
const TELEGRAM_LOGIN_ERROR_REDIRECT = 'Location: /authentication/login.php?telegram_error=';
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    header(TELEGRAM_LOGIN_ERROR_REDIRECT . urlencode('Нет токена входа'));
    exit;
}
try {
    $tokenHash = hash('sha256', $token);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT t.id AS login_token_id, t.user_id AS login_user_id, t.telegram_link_id, u.*
        FROM telegram_login_tokens t
        INNER JOIN users u ON u.id = t.user_id
        INNER JOIN telegram_links l ON l.id = t.telegram_link_id AND l.user_id = t.user_id
            AND l.telegram_chat_id = t.telegram_chat_id AND l.status = 'active' 
        WHERE t.token_hash = ?
          AND t.used_at IS NULL
          AND t.expires_at > NOW()
          AND u.is_locked = 0
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!fitodomikAccountAdmitted($row)) {
        $pdo->rollBack();
        header(TELEGRAM_LOGIN_ERROR_REDIRECT . urlencode('Ссылка Telegram устарела. Запросите новую командой /login'));
        exit;
    }
    $mark = $pdo->prepare("
        UPDATE telegram_login_tokens
        SET used_at = NOW()
        WHERE id = ? AND used_at IS NULL AND expires_at > NOW()
    ");
    $mark->execute([(int)$row['login_token_id']]);
    if ($mark->rowCount() !== 1) {
        $pdo->rollBack();
        header(TELEGRAM_LOGIN_ERROR_REDIRECT . urlencode('Ссылка Telegram уже использована'));
        exit;
    }
    $newRememberCookie = fitodomikCreateRememberLogin($pdo, (int)$row['login_user_id'], false, false, $row['telegram_link_id']);
    $pdo->commit();
    session_regenerate_id(true);
    fitodomikFillSessionFromUser($row, $row['telegram_link_id']);
    fitodomikSetRememberCookie($newRememberCookie);
    header('Location: /farm.php');
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fitodomikClearRememberCookie();
    error_log('telegram_login.php: ' . $e->getMessage());
    header(TELEGRAM_LOGIN_ERROR_REDIRECT . urlencode('Не удалось войти через Telegram'));
    exit;
}
