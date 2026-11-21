<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/plant_catalog.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fitodomikPlantCatalogJsonResponse(405, ['success' => false, 'message' => 'Метод не поддерживается']);
}
$slug = trim((string)($_GET['plant'] ?? ''));
$plant = fitodomikPlantCatalogItem($pdo, $slug);
if ($plant === null) {
    fitodomikPlantCatalogJsonResponse(404, ['success' => false, 'message' => 'Растение не найдено']);
}
fitodomikPlantCatalogJsonResponse(200, ['success' => true, 'item' => $plant]);
