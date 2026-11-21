<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /authentication/register.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
