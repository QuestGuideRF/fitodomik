<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/api_security.php';
require_once __DIR__ . '/site.php';
if (!function_exists('fitodomikAllowedOrigins')) {
    function fitodomikAllowedOrigins()
    {
        $configured = trim((string)fitodomikEnv('CORS_ALLOWED_ORIGINS', ''));
        $origins = [fitodomikSiteUrl()];
        if ($configured !== '') {
            $origins = array_merge($origins, array_filter(array_map('trim', explode(',', $configured))));
        }
        return array_values(array_unique($origins));
    }
}
if (!function_exists('fitodomikIsDebugMode')) {
    function fitodomikIsDebugMode()
    {
        return filter_var(fitodomikEnv('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    }
}
if (!function_exists('fitodomikIsAllowedOrigin')) {
    function fitodomikIsAllowedOrigin($origin)
    {
        $origin = rtrim(trim((string)$origin), '/');
        $allowed = $origin === '';
        if ($origin === '') {
            return $allowed;
        }
        foreach (fitodomikAllowedOrigins() as $allowedOrigin) {
            if (hash_equals(rtrim($allowedOrigin, '/'), $origin)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed && fitodomikIsDebugMode()) {
            $allowed = (bool)preg_match(
                '#^https?://(?:localhost|127\.0\.0\.1)(?::\d{1,5})?$#i',
                $origin
            );
        }
        return $allowed;
    }
}
if (!function_exists('fitodomikCorsReject')) {
    function fitodomikCorsReject()
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Источник запроса не разрешён',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('fitodomikApplyCors')) {
    function fitodomikApplyCors($methods = ['GET', 'POST'], $allowedHeaders = [], $allowCredentials = true)
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        header('Vary: Origin', false);
        if ($origin !== '') {
            if (!fitodomikIsAllowedOrigin($origin)) {
                fitodomikCorsReject();
            }
            header('Access-Control-Allow-Origin: ' . $origin);
            if ($allowCredentials) {
                header('Access-Control-Allow-Credentials: true');
            }
        }
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        if (!in_array('OPTIONS', $methods, true)) {
            $methods[] = 'OPTIONS';
        }
        $headers = array_values(array_unique(array_merge(
            ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token', 'X-CSRF-Token'],
            $allowedHeaders
        )));
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $headers));
        $requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($requestMethod === 'OPTIONS') {
            header('Access-Control-Max-Age: 600');
            http_response_code(204);
            exit;
        }
        if (!in_array($requestMethod, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Метод не разрешён',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
