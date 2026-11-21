<?php
function fitodomikJsonResponse($status, $payload, $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
{
    http_response_code($status);
    echo json_encode($payload, $flags);
    exit;
}
function fitodomikJsonMessageResponse($success, $message, $data = [], $flags = 0)
{
    fitodomikJsonResponse(
        http_response_code() ?: 200,
        array_merge(['success' => $success, 'message' => $message], $data),
        $flags
    );
}
