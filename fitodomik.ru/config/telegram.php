<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/api_exceptions.php';
$telegramToken = trim((string)fitodomikEnv('TELEGRAM_BOT_TOKEN'));
$telegramBotUsername = trim((string)fitodomikEnv('TELEGRAM_BOT_USERNAME'));
$schemaMaintenance = PHP_SAPI === 'cli' && defined('FITODOMIK_SCHEMA_MAINTENANCE') && FITODOMIK_SCHEMA_MAINTENANCE;
if (!$schemaMaintenance && $telegramToken === '') {
    error_log("Telegram Bot Token не настроен в корневом .env");
    throw new FitodomikConfigurationException('Telegram Bot Token не настроен');
}
if (!$schemaMaintenance && preg_match('/^\w{5,32}$/D', $telegramBotUsername) !== 1) {
    error_log("Telegram Bot Username не настроен в корневом .env");
    throw new FitodomikConfigurationException('Telegram Bot Username не настроен');
}
if (!defined('TELEGRAM_BOT_TOKEN')) {
    define('TELEGRAM_BOT_TOKEN', $telegramToken);
}
if (!defined('TELEGRAM_BOT_USERNAME')) {
    define('TELEGRAM_BOT_USERNAME', $telegramBotUsername);
}
