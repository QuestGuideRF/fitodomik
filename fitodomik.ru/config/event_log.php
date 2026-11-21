<?php
function fitodomikLogEventOnce(PDO $pdo, int $userId, string $eventType, string $description, bool $inclusive = false): bool
{
    $ownTransaction = !$pdo->inTransaction();
    try {
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        $lock = $pdo->prepare('INSERT INTO event_log_locks (lock_id) VALUES (1) ON DUPLICATE KEY UPDATE lock_id = VALUES(lock_id)');
        $lock->execute();
        $query = $inclusive
            ? 'SELECT id FROM event_log WHERE user_id = ? AND event_type = ? AND event_description = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1 FOR UPDATE'
            : 'SELECT id FROM event_log WHERE user_id = ? AND event_type = ? AND event_description = ? AND created_at > NOW() - INTERVAL 10 MINUTE LIMIT 1 FOR UPDATE';
        $check = $pdo->prepare($query);
        $check->execute([$userId, $eventType, $description]);
        if ($check->fetchColumn() === false) {
            $query = $inclusive
                ? 'INSERT INTO event_log (user_id, event_type, event_description) VALUES (?, ?, ?)'
                : 'INSERT INTO event_log (user_id, event_type, event_description, created_at) VALUES (?, ?, ?, NOW())';
            $insert = $pdo->prepare($query);
            $insert->execute([$userId, $eventType, $description]);
        }
        if ($ownTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Exception $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($inclusive && !$e instanceof PDOException) {
            throw $e;
        }
        $prefix = $inclusive ? 'Error logging event: ' : 'Ошибка при логировании события: ';
        error_log($prefix . $e->getMessage());
        return false;
    }
}
