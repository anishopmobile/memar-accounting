<?php
/**
 * Sales Invoice — Product/Service Selection + Zero-Stock Backend Enforcement
 *
 * Integration tests (CLI only).
 * Run:
 *   php assets/php/tests/sales_invoice_product_service_test.php
 *
 * Uses the real memar_accounting database but operates only on test products
 * (codes prefixed "T-SIP-") and cleans up after itself.
 *
 * Covers the spec docs/sales-invoice-product-service-selection.md:
 *  - product modal data source returns only product-type + stock > 0 items
 *  - service search returns only service-type items (regardless of stock)
 *  - backend rejects zero-stock products on sales invoice create/edit
 *  - services remain sellable with stock 0
 *  - services never change product inventory
 *  - purchase invoices, existing search behavior, calculations stay intact
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../invoice_common.php';

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
        echo "  [!!] $label " . ($detail !== '' ? '— ' . $detail : '') . "\n";
    }
}

/** ساخت کالا/خدمت تستی (خودش + یک واحد پایه برای کالا) */
function t_make_product(mysqli $mysqli, string $code, string $name, string $type, $stock): array
{
    $esc = $mysqli->real_escape_string($code);
    $escName = $mysqli->real_escape_string($name);
    $t = $type === 'service' ? 'service' : 'product';
    $mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM product_units WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM products WHERE code = '$esc'");

    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, NULL, ?, ?, "عدد", 0, 0, ?, 0, "")'
    );
    $stmt->bind_param('sssd', $code, $escName, $t, $stock);
    $stmt->execute();
    $pid = (int) $stmt->insert_id;
    $stmt->close();

    if ($t === 'product') {
        $ins = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "عدد", 1, 1, 0, 0, 0)');
        $ins->bind_param('i', $pid);
        $ins->execute();
        $ins->close();
    }
    return ['id' => $pid, 'code' => $code, 'type' => $t];
}

function t_make_customer(mysqli $mysqli, string $name): int
{
    $stmt = $mysqli->prepare('INSERT INTO customers (first_name, last_name, phone) VALUES (?, "", "0")');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function t_make_supplier(mysqli $mysqli, string $name): int
{
    $stmt = $mysqli->prepare('INSERT INTO suppliers (company_name, first_name, last_name, phone) VALUES (?, "تست", "", "0")');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function t_item(int $pid, $qty, $price = '1000', $disc = '0'): array
{
    return ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price, 'discount' => $disc];
}

function t_invoice_input(string $type, int $partyId, array $items): array
{
    return [
        'type'           => $type,
        'party_id'       => $partyId,
        'payment_type'   => 'cash',
        'payment_status' => 'paid',
        'invoice_date'   => date('Y-m-d'),
        'discount'       => '0',
        'note'           => '',
        'client_token'   => 'tok-sip-' . uniqid('', true),
        'tax_rate'       => '0',
        'items'          => $items,
    ];
}

function t_stock(mysqli $mysqli, int $pid): string
{
    $stmt = $mysqli->prepare('SELECT stock FROM products WHERE id = ?');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row ? (string) $row['stock'] : '';
}

/** اجرای واقعی endpoint جستجوی کالا (invoice_products.php) در یک زیرپروسه PHP */
function endpoint_search(array $params): array
{
    $root = realpath(__DIR__ . '/../../..');
    $child = <<<'CHILD'
<?php
session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['auth_user'] = 'memar';
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$params = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (is_array($params)) {
    $_GET = $params;
}
include {ROOT} . '/assets/php/invoice_products.php';
CHILD;
    $child = str_replace('{ROOT}', var_export($root, true), $child);
    $tmp = tempnam(sys_get_temp_dir(), 'sip_') . '.php';
    file_put_contents($tmp, $child);
    $paramsFile = tempnam(sys_get_temp_dir(), 'sipp_');
    file_put_contents($paramsFile, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($paramsFile);
    $out = shell_exec($cmd);
    @unlink($tmp);
    @unlink($paramsFile);
    $decoded = json_decode((string) $out, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'invalid output'];
}
function endpoint_codes(array $data): array
{
    $codes = [];
    foreach ($data as $row) {
        $codes[] = (string) ($row['code'] ?? '');
    }
    return $codes;
}

echo "== Setup ==\n";
$zero  = t_make_product($mysqli, 'T-SIP-ZERO', 'کالای تستی بدون موجودی', 'product', 0);
$zero2 = t_make_product($mysqli, 'T-SIP-Z2', 'کالای تستی بدون موجودی (خرید)', 'product', 0);
$dup   = t_make_product($mysqli, 'T-SIP-DUP', 'کالای تستی تکراری', 'product', 10);
$stk   = t_make_product($mysqli, 'T-SIP-STK', 'کالای تستی دارای موجودی', 'product', 3);
$stk5  = t_make_product($mysqli, 'T-SIP-5', 'کالای تستی پانصدی', 'product', 5);
$svc   = t_make_product($mysqli, 'T-SIP-SVC', 'خدمت تستی فاکتور', 'service', 0);
$customer = t_make_customer($mysqli, 'تست مشتری فروش کالا-خدمت');
$supplier = t_make_supplier($mysqli, 'تست تامین فروش کالا-خدمت');

echo "\n== 1) productTypeMap / validateSalesProductAvailability ==\n";
$map = productTypeMap($mysqli, [$zero['id'], $stk['id'], $svc['id']]);
t_check('productTypeMap: product detected', ($map[$zero['id']] ?? '') === 'product' && ($map[$stk['id']] ?? '') === 'product');
t_check('productTypeMap: service detected', ($map[$svc['id']] ?? '') === 'service');

$r = validateSalesProductAvailability($mysqli, 'sales_invoice', [t_item($zero['id'], 1)]);
t_check('validate: sales + zero-stock product rejected', !$r['ok'], $r['message'] ?? '');
$r = validateSalesProductAvailability($mysqli, 'sales_invoice', [t_item($stk['id'], 1)]);
t_check('validate: sales + in-stock product accepted', $r['ok'], $r['message'] ?? '');
$r = validateSalesProductAvailability($mysqli, 'sales_invoice', [t_item($svc['id'], 1)]);
t_check('validate: sales + service with stock 0 accepted', $r['ok'], $r['message'] ?? '');
$r = validateSalesProductAvailability($mysqli, 'purchase_invoice', [t_item($zero['id'], 1)]);
t_check('validate: purchase + zero-stock product unaffected', $r['ok'], $r['message'] ?? '');

echo "\n== 2) createInvoice: sales ==\n";
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($zero['id'], 1)]));
t_check('sales invoice with zero-stock product rejected', !$r['ok'], $r['message'] ?? '');
t_check('rejection message is Persian + human', ($r['message'] ?? '') !== '' && mb_strlen($r['message']) > 5 && $r['message'] !== 'negative_stock');

