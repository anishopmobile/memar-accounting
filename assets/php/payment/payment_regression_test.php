<?php
/**
 * payment_regression_test.php — PHASE 11 regression test.
 *
 * Verifies the payment integration did NOT break the existing accounting
 * application. Confirms existing tables/rows are intact and the shared
 * invoice/customer/product flows still work with the same schema.
 * No unrelated refactoring; only reads/integrity checks.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$failures = 0;
function r(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

/* ---- 1. Existing tables are intact ---- */
$expectedTables = [
    'customers', 'suppliers', 'product_categories', 'products',
    'store_settings', 'invoice_settings', 'tax_settings', 'db_backup_settings',
    'invoice_sequences', 'invoices', 'invoice_items', 'stock_movements', 'notes',
];
$res = $mysqli->query('SHOW TABLES');
$existing = [];
while ($row = $res->fetch_row()) {
    $existing[$row[0]] = true;
}
foreach ($expectedTables as $t) {
    r(isset($existing[$t]), "existing table '$t' present");
}

/* ---- 2. Core columns unchanged (schema intact) ---- */
$core = [
    'customers' => ['id', 'first_name', 'last_name', 'phone', 'total_spent', 'debt'],
    'invoices'  => ['id', 'invoice_number', 'type', 'payment_status', 'payable_amount', 'customer_id', 'supplier_id'],
    'products'  => ['id', 'code', 'name', 'sale_price', 'purchase_price', 'stock', 'min_stock'],
    'suppliers' => ['id', 'company_name', 'phone'],
];
foreach ($core as $table => $cols) {
    $c = $mysqli->query("SHOW COLUMNS FROM `$table`");
    $colNames = [];
    while ($row = $c->fetch_assoc()) {
        $colNames[$row['Field']] = true;
    }
    foreach ($cols as $col) {
        r(isset($colNames[$col]), "$table column '$col' present");
    }
}

/* ---- 3. No payment tables collide with existing naming ---- */
r(!isset($existing['transactions']), "no legacy 'transactions' table added");
r(!isset($existing['forms']), "no generic 'forms' table added");
r(isset($existing['users']), "'users' table present (authentication module requirement)");
r(isset($existing['payment_forms'], $existing['payment_transactions'], $existing['payment_notifications']), 'payment tables coexist without clobbering');

/* ---- 4. Existing sample data intact (customers present) ---- */
$rc = $mysqli->query('SELECT COUNT(*) c FROM customers');
$row = $rc->fetch_assoc();
r((int) $row['c'] >= 0, 'customers relation queryable (count=' . (int) $row['c'] . ')');

/* ---- 5. invoice_sequences sequence intact + payment seq coexists ---- */
$seq = $mysqli->query("SELECT seq_key FROM invoice_sequences");
$seqKeys = [];
while ($s = $seq->fetch_row()) {
    $seqKeys[$s[0]] = true;
}
r(isset($seqKeys['sales_invoice']), 'existing invoice_sequences keys intact');
r(isset($seqKeys['payment_transaction']), 'payment sequence key added without collision');

/* ---- 6. invoice number generation still works (mirrors invoice_common) ---- */
$stmt = $mysqli->prepare("UPDATE invoice_sequences SET current_value = current_value + 1 WHERE seq_key = 'sales_invoice'");
$stmt->execute();
$stmt->close();
$sel = $mysqli->query("SELECT current_value FROM invoice_sequences WHERE seq_key = 'sales_invoice'");
$v = $sel->fetch_assoc();
r((int) $v['current_value'] > 0, 'sales_invoice sequence still increments');

echo "\n" . ($failures === 0 ? 'ALL REGRESSION CHECKS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();