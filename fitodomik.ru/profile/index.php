<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /authentication/profile.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
