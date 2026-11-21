<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/plant_catalog.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fitodomikPlantCatalogJsonResponse(405, ['success' => false, 'message' => 'Метод не поддерживается']);
}
try {
    $result = fitodomikPlantCatalogList($pdo, $_GET);
    $categories = fitodomikPlantCatalogCategories($pdo);
    fitodomikPlantCatalogJsonResponse(200, [
        'success' => true,
        'items' => $result['items'],
        'filters' => $result['filters'],
        'pagination' => $result['pagination'],
        'categories' => $categories,
    ]);
} catch (Throwable $e) {
    error_log('plant-catalog-list.php: ' . $e->getMessage());
    fitodomikPlantCatalogJsonResponse(500, ['success' => false, 'message' => 'Не удалось загрузить каталог']);
}
