<?php
require_once __DIR__ . '/env.php';
const FITODOMIK_RU_LOG_DATETIME_FORMAT = 'Y-m-d H:i:s';
const FITODOMIK_RU_LOG_DIR_SUFFIX = '/../logs';
$debugEnabled = strtolower((string)fitodomikRuEnv('APP_ENV', 'production')) === 'development';
ini_set('display_errors', $debugEnabled ? '1' : '0');
ini_set('display_startup_errors', $debugEnabled ? '1' : '0');
error_reporting(E_ALL);
function fitodomikLogDirectory(): string
{
    return __DIR__ . FITODOMIK_RU_LOG_DIR_SUFFIX;
}
function fitodomikEnsureLogDirectory(): ?string
{
    $logDir = fitodomikLogDirectory();
    if (!is_dir($logDir) && (!is_writable(dirname($logDir)) || !mkdir($logDir, 0755, true))) {
        return null;
    }
    return is_writable($logDir) ? $logDir : null;
}
function logDebug($message, $type = 'INFO'): void
{
    $logDir = fitodomikEnsureLogDirectory();
    if ($logDir === null) {
        return;
    }
    $timestamp = date(FITODOMIK_RU_LOG_DATETIME_FORMAT);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user = $_SESSION['user_id'] ?? 'guest';
    $url = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $logMessage = "[$timestamp] [$type] [IP: $ip] [User: $user] [URL: $url] $message\n";
    error_log($logMessage, 3, $logDir . '/debug.log');
}
function logUserAction($message): void
{
    $logDir = fitodomikEnsureLogDirectory();
    if ($logDir === null) {
        return;
    }
    $timestamp = date(FITODOMIK_RU_LOG_DATETIME_FORMAT);
    $user = $_SESSION['username'] ?? 'Гость';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    error_log("[$timestamp] [$ip] [$user] $message" . PHP_EOL, 3, $logDir . '/user_actions.log');
}
function logError($message): void
{
    $logDir = fitodomikEnsureLogDirectory();
    if ($logDir === null) {
        return;
    }
    $timestamp = date(FITODOMIK_RU_LOG_DATETIME_FORMAT);
    $user = $_SESSION['username'] ?? 'Гость';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    error_log("[$timestamp] [$ip] [$user] $message" . PHP_EOL, 3, $logDir . '/errors.log');
}
function logSQL($query, $params = []): void
{
    $message = "SQL Query: $query";
    if (!empty($params)) {
        $message .= ' Params count: ' . count($params);
    }
    logDebug($message, 'SQL');
}
fitodomikEnsureLogDirectory();
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    logError("Ошибка [$errno]: $errstr в файле $errfile на строке $errline");
    return false;
});
set_exception_handler(function ($exception) {
    logError(
        'Необработанное исключение: ' . $exception->getMessage()
        . ' в файле ' . $exception->getFile()
        . ' на строке ' . $exception->getLine()
    );
});
logDebug('Request started');