$before = t_stock($mysqli, $stk['id']);
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($stk['id'], 1)]));
t_check('sales invoice with in-stock product created', $r['ok'], $r['message'] ?? '');
$invStk = $r['invoice_id'] ?? 0;
$after = t_stock($mysqli, $stk['id']);
t_check('product inventory decreased on sale (3 -> 2)', $after === '2.00' && $before === '3.00', "before=$before after=$after");

$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($svc['id'], 1)]));
t_check('sales invoice with service (stock 0) created', $r['ok'], $r['message'] ?? '');
$invSvc = $r['invoice_id'] ?? 0;
t_check('service stock remains 0 after sale', t_stock($mysqli, $svc['id']) === '0.00');

$before5 = t_stock($mysqli, $stk5['id']);
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($stk5['id'], 1), t_item($svc['id'], 1)]));
t_check('sales invoice with product + service created', $r['ok'], $r['message'] ?? '');
t_check('product stock decreased (5 -> 4)', t_stock($mysqli, $stk5['id']) === '4.00' && $before5 === '5.00');
t_check('service stock untouched in mixed invoice', t_stock($mysqli, $svc['id']) === '0.00');

echo "\n== 3) createInvoice: calculations preserved ==\n";
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($stk5['id'], 2, '25000'), t_item($svc['id'], 1, '100000')]));
t_check('invoice with product+service (qty 2, prices) created', $r['ok'], $r['message'] ?? '');
$full = getInvoice($mysqli, (int) ($r['invoice_id'] ?? 0));
$subtotal = (float) ($full['subtotal'] ?? 0);
t_check('subtotal = 25000*2 + 100000*1', $subtotal === 150000.0, "subtotal=$subtotal");
t_check('payable = subtotal (tax 0)', (float) ($full['payable_amount'] ?? 0) === 150000.0, 'payable=' . ($full['payable_amount'] ?? ''));
t_check('line_total stored correctly for service row', (float) ($full['items'][1]['line_total'] ?? 0) === 100000.0);

echo "\n== 4) createInvoice: purchase unaffected ==\n";
$r = createInvoice($mysqli, t_invoice_input('purchase_invoice', $supplier, [t_item($zero2['id'], 2)]));
t_check('purchase invoice with zero-stock product created', $r['ok'], $r['message'] ?? '');
t_check('purchase increases product stock (0 -> 2)', t_stock($mysqli, $zero2['id']) === '2.00');
echo "\n== 5) createInvoice: duplicate-item behavior ==\n";
$beforeDup = t_stock($mysqli, $dup['id']);
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($dup['id'], 1), t_item($dup['id'], 2)]));
t_check('sales invoice with duplicate product rows created', $r['ok'], $r['message'] ?? '');
t_check('duplicate rows merged into qty 3 stock reduction', t_stock($mysqli, $dup['id']) === '7.00' && $beforeDup === '10.00', 'before=' . $beforeDup . ' after=' . t_stock($mysqli, $dup['id']));

