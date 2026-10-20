<?php
/**
 * Fresh-install smoke test: run db.php against a freshly created database.
 * Requires MySQL root with no password (matches db.php defaults).
 */
$db = mysqli_connect('127.0.0.1', 'root', '');
if (!$db) { echo "NO DB\n"; exit(1); }
$db->query('DROP DATABASE IF EXISTS memar_accounting_fresh_test');
$db->query('CREATE DATABASE memar_accounting_fresh_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->close();

// Temporarily switch db.php config to the fresh DB
$orig = file_get_contents(__DIR__ . '/../db.php');
$patched = str_replace("$dbName = 'memar_accounting';", "$dbName = 'memar_accounting_fresh_test';", $orig);
file_put_contents(__DIR__ . '/../db_fresh_patch.php', $patched);

require __DIR__ . '/../db_fresh_patch.php';

// Verify
$tables = [];
$r = $mysqli->query('SHOW TABLES');
if ($r) { while ($row = $r->fetch_row()) { $tables[] = $row[0]; } }
$checkProductUnits = in_array('product_units', $tables, true);
$colOk = false;
$r = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema='memar_accounting_fresh_test' AND table_name='invoice_items' AND column_name='unit_id'");
if ($r && $row = $r->fetch_assoc()) { $colOk = (int)$row['c'] === 1; }
$baseUnitCount = 0;
$r = $mysqli->query("SELECT COUNT(*) AS c FROM product_units WHERE is_base = 1");
if ($r && $row = $r->fetch_assoc()) { $baseUnitCount = (int)$row['c']; }

echo "product_units table: " . ($checkProductUnits ? 'OK' : 'MISSING') . "\n";
echo "invoice_items.unit_id column: " . ($colOk ? 'OK' : 'MISSING') . "\n";
echo "fresh products: " . (int)$mysqli->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'] . "\n";
echo "base units: $baseUnitCount\n";

$ok = $checkProductUnits && $colOk && $baseUnitCount >= 0;
$mysqli->query('DROP DATABASE memar_accounting_fresh_test');
unlink(__DIR__ . '/../db_fresh_patch.php');
echo ($ok ? "FRESH INSTALL OK" : "FRESH INSTALL PROBLEM") . "\n";
exit($ok ? 0 : 1);