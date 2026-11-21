<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
$directory = __DIR__ . '/../uploads/farm_photos';
$insert = $pdo->prepare('INSERT INTO farm_photo_storage(filename,user_id,size_bytes) VALUES(?,?,?) ON DUPLICATE KEY UPDATE size_bytes=VALUES(size_bytes)');
$count=0;
if (is_dir($directory)) {
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile() || $file->isLink() || !preg_match('/\A(?:farm|analysis)_([1-9][0-9]*)_[0-9]+_[a-f0-9]{12}\.(?:jpg|png|gif|webp)\z/D', $file->getFilename(), $match)) continue;
        $insert->execute([$file->getFilename(), (int)$match[1], max(0,$file->getSize())]);
        $count++;
    }
}
$pdo->exec("INSERT INTO fitodomik_schema_meta(meta_key,meta_value) VALUES('photo_storage_ready','1') ON DUPLICATE KEY UPDATE meta_value='1'");
echo "Indexed files: $count. Upload quota index ready.\n";
