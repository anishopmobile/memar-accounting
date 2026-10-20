<?php
/**
 * Sales Return — Integration tests (CLI only)
 *
 * Run:
 *   php assets/php/tests/sales_return_test.php
 *
 * Uses the real memar_accounting database but operates only on test products
 * (codes prefixed "T-SR-") and cleans up after itself.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

session_start();
$_SESSION['auth_user'] = 'memar';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../sales_return_common.php';
require_once __DIR__ . '/../sales_return_pdf_data.php';

$pass = 0;
$fail = 0;
$failures = [];

function t_check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  [OK] $label\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? ' — ' . $detail : '');
        echo "  [!!] $label " . ($detail !== '' ? '-- ' . $detail : '') . "\n";
    }
}

/** ساخت کالای تستی با یک واحد پایه (عدد، ضریب ۱) */
function t_make_product(mysqli $mysqli, string $code, string $name): array
{
    $esc = $mysqli->real_escape_string($code);
    $mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM products WHERE code = '$esc'");
    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, NULL, ?, "product", "عدد", 0, 0, 0, 0, "")'
    );
    $stmt->bind_param('ss', $code, $name);
    $stmt->execute();
    $pid = (int) $stmt->insert_id;
    $stmt->close();
    $ins = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "عدد", 1, 1, 0, 0, 0)');
    $ins->bind_param('i', $pid);
    $ins->execute();
    $unitId = (int) $ins->insert_id;
    $ins->close();
    return ['id' => $pid, 'unit_id' => $unitId, 'code' => $code];
}

