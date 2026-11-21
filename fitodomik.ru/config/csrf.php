<?php
if (!function_exists('fitodomikCsrfToken')) {
    function fitodomikCsrfToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = $_SESSION['fitodomikCsrfToken'] ?? '';
        if (!is_string($token) || strlen($token) < 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['fitodomikCsrfToken'] = $token;
        }
        return $token;
    }
}
if (!function_exists('fitodomikCsrfInput')) {
    function fitodomikCsrfInput()
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(fitodomikCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
    }
}
if (!function_exists('fitodomikCsrfRequestToken')) {
    function fitodomikCsrfRequestToken()
    {
        $postToken = $_POST['csrf_token'] ?? '';
        if (is_string($postToken) && $postToken !== '') {
            return $postToken;
        }
        return trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    }
}
if (!function_exists('fitodomikVerifyCsrf')) {
    function fitodomikVerifyCsrf($token = null)
    {
        $sessionToken = $_SESSION['fitodomikCsrfToken'] ?? '';
        $requestToken = $token === null ? fitodomikCsrfRequestToken() : $token;
        return is_string($sessionToken)
            && $sessionToken !== ''
            && is_string($requestToken)
            && $requestToken !== ''
            && hash_equals($sessionToken, $requestToken);
    }
}
if (!function_exists('fitodomikRequireCsrf')) {
    function fitodomikRequireCsrf($json = false)
    {
        if (fitodomikVerifyCsrf()) {
            return;
        }
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-cache');
            header('X-Content-Type-Options: nosniff');
            echo json_encode([
                'success' => false,
                'message' => 'Сессия устарела, обновите страницу',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Срок действия формы истёк. Обновите страницу и повторите действие.';
        }
        exit;
    }
}
