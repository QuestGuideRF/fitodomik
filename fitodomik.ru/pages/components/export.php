<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session.php';
if (!isAdmin()) {
    http_response_code(403);
    exit('Доступ запрещён');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Метод не разрешён');
}
fitodomikRequireCsrf();
$requestedStatuses = filter_input(
    INPUT_POST,
    'export_status',
    FILTER_DEFAULT,
    FILTER_REQUIRE_ARRAY
);
$exportBought = is_array($requestedStatuses)
    && in_array('bought', $requestedStatuses, true);
$exportNotBought = is_array($requestedStatuses)
    && in_array('not_bought', $requestedStatuses, true);
if (!$exportBought && !$exportNotBought) {
    $exportBought = true;
    $exportNotBought = true;
}
try {
    if ($exportBought && $exportNotBought) {
        $stmt = $pdo->prepare(
            "SELECT * FROM components WHERE status IN (?, ?) ORDER BY status DESC, created_at DESC"
        );
        $stmt->execute(['bought', 'not_bought']);
    } elseif ($exportBought) {
        $stmt = $pdo->prepare(
            "SELECT * FROM components WHERE status = ? ORDER BY status DESC, created_at DESC"
        );
        $stmt->execute(['bought']);
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM components WHERE status = ? ORDER BY status DESC, created_at DESC"
        );
        $stmt->execute(['not_bought']);
    }
    $components = $stmt->fetchAll();
    $filename = "components_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    $delimiter = ";";
    fputcsv($output, [
        'Название',
        'Статус',
        'Цена',
        'Количество',
        'Информация'
    ], $delimiter);
    foreach ($components as $component) {
        $info = str_replace(["\r\n", "\r", "\n"], " ", $component['info']);
        fputcsv($output, [
            $component['name'],
            formatStatus($component['status']),
            formatPrice($component['price_type'], $component['price']),
            $component['quantity'],
            $info
        ], $delimiter);
    }
    $bought_components = [];
    $not_bought_components = [];
    foreach ($components as $component) {
        if ($component['status'] === 'bought') {
            $bought_components[] = $component;
        } else {
            $not_bought_components[] = $component;
        }
    }
    $not_bought_total = calculateTotalAmount($not_bought_components);
    $bought_total = calculateTotalAmount($bought_components);
    fputcsv($output, [], $delimiter);
    fputcsv($output, ['ИТОГО:'], $delimiter);
    if ($exportNotBought && !empty($not_bought_components)) {
        $summary = ['Общая сумма необходимых компонентов:', number_format($not_bought_total['total'], 2, ',', ' ') . ' ₽'];
        if ($not_bought_total['unknown_count'] > 0) {
            $summary[] = 'с неизвестной ценой ' . $not_bought_total['unknown_count'];
        }
        fputcsv($output, $summary, $delimiter);
    }
    if ($exportBought && !empty($bought_components)) {
        $summary = ['Общая сумма купленных компонентов:', number_format($bought_total['total'], 2, ',', ' ') . ' ₽'];
        if ($bought_total['unknown_count'] > 0) {
            $summary[] = 'с неизвестной ценой ' . $bought_total['unknown_count'];
        }
        fputcsv($output, $summary, $delimiter);
    }
    fclose($output);
} catch(Exception $e) {
    error_log("Ошибка при экспорте: " . $e->getMessage());
    die("Ошибка при экспорте данных");
}
