<?php
require_once __DIR__ . '/account_admission.php';
require_once __DIR__ . '/api_security.php';
require_once __DIR__ . '/site.php';
const FITODOMIK_AUTH_LIFETIME = 2592000;
const FITODOMIK_REMEMBER_COOKIE = 'fitodomik_remember';
function fitodomikIsLocalDevelopment(): bool
{
    $environment = strtolower(trim((string)fitodomikEnv('APP_ENV', 'production')));
    $hostHeader = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $requestHost = $hostHeader;
    if (substr($hostHeader, 0, 1) === '[' && strpos($hostHeader, ']') !== false) {
        $requestHost = substr($hostHeader, 1, (int)strpos($hostHeader, ']') - 1);
    } elseif (substr_count($hostHeader, ':') === 1) {
        $requestHost = explode(':', $hostHeader, 2)[0];
    }
    return in_array($environment, ['development', 'debug'], true)
        && fitodomikEnv('FITODOMIK_ALLOW_INSECURE_LOCAL_HTTP', '') === '1'
        && in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
}
function fitodomikTrustedProxyRequest(): bool
{
    $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $trustedProxies = array_filter(array_map(
        'trim',
        explode(',', (string)fitodomikEnv('FITODOMIK_TRUSTED_PROXIES', ''))
    ));
    return $remoteAddress !== '' && in_array($remoteAddress, $trustedProxies, true);
}
function fitodomikRequestIsHttps(): bool
{
    $directHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if ($directHttps || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return fitodomikTrustedProxyRequest() && $forwardedProto === 'https';
}
function fitodomikRequireHttps(): void
{
    $isWebRequest = PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
    if (!$isWebRequest || fitodomikIsLocalDevelopment() || fitodomikRequestIsHttps()) {
        return;
    }
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($requestUri === '' || $requestUri[0] !== '/' || preg_match('/[\r\n]/', $requestUri) === 1) {
        $requestUri = '/';
    }
    if (!headers_sent()) {
        header('Location: ' . rtrim(fitodomikSiteUrl(), '/') . $requestUri, true, 308);
    }
    exit;
}
function fitodomikCookieOptions($expires)
{
    if (fitodomikIsLocalDevelopment()) {
        return [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}
function fitodomikStartSession()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    ini_set('session.gc_maxlifetime', (string)FITODOMIK_AUTH_LIFETIME);
    ini_set('session.cookie_lifetime', (string)FITODOMIK_AUTH_LIFETIME);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (fitodomikIsLocalDevelopment()) {
        ini_set('session.cookie_secure', '0');
    } else {
        ini_set('session.cookie_secure', '1');
    }
    session_cache_limiter('');
    session_start();
    if (!headers_sent()) {
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: private, no-cache');
    }
}
fitodomikRequireHttps();
function fitodomikSafeRedirect($redirect, $defaultRedirect = '/index.php')
{
    $redirect = trim((string)$redirect);
    $isValidInput = !(
        $redirect === ''
        || strpos($redirect, '\\') !== false
        || preg_match('/[\x00-\x1F\x7F]/', $redirect)
    );
    $safeRedirect = $defaultRedirect;
    if ($isValidInput) {
        $parts = parse_url($redirect);
        if (is_array($parts)) {
            $host = strtolower((string)($parts['host'] ?? ''));
            $siteHost = strtolower((string)parse_url(fitodomikSiteUrl(), PHP_URL_HOST));
            $hostAllowed = $host === '' || ($siteHost !== '' && hash_equals($siteHost, $host));
            $path = (string)($parts['path'] ?? '');
            $pathAllowed = $path !== '' && $path[0] === '/' && strncmp($path, '//', 2) !== 0;
            if ($hostAllowed && $pathAllowed) {
                $query = isset($parts['query']) ? '?' . $parts['query'] : '';
                $safeRedirect = $path . $query;
            }
        }
    }
    return $safeRedirect;
}
function fitodomikEnsureRememberStorage($pdo)
{
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_remember_tokens (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector VARCHAR(32) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            user_agent_hash CHAR(64) DEFAULT NULL,
            INDEX idx_remember_user (user_id),
            INDEX idx_remember_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
function fitodomikFillSessionFromUser($user, $telegramLinkId = null)
{
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['first_name'] = $user['first_name'] ?? '';
    $_SESSION['last_name'] = $user['last_name'] ?? '';
    $_SESSION['telegram'] = $user['telegram_username'] ?? ($user['telegram'] ?? '');
    $_SESSION['is_admin'] = !empty($user['is_admin']);
    $_SESSION['last_activity'] = time();
    global $pdo;
    if (!array_key_exists('auth_generation', $user)) {
        $epoch = $pdo->prepare('SELECT auth_generation FROM users WHERE id = ?');
        $epoch->execute([(int)$user['id']]);
        $user['auth_generation'] = (int)$epoch->fetchColumn();
    }
    $_SESSION['auth_generation'] = (int)$user['auth_generation'];
    $_SESSION['telegram_link_id'] = $telegramLinkId === null ? null : (int)$telegramLinkId;
}
function fitodomikCreateRememberLogin($pdo, $user_id, $ensureStorage = true, $setCookie = true, $telegramLinkId = null)
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + FITODOMIK_AUTH_LIFETIME;
    $stmt = $pdo->prepare("
        INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at, user_agent_hash, telegram_link_id, auth_generation)
        SELECT id, ?, ?, FROM_UNIXTIME(?), ?, ?, auth_generation FROM users WHERE id = ? AND is_locked = 0
    ");
    $stmt->execute([
        $selector,
        hash('sha256', $validator),
        $expires,
        hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
        $telegramLinkId,
        (int)$user_id,
    ]);
    if ($stmt->rowCount() !== 1) { throw new RuntimeException('Account not admitted'); }
    $cookie = ['value' => $selector . ':' . $validator, 'expires' => $expires];
    if ($setCookie) {
        fitodomikSetRememberCookie($cookie);
    }
    return $cookie;
}
function fitodomikSetRememberCookie($cookie)
{
    setcookie(FITODOMIK_REMEMBER_COOKIE, $cookie['value'], fitodomikCookieOptions($cookie['expires']));
}
function fitodomikClearRememberCookie()
{
    if (!headers_sent()) {
        setcookie(FITODOMIK_REMEMBER_COOKIE, '', fitodomikCookieOptions(time() - 3600));
    }
}
function fitodomikForgetCurrentRememberToken($pdo)
{
    $cookie = $_COOKIE[FITODOMIK_REMEMBER_COOKIE] ?? '';
    if (strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        if ($selector !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE user_remember_tokens SET revoked_at = NOW() WHERE selector = ?");
                $stmt->execute([$selector]);
            } catch (Exception $e) {
                error_log('remember logout: ' . $e->getMessage());
            }
        }
    }
    fitodomikClearRememberCookie();
}
function fitodomikRevokeRememberLogins($pdo, $userId)
{
    $stmt = $pdo->prepare("
        UPDATE user_remember_tokens
        SET revoked_at = NOW()
        WHERE user_id = ? AND revoked_at IS NULL
    ");
    $stmt->execute([(int)$userId]);
    fitodomikClearRememberCookie();
}
function fitodomikRememberCredentials()
{
    if (isset($_SESSION['user_id']) || empty($_COOKIE[FITODOMIK_REMEMBER_COOKIE])) {
        return null;
    }
    $cookie = (string)$_COOKIE[FITODOMIK_REMEMBER_COOKIE];
    $parts = strpos($cookie, ':') === false ? [] : explode(':', $cookie, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        fitodomikClearRememberCookie();
        return null;
    }
    return ['selector' => $parts[0], 'validator' => $parts[1]];
}
function fitodomikLoadRememberUser($pdo, $selector)
{
    $stmt = $pdo->prepare("
        SELECT t.*, u.id AS user_id, u.username, u.first_name, u.last_name, u.telegram_username, u.is_admin, u.is_locked, u.auth_generation AS current_auth_generation
        FROM user_remember_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.selector = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
          AND u.is_locked = 0 AND t.auth_generation = u.auth_generation
          AND (t.telegram_link_id IS NULL OR EXISTS (
              SELECT 1 FROM telegram_links l WHERE l.id = t.telegram_link_id AND l.user_id = u.id AND l.status = 'active'
          ))
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$selector]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function fitodomikRememberTokenMatches($row, $validator)
{
    return is_array($row)
        && hash_equals((string)$row['token_hash'], hash('sha256', (string)$validator));
}
function fitodomikRememberUserAgentMatches($row)
{
    $expected = (string)($row['user_agent_hash'] ?? '');
    $actual = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    return $expected === '' || hash_equals($expected, $actual);
}
function fitodomikFinishRememberTransaction($pdo, $startedTransaction, $commit)
{
    if ($startedTransaction && $pdo->inTransaction()) {
        if ($commit) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    }
}
function fitodomikRestoreRememberLogin($pdo)
{
    // Publishing a PHP session cannot be rolled back with the caller's SQL.
    // Defer restoration until we can commit the token rotation ourselves.
    if ($pdo->inTransaction()) {
        return;
    }
    $credentials = fitodomikRememberCredentials();
    if ($credentials === null) {
        return;
    }
    $startedTransaction = false;
    try {
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        $row = fitodomikLoadRememberUser($pdo, $credentials['selector']);
        if (!fitodomikAccountAdmitted($row) || !fitodomikRememberTokenMatches($row, $credentials['validator'])) {
            fitodomikFinishRememberTransaction($pdo, $startedTransaction, false);
            fitodomikClearRememberCookie();
            return;
        }
        if (!fitodomikRememberUserAgentMatches($row)) {
            $revoke = $pdo->prepare("UPDATE user_remember_tokens SET revoked_at = NOW() WHERE id = ?");
            $revoke->execute([(int)$row['id']]);
            fitodomikFinishRememberTransaction($pdo, $startedTransaction, true);
            fitodomikClearRememberCookie();
            return;
        }
        $revoke = $pdo->prepare("
            UPDATE user_remember_tokens
            SET revoked_at = NOW(), last_used_at = NOW()
            WHERE id = ? AND revoked_at IS NULL
        ");
        $revoke->execute([(int)$row['id']]);
        if ($revoke->rowCount() !== 1) {
            throw new FitodomikConflictException('Remember token was already consumed');
        }
        $newCookie = fitodomikCreateRememberLogin($pdo, (int)$row['user_id'], false, false, $row['telegram_link_id']);
        fitodomikFinishRememberTransaction($pdo, $startedTransaction, true);
        session_regenerate_id(true);
        fitodomikFillSessionFromUser([
            'id' => $row['user_id'],
            'username' => $row['username'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'telegram_username' => $row['telegram_username'],
            'is_admin' => $row['is_admin'],
            'auth_generation' => $row['current_auth_generation'],
        ], $row['telegram_link_id']);
        fitodomikSetRememberCookie($newCookie);
    } catch (Exception $e) {
        fitodomikFinishRememberTransaction($pdo, $startedTransaction, false);
        $_SESSION = [];
        fitodomikClearRememberCookie();
        error_log('remember restore: ' . $e->getMessage());
    }
}
fitodomikStartSession();
$csrf_file = __DIR__ . '/csrf.php';
if (file_exists($csrf_file)) {
    require_once $csrf_file;
}
$headers_file = __DIR__ . '/headers.php';
if (file_exists($headers_file)) {
    require_once $headers_file;
}
if (isset($pdo)) {
    if (isset($_SESSION['user_id'])) {
        $activeSession = $pdo->prepare('SELECT is_locked, auth_generation FROM users WHERE id = ? LIMIT 1');
        $activeSession->execute([(int)$_SESSION['user_id']]);
        $activeUser = $activeSession->fetch(PDO::FETCH_ASSOC);
        $linkValid = true;
        if (!empty($_SESSION['telegram_link_id'])) {
            $link = $pdo->prepare("SELECT id FROM telegram_links WHERE id = ? AND user_id = ? AND status = 'active'");
            $link->execute([(int)$_SESSION['telegram_link_id'], (int)$_SESSION['user_id']]);
            $linkValid = (bool)$link->fetchColumn();
        }
        if (!fitodomikAccountAdmitted($activeUser) || !$linkValid
            || (int)($activeUser['auth_generation'] ?? -1) !== (int)($_SESSION['auth_generation'] ?? 0)) {
            $_SESSION = [];
            session_regenerate_id(true);
            fitodomikClearRememberCookie();
        }
    }
    fitodomikRestoreRememberLogin($pdo);
}
if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
}
if (!function_exists('getCurrentUser')) {
    function getCurrentUser()
    {
        if (isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'] ?? null,
                'username' => $_SESSION['username'] ?? '',
                'first_name' => $_SESSION['first_name'] ?? '',
                'last_name' => $_SESSION['last_name'] ?? '',
                'telegram' => $_SESSION['telegram'] ?? '',
                'is_admin' => !empty($_SESSION['is_admin']),
            ];
        }
        return null;
    }
}
if (!function_exists('getGreeting')) {
    function getGreeting()
    {
        $hour = date('H');
        if ($hour >= 5 && $hour < 12) {
            return 'Доброе утро';
        }
        if ($hour >= 12 && $hour < 18) {
            return 'Добрый день';
        }
        return 'Добрый вечер';
    }
}
