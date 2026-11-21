<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/device_context.php';
require_once __DIR__ . '/api_security.php';
const TELEGRAM_USER_ID_COLUMN = 'BIGINT DEFAULT NULL AFTER `telegram_chat_id`';
const TELEGRAM_FIRST_NAME_COLUMN = 'VARCHAR(120) DEFAULT NULL AFTER `telegram_username`';
function fitodomikBotSiteUrl()
{
    return fitodomikSiteUrl();
}
function fitodomikBotRepairVerificationId($pdo)
{
    $stmt = $pdo->prepare("
        SELECT EXTRA FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'telegram_verifications'
          AND COLUMN_NAME = 'id'
        LIMIT 1
    ");
    $stmt->execute();
    $extra = strtolower((string)$stmt->fetchColumn());
    if (strpos($extra, 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE telegram_verifications MODIFY id BIGINT NOT NULL AUTO_INCREMENT");
    }
}
function fitodomikBotEnsureStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    static $storageReady = false;
    if ($storageReady) {
        return;
    }
    fitodomikEnsureNotificationStorage($pdo);
    fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_username', "VARCHAR(120) DEFAULT NULL");
    fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_chat_id', "VARCHAR(64) DEFAULT NULL");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_verifications (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(120) NOT NULL UNIQUE,
            telegram_chat_id VARCHAR(64) NOT NULL,
            telegram_user_id BIGINT DEFAULT NULL,
            telegram_username VARCHAR(120) DEFAULT NULL,
            telegram_first_name VARCHAR(120) DEFAULT NULL,
            registration_code_hash CHAR(64) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_telegram_verifications_chat (telegram_chat_id),
            INDEX idx_telegram_verifications_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    fitodomikAddColumnIfMissing($pdo, 'telegram_verifications', 'telegram_user_id', TELEGRAM_USER_ID_COLUMN);
    fitodomikAddColumnIfMissing($pdo, 'telegram_verifications', 'telegram_username', "VARCHAR(120) DEFAULT NULL AFTER `telegram_user_id`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_verifications', 'telegram_first_name', TELEGRAM_FIRST_NAME_COLUMN);
    fitodomikAddColumnIfMissing($pdo, 'telegram_verifications', 'registration_code_hash', "CHAR(64) DEFAULT NULL AFTER `telegram_first_name`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_verifications', 'user_id', "INT DEFAULT NULL AFTER `registration_code_hash`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_verifications', 'verified_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `user_id`");
    fitodomikBotRepairVerificationId($pdo);
    fitodomikAddUniqueIndexIfMissing(
        $pdo,
        'telegram_verifications',
        'idx_telegram_verifications_username',
        '`username`'
    );
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_login_tokens (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            telegram_chat_id VARCHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telegram_login_user (user_id),
            INDEX idx_telegram_login_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $storageReady = true;
}
function fitodomikBotApi($method, array $params = [], $requestTimeout = 30)
{
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $requestTimeout,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new FitodomikExternalServiceException('Telegram API: ' . $error);
        }
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => $requestTimeout,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new FitodomikExternalServiceException('Telegram API недоступен');
        }
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        $message = is_array($data) ? ($data['description'] ?? 'неизвестная ошибка') : 'пустой ответ';
        throw new FitodomikExternalServiceException('Telegram API: ' . $message);
    }
    return $data['result'];
}
function fitodomikBotSendMessage($chat_id, $text, array $extra = [])
{
    return fitodomikBotApi('sendMessage', array_merge([
        'chat_id' => (string)$chat_id,
        'text' => $text,
        'disable_web_page_preview' => true,
    ], $extra));
}
function fitodomikBotLinkChatToUser(
    $pdo,
    array $user,
    $chat_id,
    $telegram_user_id,
    $telegram_username,
    $telegram_first_name,
    $ensureStorage = true
) {
    $update = $pdo->prepare("
        UPDATE users SET telegram_chat_id = ?, telegram_user_id = ?, telegram_username = ?
        WHERE id = ?
    ");
    $update->execute([(string)$chat_id, $telegram_user_id, $telegram_username, (int)$user['id']]);
    $stmt = $pdo->prepare("
        INSERT INTO user_telegram_settings (
            user_id, telegram_chat_id, telegram_user_id, telegram_username,
            enabled, status, last_seen_at
        ) VALUES (?, ?, ?, ?, 1, 'active', NOW())
        ON DUPLICATE KEY UPDATE telegram_chat_id = VALUES(telegram_chat_id),
            telegram_user_id = VALUES(telegram_user_id),
            telegram_username = VALUES(telegram_username), enabled = 1,
            status = 'active', last_seen_at = NOW()
    ");
    $stmt->execute([(int)$user['id'], (string)$chat_id, $telegram_user_id, $telegram_username]);
    $pdo->prepare("UPDATE telegram_links SET status = 'revoked', revoked_at = NOW() WHERE user_id = ? AND status = 'active'")
        ->execute([(int)$user['id']]);
    $pdo->prepare("UPDATE telegram_links SET status = 'revoked', revoked_at = NOW() WHERE telegram_user_id = ? AND status = 'active'")
        ->execute([(int)$telegram_user_id]);
    $pdo->prepare("UPDATE telegram_link_codes SET status = 'revoked' WHERE user_id = ? AND status = 'pending'")
        ->execute([(int)$user['id']]);
    $link = $pdo->prepare("
        INSERT INTO telegram_links (
            user_id, telegram_user_id, telegram_chat_id, telegram_username,
            telegram_first_name, status, linked_at, last_seen_at
        ) VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())
    ");
    $link->execute([
        (int)$user['id'],
        (int)$telegram_user_id,
        (string)$chat_id,
        $telegram_username,
        $telegram_first_name,
    ]);
    fitodomikTgTouchSession($pdo, (int)$telegram_user_id, (int)$user['id']);
    $verify = $pdo->prepare("
        INSERT INTO telegram_verifications (
            username, telegram_chat_id, telegram_user_id, telegram_username,
            telegram_first_name, registration_code_hash, user_id, verified_at
        ) VALUES (?, ?, ?, ?, ?, NULL, ?, NOW())
        ON DUPLICATE KEY UPDATE telegram_chat_id = VALUES(telegram_chat_id),
            telegram_user_id = VALUES(telegram_user_id),
            telegram_username = VALUES(telegram_username),
            telegram_first_name = VALUES(telegram_first_name),
            registration_code_hash = NULL, user_id = VALUES(user_id), verified_at = NOW()
    ");
    $verify->execute([
        $user['username'],
        (string)$chat_id,
        $telegram_user_id,
        $telegram_username,
        $telegram_first_name,
        (int)$user['id'],
    ]);
}
