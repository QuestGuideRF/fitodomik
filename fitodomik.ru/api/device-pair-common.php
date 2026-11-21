<?php
require_once __DIR__ . '/../config/device_context.php';
require_once __DIR__ . '/../config/api_security.php';
require_once __DIR__ . '/../config/site.php';
require_once __DIR__ . '/../config/json_response.php';
function devicePairJsonResponse($status, $payload)
{
    fitodomikJsonResponse($status, $payload, JSON_UNESCAPED_UNICODE);
}
function devicePairInput()
{
    $maxBodyBytes = 64 * 1024;
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBodyBytes) {
        devicePairJsonResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
    if (is_string($raw) && strlen($raw) > $maxBodyBytes) {
        devicePairJsonResponse(413, ['success' => false, 'message' => 'Тело запроса слишком большое']);
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}
function devicePairPublicBaseUrl()
{
    return fitodomikSiteUrl();
}
function devicePairAuthToken()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['X-Auth-Token'])) {
        return trim((string)$headers['X-Auth-Token']);
    }
    if (isset($headers['x-auth-token'])) {
        return trim((string)$headers['x-auth-token']);
    }
    return trim((string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
}
function devicePairUserByToken($pdo, $token)
{
    return fitodomikUserByToken($pdo, $token);
}
function devicePairExpireOldRequests($pdo)
{
    $stmt = $pdo->prepare("
        UPDATE device_pair_requests
        SET status = 'expired'
        WHERE status IN ('pending', 'approved')
          AND token_delivered_at IS NULL
          AND expires_at < NOW()
    ");
    $stmt->execute();
    $cleanup = $pdo->prepare("
        DELETE FROM device_pair_requests
        WHERE (
            status IN ('expired', 'cancelled')
            OR token_delivered_at IS NOT NULL
        )
          AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $cleanup->execute();
}
function devicePairSecretMatches($request, $secret)
{
    $expected = trim((string)($request['client_secret_hash'] ?? ''));
    $secret = trim((string)$secret);
    if ($expected === '' || $secret === '') {
        return false;
    }
    return hash_equals($expected, hash('sha256', $secret));
}
