<?php
declare(strict_types=1);
function fitodomikLockClientAccount(PDO $pdo, int $userId): void
{
    $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $lock->execute([$userId]);
    $lock->fetchColumn();
}
function fitodomikCreateClientGrant(PDO $pdo, int $userId, string $origin): string
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        fitodomikLockClientAccount($pdo, $userId);
        $active = $pdo->prepare("SELECT d.device_id FROM user_devices d JOIN users u ON u.id=d.user_id
            WHERE d.user_id=? AND d.device_id=? AND d.status='active' AND u.is_locked=0 FOR UPDATE");
        $active->execute([$userId, $origin]);
        if (!$active->fetchColumn()) { throw new DomainException('Активное устройство не найдено'); }
        $token = bin2hex(random_bytes(32));
        $insert = $pdo->prepare('INSERT INTO account_client_grants(user_id,origin_device_id,token_hash) VALUES (?,?,?)');
        $insert->execute([$userId, $origin, hash('sha256', $token)]);
        if ($ownTransaction) { $pdo->commit(); }
        return $token;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
function fitodomikClientGrantUser(PDO $pdo, string $token): ?array
{
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT u.id,u.username,u.first_name,u.last_name,u.is_locked,g.id AS client_grant_id
        FROM account_client_grants g JOIN users u ON u.id=g.user_id
        JOIN user_devices d ON d.user_id=g.user_id AND d.device_id=g.origin_device_id AND d.status='active'
        WHERE g.token_hash=? AND g.revoked_at IS NULL AND u.is_locked=0 LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { return null; }
    $user['client_token'] = $token;
    return $user;
}
function fitodomikClientDeviceToken(PDO $pdo, array $user, string $deviceId): string
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        fitodomikLockClientAccount($pdo, (int)$user['id']);
        $device = $pdo->prepare("SELECT d.device_id,d.device_token FROM account_client_grants g
            JOIN users u ON u.id=g.user_id AND u.is_locked=0
            JOIN user_devices origin ON origin.user_id=g.user_id AND origin.device_id=g.origin_device_id AND origin.status='active'
            JOIN user_devices d ON d.user_id=g.user_id AND d.device_id=? AND d.status='active'
            WHERE g.id=? AND g.user_id=? AND g.token_hash=? AND g.revoked_at IS NULL FOR UPDATE");
        $device->execute([$deviceId, (int)$user['client_grant_id'], (int)$user['id'], hash('sha256', $user['client_token'])]);
        $current = $device->fetch(PDO::FETCH_ASSOC);
        if (!$current || empty($current['device_token'])) {
            throw new DomainException('Доступ клиента отозван или устройство отвязано');
        }
        $token = hash_hmac('sha256', 'device:' . $current['device_id'] . ':' . $current['device_token'], $user['client_token']);
        $stmt = $pdo->prepare('INSERT INTO client_device_credentials(grant_id,device_id,token_hash) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash)');
        $stmt->execute([(int)$user['client_grant_id'], $deviceId, hash('sha256', $token)]);
        if ($ownTransaction) { $pdo->commit(); }
        return $token;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
function fitodomikClientDeviceByToken(PDO $pdo, string $deviceId, string $token): ?array
{
    $stmt = $pdo->prepare("SELECT d.*,u.username,u.first_name,u.last_name,u.is_locked
        FROM client_device_credentials c JOIN account_client_grants g ON g.id=c.grant_id
        JOIN users u ON u.id=g.user_id
        JOIN user_devices origin ON origin.user_id=g.user_id AND origin.device_id=g.origin_device_id AND origin.status='active'
        JOIN user_devices d ON d.device_id=c.device_id AND d.user_id=g.user_id AND d.status='active'
        WHERE c.device_id=? AND c.token_hash=? AND g.revoked_at IS NULL AND u.is_locked=0 LIMIT 1");
    $stmt->execute([$deviceId, hash('sha256', $token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function fitodomikRevokeClientGrant(PDO $pdo, int $userId, int $grantId): bool
{
    $stmt = $pdo->prepare('UPDATE account_client_grants SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL');
    $stmt->execute([$grantId, $userId]);
    return $stmt->rowCount() === 1;
}
function fitodomikUnlinkDevice(PDO $pdo, int $userId, string $deviceId): int
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        fitodomikLockClientAccount($pdo, $userId);
        $stmt = $pdo->prepare("UPDATE user_devices SET status='revoked',revoked_at=NOW()
            WHERE user_id=? AND device_id=? AND status='active'");
        $stmt->execute([$userId, $deviceId]);
        $changed = $stmt->rowCount();
        $pdo->prepare('UPDATE account_client_grants SET revoked_at=NOW()
            WHERE user_id=? AND origin_device_id=? AND revoked_at IS NULL')->execute([$userId, $deviceId]);
        $pdo->prepare('DELETE c FROM client_device_credentials c JOIN account_client_grants g ON g.id=c.grant_id
            WHERE g.user_id=? AND c.device_id=?')->execute([$userId, $deviceId]);
        if ($ownTransaction) { $pdo->commit(); }
        return $changed;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
