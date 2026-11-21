<?php
function fitodomikUsesContractV2(): bool
{
    return ($_SERVER['HTTP_X_FITODOMIK_CONTRACT'] ?? '') === '2';
}
function fitodomikContractResponse(array $legacy, int $status, ?array $data = null, string $code = 'request_failed'): never
{
    header('Vary: X-Fitodomik-Contract', false);
    header('Content-Type: application/json; charset=utf-8');
    if (fitodomikUsesContractV2()) {
        http_response_code($status);
        header('X-Fitodomik-Contract: 2');
        $success=$status < 400 && ($legacy['success'] ?? true) === true;
        $legacy=['success'=>$success,'data'=>$success ? ($data ?? $legacy) : null,
            'error'=>$success ? null : ['code'=>$code,'message'=>(string)($legacy['message'] ?? $legacy['error'] ?? 'Ошибка запроса')]];
    }
    echo json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
