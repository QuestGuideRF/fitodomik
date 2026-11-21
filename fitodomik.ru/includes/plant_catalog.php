<?php
declare(strict_types=1);
const FITODOMIK_PLANT_CATALOG_PER_PAGE = 12;
const FITODOMIK_PLANT_CATALOG_MAX_PER_PAGE = 24;
function fitodomikPlantCatalogCategories(PDO $pdo): array
{
    $stmt = $pdo->query("\n        SELECT id, slug, name, description\n        FROM plant_categories\n        WHERE is_active = 1\n        ORDER BY sort_order, name\n    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function fitodomikPlantCatalogDifficultyLabel(string $difficulty): string
{
    return [
        'easy' => 'Просто',
        'medium' => 'Средне',
        'hard' => 'Сложно',
    ][$difficulty] ?? 'Средне';
}
function fitodomikPlantCatalogBool($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}
function fitodomikPlantCatalogLimitText(string $value, int $maxLength): string
{
    if ($maxLength < 1) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($characters)
        ? implode('', array_slice($characters, 0, $maxLength))
        : substr($value, 0, $maxLength);
}
function fitodomikPlantCatalogNormalizeFilters(array $input): array
{
    $difficulty = trim((string)($input['difficulty'] ?? ''));
    $sort = trim((string)($input['sort'] ?? 'popular'));
    $allowedSorts = ['popular', 'name', 'fast', 'easy', 'new'];
    return [
        'q' => fitodomikPlantCatalogLimitText(trim((string)($input['q'] ?? '')), 80),
        'category' => preg_replace('/[^a-z0-9-]/', '', strtolower((string)($input['category'] ?? ''))),
        'difficulty' => in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : '',
        'harvest_max' => max(0, min(730, (int)($input['harvest_max'] ?? 0))),
        'light_max' => max(0, min(24, (int)($input['light_max'] ?? 0))),
        'temperature' => max(0, min(45, (int)($input['temperature'] ?? 0))),
        'indoor' => fitodomikPlantCatalogBool($input['indoor'] ?? false),
        'edible' => fitodomikPlantCatalogBool($input['edible'] ?? false),
        'decorative' => fitodomikPlantCatalogBool($input['decorative'] ?? false),
        'fast' => fitodomikPlantCatalogBool($input['fast'] ?? false),
        'sort' => in_array($sort, $allowedSorts, true) ? $sort : 'popular',
        'page' => max(1, (int)($input['page'] ?? 1)),
        'per_page' => max(1, min(FITODOMIK_PLANT_CATALOG_MAX_PER_PAGE, (int)($input['per_page'] ?? FITODOMIK_PLANT_CATALOG_PER_PAGE))),
    ];
}
function fitodomikPlantCatalogWhere(array $filters, array &$params): string
{
    $where = ['p.is_active = 1', 'c.is_active = 1'];
    if ($filters['q'] !== '') {
        $where[] = '(p.name_ru LIKE :q_name OR p.name_latin LIKE :q_latin OR p.keywords LIKE :q_keywords OR p.short_description LIKE :q_description)';
        $search = '%' . $filters['q'] . '%';
        $params[':q_name'] = $search;
        $params[':q_latin'] = $search;
        $params[':q_keywords'] = $search;
        $params[':q_description'] = $search;
    }
    if ($filters['category'] !== '') {
        $where[] = 'c.slug = :category';
        $params[':category'] = $filters['category'];
    }
    if ($filters['difficulty'] !== '') {
        $where[] = 'p.difficulty = :difficulty';
        $params[':difficulty'] = $filters['difficulty'];
    }
    if ($filters['harvest_max'] > 0) {
        $where[] = 'p.harvest_days_min IS NOT NULL AND p.harvest_days_min <= :harvest_max';
        $params[':harvest_max'] = $filters['harvest_max'];
    }
    if ($filters['light_max'] > 0) {
        $where[] = 'p.light_hours_min <= :light_max';
        $params[':light_max'] = $filters['light_max'];
    }
    if ($filters['temperature'] > 0) {
        $where[] = ':temperature BETWEEN p.temperature_min AND p.temperature_max';
        $params[':temperature'] = $filters['temperature'];
    }
    foreach (['indoor' => 'suitable_indoor', 'edible' => 'is_edible', 'decorative' => 'is_decorative', 'fast' => 'is_fast_growing'] as $filter => $column) {
        if ($filters[$filter]) {
            $where[] = 'p.' . $column . ' = 1';
        }
    }
    return implode(' AND ', $where);
}
function fitodomikPlantCatalogOrder(string $sort): string
{
    return [
        'name' => 'p.name_ru ASC, p.id ASC',
        'fast' => 'COALESCE(p.harvest_days_min, 32767) ASC, p.name_ru ASC',
        'easy' => "FIELD(p.difficulty, 'easy', 'medium', 'hard'), p.popularity DESC, p.name_ru ASC",
        'new' => 'p.created_at DESC, p.id DESC',
        'popular' => 'p.is_featured DESC, p.popularity DESC, p.sort_order ASC, p.name_ru ASC',
    ][$sort] ?? 'p.is_featured DESC, p.popularity DESC, p.sort_order ASC, p.name_ru ASC';
}
function fitodomikPlantCatalogList(PDO $pdo, array $rawFilters): array
{
    $filters = fitodomikPlantCatalogNormalizeFilters($rawFilters);
    $params = [];
    $where = fitodomikPlantCatalogWhere($filters, $params);
    $countStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM plant_catalog p\n        JOIN plant_categories c ON c.id = p.category_id\n        WHERE {$where}\n    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $filters['per_page']));
    $filters['page'] = min($filters['page'], $pages);
    $offset = ($filters['page'] - 1) * $filters['per_page'];
    $sql = "\n        SELECT p.id, p.slug, p.name_ru, p.name_latin, p.short_description,\n               p.difficulty, p.harvest_days_min, p.harvest_days_max,\n               p.temperature_min, p.temperature_max, p.light_hours_min, p.light_hours_max,\n               p.suitable_indoor, p.is_edible, p.is_decorative, p.is_fast_growing,\n               p.is_featured, p.image_path, p.image_author, p.image_license,\n               c.slug AS category_slug, c.name AS category_name\n        FROM plant_catalog p\n        JOIN plant_categories c ON c.id = p.category_id\n        WHERE {$where}\n        ORDER BY " . fitodomikPlantCatalogOrder($filters['sort']) . "\n        LIMIT :limit OFFSET :offset\n    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $filters['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return [
        'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'filters' => $filters,
        'pagination' => [
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'total' => $total,
            'pages' => $pages,
        ],
    ];
}
function fitodomikPlantCatalogItem(PDO $pdo, string $slug): ?array
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,119}$/', $slug)) {
        return null;
    }
    $stmt = $pdo->prepare("\n        SELECT p.*, c.slug AS category_slug, c.name AS category_name\n        FROM plant_catalog p\n        JOIN plant_categories c ON c.id = p.category_id AND c.is_active = 1\n        WHERE p.slug = ? AND p.is_active = 1\n        LIMIT 1\n    ");
    $stmt->execute([$slug]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return null;
    }
    $sources = json_decode((string)$item['source_urls'], true);
    $item['source_urls'] = is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
    return $item;
}
function fitodomikPlantCatalogModeSnapshot(array $plant): array
{
    $temperature = round(((float)$plant['temperature_min'] + (float)$plant['temperature_max']) / 2, 1);
    $temperatureTolerance = min(10.0, round(((float)$plant['temperature_max'] - (float)$plant['temperature_min']) / 2, 1));
    $humidity = (int)round(((int)$plant['air_humidity_min'] + (int)$plant['air_humidity_max']) / 2);
    $humidityTolerance = min(20.0, round(((int)$plant['air_humidity_max'] - (int)$plant['air_humidity_min']) / 2, 1));
    $soil = (int)round(((int)$plant['soil_moisture_min'] + (int)$plant['soil_moisture_max']) / 2);
    $soilTolerance = min(30.0, round(((int)$plant['soil_moisture_max'] - (int)$plant['soil_moisture_min']) / 2, 1));
    $lightHours = max(0, min(24, (int)round(((float)$plant['light_hours_min'] + (float)$plant['light_hours_max']) / 2)));
    $lightStartHour = 7;
    $lampHours = [];
    for ($index = 0; $index < $lightHours; $index++) {
        $lampHours[] = ($lightStartHour + $index) % 24;
    }
    sort($lampHours);
    return [
        'name' => (string)$plant['name_ru'],
        'temperature' => $temperature,
        'tolerance' => $temperatureTolerance,
        'humidity' => $humidity,
        'humidity_tolerance' => $humidityTolerance,
        'soil_moisture' => $soil,
        'soil_moisture_tolerance' => $soilTolerance,
        'light_hours' => $lightHours,
        'light_start' => sprintf('%02d:00', $lightStartHour),
        'light_end' => sprintf('%02d:00', ($lightStartHour + $lightHours) % 24),
        'lamp_hours_json' => json_encode($lampHours, JSON_UNESCAPED_UNICODE),
    ];
}
function fitodomikPlantCatalogJsonResponse(int $status, array $payload): void
{
    if (!headers_sent()) {
        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
