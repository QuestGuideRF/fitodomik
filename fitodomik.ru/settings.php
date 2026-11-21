<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /authentication/settings.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
