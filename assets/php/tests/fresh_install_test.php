<?php
// Only against an explicitly selected disposable test server.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
$host = getenv('MEMAR_DB_HOST') ?: '127.0.0.1';
$user = getenv('MEMAR_DB_USER') ?: 'root';
$pass = getenv('MEMAR_DB_PASS') ?: '';
$port = (int)(getenv('MEMAR_DB_PORT') ?: 3306);
$name = 'memar_fresh_' . bin2hex(random_bytes(6));
$db = new mysqli($host, $user, $pass, '', $port);
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('MEMAR_DB_NAME=' . $name);
try {
    require dirname(__DIR__, 3) . '/tools/migrate-database.php';
    $check = new mysqli($host, $user, $pass, $name, $port);
    foreach (['product_units','invoices','sales_returns','account_payments'] as $table) {
        $result = $check->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows !== 1) { throw new RuntimeException('Missing ' . $table); }
    }
    $result = $check->query("SHOW COLUMNS FROM sales_returns LIKE 'client_token'");
    if ($result->num_rows !== 1) { throw new RuntimeException('Retry schema missing'); }
    $check->close();
    echo "FRESH INSTALL OK\n";
} finally {
    $db->query("DROP DATABASE `$name`");
    $db->close();
}
