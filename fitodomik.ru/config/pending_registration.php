<?php
require_once __DIR__ . '/api_exceptions.php';
require_once __DIR__ . '/device_context.php';
const FITODOMIK_PENDING_REGISTRATION_LIFETIME = 900;
function fitodomikPendingRegistrationIsValid(array $tempUser): bool
{
    $firstName = trim((string)($tempUser['first_name'] ?? ''));
    $lastName = trim((string)($tempUser['last_name'] ?? ''));
    $username = trim((string)($tempUser['username'] ?? ''));
    $passwordHash = (string)($tempUser['password'] ?? '');
    $startedAt = (int)($tempUser['registration_started_at'] ?? 0);
    $passwordInfo = password_get_info($passwordHash);
    return $firstName !== ''
        && $lastName !== ''
        && preg_match('/^\w{3,20}$/D', $username) === 1
        && ($passwordInfo['algoName'] ?? 'unknown') !== 'unknown'
        && $startedAt >= time() - FITODOMIK_PENDING_REGISTRATION_LIFETIME
        && $startedAt <= time() + 60;
}
function fitodomikCreateUserFromPendingRegistration(PDO $pdo, array $tempUser): array
{
    if (!fitodomikPendingRegistrationIsValid($tempUser)) {
        throw new FitodomikInputException('Срок регистрации истёк. Начните регистрацию заново.');
    }
    $username = trim((string)$tempUser['username']);
    $existing = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $existing->execute([$username]);
    if ($existing->fetchColumn()) {
        throw new FitodomikConflictException('Этот никнейм уже занят. Вернитесь к регистрации.');
    }
    $values = [
        trim((string)$tempUser['first_name']),
        trim((string)$tempUser['last_name']),
        $username,
        (string)$tempUser['password'],
    ];
    $sql = 'INSERT INTO users (first_name, last_name, username, password) VALUES (?, ?, ?, ?)';
    try {
        $insert = $pdo->prepare($sql);
        $insert->execute($values);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new FitodomikConflictException('Этот никнейм уже занят. Вернитесь к регистрации.', 0, $e);
        }
        throw $e;
    }
    $userId = (int)$pdo->lastInsertId();
    $userQuery = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $userQuery->execute([$userId]);
    $user = $userQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user)) {
        throw new FitodomikStorageException('Созданный аккаунт не найден');
    }
    return $user;
}
