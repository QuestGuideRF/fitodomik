<?php
require_once dirname(__DIR__) . '/config/session.php';
if (!function_exists('isAdmin')) {
    function isAdmin(): bool
    {
        return isLoggedIn() && !empty($_SESSION['is_admin']);
    }
}
if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId(): ?int
    {
        return isLoggedIn() ? (int)$_SESSION['user_id'] : null;
    }
}
if (!function_exists('getCurrentUsername')) {
    function getCurrentUsername(): ?string
    {
        return isLoggedIn() ? (string)($_SESSION['username'] ?? '') : null;
    }
}
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}
