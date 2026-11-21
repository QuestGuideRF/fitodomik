<?php
require_once __DIR__ . '/env_loader.php';
if (!function_exists('fitodomikAppDebugEnabled')) {
    function fitodomikAppDebugEnabled()
    {
        return in_array(
            strtolower(trim((string)fitodomikEnv('APP_DEBUG', '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
if (!function_exists('fitodomikConfigureErrorHandling')) {
    function fitodomikPhpErrorLogPath(): string
    {
        $configured = trim((string)fitodomikEnv('FITODOMIK_PHP_ERROR_LOG', ''));
        return $configured !== '' ? $configured : dirname(__DIR__) . '/logs/php-error.log';
    }
    function fitodomikHandleUncaughtException(Throwable $error): void
    {
        $reference = bin2hex(random_bytes(8));
        $message = $error->getMessage();
        foreach (['DB_PASSWORD', 'DB_PASS', 'TELEGRAM_BOT_TOKEN', 'CRON_SECRET'] as $key) {
            $secret = (string)fitodomikEnv($key, '');
            if (strlen($secret) >= 4) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }
        $driverCode = $error instanceof PDOException ? (int)($error->errorInfo[1] ?? 0) : 0;
        $accessSchemaMissing = ($driverCode === 1054 && preg_match('/\b(?:auth_generation|telegram_link_id)\b/i', $error->getMessage()))
            || ($driverCode === 1146 && preg_match('/\b(?:account_client_grants|client_device_credentials)\b/i', $error->getMessage()));
        $entry = [
            'time' => gmdate('c'), 'request_id' => $reference,
            'type' => get_class($error), 'message' => substr($message, 0, 8192),
            'file' => $error->getFile(), 'line' => $error->getLine(),
        ];
        if ($accessSchemaMissing) {
            $entry['action'] = 'Verify a database backup; run php tools/prepare-schema.php --backup-verified using maintenance credentials.';
        }
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        if (!@error_log($line, 3, fitodomikPhpErrorLogPath())) {
            error_log($line);
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Application error [' . $reference . ']. See PHP error log.' . PHP_EOL);
            exit(1);
        }
        $status = $accessSchemaMissing ? 503 : 500;
        $publicMessage = $accessSchemaMissing
            ? 'Обновление сайта не завершено. Требуется обновление структуры базы данных.'
            : 'Не удалось обработать запрос. Подробности сохранены в журнале ошибок.';
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: private, no-store');
            header('X-Request-ID: ' . $reference);
            header('X-Content-Type-Options: nosniff');
        }
        if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false) {
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            if (function_exists('fitodomikUsesContractV2') && fitodomikUsesContractV2()) {
                fitodomikContractResponse(['success' => false, 'message' => $publicMessage], $status, null,
                    $accessSchemaMissing ? 'schema_upgrade_required' : 'internal_error');
            }
            echo json_encode(['success' => false, 'message' => $publicMessage,
                'error' => $accessSchemaMissing ? 'schema_upgrade_required' : 'internal_error',
                'request_id' => $reference], JSON_UNESCAPED_UNICODE);
        } else {
            if (!headers_sent()) { header('Content-Type: text/plain; charset=utf-8'); }
            echo $publicMessage . ' [' . $reference . ']';
        }
        exit;
    }
    function fitodomikConfigureErrorHandling()
    {
        static $configured = false;
        if ($configured) { return; }
        $configured = true;
        $debug = fitodomikAppDebugEnabled();
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        // Do not let native exception traces record passwords passed to PDO.
        ini_set('zend.exception_ignore_args', '1');
        $logPath = fitodomikPhpErrorLogPath();
        $logDirectory = dirname($logPath);
        if (!is_dir($logDirectory)) { @mkdir($logDirectory, 0700, true); }
        if (is_dir($logDirectory) && is_writable($logDirectory)) {
            $newFile = !file_exists($logPath);
            $file = @fopen($logPath, 'ab');
            if ($file !== false) {
                fclose($file);
                if ($newFile) { @chmod($logPath, 0600); }
                ini_set('error_log', $logPath);
            }
        }
        error_reporting(E_ALL);
        set_exception_handler('fitodomikHandleUncaughtException');
    }
}
fitodomikConfigureErrorHandling();
