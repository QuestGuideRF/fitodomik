<?php
require_once __DIR__ . '/../config/cors.php';
fitodomikApplyCors(['POST'], ['Content-Type', 'X-Auth-Token', 'X-Device-ID', 'X-Device-Token']);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/device_dynamic_endpoint.php';
fitodomikRunDynamicCrudEndpoint($pdo, 'scenario', 'save');
