<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/headers.php';
require_once '../config/device_context.php';
if (!isLoggedIn()) {
    header('Location: /authentication/login.php?redirect=' . urlencode('/farm.php'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Метод не разрешён';
    exit;
}
fitodomikRequireCsrf();
$userId = (int)$_SESSION['user_id'];
$deviceId = trim((string)($_POST['device_id'] ?? ''));
$requestedRedirect = trim((string)($_POST['redirect'] ?? ''));
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$redirectSource = $requestedRedirect !== '' ? $requestedRedirect : $referer;
$redirect = fitodomikSafeRedirect($redirectSource, '/farm.php');
try {
    if ($deviceId !== '' && fitodomikSelectDevice($pdo, $userId, $deviceId)) {
        header('Location: ' . $redirect);
        exit;
    }
} catch (Exception $e) {
    error_log('select-device.php: ' . $e->getMessage());
}
header('Location: /farm.php');
exit;
