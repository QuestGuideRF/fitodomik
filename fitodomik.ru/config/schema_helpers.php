<?php
if (!function_exists('fitodomikTableExists')) {
    function fitodomikTableExists($pdo, $table)
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('fitodomikColumnExists')) {
    function fitodomikColumnExists($pdo, $table, $column)
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('fitodomikIndexExists')) {
    function fitodomikIndexExists($pdo, $table, $index)
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
        ");
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('fitodomikAddColumnIfMissing')) {
    function fitodomikAddColumnIfMissing($pdo, $table, $column, $definition)
    {
        if (!fitodomikTableExists($pdo, $table) || fitodomikColumnExists($pdo, $table, $column)) {
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
if (!function_exists('fitodomikAddIndexIfMissing')) {
    function fitodomikAddIndexIfMissing($pdo, $table, $index, $columns)
    {
        if (!fitodomikTableExists($pdo, $table) || fitodomikIndexExists($pdo, $table, $index)) {
            return;
        }
        try {
            $pdo->exec("CREATE INDEX `$index` ON `$table` ($columns)");
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false && strpos($e->getMessage(), '1061') === false) {
                throw $e;
            }
        }
    }
}