function t_make_customer(mysqli $mysqli, string $name): int
{
    $stmt = $mysqli->prepare('INSERT INTO customers (first_name, last_name, phone) VALUES (?, ?, "0")');
    $lname = '';
    $stmt->bind_param('ss', $name, $lname);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function t_make_supplier(mysqli $mysqli, string $name): int
{
    $stmt = $mysqli->prepare('INSERT INTO suppliers (company_name, first_name, last_name, phone) VALUES (?, ?, ?, "0")');
    $fname = '';
    $lname = '';
    $stmt->bind_param('sss', $name, $fname, $lname);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** خرید قطعی (افزایش موجودی) */
function t_purchase(mysqli $mysqli, int $productId, int $unitId, $qty, $price): array
{
    $supplier = t_make_supplier($mysqli, 'تامپیر تست' . uniqid());
    $in = [
        'type'           => 'purchase_invoice',
        'party_id'       => $supplier,
        'payment_type'   => 'cash',
        'payment_status' => 'paid',
        'invoice_date'   => date('Y-m-d'),
        'discount'       => '0',
        'note'           => 'test purchase',
        'client_token'   => 'sr-test-' . uniqid(),
        'tax_rate'       => null,
        'items'          => [[
            'product_id' => $productId,
            'unit_id'    => $unitId,
            'quantity'   => (string) $qty,
            'unit_price' => (string) $price,
            'discount'   => '0',
        ]],
    ];
    return createInvoice($mysqli, $in);
}

/** فروش قطعی (کاهش موجودی) */
function t_sell(mysqli $mysqli, int $productId, int $unitId, $qty, $price): array
{
    $customer = t_make_customer($mysqli, 'خریدار تست' . uniqid());
    $in = [
        'type'           => 'sales_invoice',
        'party_id'       => $customer,
        'payment_type'   => 'cash',
        'payment_status' => 'paid',
        'invoice_date'   => date('Y-m-d'),
        'discount'       => '0',
        'note'           => 'test sale',
        'client_token'   => 'sr-test-sale-' . uniqid(),
        'tax_rate'       => null,
        'items'          => [[
            'product_id' => $productId,
            'unit_id'    => $unitId,
            'quantity'   => (string) $qty,
            'unit_price' => (string) $price,
            'discount'   => '0',
        ]],
    ];
    return createInvoice($mysqli, $in);
}

/** فروش فقط برای تست (با متد make_sell_custom) */
function t_make_proforma(mysqli $mysqli, int $productId, int $unitId, $qty, $price): array
{
    $customer = t_make_customer($mysqli, 'خریدار تست' . uniqid());
    $in = [
        'type'           => 'sales_proforma',
        'party_id'       => $customer,
        'payment_type'   => 'cash',
        'payment_status' => 'unpaid',
        'invoice_date'   => date('Y-m-d'),
        'discount'       => '0',
        'note'           => 'proforma test',
        'client_token'   => 'sr-test-pro-' . uniqid(),
        'tax_rate'       => null,
        'items'          => [[
            'product_id' => $productId,
            'unit_id'    => $unitId,
            'quantity'   => (string) $qty,
            'unit_price' => (string) $price,
            'discount'   => '0',
        ]],
    ];
    return createInvoice($mysqli, $in);
}

function t_stock_of(mysqli $mysqli, int $productId): string
{
    $stmt = $mysqli->prepare('SELECT stock FROM products WHERE id = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row ? money_round((string) $row['stock']) : '0';
}

/** اقلام فاکتور فروش (invoice_item_id) */
function t_invoice_items(mysqli $mysqli, int $invoiceId): array
{
    $stmt = $mysqli->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}

/** ساخت درخواست برگشت (داده خام؛ همان چیزی که کلاینت می‌فرستد) */
function t_ret_input(int $invoiceId, array $items, string $date = ''): array
{
    return [
        'invoice_id'  => (string) $invoiceId,
        'return_date' => $date !== '' ? $date : date('Y-m-d'),
        'note'        => '',
        'items'       => $items,
    ];
}

function t_ret_item(int $invoiceItemId, int $productId, int $unitId, $qty): array
{
    return [
        'invoice_item_id' => (string) $invoiceItemId,
        'product_id'      => (string) $productId,
        'unit_id'         => (string) $unitId,
        'quantity'        => (string) $qty,
    ];
}

/** آخرین حرکت موجودی مربوط به یک سند برگشت */
function t_last_return_movement(mysqli $mysqli, int $returnId): ?array
{
    $stmt = $mysqli->prepare('SELECT * FROM stock_movements WHERE sales_return_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res ? $res->fetch_assoc() : null;
}

echo "== Test A: Full return ==\n";
$p = t_make_product($mysqli, 'T-SR-A-1', 'برگشت کامل');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
t_check('purchase created', $r['ok']);
$purchaseInv = (int) $r['invoice_id'];
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
t_check('sales created', $r['ok']);
$saleInv = (int) $r['invoice_id'];
$stockAfterSale = t_stock_of($mysqli, $p['id']);
t_check('stock after sale = 90', money_cmp($stockAfterSale, '90') === 0, $stockAfterSale);
$ii = t_invoice_items($mysqli, $saleInv);
$ctx0 = sr_invoice_context($mysqli, $saleInv, null);
t_check('crx returnable = 10', money_cmp((string) $ctx0['items'][$ii[0]['id']]['returnable_base'], '10') === 0);

$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 10)]));
t_check('full return success', $ret['ok'], $ret['message']);
$returnIdA = (int) ($ret['return_id'] ?? 0);
$GLOBALS['__retANumber'] = (string) ($ret['return_number'] ?? '');
t_check('return number assigned', str_starts_with((string) ($ret['return_number'] ?? ''), 'SR-'), (string) ($ret['return_number'] ?? ''));
t_check('stock after full return = 100', money_cmp(t_stock_of($mysqli, $p['id']), '100') === 0, t_stock_of($mysqli, $p['id']));

$mov = t_last_return_movement($mysqli, $returnIdA);
t_check('movement type sales_return + in', ($mov['type'] ?? '') === 'sales_return' && ($mov['direction'] ?? '') === 'in');
t_check('movement quantity = 10', money_cmp((string) ($mov['quantity'] ?? '0'), '10') === 0);
t_check('movement linked to return id', (int) ($mov['sales_return_id'] ?? 0) === $returnIdA);

$ctx1 = sr_invoice_context($mysqli, $saleInv, null);
t_check('crx returnable now 0', money_cmp((string) $ctx1['items'][$ii[0]['id']]['returnable_base'], '0') === 0);
$pg = getSalesReturn($mysqli, $returnIdA);
t_check('get return header', $pg !== null && (string) $pg['return_number'] === (string) $ret['return_number']);
t_check('get return has items', $pg !== null && count($pg['items']) === 1);

