<?php
// Credentials can be supplied by environment without changing tracked files.
$dbHost = getenv('MEMAR_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('MEMAR_DB_USER') ?: 'root';
$dbPass = getenv('MEMAR_DB_PASS') !== false ? getenv('MEMAR_DB_PASS') : '';
$dbPort = (int) (getenv('MEMAR_DB_PORT') ?: 3306);
$dbName = getenv('MEMAR_DB_NAME') ?: 'memar_accounting';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    if (str_starts_with($dbHost, 'p:')) {
        throw new RuntimeException('Persistent database hosts are disabled');
    }
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $mysqli->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') { throw $e; }
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'سرویس پایگاه داده موقتاً در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/db_schema_helpers.php';
