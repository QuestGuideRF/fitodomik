<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--backup-verified', $argv, true)) {
    fwrite(STDERR, "Restore-test a database backup first, then pass --backup-verified.\n");
    exit(2);
}
define('FITODOMIK_SCHEMA_MAINTENANCE', true);
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema.php';
fitodomikEnsureCurrentSchema($pdo);
require __DIR__ . '/../config/device_commands.php';
require __DIR__ . '/../config/device_dynamic_config.php';
require __DIR__ . '/../config/sensor_profiles.php';
require __DIR__ . '/../config/api_security.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/telegram_bot_features.php';
fitodomikEnsureDeviceStorage($pdo);
fitodomikEnsureCommandStorage($pdo);
fitodomikEnsureDynamicDeviceConfigStorage($pdo);
fitodomikEnsureNotificationStorage($pdo);
fitodomikSensorProfilesEnsureStorage($pdo);
fitodomikEnsureRememberStorage($pdo);
fitodomikEnsureRateLimitStorage($pdo);
devicePairEnsureTables($pdo);
fitodomikBotEnsureStorage($pdo);
fitodomikTgEnsureStorage($pdo);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/feedback_schema.php';
require __DIR__ . '/../includes/gallery_helpers.php';
fitodomikEnsureFeedbackSchema($pdo);
fitodomikEnsureLegacyFeedbackSchema($pdo);
galleryEnsureSchema($pdo);
require __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../config/access_schema.php';
fitodomikEnsureAccessSchema($pdo);
error_reporting(E_ALL);
echo "Base and access schema prepared. Apply remaining versioned SQL migrations before serving traffic.\n";
