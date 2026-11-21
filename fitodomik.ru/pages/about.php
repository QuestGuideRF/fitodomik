<?php
$query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
$location = '/pages/info/' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $location, true, 301);
exit;
