<?php
declare(strict_types=1);
const FITODOMIK_STATIC_MIME_CSS = 'text/css; charset=utf-8';
const FITODOMIK_STATIC_MIME_JS = 'application/javascript; charset=utf-8';
const FITODOMIK_STATIC_MIME_PNG = 'image/png';
const FITODOMIK_STATIC_MIME_WEBP = 'image/webp';
const FITODOMIK_STATIC_MIME_WOFF2 = 'font/woff2';
const FITODOMIK_STATIC_MIME_JPEG = 'image/jpeg';
const FITODOMIK_STATIC_DIRECTORY_MAP = [
    'assets/' => __DIR__ . '/assets',
    'icon/' => __DIR__ . '/icon',
    'uploads/gallery/' => __DIR__ . '/uploads/gallery',
    '3d/' => __DIR__ . '/3d',
];
const FITODOMIK_STATIC_ALLOWED_EXTENSIONS = [
    'css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'woff', 'woff2',
    'webmanifest', 'ico', 'glb', 'gltf', 'stl', 'bin',
];
$assets = [
    'style-css' => ['assets/css/style.css', FITODOMIK_STATIC_MIME_CSS],
    'bootstrap-css' => ['assets/vendor/bootstrap/5.1.3/bootstrap.min.css', FITODOMIK_STATIC_MIME_CSS],
    'bootstrap-js' => ['assets/vendor/bootstrap/5.1.3/bootstrap.bundle.min.js', FITODOMIK_STATIC_MIME_JS],
    'theme-js' => ['assets/js/theme.js', FITODOMIK_STATIC_MIME_JS],
    'deferred-icons-js' => ['assets/js/deferred-icons.js', FITODOMIK_STATIC_MIME_JS],
    'model-viewer-js' => ['assets/js/model-viewer.min.js', FITODOMIK_STATIC_MIME_JS],
    'components-scroll-js' => ['assets/js/components-scroll.js', FITODOMIK_STATIC_MIME_JS],
    'bootstrap-icons-css' => ['assets/css/bootstrap-icons.css', FITODOMIK_STATIC_MIME_CSS],
    'hero-png' => ['assets/fotofatm.png', FITODOMIK_STATIC_MIME_PNG],
    'hero-avif' => ['assets/fotofatm-322.avif', 'image/avif'],
    'hero-webp' => ['assets/fotofatm-322.webp', FITODOMIK_STATIC_MIME_WEBP],
    'manrope-font' => ['assets/fonts/site/manrope-cyrillic.woff2', FITODOMIK_STATIC_MIME_WOFF2],
    'unbounded-font' => ['assets/fonts/site/unbounded-cyrillic.woff2', FITODOMIK_STATIC_MIME_WOFF2],
    'bootstrap-icons-font' => ['assets/fonts/bootstrap-icons/bootstrap-icons.woff2', FITODOMIK_STATIC_MIME_WOFF2],
    'bootstrap-icons-font-woff' => ['assets/fonts/bootstrap-icons/bootstrap-icons.woff', 'font/woff'],
    'manifest' => ['icon/site.webmanifest', 'application/manifest+json; charset=utf-8'],
    'favicon' => ['icon/favicon.ico', 'image/vnd.microsoft.icon'],
    'favicon-32' => ['icon/favicon-32x32.png', FITODOMIK_STATIC_MIME_PNG],
    'favicon-16' => ['icon/favicon-16x16.png', FITODOMIK_STATIC_MIME_PNG],
    'apple-touch-icon' => ['icon/apple-touch-icon.png', FITODOMIK_STATIC_MIME_PNG],
    'android-icon-192' => ['icon/android-chrome-192x192.png', FITODOMIK_STATIC_MIME_PNG],
    'android-icon-512' => ['icon/android-chrome-512x512.png', FITODOMIK_STATIC_MIME_PNG],
    'telegram-qr' => ['assets/qr/telegram.webp', FITODOMIK_STATIC_MIME_WEBP],
    'site-qr' => ['assets/qr/site.webp', FITODOMIK_STATIC_MIME_WEBP],
];
function fitodomikStaticNotFound(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    exit('Asset not found');
}
function fitodomikNormalizeStaticPath(string $requestedPath): ?string
{
    if ($requestedPath === '' || strpos($requestedPath, "\0") !== false
        || strpos($requestedPath, '://') !== false || strpos($requestedPath, '\\') !== false) {
        return null;
    }
    $decodedPath = rawurldecode($requestedPath);
    $isInvalid = $decodedPath === ''
        || strpos($decodedPath, "\0") !== false
        || strpos($decodedPath, '://') !== false
        || strpos($decodedPath, '\\') !== false
        || $decodedPath[0] === '/'
        || preg_match('/\A[a-z]:/iD', $decodedPath) === 1;
    if ($isInvalid) {
        return null;
    }
    return preg_match('/\A[a-z0-9][a-z0-9._ \/-]*\z/iD', $decodedPath) === 1
        ? $decodedPath
        : null;
}
function fitodomikStaticPathSegments(string $normalizedPath): ?array
{
    $segments = explode('/', $normalizedPath);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }
    }
    return $segments;
}
function fitodomikStaticLocation(string $normalizedPath): ?array
{
    $extension = strtolower((string)pathinfo($normalizedPath, PATHINFO_EXTENSION));
    if (!in_array($extension, FITODOMIK_STATIC_ALLOWED_EXTENSIONS, true)) {
        return null;
    }
    foreach (FITODOMIK_STATIC_DIRECTORY_MAP as $prefix => $baseDirectory) {
        if (strncmp($normalizedPath, $prefix, strlen($prefix)) === 0) {
            return [
                'base_directory' => $baseDirectory,
                'relative_path' => substr($normalizedPath, strlen($prefix)),
            ];
        }
    }
    return null;
}
function fitodomikCanonicalStaticBase(string $baseDirectory): ?string
{
    $baseReal = is_link($baseDirectory) ? false : realpath($baseDirectory);
    return $baseReal !== false && is_dir($baseReal) ? $baseReal : null;
}
function fitodomikCanonicalStaticFile(string $baseDirectory, array $segments): ?string
{
    $baseReal = fitodomikCanonicalStaticBase($baseDirectory);
    if ($baseReal === null) {
        return null;
    }
    $candidate = $baseReal;
    $containsSymlink = false;
    foreach ($segments as $segment) {
        $candidate .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($candidate)) {
            $containsSymlink = true;
            break;
        }
    }
    if ($containsSymlink) {
        return null;
    }
    $fileReal = realpath($candidate);
    $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $isAllowed = $fileReal !== false
        && strncmp($fileReal, $basePrefix, strlen($basePrefix)) === 0
        && is_file($fileReal)
        && is_readable($fileReal)
        && !is_link($fileReal);
    return $isAllowed ? $fileReal : null;
}
function resolveStaticAssetPath(string $requestedPath): ?string
{
    $normalizedPath = fitodomikNormalizeStaticPath($requestedPath);
    $resolvedPath = null;
    if ($normalizedPath !== null) {
        $location = fitodomikStaticLocation($normalizedPath);
        if ($location !== null) {
            $segments = fitodomikStaticPathSegments($location['relative_path']);
            if ($segments !== null) {
                $resolvedPath = fitodomikCanonicalStaticFile($location['base_directory'], $segments);
            }
        }
    }
    return $resolvedPath;
}
final class FitodomikResolvedStaticAsset
{
    private string $canonicalPath;
    private function __construct(string $canonicalPath)
    {
        $this->canonicalPath = $canonicalPath;
    }
    public static function fromRequestedPath(string $requestedPath): ?self
    {
        $canonicalPath = resolveStaticAssetPath($requestedPath);
        return $canonicalPath === null ? null : new self($canonicalPath);
    }
    public function size(): int|false
    {
        return filesize($this->canonicalPath);
    }
    public function send(): void
    {
        readfile($this->canonicalPath);
    }
}
if (defined('FITODOMIK_STATIC_LIBRARY_ONLY') && FITODOMIK_STATIC_LIBRARY_ONLY === true) {
    return;
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$version = (string)($_GET['v'] ?? '');
if ($version !== '' && preg_match('/\A\d+\z/D', $version) !== 1) {
    fitodomikStaticNotFound();
}
$key = (string)($_GET['asset'] ?? '');
$relativePath = '';
$contentType = '';
if ($key === 'gallery-image') {
    $fileName = (string)($_GET['file'] ?? '');
    if (!preg_match('/\A[a-z0-9][a-z0-9._-]*\.(?:png|jpe?g|webp|avif)\z/iD', $fileName)) {
        fitodomikStaticNotFound();
    }
    $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
    $imageTypes = [
        'png' => FITODOMIK_STATIC_MIME_PNG,
        'jpg' => FITODOMIK_STATIC_MIME_JPEG,
        'jpeg' => FITODOMIK_STATIC_MIME_JPEG,
        'webp' => FITODOMIK_STATIC_MIME_WEBP,
        'avif' => 'image/avif',
    ];
    $relativePath = 'uploads/gallery/' . $fileName;
    $contentType = $imageTypes[$extension] ?? '';
} elseif ($key === 'component-frame') {
    $frame = (string)($_GET['frame'] ?? '');
    if (!preg_match('/\A(?:0[1-9]|1\d|2[0-7])\z/D', $frame)) {
        fitodomikStaticNotFound();
    }
    $relativePath = 'assets/images/components-sequence/' . $frame . '.webp';
    $contentType = FITODOMIK_STATIC_MIME_WEBP;
} elseif ($key === 'model-file') {
    $fileName = (string)($_GET['file'] ?? '');
    $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
    $modelTypes = [
        'glb' => 'model/gltf-binary',
        'gltf' => 'model/gltf+json',
        'stl' => 'model/stl',
        'bin' => 'application/octet-stream',
        'png' => FITODOMIK_STATIC_MIME_PNG,
        'jpg' => FITODOMIK_STATIC_MIME_JPEG,
        'jpeg' => FITODOMIK_STATIC_MIME_JPEG,
        'webp' => FITODOMIK_STATIC_MIME_WEBP,
    ];
    $validName = preg_match('/\A[a-z0-9][a-z0-9._ -]*\.(?:glb|gltf|stl|bin|png|jpe?g|webp)\z/iD', $fileName) === 1;
    if (!$validName || !isset($modelTypes[$extension])) {
        fitodomikStaticNotFound();
    }
    $relativePath = '3d/' . $fileName;
    $contentType = $modelTypes[$extension];
} elseif (isset($assets[$key])) {
    [$relativePath, $contentType] = $assets[$key];
} else {
    fitodomikStaticNotFound();
}
$resolvedAsset = FitodomikResolvedStaticAsset::fromRequestedPath($relativePath);
if ($resolvedAsset === null) {
    fitodomikStaticNotFound();
}
$fileSize = $resolvedAsset->size();
if ($fileSize === false) {
    fitodomikStaticNotFound();
}
header_remove('Expires');
header_remove('Pragma');
header_remove('Content-Security-Policy');
header_remove('X-Frame-Options');
header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=3600, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)$fileSize);
if ($method === 'GET') {
    $resolvedAsset->send();
}
