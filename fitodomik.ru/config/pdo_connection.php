<?php
require_once __DIR__ . '/security_headers.php';
function fitodomikOpenDatabaseConnection(string $host, string $database, string $user, string $password): PDO
{
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $mode=(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $modes=array_filter(explode(',', $mode));
    if (!in_array('NO_AUTO_VALUE_ON_ZERO',$modes,true)) $modes[]='NO_AUTO_VALUE_ON_ZERO';
    $pdo->prepare('SET SESSION sql_mode=?')->execute([implode(',',$modes)]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    return $pdo;
}
function fitodomikDatabaseUnavailable(Throwable $error): never
{
    $reference=bin2hex(random_bytes(8));
    error_log('Database unavailable ['.$reference.']: '.$error->getMessage());
    if (PHP_SAPI === 'cli') throw new RuntimeException('Database unavailable ['.$reference.']',0,$error);
    http_response_code(503);
    header('Retry-After: 30'); header('Cache-Control: no-store');
    header('X-Request-ID: '.$reference);
    if (strpos((string)($_SERVER['SCRIPT_NAME']??''),'/api/')!==false) {
        header('Content-Type: application/json; charset=utf-8');
        if (function_exists('fitodomikUsesContractV2') && fitodomikUsesContractV2()) {
            fitodomikContractResponse(['success'=>false,'message'=>'Сервис временно недоступен'],503,null,'database_unavailable');
        }
        echo json_encode(['success'=>false,'message'=>'Сервис временно недоступен','error'=>'database_unavailable','request_id'=>$reference],JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8'); echo 'Сервис временно недоступен ['.$reference.']';
    }
    exit;
}
