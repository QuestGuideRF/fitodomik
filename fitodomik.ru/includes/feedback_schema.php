<?php
function fitodomikEnsureSettingsSchema(PDO $pdo): void
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT NOT NULL PRIMARY KEY,
            cooldown_time INT NOT NULL DEFAULT 600,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!fitodomikFeedbackColumnExists($pdo, 'settings', 'cooldown_time')) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN cooldown_time INT NOT NULL DEFAULT 600");
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (id, cooldown_time) VALUES (1, 600)");
    $stmt->execute();
}
function fitodomikFeedbackColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}
function fitodomikFeedbackIndexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}
function fitodomikEnsureFeedbackAutoIncrement(PDO $pdo, string $table): void
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $allowedTables = ['feedback_messages', 'feedback_replies'];
    if (!in_array($table, $allowedTables, true)) {
        return;
    }
    $column = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        return;
    }
    if (!fitodomikFeedbackIndexExists($pdo, $table, 'PRIMARY')) {
        $pdo->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
    }
    if (stripos((string)($column['Extra'] ?? ''), 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
    }
}
function fitodomikEnsureFeedbackSchema(PDO $pdo): void
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    fitodomikEnsureSettingsSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_notification_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL UNIQUE,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        delivered_at DATETIME DEFAULT NULL,
        INDEX idx_feedback_outbox_ready(delivered_at,available_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedback_messages (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(45) NOT NULL,
            is_answered TINYINT(1) NOT NULL DEFAULT 0,
            is_faq TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messageColumns = [
        'name' => "ALTER TABLE feedback_messages ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT ''",
        'email' => "ALTER TABLE feedback_messages ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER name",
        'message' => "ALTER TABLE feedback_messages ADD COLUMN message TEXT NULL",
        'type' => "ALTER TABLE feedback_messages ADD COLUMN type VARCHAR(50) NOT NULL DEFAULT 'question'",
        'image_path' => "ALTER TABLE feedback_messages ADD COLUMN image_path VARCHAR(255) DEFAULT NULL",
        'ip_address' => "ALTER TABLE feedback_messages ADD COLUMN ip_address VARCHAR(45) NOT NULL DEFAULT ''",
        'is_answered' => "ALTER TABLE feedback_messages ADD COLUMN is_answered TINYINT(1) NOT NULL DEFAULT 0",
        'is_faq' => "ALTER TABLE feedback_messages ADD COLUMN is_faq TINYINT(1) NOT NULL DEFAULT 0",
        'created_at' => "ALTER TABLE feedback_messages ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($messageColumns as $column => $sql) {
        if (!fitodomikFeedbackColumnExists($pdo, 'feedback_messages', $column)) {
            $pdo->exec($sql);
        }
    }
    fitodomikEnsureFeedbackAutoIncrement($pdo, 'feedback_messages');
    if (!fitodomikFeedbackIndexExists($pdo, 'feedback_messages', 'idx_feedback_faq')) {
        $pdo->exec('ALTER TABLE feedback_messages ADD INDEX idx_feedback_faq (is_faq, created_at)');
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedback_replies (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            reply_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_replies_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $replyColumns = [
        'message_id' => "ALTER TABLE feedback_replies ADD COLUMN message_id INT NOT NULL DEFAULT 0",
        'reply_text' => "ALTER TABLE feedback_replies ADD COLUMN reply_text TEXT NULL",
        'created_at' => "ALTER TABLE feedback_replies ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($replyColumns as $column => $sql) {
        if (!fitodomikFeedbackColumnExists($pdo, 'feedback_replies', $column)) {
            $pdo->exec($sql);
        }
    }
    fitodomikEnsureFeedbackAutoIncrement($pdo, 'feedback_replies');
    if (!fitodomikFeedbackIndexExists($pdo, 'feedback_replies', 'idx_feedback_replies_message')) {
        $pdo->exec('ALTER TABLE feedback_replies ADD INDEX idx_feedback_replies_message (message_id)');
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedback_cooldown (
            ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
            last_message_time DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!fitodomikFeedbackColumnExists($pdo, 'feedback_cooldown', 'last_message_time')) {
        $pdo->exec("ALTER TABLE feedback_cooldown ADD COLUMN last_message_time DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'");
    }
}
function fitodomikEnsureLegacyFeedbackSchema(PDO $pdo): void
{
    if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedback (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            attachment VARCHAR(255) DEFAULT NULL,
            status ENUM('new', 'in_progress', 'completed') DEFAULT 'new',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!fitodomikFeedbackColumnExists($pdo, 'feedback', 'ip')) {
        $pdo->exec("ALTER TABLE feedback ADD COLUMN ip VARCHAR(45) DEFAULT NULL AFTER attachment");
    }
}
