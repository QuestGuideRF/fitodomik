<?php
const SECURITY_CACHE_ONE_YEAR = "Cache-Control: public, max-age=31536000";
const SECURITY_CACHE_ONE_WEEK = "Cache-Control: public, max-age=604800";
function addSecurityHeaders()
{
    $request_uri = $_SERVER['REQUEST_URI'];
    if (preg_match('/\.([a-zA-Z0-9]+)(?:\?.*)?$/', $request_uri, $matches)) {
        $extension = strtolower($matches[1]);
        switch ($extension) {
            case 'css':
                header("Content-Type: text/css");
                header("Cache-Control: public, max-age=2592000");
                break;
            case 'js':
                header("Content-Type: application/javascript");
                header("Cache-Control: public, max-age=2592000");
                break;
            case 'jpg':
            case 'jpeg':
                header("Content-Type: image/jpeg");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'png':
                header("Content-Type: image/png");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'gif':
                header("Content-Type: image/gif");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'webp':
                header("Content-Type: image/webp");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'ico':
                header("Content-Type: image/x-icon");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'svg':
                header("Content-Type: image/svg+xml");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'webmanifest':
                header("Content-Type: application/manifest+json");
                header(SECURITY_CACHE_ONE_WEEK);
                break;
            case 'woff':
                header("Content-Type: font/woff");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'woff2':
                header("Content-Type: font/woff2");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'ttf':
                header("Content-Type: font/ttf");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'otf':
                header("Content-Type: font/otf");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'eot':
                header("Content-Type: application/vnd.ms-fontobject");
                header(SECURITY_CACHE_ONE_YEAR);
                break;
            case 'json':
                header("Content-Type: application/json");
                header(SECURITY_CACHE_ONE_WEEK);
                break;
            case 'xml':
                header("Content-Type: text/xml");
                header(SECURITY_CACHE_ONE_WEEK);
                break;
            default:
                break;
        }
    }
}
addSecurityHeaders();