echo "\n== Test B: Partial return ==\n";
$p = t_make_product($mysqli, 'T-SR-B-1', 'برگشت جزئی');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 3)]));
t_check('partial return success', $ret['ok'], $ret['message']);
t_check('stock after partial return = 93', money_cmp(t_stock_of($mysqli, $p['id']), '93') === 0, t_stock_of($mysqli, $p['id']));

echo "\n== Test C: Multiple partial returns (2+3+5=10) ==\n";
$p = t_make_product($mysqli, 'T-SR-C-1', 'چند برگشت جزئی');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$okAll = true;
foreach ([2, 3, 5] as $q) {
    $ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], $q)]));
    if (!$ret['ok']) { $okAll = false; }
}
t_check('returns 2+3+5 all success', $okAll);
t_check('stock back to 100 after 10 returned', money_cmp(t_stock_of($mysqli, $p['id']), '100') === 0, t_stock_of($mysqli, $p['id']));
$ctx = sr_invoice_context($mysqli, $saleInv, null);
t_check('ctx returnable = 0', money_cmp((string) $ctx['items'][$ii[0]['id']]['returnable_base'], '0') === 0);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 1)]));
t_check('extra return rejected', !$ret['ok'], $ret['message']);

echo "\n== Test D: Over return ==\n";
$p = t_make_product($mysqli, 'T-SR-D-1', 'برگشت بیش از حد');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 8)]));
t_check('first return 8 success', $ret['ok'], $ret['message']);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 3)]));
t_check('second return 3 rejected (over)', !$ret['ok'], $ret['message']);
t_check('stock unchanged after rejection = 98', money_cmp(t_stock_of($mysqli, $p['id']), '98') === 0, t_stock_of($mysqli, $p['id']));

echo "\n== Test E: Proforma rejection ==\n";
$p = t_make_product($mysqli, 'T-SR-E-1', 'پیش فاکتور');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_make_proforma($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$proInv = (int) $r['invoice_id'];
$ctx = sr_invoice_context($mysqli, $proInv, null);
t_check('proforma ctx rejected', !$ctx['ok']);
$ii = t_invoice_items($mysqli, $proInv);
$ret = createSalesReturn($mysqli, t_ret_input($proInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 3)]));
t_check('proforma return rejected', !$ret['ok'], $ret['message']);

echo "\n== Test H: Edit increase (delta +2) ==\n";
$p = t_make_product($mysqli, 'T-SR-H-1', 'ویرایش افزایش');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 3)]));
$ridH = (int) $ret['return_id'];
t_check('edit base return 3 ok', $ret['ok']);
t_check('stock = 93', money_cmp(t_stock_of($mysqli, $p['id']), '93') === 0);
$ret = editSalesReturn($mysqli, $ridH, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 5)]));
t_check('edit to 5 ok', $ret['ok'], $ret['message']);
t_check('stock after +2 delta = 95', money_cmp(t_stock_of($mysqli, $p['id']), '95') === 0, t_stock_of($mysqli, $p['id']));
$pg = getSalesReturn($mysqli, $ridH);
t_check('return payable recomputed', $pg !== null && money_cmp((string) $pg['payable_amount'], '10000') === 0, (string) ($pg['payable_amount'] ?? ''));

echo "\n== Test I: Edit decrease (delta -3) ==\n";
$p = t_make_product($mysqli, 'T-SR-I-1', 'ویرایش کاهش');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 5)]));
$ridI = (int) $ret['return_id'];
t_check('edit base return 5 ok', $ret['ok']);
t_check('stock = 95', money_cmp(t_stock_of($mysqli, $p['id']), '95') === 0);
$ret = editSalesReturn($mysqli, $ridI, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 2)]));
t_check('edit to 2 ok', $ret['ok'], $ret['message']);
t_check('stock after -3 delta = 92', money_cmp(t_stock_of($mysqli, $p['id']), '92') === 0, t_stock_of($mysqli, $p['id']));

