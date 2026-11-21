<?php
function generateQrCode($text, $size = 200) {
    $qrImages = [
        'https://t.me/FitoDomik' => [
            'src' => '/static/images/telegram-qr.v14.webp',
            'alt' => 'QR-код Telegram для связи с автором ФитоДомика',
        ],
        'https://fitodomik.ru' => [
            'src' => '/static/images/site-qr.v14.webp',
            'alt' => 'QR-код сайта fitodomik.ru',
        ],
    ];
    if (!isset($qrImages[$text])) {
        return '';
    }
    $safeSize = max(120, min(320, (int)$size));
    $src = htmlspecialchars($qrImages[$text]['src'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $alt = htmlspecialchars($qrImages[$text]['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return sprintf(
        '<div class="qr-code-container"><img src="%s" width="%d" height="%d" alt="%s" class="qr-code-image" decoding="async"></div>',
        $src,
        $safeSize,
        $safeSize,
        $alt
    );
}
