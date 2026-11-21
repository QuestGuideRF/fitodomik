<?php
require_once __DIR__ . '/api_exceptions.php';
if (!function_exists('fitodomikClientAddress')) {
    function fitodomikClientAddress()
    {
        return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}
if (!function_exists('fitodomikEnsureRateLimitStorage')) {
    function fitodomikEnsureRateLimitStorage($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS api_rate_limits (
                bucket_key CHAR(64) NOT NULL PRIMARY KEY,
                window_started_at DATETIME NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                blocked_until DATETIME DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_api_rate_limits_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
if (!function_exists('fitodomikLockRateLimitBucket')) {
    function fitodomikLockRateLimitBucket($pdo, $bucketKey)
    {
        $stmt = $pdo->prepare("
            SELECT attempts, window_started_at, blocked_until
            FROM api_rate_limits
            WHERE bucket_key = ?
            FOR UPDATE
        ");
        $stmt->execute([$bucketKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
if (!function_exists('fitodomikRateLimitState')) {
    function fitodomikRateLimitState($row, $maxAttempts, $windowSeconds, $blockSeconds)
    {
        $now = time();
        $blockedUntil = $row && !empty($row['blocked_until']) ? strtotime($row['blocked_until']) : false;
        if ($blockedUntil !== false && $blockedUntil > $now) {
            return [
                'retry_after' => max(1, $blockedUntil - $now),
                'attempts' => 0,
                'window_reset' => 0,
                'blocked' => false,
            ];
        }
        $windowStarted = $row ? strtotime((string)$row['window_started_at']) : false;
        $windowReset = !$row || $windowStarted === false || $windowStarted <= $now - $windowSeconds;
        $attempts = $windowReset ? 1 : (int)$row['attempts'] + 1;
        $blocked = $attempts > $maxAttempts;
        return [
            'retry_after' => $blocked ? $blockSeconds : 0,
            'attempts' => $attempts,
            'window_reset' => $windowReset ? 1 : 0,
            'blocked' => $blocked,
        ];
    }
}
if (!function_exists('fitodomikSaveRateLimitState')) {
    function fitodomikSaveRateLimitState($pdo, $bucketKey, $state, $blockSeconds)
    {
        $blockedSql = $state['blocked']
            ? 'DATE_ADD(NOW(), INTERVAL ' . (int)$blockSeconds . ' SECOND)'
            : 'NULL';
        $upsert = $pdo->prepare("
            INSERT INTO api_rate_limits (bucket_key, window_started_at, attempts, blocked_until)
            VALUES (?, NOW(), ?, {$blockedSql})
            ON DUPLICATE KEY UPDATE
                window_started_at = IF(? = 1, NOW(), window_started_at),
                attempts = VALUES(attempts),
                blocked_until = VALUES(blocked_until)
        ");
        $upsert->execute([$bucketKey, $state['attempts'], $state['window_reset']]);
    }
}
if (!function_exists('fitodomikConsumeRateLimit')) {
    function fitodomikConsumeRateLimit($pdo, $scope, $subject, $maxAttempts, $windowSeconds, $blockSeconds)
    {
        $maxAttempts = max(1, (int)$maxAttempts);
        $windowSeconds = max(1, (int)$windowSeconds);
        $blockSeconds = max(1, (int)$blockSeconds);
        $bucketKey = hash('sha256', (string)$scope . "\0" . (string)$subject);
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $row = fitodomikLockRateLimitBucket($pdo, $bucketKey);
            $state = fitodomikRateLimitState($row, $maxAttempts, $windowSeconds, $blockSeconds);
            if ($state['attempts'] > 0) {
                fitodomikSaveRateLimitState($pdo, $bucketKey, $state, $blockSeconds);
            }
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $state['retry_after'];
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
if (!function_exists('fitodomikClearRateLimit')) {
    function fitodomikClearRateLimit($pdo, $scope, $subject)
    {
        $bucketKey = hash('sha256', (string)$scope . "\0" . (string)$subject);
        $stmt = $pdo->prepare("DELETE FROM api_rate_limits WHERE bucket_key = ?");
        $stmt->execute([$bucketKey]);
    }
}
if (!function_exists('fitodomikApiErrorMessage')) {
    function fitodomikApiErrorMessage($exception, $fallback = 'Не удалось выполнить запрос')
    {
        $result = $fallback;
        if ($exception instanceof PDOException) {
            return $result;
        }
        $message = trim((string)$exception->getMessage());
        if ($message !== '') {
            $sensitivePatterns = [
                '/SQLSTATE/i',
                '/\b(?:SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/i',
                '/(?:\/var\/www\/|[A-Z]:\\\\)/i',
                '/\b(?:token|secret|password|credential)\b/i',
                '/\b(?:stack|trace)\b/i',
            ];
            $isSensitive = false;
            foreach ($sensitivePatterns as $pattern) {
                if (preg_match($pattern, $message)) {
                    $isSensitive = true;
                    break;
                }
            }
            if (!$isSensitive) {
                $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
                $result = function_exists('mb_substr')
                    ? mb_substr($message, 0, 240, 'UTF-8')
                    : substr($message, 0, 240);
            }
        }
        return $result;
    }
}
