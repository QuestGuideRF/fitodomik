<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/gallery_helpers.php';
function sitemapXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}
try {
    $stmt = $pdo->query('SELECT * FROM photos WHERE COALESCE(is_indexable, 1) = 1 ORDER BY created_at DESC, id DESC');
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $photos = [];
    error_log('sitemap-images.php: ' . $e->getMessage());
}
header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<?php foreach ($photos as $photo): ?>
    <url>
        <loc><?php echo sitemapXmlEscape(galleryPhotoPageUrl($photo, true)); ?></loc>
        <image:image>
            <image:loc><?php echo sitemapXmlEscape(galleryPhotoImageUrl($photo, true)); ?></image:loc>
            <image:title><?php echo sitemapXmlEscape(galleryPhotoTitle($photo)); ?></image:title>
            <image:caption><?php echo sitemapXmlEscape(galleryPhotoDescription($photo)); ?></image:caption>
        </image:image>
    </url>
<?php endforeach; ?>
</urlset>
