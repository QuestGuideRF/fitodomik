<?php
require_once __DIR__ . '/config/site.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /config/\n";
echo "Disallow: /runtime/\n";
echo "Disallow: /uploads/\n";
echo 'Sitemap: ' . fitodomikUrl('/sitemap.xml') . "\n";