echo "\n== Test G: Delete reversal ==\n";
$p = t_make_product($mysqli, 'T-SR-G-1', 'حذف برگشت');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 3)]));
$ridG = (int) $ret['return_id'];
t_check('return created (stock 93)', money_cmp(t_stock_of($mysqli, $p['id']), '93') === 0);
$ret = deleteSalesReturn($mysqli, $ridG);
t_check('delete return ok', $ret['ok'], $ret['message']);
t_check('stock back to 90', money_cmp(t_stock_of($mysqli, $p['id']), '90') === 0, t_stock_of($mysqli, $p['id']));
$ret = deleteSalesReturn($mysqli, $ridG);
t_check('double delete rejected & no double reversal', !$ret['ok'] && money_cmp(t_stock_of($mysqli, $p['id']), '90') === 0, $ret['message']);

echo "\n== Test J1: Original invoice edit/delete guards ==\n";
$p = t_make_product($mysqli, 'T-SR-J-1', 'قفل فاکتور');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $p['id'], $p['unit_id'], 2)]));
t_check('return ok', $ret['ok']);

$inv = getInvoice($mysqli, $saleInv);
$editIn = [
    'type' => 'sales_invoice',
    'party_id' => (int) $inv['customer_id'],
    'payment_type' => 'cash',
    'payment_status' => 'paid',
    'invoice_date' => date('Y-m-d'),
    'discount' => '0',
    'note' => 'test edit blocked',
    'tax_rate' => null,
    'items' => [[
        'product_id' => $p['id'],
        'unit_id' => $p['unit_id'],
        'quantity' => '10',
        'unit_price' => '2000',
        'discount' => '0',
    ]],
];
$r = editInvoice($mysqli, $saleInv, $editIn);
t_check('edit invoice blocked after return', !$r['ok'], $r['message']);
$r = deleteInvoice($mysqli, $saleInv);
t_check('delete invoice blocked after return', !$r['ok'], $r['message']);
t_check('invoice still exists', getInvoice($mysqli, $saleInv) !== null);

echo "\n== Test F2: Multi-unit conversion return ==\n";
$code = 'T-SR-MU-' . rand(100, 999);
$esc = $mysqli->real_escape_string($code);
$mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
$mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
$mysqli->query("DELETE FROM products WHERE code = '$esc'");
$stmt = $mysqli->prepare('INSERT INTO products (code, name, type, unit, purchase_price, sale_price, stock, min_stock) VALUES (?, "چند واحدی", "product", "عدد", 0, 0, 0, 0)');
$stmt->bind_param('s', $code);
$stmt->execute();
$pid = (int) $stmt->insert_id;
$stmt->close();
$ins = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "عدد", 1, 1, 0, 0, 0)');
$ins->bind_param('i', $pid);
$ins->execute();
$baseUnit = (int) $ins->insert_id;
$ins->close();
$ins = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "کارتن ۱۰", 10, 0, 0, 0, 1)');
$ins->bind_param('i', $pid);
$ins->execute();
$cartonUnit = (int) $ins->insert_id;
$ins->close();
$r = t_purchase($mysqli, $pid, $baseUnit, 500, '100');
t_check('multi-unit purchase ok', $r['ok']);
$r = t_sell($mysqli, $pid, $cartonUnit, 5, '20000');
t_check('multi-unit sale (5 cartons=50 base) ok', $r['ok']);
$saleInv = (int) $r['invoice_id'];
$ii = t_invoice_items($mysqli, $saleInv);
t_check('invoice item base qty = 50', money_cmp((string) $ii[0]['base_quantity'], '50') === 0);
$ctx = sr_invoice_context($mysqli, $saleInv, null);
t_check('ctx returnable base = 50', money_cmp((string) $ctx['items'][$ii[0]['id']]['returnable_base'], '50') === 0);
t_check('ctx returnable display = 5 cartons', money_cmp((string) $ctx['items'][$ii[0]['id']]['returnable_display'], '5') === 0);
$ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]['id'], $pid, $cartonUnit, 2)]));
t_check('return 2 cartons (base 20) ok', $ret['ok'], $ret['message']);
$stockNow = t_stock_of($mysqli, $pid);
t_check('stock increased by 20 (base units)', money_cmp($stockNow, '470') === 0, $stockNow);

