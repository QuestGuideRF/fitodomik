<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type']);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/headers.php';
require_once '../config/device_context.php';
require_once '../config/api_security.php';
function jsonResponse($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['success' => false, 'message' => 'Метод не разрешён']);
}
$maxBodyBytes = 64 * 1024;
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBodyBytes) {
    jsonResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
}
$raw = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
if (is_string($raw) && strlen($raw) > $maxBodyBytes) {
    jsonResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
}
$json = json_decode($raw, true);
$input = is_array($json) ? $json : $_POST;
$username = trim($input['username'] ?? '');
$password = (string)($input['password'] ?? '');
$device_name = fitodomikNormalizeDeviceName($input['device_name'] ?? 'FitoDomik');
$device_id = trim((string)($input['device_id'] ?? ''));
if ($username === '' || $password === '') {
    jsonResponse(400, ['success' => false, 'message' => 'Введите имя пользователя и пароль']);
}
try {
    $rateSubject = fitodomikClientAddress() . '|' . strtolower($username);
    $retryAfter = fitodomikConsumeRateLimit($pdo, 'desktop-login', $rateSubject, 10, 900, 900);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        jsonResponse(429, ['success' => false, 'message' => 'Слишком много попыток. Повторите позже.']);
    }
    $stmt = $pdo->prepare("SELECT id, username, first_name, last_name, password, is_locked FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!fitodomikAccountAdmitted($user) || !password_verify($password, $user['password'])) {
        jsonResponse(401, ['success' => false, 'message' => 'Неверное имя пользователя или пароль']);
    }
    fitodomikClearRateLimit($pdo, 'desktop-login', $rateSubject);
    if ($device_id === '') {
        $device_id = fitodomikCreateDeviceId($pdo);
    }
    $device = fitodomikEnsureUserDevice($pdo, (int)$user['id'], $device_id, $device_name);
    $token = fitodomikCreateClientGrant($pdo, (int)$user['id'], $device['device_id']);
    $grantUser = fitodomikClientGrantUser($pdo, $token);
    $device['device_token'] = fitodomikClientDeviceToken($pdo, $grantUser, $device['device_id']);
    jsonResponse(200, [
        'success' => true,
        'message' => 'Аккаунт успешно привязан',
        'token' => $token,
        'device_id' => $device['device_id'],
        'device_token' => $device['device_token'],
        'device_name' => $device['device_name'],
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
        ],
    ]);
} catch (DomainException $e) {
    jsonResponse(409, [
        'success' => false,
        'message' => 'Этот идентификатор устройства нельзя привязать к аккаунту',
    ]);
} catch (Exception $e) {
    error_log('desktop-login.php: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Ошибка сервера']);
}
