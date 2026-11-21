<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/api_exceptions.php';
if (!function_exists('fitodomikSiteUrl')) {
    function fitodomikSiteUrl(): string
    {
        $configured = rtrim(trim((string)fitodomikEnv('SITE_URL')), '/');
        $parts = $configured === '' ? false : parse_url($configured);
        if (!is_array($parts) || !filter_var($configured, FILTER_VALIDATE_URL)) {
            throw new FitodomikConfigurationException('SITE_URL не настроен или имеет неверный формат');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $debug = filter_var(fitodomikEnv('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($debug && $scheme === 'http' && $localHost)) {
            throw new FitodomikConfigurationException('SITE_URL должен использовать HTTPS');
        }
        if (isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
        ) {
            throw new FitodomikConfigurationException('SITE_URL должен содержать только адрес сайта');
        }
        return $configured;
    }
}
if (!function_exists('fitodomikUrl')) {
    function fitodomikUrl(string $path = ''): string
    {
        if ($path === '') {
            return fitodomikSiteUrl();
        }
        return fitodomikSiteUrl() . '/' . ltrim($path, '/');
    }
}
if (!function_exists('fitodomikApiBaseUrl')) {
    function fitodomikApiBaseUrl(): string
    {
        $configured = rtrim(trim((string)fitodomikEnv('API_BASE_URL')), '/');
        if ($configured === '') {
            return fitodomikUrl('/api');
        }
        if (!filter_var($configured, FILTER_VALIDATE_URL)) {
            throw new FitodomikConfigurationException('API_BASE_URL имеет неверный формат');
        }
        $scheme = strtolower((string)parse_url($configured, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($configured, PHP_URL_HOST));
        $debug = filter_var(fitodomikEnv('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($debug && $scheme === 'http' && $localHost)) {
            throw new FitodomikConfigurationException('API_BASE_URL должен использовать HTTPS');
        }
        return $configured;
    }
}
if (!function_exists('fitodomikCanonicalHost')) {
    function fitodomikCanonicalHost(): string
    {
        $configured = strtolower(trim((string)fitodomikEnv('CANONICAL_HOST')));
        if ($configured !== '') {
            return $configured;
        }
        return strtolower((string)parse_url(fitodomikSiteUrl(), PHP_URL_HOST));
    }
}