echo "\n== Test J2: CSRF via endpoint (subprocess) ==\n";
/** اجرای کد PHP در یک process جداگانه (بدون مشکل نقل‌قول shell) */
function t_run_child(string $code): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'sr-test-');
    file_put_contents($tmp, '<?php ' . $code);
    $out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return $out;
}
$saveAbs = realpath(__DIR__ . '/../sales_return_save.php');
$code1 = 'session_start();$_SESSION["auth_user"]="memar";$_SESSION["csrf_token"]="good-token";'
    . '$_POST=["csrf_token"=>"bad-token","invoice_id"=>"1","return_date"=>"2026-01-01","note"=>"","items"=>"[]"];'
    . 'require ' . var_export($saveAbs, true) . ';';
$out1 = t_run_child($code1);
t_check('invalid CSRF => success false', str_contains($out1, '"success":false'), substr($out1, 0, 80));
t_check('invalid CSRF message mentions CSRF', mb_strpos($out1, 'CSRF') !== false);
$code2 = 'session_start();$_SESSION["auth_user"]="memar";$_SESSION["csrf_token"]="good-token";'
    . '$_POST=["csrf_token"=>"good-token","invoice_id"=>"9999999","return_date"=>"2026-01-01","note"=>"","items"=>"[]"];'
    . 'require ' . var_export($saveAbs, true) . ';';
$out2 = t_run_child($code2);
t_check('valid CSRF passed (moved to invoice validation)', str_contains($out2, 'فاکتور موردنظر یافت نشد'), substr($out2, 0, 80));

echo "\n== Test K: Concurrent writers serialize on invoice row lock ==\n";
$p = t_make_product($mysqli, 'T-SR-K-1', 'همزمانی');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
$saleInv = (int) $r['invoice_id'];
$c2 = new mysqli('127.0.0.1', 'root', '', 'memar_accounting');
$c2->set_charset('utf8mb4');
$c2->query('SET SESSION innodb_lock_wait_timeout = 1');
$mysqli->begin_transaction();
$stmt = $mysqli->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE');
$stmt->bind_param('i', $saleInv);
$stmt->execute();
$stmt->close();
$blocked = false;
$c2->begin_transaction();
try {
    $stmt2 = $c2->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE');
    $stmt2->bind_param('i', $saleInv);
    $stmt2->execute();
    $stmt2->close();
} catch (Throwable $e) {
    $blocked = true;
}
$mysqli->commit();
try { $c2->rollback(); } catch (Throwable $e) {}
$c2->close();
t_check('second writer blocked while first holds invoice lock', $blocked === true);

echo "\n== Test L/M: PDF generation (existing mPDF architecture) ==\n";
function t_run_pdf_endpoint(string $absEndpoint, int $id, string $mode): string
{
    $code = 'session_start();$_SESSION["auth_user"]="memar";'
        . '$_GET=["id"=>"' . $id . '","mode"=>"' . $mode . '"];'
        . 'require ' . var_export($absEndpoint, true) . ';';
    $tmp = tempnam(sys_get_temp_dir(), 'sr-pdf-');
    $outFile = $tmp . '.out';
    file_put_contents($tmp, '<?php ' . $code);
    // خروجی باینری PDF از طریق redirect به فایل (capture از طریق pipe در Windows ناقص است)
    $cmd = PHP_BINARY . ' ' . escapeshellarg($tmp) . ' > ' . escapeshellarg($outFile) . ' 2>&1';
    shell_exec($cmd);
    $out = is_file($outFile) ? (string) file_get_contents($outFile) : '';
    @unlink($tmp);
    @unlink($outFile);
    return $out;
}
$srPdfAbs = realpath(__DIR__ . '/../sales_return_pdf.php');
$pdf = t_run_pdf_endpoint($srPdfAbs, (int) $returnIdA, 'view');
t_check('return PDF is %PDF', str_starts_with($pdf, '%PDF'), substr($pdf, 0, 30));
t_check('return PDF is not empty', strlen($pdf) > 1000, (string) strlen($pdf));

