<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../config/pdo_connection.php';
const COMPONENT_STATUS_BOUGHT_LABEL = 'Куплено';
if (!function_exists('fitodomikRuConfigValue')) {
    function fitodomikRuConfigValue($key, $default = '') {
        return fitodomikRuEnv($key, $default);
    }
}
if (!function_exists('fitodomikRuSiteUrl')) {
    function fitodomikRuSiteUrl(): string
    {
        return rtrim((string)fitodomikRuConfigValue('SITE_URL', 'https://fitodomik.ru'), '/');
    }
}
if (!defined('DB_HOST')) {
    define('DB_HOST', fitodomikRuConfigValue('DB_HOST', 'localhost'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', fitodomikRuConfigValue('DB_NAME'));
}
if (!defined('DB_USER')) {
    define('DB_USER', fitodomikRuConfigValue('DB_USERNAME', fitodomikRuConfigValue('DB_USER')));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', fitodomikRuConfigValue('DB_PASSWORD', fitodomikRuConfigValue('DB_PASS')));
}
if (!defined('TELEGRAM_BOT_TOKEN')) {
    define('TELEGRAM_BOT_TOKEN', fitodomikRuConfigValue('TELEGRAM_BOT_TOKEN'));
}
if (!defined('TELEGRAM_CHAT_ID')) {
    define('TELEGRAM_CHAT_ID', fitodomikRuConfigValue('TELEGRAM_CHAT_ID'));
}
try {
    $pdo = fitodomikOpenDatabaseConnection(DB_HOST, DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
    fitodomikDatabaseUnavailable($e);
}
if (PHP_SAPI === 'cli' && defined('FITODOMIK_SCHEMA_MAINTENANCE') && FITODOMIK_SCHEMA_MAINTENANCE) {
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    is_locked BOOLEAN DEFAULT FALSE,
    reset_token VARCHAR(100) NULL,
    reset_token_expires TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log('Failed to initialize users table: ' . $e->getMessage());
    http_response_code(503);
    exit('Сервис временно недоступен');
}
$sql = "CREATE TABLE IF NOT EXISTS components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NULL,
    price_type ENUM('bought', 'custom', 'undefined') DEFAULT 'undefined',
    status ENUM('bought', 'not_bought') DEFAULT 'not_bought',
    info TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log("Ошибка при создании таблицы components: " . $e->getMessage());
}
$sql = "CREATE TABLE IF NOT EXISTS code (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    content TEXT NOT NULL,
    language VARCHAR(50) DEFAULT 'text',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log("Ошибка при создании таблицы code: " . $e->getMessage());
}
$sql = "CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    attachment VARCHAR(255) NULL,
    status ENUM('new', 'in_progress', 'completed') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log("Ошибка при создании таблицы feedback: " . $e->getMessage());
}
$sql = "CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log("Ошибка при создании таблицы posts: " . $e->getMessage());
}
$sql = "CREATE TABLE IF NOT EXISTS timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log("Ошибка при создании таблицы timeline: " . $e->getMessage());
}
function ensureAutoIncrementId(PDO $pdo, string $tableName): void {
    $allowedTables = ['posts', 'timeline', 'code_links'];
    if (!in_array($tableName, $allowedTables, true)) {
        return;
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE 'id'");
        $stmt->execute();
        $column = $stmt->fetch();
        if ($column && stripos($column['Extra'] ?? '', 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE `$tableName` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
        }
    } catch (PDOException $e) {
        error_log("Ошибка при проверке AUTO_INCREMENT для {$tableName}: " . $e->getMessage());
    }
}
ensureAutoIncrementId($pdo, 'posts');
ensureAutoIncrementId($pdo, 'timeline');
$sql = "CREATE TABLE IF NOT EXISTS code_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL COMMENT 'Название ссылки для отображения',
    url VARCHAR(500) NOT NULL COMMENT 'URL ссылки',
    description TEXT NULL COMMENT 'Описание ссылки (опционально)',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Активна ли ссылка',
    sort_order INT DEFAULT 0 COMMENT 'Порядок сортировки',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
try {
    $pdo->exec($sql);
} catch(PDOException $e) {
    error_log("Ошибка при создании таблицы code_links: " . $e->getMessage());
}
ensureAutoIncrementId($pdo, 'code_links');
}
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
if (!function_exists('fitodomikRuSendTelegramMessage')) {
    function fitodomikRuSendTelegramMessage($message) {
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
        $data = [
            'chat_id' => TELEGRAM_CHAT_ID,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        file_get_contents($url, false, $context);
    }
}
function isUsernameUnique($pdo, $username) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetchColumn() == 0;
}
function isPasswordStrong($password) {
    return strlen($password) >= 8 &&
           preg_match('/[A-Z]/', $password) &&
           preg_match('/[a-z]/', $password) &&
           preg_match('/\d/', $password);
}
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}
if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        if (!isAuthenticated()) {
            return null;
        }
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
}
function formatPrice($price_type, $price) {
    switch ($price_type) {
        case 'bought':
            return COMPONENT_STATUS_BOUGHT_LABEL;
        case 'custom':
            return number_format($price, 0, ',', ' ') . ' ₽';
        case 'undefined':
        default:
            return 'Неизвестно';
    }
}
function formatStatus($status) {
    return $status === 'bought' ? COMPONENT_STATUS_BOUGHT_LABEL : 'Не куплено';
}
function formatPriceType($price_type) {
    switch ($price_type) {
        case 'bought':
            return COMPONENT_STATUS_BOUGHT_LABEL;
        case 'custom':
            return 'Указать цену';
        case 'undefined':
        default:
            return 'Цена не определена';
    }
}
function calculateTotalAmount($components) {
    $total = 0;
    $unknown_count = 0;
    foreach ($components as $component) {
        if ($component['price_type'] === 'custom' && $component['price'] !== null) {
            $total += $component['price'] * $component['quantity'];
        } elseif ($component['price_type'] === 'undefined') {
            $unknown_count += $component['quantity'];
        }
    }
    return [
        'total' => $total,
        'unknown_count' => $unknown_count
    ];
}
