<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/headers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /authentication/logout.php', true, 302);
    exit;
}
fitodomikRequireCsrf();
fitodomikForgetCurrentRememberToken($pdo);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', fitodomikCookieOptions(time() - 3600));
}
session_destroy();
header('Location: /', true, 302);
exit;
