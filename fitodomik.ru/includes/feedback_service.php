<?php
declare(strict_types=1);
require_once __DIR__ . '/image_upload.php';
class FitodomikFeedbackValidationException extends RuntimeException
{
}
function fitodomikFeedbackClientIp(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}
function fitodomikFeedbackTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function fitodomikFeedbackCooldown(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT cooldown_time FROM settings WHERE id = 1 LIMIT 1');
    return max(0, (int)($stmt->fetchColumn() ?: 0));
}
function fitodomikFeedbackWaitSeconds(PDO $pdo, string $ip, int $cooldownSeconds): int
{
    if ($cooldownSeconds < 1) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT last_message_time FROM feedback_cooldown WHERE ip_address = ? LIMIT 1');
    $stmt->execute([$ip]);
    $lastMessageTime = $stmt->fetchColumn();
    if ($lastMessageTime === false) {
        return 0;
    }
    $elapsed = time() - (int)strtotime((string)$lastMessageTime);
    return max(0, $cooldownSeconds - $elapsed);
}
function fitodomikFeedbackNotify(array $message, ?array $storedImage): void
{
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')
        || TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        throw new RuntimeException('Feedback Telegram delivery is not configured');
    }
    $escape = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $text = "Новое сообщение с сайта\n"
        . 'Имя: ' . $escape($message['name']) . "\n"
        . 'Email: ' . $escape($message['email']) . "\n"
        . 'Тип: ' . $escape($message['type']) . "\n"
        . 'Сообщение: ' . $escape($message['message']) . "\n"
        . 'IP: ' . $escape($message['ip']);
    if (!function_exists('curl_init')) { throw new RuntimeException('Feedback worker requires ext-curl'); }
    $send = static function (string $method, array $payload): void {
        $handle = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
        if ($handle === false) {
            throw new RuntimeException('Feedback Telegram connection could not be initialized');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $result = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($result === false || $status !== 200 || empty(json_decode((string)$result, true)['ok'])) {
            throw new RuntimeException('Feedback Telegram notification failed');
        }
    };
    $send('sendMessage', [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
    ]);
    if ($storedImage && is_file((string)$storedImage['absolute_path'])) {
        $send('sendPhoto', [
            'chat_id' => TELEGRAM_CHAT_ID,
            'photo' => new CURLFile((string)$storedImage['absolute_path']),
            'caption' => $text,
            'parse_mode' => 'HTML',
        ]);
    }
}
function fitodomikValidateFeedbackInput(array $post): array
{
    $name = trim((string)($post['name'] ?? ''));
    $email = trim((string)($post['email'] ?? ''));
    $message = trim((string)($post['message'] ?? ''));
    $type = trim((string)($post['type'] ?? 'question'));
    if ($name === '' || $email === '' || $message === '') {
        throw new FitodomikFeedbackValidationException('Заполните имя, email и текст сообщения.');
    }
    if (fitodomikFeedbackTextLength($name) > 100) {
        throw new FitodomikFeedbackValidationException('Имя слишком длинное.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || fitodomikFeedbackTextLength($email) > 255) {
        throw new FitodomikFeedbackValidationException('Укажите корректный email.');
    }
    if (fitodomikFeedbackTextLength($message) > 5000) {
        throw new FitodomikFeedbackValidationException('Сообщение должно быть не длиннее 5000 символов.');
    }
    if (!in_array($type, ['question', 'suggestion', 'error'], true)) {
        throw new FitodomikFeedbackValidationException('Выберите допустимый тип сообщения.');
    }
    return [$name, $email, $message, $type];
}
function fitodomikSubmitFeedback(PDO $pdo, array $post, array $files): void
{
    [$name, $email, $message, $type] = fitodomikValidateFeedbackInput($post);
    $ip = fitodomikFeedbackClientIp();
    $cooldown = fitodomikFeedbackCooldown($pdo);
    $uploadDirectory = dirname(__DIR__) . '/uploads/feedback/';
    $storedImage = null;
    try {
        $pdo->beginTransaction();
        $reserve = $pdo->prepare("INSERT INTO feedback_cooldown (ip_address,last_message_time)
            VALUES (?, '1970-01-01 00:00:00') ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address)");
        $reserve->execute([$ip]);
        $locked = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,last_message_time,NOW()) FROM feedback_cooldown WHERE ip_address = ? FOR UPDATE');
        $locked->execute([$ip]);
        $waitSeconds = max(0, $cooldown - (int)$locked->fetchColumn());
        if ($waitSeconds > 0) {
            throw new FitodomikFeedbackValidationException('Подождите ' . $waitSeconds . ' сек. перед повторной отправкой.');
        }
        $imagePath = null;
        if (isset($files['image']) && ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $storedImage = fitodomikStoreUploadedImage(
                $files['image'],
                $uploadDirectory,
                '/uploads/feedback',
                'feedback'
            );
            $imagePath = $storedImage['public_path'];
        }
        $stmt = $pdo->prepare("
            INSERT INTO feedback_messages
                (name, email, message, type, image_path, ip_address, is_answered, is_faq)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->execute([$name, $email, $message, $type, $imagePath, $ip]);
        $messageId = (int)$pdo->lastInsertId();
        if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '' && defined('TELEGRAM_CHAT_ID') && TELEGRAM_CHAT_ID !== '') {
            $pending = (int)$pdo->query('SELECT COUNT(*) FROM feedback_notification_outbox WHERE delivered_at IS NULL AND attempts < 5')->fetchColumn();
            if ($pending >= 10000) { throw new RuntimeException('Очередь уведомлений заполнена. Повторите отправку позже.'); }
            $pdo->prepare('INSERT INTO feedback_notification_outbox(message_id) VALUES (?)')->execute([$messageId]);
        }
        $stmt = $pdo->prepare("
            INSERT INTO feedback_cooldown (ip_address, last_message_time)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_message_time = VALUES(last_message_time)
        ");
        $stmt->execute([$ip]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($storedImage['absolute_path'])) {
            fitodomikDeleteUploadedImage((string)$storedImage['absolute_path'], $uploadDirectory);
        }
        throw $e;
    }
}