echo "\n== 6) editInvoice: sales ==\n";
$r = editInvoice($mysqli, $invStk, t_invoice_input('sales_invoice', $customer, [t_item($stk['id'], 1), t_item($zero['id'], 1)]));
t_check('edit(sales) adding zero-stock product rejected', !$r['ok'], $r['message'] ?? '');
t_check('stock not mutated by rejected edit (product still 2.00)', t_stock($mysqli, $stk['id']) === '2.00');

$r = editInvoice($mysqli, $invSvc, t_invoice_input('sales_invoice', $customer, [t_item($svc['id'], 2)]));
t_check('edit(sales) service qty 1 -> 2 accepted', $r['ok'], $r['message'] ?? '');
t_check('service stock still 0 after edit', t_stock($mysqli, $svc['id']) === '0.00');

echo "\n== 7) deleteInvoice: sales service does not reverse inventory ==\n";
$beforeDel = t_stock($mysqli, $stk5['id']);
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $customer, [t_item($stk5['id'], 1), t_item($svc['id'], 1)]));
$invDel = (int) ($r['invoice_id'] ?? 0);
t_check('invoice for delete test created', $r['ok'] && $invDel > 0, $r['message'] ?? '');
t_check('product stock decreased by the sale (before-1)', t_stock($mysqli, $stk5['id']) === (string) ((float) $beforeDel - 1.0) . '.00');
$r = deleteInvoice($mysqli, $invDel);
t_check('deleteInvoice executed', $r['ok'], $r['message'] ?? '');
t_check('product stock restored after delete', t_stock($mysqli, $stk5['id']) === $beforeDel);
t_check('service stock untouched by delete', t_stock($mysqli, $svc['id']) === '0.00');

echo "\n== 8) convertInvoice: sales proforma -> final ==\n";
$beforePro = t_stock($mysqli, $stk5['id']);
$r = createInvoice($mysqli, t_invoice_input('sales_proforma', $customer, [t_item($stk5['id'], 1), t_item($svc['id'], 1)]));
$invPro = (int) ($r['invoice_id'] ?? 0);
t_check('sales proforma (product + service) created', $r['ok'] && $invPro > 0, $r['message'] ?? '');
t_check('proforma creation does not change product stock', t_stock($mysqli, $stk5['id']) === $beforePro);
$r = convertInvoice($mysqli, $invPro);
t_check('convertInvoice executed', $r['ok'], $r['message'] ?? '');
t_check('product stock decreased on convert (before-1)', t_stock($mysqli, $stk5['id']) === (string) ((float) $beforePro - 1.0) . '.00');
t_check('service stock untouched by convert', t_stock($mysqli, $svc['id']) === '0.00');

echo "\n== 9) invoice_products.php search endpoint ==\n";
$res = endpoint_search(['search' => '']);
t_check('endpoint: default search (no filters) returns data', ($res['success'] ?? false) === true);
$codes = endpoint_codes($res['data'] ?? []);
t_check('endpoint: default search still includes product+service (all types)', in_array($zero['code'], $codes, true) && in_array($svc['code'], $codes, true));

$res = endpoint_search(['search' => '', 'in_stock' => '1']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('product modal (in_stock=1) includes in-stock products', in_array($stk['code'], $codes, true) && in_array($stk5['code'], $codes, true));
t_check('product modal (in_stock=1) excludes zero-stock product', !in_array($zero['code'], $codes, true));
t_check('product modal (in_stock=1) excludes services', !in_array($svc['code'], $codes, true));

$res = endpoint_search(['search' => 'T-SIP-ZERO', 'in_stock' => '1']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('product modal: search does not reveal zero-stock product', !in_array($zero['code'], $codes, true));
$res = endpoint_search(['search' => 'T-SIP-STK', 'in_stock' => '1']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('product modal: search reveals in-stock product', in_array($stk['code'], $codes, true));

$res = endpoint_search(['search' => '', 'type' => 'service']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('service modal (type=service) includes service with stock 0', in_array($svc['code'], $codes, true));
t_check('service modal (type=service) excludes products', !in_array($stk['code'], $codes, true) && !in_array($zero['code'], $codes, true));
$res = endpoint_search(['search' => 'T-SIP-ZERO', 'type' => 'service']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('service modal: product search term returns nothing', !in_array($zero['code'], $codes, true));

$res = endpoint_search(['search' => 'T-SIP-ZERO', 'type' => 'product']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('type=product (defective goods path) still includes zero-stock product', in_array($zero['code'], $codes, true));

echo "\n== Cleanup ==\n";
$mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-SIP-%')");
$mysqli->query("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_token LIKE 'tok-sip-%')");
$mysqli->query("DELETE FROM invoices WHERE client_token LIKE 'tok-sip-%'");
$mysqli->query("DELETE FROM product_units WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-SIP-%')");
$mysqli->query("DELETE FROM products WHERE code LIKE 'T-SIP-%'");
$mysqli->query("DELETE FROM customers WHERE first_name = 'تست مشتری فروش کالا-خدمت'");
$mysqli->query("DELETE FROM suppliers WHERE company_name = 'تست تامین فروش کالا-خدمت'");
$mysqli->close();

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