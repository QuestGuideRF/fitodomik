<?php
function fitodomikImportPreset(PDO $pdo, int $userId, array $mode): int
{
    if ((int)$mode['user_id'] === $userId) { throw new DomainException('Этот режим уже принадлежит вам'); }
    $fields = ['name','description','temperature','tolerance','humidity','humidity_tolerance',
        'light_hours','light_start','light_end','soil_moisture','soil_moisture_tolerance','lamp_hours_json','curtain_schedule'];
    $values = [$userId];
    foreach ($fields as $field) { $values[] = $field === 'name' ? $mode[$field] . ' (импорт)' : ($mode[$field] ?? null); }
    $stmt = $pdo->prepare('INSERT INTO preset_modes (user_id,' . implode(',', $fields) . ',created_at)
        VALUES (' . implode(',', array_fill(0, count($values), '?')) . ',NOW())');
    $stmt->execute($values);
    return (int)$pdo->lastInsertId();
}
