<?php
// Verify snack: create a test product with a multi-unit, buy 2x package-12, then inspect stock_movements snapshot.
if (php_sapi_name() !== 'cli') { exit(1); }
require __DIR__ . '/../db.php';
require __DIR__ . '/../invoice_common.php';

$code = 'T-SNAP-' . mt_rand(1000, 9999);
$mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code = '$code')");
$mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code = '$code')");
$mysqli->query("DELETE FROM products WHERE code = '$code'");

$stmt = $mysqli->prepare('INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description) VALUES (?, NULL, "تست", "product", "عدد", 0, 0, 0, 0, "")');
$stmt->bind_param('s', $code);
$stmt->execute();
$pid = (int) $stmt->insert_id;
$stmt->close();

$stmt = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "عدد", 1, 1, 100, 120, 0)');
$stmt->bind_param('i', $pid); $stmt->execute(); $stmt->close();
$stmt = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "بسته 12", 12, 0, 1100, 1300, 1)');
$stmt->bind_param('i', $pid); $stmt->execute(); $stmt->close();

$res = $mysqli->query("SELECT id FROM product_units WHERE product_id = $pid AND name = 'بسته 12'");
$uRow = $res->fetch_assoc();
$unitId = (int) $uRow['id'];

// party
$stmt = $mysqli->prepare('INSERT INTO suppliers (company_name, first_name, last_name, phone) VALUES ("تست", "", "", "0")');
$stmt->execute(); $supplier = (int) $stmt->insert_id; $stmt->close();

$in = [
    'type' => 'purchase_invoice', 'party_id' => $supplier,
    'payment_type' => 'cash', 'payment_status' => 'paid',
    'invoice_date' => '2026-09-02', 'discount' => '0', 'note' => null,
    'client_token' => 'tok-snap-' . uniqid(),
    'items' => [['product_id' => $pid, 'unit_id' => (string) $unitId, 'quantity' => '2', 'unit_price' => '1100', 'discount' => '0']],
];
$r = createInvoice($mysqli, $in);
echo "invoice ok: " . ($r['ok'] ? 'yes' : $r['message']) . "\n";
$res = $mysqli->query("SELECT type, unit_name, quantity, transaction_quantity, conversion_factor, stock_before, stock_after FROM stock_movements WHERE product_id = $pid ORDER BY id DESC LIMIT 1");
$row = $res->fetch_assoc();
echo "movement type: " . $row['type'] . "\n";
echo "unit_name: " . var_export($row['unit_name'], true) . "\n";
echo "quantity(base): " . $row['quantity'] . " tx_qty: " . var_export($row['transaction_quantity'], true) . " factor: " . $row['conversion_factor'] . "\n";
echo "before: " . $row['stock_before'] . " after: " . $row['stock_after'] . "\n";
$ok = $row['unit_name'] === 'بسته 12' && $row['quantity'] == 24 && $row['transaction_quantity'] == 2 && $row['conversion_factor'] == 12;

// cleanup
$mysqli->query("DELETE FROM stock_movements WHERE product_id = $pid");
$mysqli->query("DELETE FROM invoice_items WHERE product_id = $pid");
$mysqli->query("DELETE FROM invoices WHERE id = {$r['invoice_id']}");
$mysqli->query("DELETE FROM suppliers WHERE id = $supplier");
$mysqli->query("DELETE FROM products WHERE id = $pid");
echo ($ok ? "SNAPSHOT OK" : "SNAPSHOT FAIL") . "\n";