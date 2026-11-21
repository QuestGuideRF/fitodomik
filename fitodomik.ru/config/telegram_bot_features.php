<?php
require_once __DIR__ . '/telegram_bot_common.php';
function fitodomikTgEnsureLegacyStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_user_id', TELEGRAM_USER_ID_COLUMN);
    fitodomikAddColumnIfMissing($pdo, 'users', 'telegram_last_name', TELEGRAM_FIRST_NAME_COLUMN);
    fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'telegram_user_id', TELEGRAM_USER_ID_COLUMN);
    fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'telegram_last_name', TELEGRAM_FIRST_NAME_COLUMN);
    fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `enabled`");
    fitodomikAddColumnIfMissing($pdo, 'user_telegram_settings', 'last_seen_at', "DATETIME DEFAULT NULL AFTER `status`");
    fitodomikAddIndexIfMissing($pdo, 'user_telegram_settings', 'idx_user_telegram_id', '`telegram_user_id`');
}
function fitodomikTgEnsureLinkStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_links (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            telegram_user_id BIGINT NOT NULL,
            telegram_chat_id VARCHAR(64) NOT NULL,
            telegram_username VARCHAR(120) DEFAULT NULL,
            telegram_first_name VARCHAR(120) DEFAULT NULL,
            telegram_last_name VARCHAR(120) DEFAULT NULL,
            status ENUM('active','revoked') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            INDEX idx_telegram_links_telegram_user (telegram_user_id),
            INDEX idx_telegram_links_user (user_id),
            INDEX idx_telegram_links_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (fitodomikIndexExists($pdo, 'telegram_links', 'uniq_telegram_user_active')) {
        try {
            $pdo->exec("ALTER TABLE telegram_links DROP INDEX uniq_telegram_user_active");
        } catch (Exception $e) {
            error_log('telegram_links drop obsolete unique index failed');
        }
    }
    fitodomikAddIndexIfMissing($pdo, 'telegram_links', 'idx_telegram_links_telegram_user', '`telegram_user_id`');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_link_codes (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(20) NOT NULL UNIQUE,
            deep_token_hash CHAR(64) DEFAULT NULL,
            status ENUM('pending','used','expired','revoked') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            telegram_user_id BIGINT DEFAULT NULL,
            telegram_chat_id VARCHAR(64) DEFAULT NULL,
            INDEX idx_tg_link_codes_user (user_id),
            INDEX idx_tg_link_codes_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    fitodomikAddColumnIfMissing(
        $pdo,
        'telegram_link_codes',
        'deep_token_hash',
        "CHAR(64) DEFAULT NULL AFTER `code`"
    );
    fitodomikAddUniqueIndexIfMissing(
        $pdo,
        'telegram_link_codes',
        'uniq_tg_link_deep_token',
        '`deep_token_hash`'
    );
}
function fitodomikTgEnsureSessionStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_sessions (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            telegram_user_id BIGINT NOT NULL UNIQUE,
            user_id INT NOT NULL,
            selected_device_id VARCHAR(64) DEFAULT NULL,
            state VARCHAR(80) DEFAULT NULL,
            state_payload LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_seen_at DATETIME DEFAULT NULL,
            INDEX idx_tg_sessions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
function fitodomikTgEnsureSettingsStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_settings (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_id VARCHAR(64) DEFAULT NULL,
            alerts_enabled TINYINT(1) NOT NULL DEFAULT 1,
            daily_report_enabled TINYINT(1) NOT NULL DEFAULT 1,
            offline_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
            arduino_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
            camera_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
            analysis_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
            photo_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
            system_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
            report_time VARCHAR(5) NOT NULL DEFAULT '20:00',
            quiet_hours_start VARCHAR(5) DEFAULT NULL,
            quiet_hours_end VARCHAR(5) DEFAULT NULL,
            offline_after_minutes INT NOT NULL DEFAULT 120,
            alert_cooldown_minutes INT NOT NULL DEFAULT 180,
            last_daily_report_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tg_settings_device (user_id, device_id),
            INDEX idx_tg_settings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    fitodomikAddColumnIfMissing($pdo, 'telegram_settings', 'photo_alert_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `analysis_alert_enabled`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_settings', 'system_alert_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `photo_alert_enabled`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_settings', 'offline_after_minutes', "INT NOT NULL DEFAULT 120 AFTER `quiet_hours_end`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_settings', 'alert_cooldown_minutes', "INT NOT NULL DEFAULT 180 AFTER `offline_after_minutes`");
    fitodomikAddColumnIfMissing($pdo, 'telegram_settings', 'last_daily_report_date', "DATE DEFAULT NULL AFTER `alert_cooldown_minutes`");
}
function fitodomikTgEnsureAlertStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_alert_state (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_id VARCHAR(64) NOT NULL,
            alert_key VARCHAR(80) NOT NULL,
            active_since DATETIME DEFAULT NULL,
            last_sent_at DATETIME DEFAULT NULL,
            last_alert_value VARCHAR(120) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tg_alert_state (user_id, device_id, alert_key),
            INDEX idx_tg_alert_state_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    fitodomikAddColumnIfMissing($pdo, 'telegram_alert_state', 'last_alert_value', "VARCHAR(120) DEFAULT NULL AFTER `last_sent_at`");
}
function fitodomikTgEnsureCallbackStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_callback_actions (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            telegram_user_id BIGINT NOT NULL,
            device_id VARCHAR(64) NOT NULL,
            action VARCHAR(80) NOT NULL,
            action_payload LONGTEXT DEFAULT NULL,
            status ENUM('pending','used','cancelled','expired') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            INDEX idx_tg_callback_user (user_id, telegram_user_id),
            INDEX idx_tg_callback_status_expires (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
function fitodomikTgEnsureEventLogStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    if (fitodomikTableExists($pdo, 'event_log')) {
        fitodomikAddColumnIfMissing($pdo, 'event_log', 'device_id', "VARCHAR(64) DEFAULT NULL AFTER `user_id`");
        fitodomikAddIndexIfMissing($pdo, 'event_log', 'idx_event_user_device_created', '`user_id`, `device_id`, `created_at`');
    }
}
function fitodomikTgEnsureStorage($pdo)
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    static $storageReady = false;
    if ($storageReady) {
        return;
    }
    fitodomikBotEnsureStorage($pdo);
    fitodomikTgEnsureLegacyStorage($pdo);
    fitodomikTgEnsureLinkStorage($pdo);
    fitodomikTgEnsureSessionStorage($pdo);
    fitodomikTgEnsureSettingsStorage($pdo);
    fitodomikTgEnsureAlertStorage($pdo);
    fitodomikTgEnsureCallbackStorage($pdo);
    fitodomikTgEnsureEventLogStorage($pdo);
    $storageReady = true;
}
function fitodomikTgCreateLinkCode($pdo, $user_id)
{
    $pdo->prepare("UPDATE telegram_link_codes SET status = 'revoked' WHERE user_id = ? AND status = 'pending'")
        ->execute([(int)$user_id]);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $deepToken = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("
            INSERT INTO telegram_link_codes (user_id, code, deep_token_hash, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ");
        try {
            $stmt->execute([(int)$user_id, $code, hash('sha256', $deepToken)]);
            return ['code' => $code, 'deep_token' => $deepToken];
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'duplicate') === false) {
                throw $e;
            }
        }
    }
    throw new FitodomikStorageException('Не удалось создать код Telegram');
}
function fitodomikTgGetLinkStatus($pdo, $user_id)
{
    $stmt = $pdo->prepare("
        SELECT * FROM telegram_links
        WHERE user_id = ? AND status = 'active'
        ORDER BY linked_at DESC LIMIT 1
    ");
    $stmt->execute([(int)$user_id]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    $codeStmt = $pdo->prepare("
        SELECT code, expires_at FROM telegram_link_codes
        WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()
        ORDER BY created_at DESC LIMIT 1
    ");
    $codeStmt->execute([(int)$user_id]);
    return [
        'link' => $link ?: null,
        'code' => $codeStmt->fetch(PDO::FETCH_ASSOC) ?: null,
    ];
}
function fitodomikTgUnlinkUser($pdo, $user_id)
{
    $userId = (int)$user_id;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET auth_generation = auth_generation + 1 WHERE id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE telegram_login_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare('UPDATE user_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$userId]);
        $pdo->prepare("UPDATE telegram_links SET status = 'revoked', revoked_at = NOW() WHERE user_id = ? AND status = 'active'")
            ->execute([$userId]);
        $pdo->prepare("UPDATE telegram_link_codes SET status = 'revoked' WHERE user_id = ? AND status = 'pending'")
            ->execute([$userId]);
        $pdo->prepare("UPDATE telegram_callback_actions SET status = 'cancelled', used_at = NOW() WHERE user_id = ? AND status = 'pending'")
            ->execute([$userId]);
        $pdo->prepare("UPDATE user_telegram_settings SET status = 'revoked', enabled = 0 WHERE user_id = ?")
            ->execute([$userId]);
        $pdo->prepare("UPDATE users SET telegram_user_id = NULL, telegram_chat_id = NULL WHERE id = ?")
            ->execute([$userId]);
        $pdo->prepare("DELETE FROM telegram_sessions WHERE user_id = ?")
            ->execute([$userId]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
function fitodomikTgTouchSession($pdo, $telegram_user_id, $user_id)
{
    $stmt = $pdo->prepare("
        INSERT INTO telegram_sessions (telegram_user_id, user_id, last_seen_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = NOW()
    ");
    $stmt->execute([(int)$telegram_user_id, (int)$user_id]);
}
