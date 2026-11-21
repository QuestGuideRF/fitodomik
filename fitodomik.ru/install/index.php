<?php
declare(strict_types=1);
$script = dirname(__DIR__) . '/downloads/fitodomik-install.sh';
if (!is_file($script)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "fitodomik-install.sh not found\n";
    exit;
}
header('Content-Type: text/x-shellscript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($script);
