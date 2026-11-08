<?php
// Run with the site's PHP CLI, during a maintenance window, after a backup.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MEMAR_RUN_SCHEMA', true);
require_once __DIR__ . '/../assets/php/db.php';
$lockName = 'memar_schema_' . substr(hash('sha256', $dbName), 0, 32);
$stmt = $mysqli->prepare('SELECT GET_LOCK(?, 10)');
$stmt->bind_param('s', $lockName); $stmt->execute();
if ((int)$stmt->get_result()->fetch_row()[0] !== 1) { throw new RuntimeException('Migration already running'); }
try {
    require __DIR__ . '/../assets/php/db_schema.php';
    echo "Core schema migration completed.\n";
} finally {
    $stmt = $mysqli->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $lockName); $stmt->execute();
    $mysqli->close();
}