/* Regression: existing sales + purchase invoice PDFs unchanged */
$salePdfAbs = realpath(__DIR__ . '/../invoice_pdf.php');
$pdf = t_run_pdf_endpoint($salePdfAbs, (int) $saleInv, 'view');
t_check('sales invoice PDF still generated', str_starts_with($pdf, '%PDF'), substr($pdf, 0, 30));
$purchasePdfAbs = realpath(__DIR__ . '/../invoice_pdf_purchase.php');
$pdf = t_run_pdf_endpoint($purchasePdfAbs, (int) $purchaseInv, 'view');
t_check('purchase invoice PDF still generated', str_starts_with($pdf, '%PDF'), substr($pdf, 0, 30));

echo "\n== Test N: Existing invoice create/edit/delete (no returns) ==\n";
$p = t_make_product($mysqli, 'T-SR-N-1', 'بازگشت طبیعی');
$r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
t_check('create purchase still works', $r['ok']);
$r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
t_check('create sale still works', $r['ok']);
$saleInvN = (int) $r['invoice_id'];
$invN = getInvoice($mysqli, $saleInvN);
$editIn = [
    'type' => 'sales_invoice',
    'party_id' => (int) $invN['customer_id'],
    'payment_type' => 'cash',
    'payment_status' => 'paid',
    'invoice_date' => date('Y-m-d'),
    'discount' => '0',
    'note' => 'edit ok',
    'tax_rate' => null,
    'items' => [[
        'product_id' => $p['id'],
        'unit_id' => $p['unit_id'],
        'quantity' => '8',
        'unit_price' => '2000',
        'discount' => '0',
    ]],
];
$r = editInvoice($mysqli, $saleInvN, $editIn);
t_check('edit sale still works without returns', $r['ok'], $r['message']);
$r = deleteInvoice($mysqli, $saleInvN);
t_check('delete sale still works without returns', $r['ok'], $r['message']);

echo "\n== Test O: Search / list / eligible ==\n";
$l = listSalesReturns($mysqli, ['search' => '', 'page' => 1, 'per_page' => 10]);
t_check('list total >= 1', $l['total'] >= 1, 'total=' . $l['total']);
$l2 = listSalesReturns($mysqli, ['search' => $GLOBALS['__retANumber'], 'page' => 1]);
t_check('search return number exact', $l2['total'] >= 1 && isset($l2['rows'][0]) && $l2['rows'][0]['return_number'] === $GLOBALS['__retANumber']);
$l3 = listSalesReturns($mysqli, ['search' => 'SR-999999999', 'page' => 1]);
t_check('no results for bogus number', $l3['total'] === 0);
$elig = sr_eligible_invoices($mysqli, '');
$proFound = false;
foreach ($elig as $e) {
    if ((int) $e['id'] === (int) $proInv) { $proFound = true; }
}
t_check('proforma excluded from eligible invoices', !$proFound);

/* ============================================================
 * پاکسازی داده‌های تست
 * ========================================================== */
echo "\n== Cleanup ==\n";
$mysqli->query("DELETE FROM sales_returns WHERE invoice_id IN (SELECT id FROM invoices WHERE client_token LIKE 'sr-test-%')");
$mysqli->query("DELETE FROM invoices WHERE client_token LIKE 'sr-test-%'");
$mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-SR-%')");
/* حذف پسماندهای احتمالی اقلام/فاکتورهای تست پیش از حذف کالاها */
$mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-SR-%')");
$mysqli->query("DELETE FROM invoices WHERE id IN (SELECT ii.invoice_id FROM invoice_items ii INNER JOIN products p ON p.id = ii.product_id WHERE p.code LIKE 'T-SR-%')");
$mysqli->query("DELETE FROM products WHERE code LIKE 'T-SR-%'");
$mysqli->query("DELETE FROM customers WHERE first_name LIKE 'خریدار تست%' OR first_name LIKE 'مشتری تست%'");
$mysqli->query("DELETE FROM suppliers WHERE company_name LIKE 'تامپیر تست%'");

echo "\n==============================\n";
echo "PASS: $pass  FAIL: $fail\n";
if ($failures) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);