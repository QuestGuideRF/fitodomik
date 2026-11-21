<?php
require_once __DIR__ . '/config.php';
if (!function_exists('fitodomikAnalyticsEscape')) {
    function fitodomikAnalyticsEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('fitodomikYandexMetrikaId')) {
    function fitodomikYandexMetrikaId(): string
    {
        $id = trim((string)fitodomikRuConfigValue('YANDEX_METRIKA_ID', ''));
        return preg_match('/\A\d{4,12}\z/', $id) === 1 ? $id : '';
    }
}
if (!function_exists('fitodomikGaMeasurementId')) {
    function fitodomikGaMeasurementId(): string
    {
        $id = strtoupper(trim((string)fitodomikRuConfigValue('GA_MEASUREMENT_ID', '')));
        return preg_match('/\AG-[A-Z0-9]{4,32}\z/', $id) === 1 ? $id : '';
    }
}
if (!function_exists('fitodomikRenderAnalytics')) {
    function fitodomikRenderAnalytics(): void
    {
        $yandexId = fitodomikYandexMetrikaId();
        $gaId = fitodomikGaMeasurementId();
        if ($yandexId !== '') {
            $id = (int)$yandexId;
            echo <<<HTML
    <!-- Yandex.Metrika counter -->
    <script type="text/javascript">
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j = 0; j < document.scripts.length; j++) {
                if (document.scripts[j].src === r) {
                    return;
                }
            }
            k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,
            a.parentNode.insertBefore(k,a);
        })(window, document, "script", "https://mc.yandex.ru/metrika/tag.js?id={$id}", "ym");
        ym({$id}, "init", {
            ssr:true,
            webvisor:true,
            clickmap:true,
            ecommerce:"dataLayer",
            referrer:document.referrer,
            url:location.href,
            accurateTrackBounce:true,
            trackLinks:true
        });
    </script>
    <!-- /Yandex.Metrika counter -->
HTML;
        }
        if ($gaId !== '') {
            $safeGaId = fitodomikAnalyticsEscape($gaId);
            $jsonGaId = json_encode($gaId, JSON_UNESCAPED_SLASHES);
            echo <<<HTML
    <script async src="https://www.googletagmanager.com/gtag/js?id={$safeGaId}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag("js", new Date());
        gtag("config", {$jsonGaId});
    </script>
HTML;
        }
    }
}
if (!function_exists('fitodomikRenderAnalyticsNoScript')) {
    function fitodomikRenderAnalyticsNoScript(): void
    {
        $yandexId = fitodomikYandexMetrikaId();
        if ($yandexId === '') {
            return;
        }
        $id = (int)$yandexId;
        echo <<<HTML
    <!-- Yandex.Metrika counter -->
    <noscript><div><img src="https://mc.yandex.ru/watch/{$id}" style="position:absolute; left:-9999px;" alt=""></div></noscript>
    <!-- /Yandex.Metrika counter -->
HTML;
    }
}
