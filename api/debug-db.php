<?php
// TEMPORARY diagnostic — DELETE after debugging
// Tests DB + config loading and returns any PHP error as JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = ['step' => 'start', 'php_version' => PHP_VERSION];

try {
    $result['step'] = 'require_config';
    require_once __DIR__ . '/config.php';
    $result['constants_defined'] = [
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'MISSING',
        'DB_NAME' => defined('DB_NAME') ? (DB_NAME === 'YOUR_DB_NAME' ? 'PLACEHOLDER' : 'SET') : 'MISSING',
        'DB_USER' => defined('DB_USER') ? (DB_USER === 'YOUR_DB_USER' ? 'PLACEHOLDER' : 'SET') : 'MISSING',
        'DB_PASS' => defined('DB_PASS') ? (DB_PASS === 'YOUR_DB_PASS' ? 'PLACEHOLDER' : (strlen(DB_PASS) > 0 ? 'SET(' . strlen(DB_PASS) . 'chars)' : 'EMPTY')) : 'MISSING',
        'JWT_SECRET' => defined('JWT_SECRET') ? (JWT_SECRET === 'CHANGE_THIS_TO_A_RANDOM_64_CHAR_SECRET' ? 'PLACEHOLDER' : 'SET') : 'MISSING',
        'FREE_DAILY_LIMIT' => defined('FREE_DAILY_LIMIT') ? FREE_DAILY_LIMIT : 'MISSING',
        'OPENAI_KEY' => isset($OPENAI_API_KEY) ? ($OPENAI_API_KEY === 'YOUR_OPENAI_API_KEY_HERE' ? 'PLACEHOLDER' : 'SET(' . strlen($OPENAI_API_KEY) . 'chars)') : 'MISSING',
    ];

    $result['step'] = 'connect_pdo';
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $result['step'] = 'connected';
    $result['db_connected'] = true;

    // Check tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $result['tables'] = $tables;

} catch (PDOException $e) {
    $result['error_type'] = 'PDOException';
    $result['error_msg'] = $e->getMessage();
    $result['error_code'] = $e->getCode();
} catch (Throwable $e) {
    $result['error_type'] = get_class($e);
    $result['error_msg'] = $e->getMessage();
    $result['error_file'] = $e->getFile();
    $result['error_line'] = $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT);
