<?php
require_once __DIR__ . '/security_headers.php';
header_remove('Expires');
header_remove('Pragma');
header_remove('Content-Security-Policy');
header('X-Content-Type-Options: nosniff');
const CACHE_ONE_YEAR = "Cache-Control: public, max-age=31536000, immutable";
const CACHE_ONE_WEEK = "Cache-Control: public, max-age=604800";
const FITODOMIK_PRIVATE_NO_CACHE_HEADER = 'Cache-Control: private, no-cache';
function setAjaxCacheHeaders($can_cache = false, $cache_time = 300)
{
    if ($can_cache) {
        header("Cache-Control: private, max-age=$cache_time");
    } else {
        header(FITODOMIK_PRIVATE_NO_CACHE_HEADER);
    }
}
$request_uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (preg_match('/\.([a-zA-Z0-9]+)(?:\?.*)?$/', $request_uri, $matches)) {
    $extension = strtolower($matches[1]);
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            header("Content-Type: image/jpeg");
            header(CACHE_ONE_YEAR);
            break;
        case 'png':
            header("Content-Type: image/png");
            header(CACHE_ONE_YEAR);
            break;
        case 'gif':
            header("Content-Type: image/gif");
            header(CACHE_ONE_YEAR);
            break;
        case 'webp':
            header("Content-Type: image/webp");
            header(CACHE_ONE_YEAR);
            break;
        case 'ico':
            header("Content-Type: image/x-icon");
            header(CACHE_ONE_YEAR);
            break;
        case 'svg':
            header("Content-Type: image/svg+xml; charset=utf-8");
            header(CACHE_ONE_YEAR);
            break;
        case 'css':
            header("Content-Type: text/css; charset=utf-8");
            header(CACHE_ONE_YEAR);
            break;
        case 'js':
            header("Content-Type: application/javascript; charset=utf-8");
            header(CACHE_ONE_YEAR);
            break;
        case 'woff':
            header("Content-Type: font/woff");
            header(CACHE_ONE_YEAR);
            break;
        case 'woff2':
            header("Content-Type: font/woff2");
            header(CACHE_ONE_YEAR);
            break;
        case 'ttf':
            header("Content-Type: font/ttf");
            header(CACHE_ONE_YEAR);
            break;
        case 'otf':
            header("Content-Type: font/otf");
            header(CACHE_ONE_YEAR);
            break;
        case 'eot':
            header("Content-Type: application/vnd.ms-fontobject");
            header(CACHE_ONE_YEAR);
            break;
        case 'webmanifest':
            header("Content-Type: application/manifest+json; charset=utf-8");
            header(CACHE_ONE_WEEK);
            break;
        case 'json':
            header("Content-Type: application/json; charset=utf-8");
            header(FITODOMIK_PRIVATE_NO_CACHE_HEADER);
            break;
        case 'xml':
            header("Content-Type: application/xml; charset=utf-8");
            header(CACHE_ONE_WEEK);
            break;
        default:
            break;
    }
}
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if (strpos($scriptName, '/api/') !== false) {
    header_remove('Content-Security-Policy');
    header(FITODOMIK_PRIVATE_NO_CACHE_HEADER);
    header('Content-Type: application/json; charset=utf-8');
}
