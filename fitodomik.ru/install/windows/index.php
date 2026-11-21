<?php
declare(strict_types=1);
$script = dirname(__DIR__, 2) . '/downloads/fitodomik-install.ps1';
if (!is_file($script)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "fitodomik-install.ps1 not found\n";
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($script);
