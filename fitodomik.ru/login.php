<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /authentication/login.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
