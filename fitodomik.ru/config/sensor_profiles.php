<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/device_context.php';
require_once __DIR__ . '/json_response.php';
if (!function_exists('fitodomikSensorProfilesResponse')) {
    function fitodomikSensorProfilesResponse($status, $payload)
    {
        fitodomikJsonResponse($status, $payload);
    }
}
if (!function_exists('fitodomikSensorProfilesInput')) {
    function fitodomikSensorProfilesInput()
    {
        $maxBodyBytes = 256 * 1024;
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBodyBytes) {
            fitodomikSensorProfilesResponse(413, ['success' => false, 'message' => 'Профиль слишком большой']);
        }
        $rawInput = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if (is_string($rawInput) && strlen($rawInput) > $maxBodyBytes) {
            fitodomikSensorProfilesResponse(413, ['success' => false, 'message' => 'Профиль слишком большой']);
        }
        $input = json_decode((string)$rawInput, true, 64);
        if (!is_array($input)) {
            $input = $_REQUEST;
        }
        return is_array($input) ? $input : [];
    }
}
if (!function_exists('fitodomikSensorProfilesAuth')) {
    function fitodomikSensorProfilesAuth($pdo)
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (!empty($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("SELECT id, username, is_locked FROM users WHERE id = ? AND is_locked = 0 LIMIT 1");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (fitodomikAccountAdmitted($user)) {
                return $user;
            }
        }
        $token = fitodomikRequestHeader('X-Auth-Token');
        return fitodomikUserByToken($pdo, $token);
    }
}
if (!function_exists('fitodomikSensorProfilesEnsureStorage')) {
    function fitodomikSensorProfilesEnsureStorage($pdo)
    {
        if (PHP_SAPI !== 'cli' || !defined('FITODOMIK_SCHEMA_MAINTENANCE') || !FITODOMIK_SCHEMA_MAINTENANCE) { return; }
        fitodomikEnsureDeviceStorage($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_sensor_profiles (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                profile_id VARCHAR(120) NOT NULL,
                title VARCHAR(180) NOT NULL,
                sensor_type VARCHAR(80) NOT NULL,
                connection_type VARCHAR(40) NOT NULL,
                profile_json LONGTEXT NOT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'private',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_profile (user_id, profile_id),
                INDEX idx_sensor_profiles_user (user_id, updated_at),
                INDEX idx_sensor_profiles_public (is_public, status, updated_at),
                INDEX idx_sensor_profiles_type (sensor_type, connection_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
if (!function_exists('fitodomikSensorProfileValidate')) {
    function fitodomikSensorProfileValidate($profile)
    {
        $errors = [];
        if (!is_array($profile)) {
            return ['ok' => false, 'errors' => ['Профиль должен быть JSON-объектом']];
        }
        foreach (['profile_id', 'sensor_type', 'name', 'connection', 'includes', 'constructor_template', 'setup_template', 'read_templates', 'template_status'] as $field) {
            if (!array_key_exists($field, $profile)) {
                $errors[] = 'Нет поля ' . $field;
            }
        }
        $profileId = trim((string)($profile['profile_id'] ?? ''));
        if ($profileId === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,120}$/', $profileId)) {
            $errors[] = 'profile_id может содержать только буквы, цифры, _ и -';
        }
        $connection = strtolower(trim((string)($profile['connection'] ?? '')));
        if (!in_array($connection, ['analog', 'digital', 'i2c', 'uart', 'spi'], true)) {
            $errors[] = 'connection должен быть analog, digital, i2c, uart или spi';
        }
        if (!is_array($profile['includes'] ?? null)) {
            $errors[] = 'includes должен быть массивом';
        }
        if (!is_array($profile['read_templates'] ?? null) || !($profile['read_templates'] ?? [])) {
            $errors[] = 'read_templates должен быть непустым массивом';
        }
        if (!in_array((string)($profile['template_status'] ?? ''), ['supported', 'partial', 'library_required'], true)) {
            $errors[] = 'template_status должен быть supported, partial или library_required';
        }
        return ['ok' => !$errors, 'errors' => $errors];
    }
}
if (!function_exists('fitodomikSensorProfilePublicRow')) {
    function fitodomikSensorProfilePublicRow($row, $includeJson = false)
    {
        $profile = json_decode((string)($row['profile_json'] ?? '{}'), true);
        if (!is_array($profile)) {
            $profile = [];
        }
        $result = [
            'profile_id' => $row['profile_id'],
            'title' => $row['title'],
            'sensor_type' => $row['sensor_type'],
            'connection' => $row['connection_type'],
            'is_public' => (bool)$row['is_public'],
            'status' => $row['status'],
            'template_status' => $profile['template_status'] ?? 'private',
            'library_name' => $profile['library_name'] ?? '',
            'updated_at' => $row['updated_at'],
        ];
        if ($includeJson) {
            $result['profile'] = $profile;
        }
        return $result;
    }
}
