<?php
require_once __DIR__ . '/security_bootstrap.php';
if (!isset($_GET['file']) || empty($_GET['file'])) {
    header("HTTP/1.0 400 Bad Request");
    exit("Файл не указан");
}
$file = basename((string)$_GET['file']);
if ($file !== 'site.webmanifest') {
    header("HTTP/1.0 400 Bad Request");
    exit("Только webmanifest файлы разрешены");
}
$file_path = dirname(__DIR__) . '/icon/' . basename($file);
if (!file_exists($file_path) || !is_file($file_path)) {
    header("HTTP/1.0 404 Not Found");
    exit("Файл не найден");
}
header("Content-Type: application/manifest+json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
$version = (string)($_GET['v'] ?? '');
if (preg_match('/^\d+$/', $version)) {
    header("Cache-Control: public, max-age=31536000, immutable");
} else {
    header("Cache-Control: public, max-age=604800");
}
readfile($file_path);
