<?php
require_once __DIR__ . '/security_bootstrap.php';
if (!isset($_GET['file']) || empty($_GET['file'])) {
    header("HTTP/1.0 400 Bad Request");
    exit("Файл не указан");
}
$file = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $_GET['file']);
if (!preg_match('/\.js$/', $file)) {
    header("HTTP/1.0 400 Bad Request");
    exit("Только JavaScript файлы разрешены");
}
$base_path = realpath(dirname(__DIR__) . '/js');
if ($base_path === false) {
    header("HTTP/1.0 404 Not Found");
    exit("Файл не найден");
}
$candidate_path = $base_path . DIRECTORY_SEPARATOR . ltrim($file, "/\\");
$file_path = realpath($candidate_path);
$base_prefix = rtrim($base_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (
    $file_path === false
    || strncmp($file_path, $base_prefix, strlen($base_prefix)) !== 0
    || !is_file($file_path)
    || is_link($file_path)
) {
    header("HTTP/1.0 404 Not Found");
    exit("Файл не найден");
}
header_remove('Expires');
header_remove('Pragma');
header_remove('Content-Security-Policy');
header_remove('X-Frame-Options');
header("X-Content-Type-Options: nosniff");
header("Content-Type: application/javascript; charset=utf-8");
header("Cache-Control: public, max-age=3600, must-revalidate");
readfile($file_path);
