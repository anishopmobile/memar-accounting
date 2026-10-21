<?php
/**
 * CI-only isolated DB integration test. Requires an EMPTY MySQL test server
 * at 127.0.0.1 with root/no-password, as the application's db.php expects.
 * Never run against production. Rolls back every test data modification.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/currency.php';
require_once dirname(__DIR__) . '/invoice_pdf_common.php';

$check = static function(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
};
$before = $mysqli->query("SELECT currency_code FROM currency_settings WHERE id = 1")->fetch_assoc();
$check(($before['currency_code'] ?? '') === 'TOMAN', 'fresh install default currency TOMAN');
$check(getCurrentCurrency($mysqli) === 'TOMAN', 'initial preference');
$mysqli->begin_transaction();
try {
    // A sandbox product with distinctive prices verifies preference updates
    // do not rewrite any monetary database columns.
    $code = 'CI-MONEY-' . bin2hex(random_bytes(5));
    $stmt = $mysqli->prepare("INSERT INTO products (code, name, purchase_price, sale_price, stock) VALUES (?, 'Currency sandbox', '2500.50', '10000.00', 0)");
    $stmt->bind_param('s', $code);
    $check($stmt->execute(), 'sandbox product inserted');
    $productId = (int) $stmt->insert_id;
    $stmt->close();

    $check($mysqli->query("UPDATE currency_settings SET currency_code = 'IRR' WHERE id = 1") !== false, 'switch preference to IRR');
    $check(getCurrentCurrency($mysqli) === 'IRR', 'IRR preference reads back');
    $stmt = $mysqli->prepare('SELECT purchase_price, sale_price FROM products WHERE id = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $check($row['sale_price'] === '10000.00', 'stored TOMAN sale price unchanged');
    $check($row['purchase_price'] === '2500.50', 'stored TOMAN purchase price unchanged');
    $check(formatMoney($row['sale_price'], 'IRR') === '100,000 ریال', 'IRR display is exact');
    $check(pdf_amount_in_words('0.01') === 'صفر و یک دهم ریال', 'PDF amount words preserve IRR tenths');
    $check(pdf_amount_in_words('-100') === 'منفی هزار ریال', 'PDF amount words preserve minus sign in IRR');
    $check(parseMoneyInput('100000', 'IRR') === '10000', 'IRR input roundtrips without multiplying existing rows');

    $check($mysqli->query("UPDATE currency_settings SET currency_code = 'TOMAN' WHERE id = 1") !== false, 'switch preference back to TOMAN');
    $check(getCurrentCurrency($mysqli) === 'TOMAN', 'TOMAN preference reads back');
    $check(formatMoney($row['sale_price'], 'TOMAN') === '10,000 تومان', 'TOMAN display is exact');
} finally {
    $mysqli->rollback();
}
$check($mysqli->query("SELECT currency_code FROM currency_settings WHERE id = 1")->fetch_assoc()['currency_code'] === 'TOMAN', 'rollback restored original currency');
echo "PASS all MySQL currency integration checks\n";
