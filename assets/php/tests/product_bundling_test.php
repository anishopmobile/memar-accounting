<?php
/**
 * Product Bundling / Bundle Sales — Integration Tests (CLI only)
 *
 * Run:
 *   php assets/php/tests/product_bundling_test.php
 *
 * Uses the real memar_accounting database but only operates on test products
 * (codes prefixed "T-BND-") and cleans up after itself.
 *
 * Covers docs/product-bundling-spec.md:
 *  - bundle creation/validation (components, duplicates, service/self/zero)
 *  - derived availability (MIN floor stock/qty)
 *  - sales invoice consumes component stock (not the bundle)
 *  - insufficient stock => full rollback
 *  - delta-based invoice edit
 *  - historical snapshot on delete (master change ignored)
 *  - proforma + conversion uses stored snapshot
 *  - invoice_products.php picker (bundles in sales, excluded from purchase)
 *  - sales-return restores components via snapshot
 *  - ordinary product/sales-return regression inside the same flow
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../invoice_common.php';
require_once __DIR__ . '/../bundle_common.php';
require_once __DIR__ . '/../sales_return_common.php';

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

/* ---------- helpers ---------- */

function t_cleanup_test_data(mysqli $mysqli): void
{
    $ids = [];
    $res = $mysqli->query("SELECT id FROM products WHERE code LIKE 'T-BND-%'");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $res->free();
    }
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        /* بسته‌هایی که از این کالاها استفاده کرده‌اند (کد خودکار عددی دارند) */
        $r2 = $mysqli->query("SELECT DISTINCT bundle_product_id AS id FROM bundle_components WHERE component_product_id IN ($in)");
        if ($r2) {
            while ($row = $r2->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
            $r2->free();
        }
        /* بسته‌های با نام تستی (کد عددی خودکار) */
        $r3 = $mysqli->query("SELECT id FROM products WHERE product_kind = 'bundle' AND name IN ('بسته تک‌جزءی','بسته سه‌جزءی','x','x2','b3')");
        if ($r3) {
            while ($row = $r3->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
            $r3->free();
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $in = implode(',', $ids);
        $mysqli->query("DELETE ibi FROM invoice_bundle_items ibi
                        LEFT JOIN invoice_items ii ON ii.id = ibi.invoice_item_id
                        WHERE ibi.bundle_product_id IN ($in) OR ibi.component_product_id IN ($in) OR ii.product_id IN ($in)");
        $mysqli->query("DELETE FROM bundle_components WHERE bundle_product_id IN ($in) OR component_product_id IN ($in)");
        $mysqli->query("DELETE FROM stock_movements WHERE product_id IN ($in)");
        $mysqli->query("DELETE FROM invoice_items WHERE product_id IN ($in)");
        $mysqli->query("DELETE FROM product_units WHERE product_id IN ($in)");
        $mysqli->query("DELETE FROM products WHERE id IN ($in)");
    }
    $mysqli->query("DELETE FROM sales_returns WHERE invoice_id IN (SELECT id FROM invoices WHERE client_token LIKE 'tok-bnd-%')");
    $mysqli->query("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_token LIKE 'tok-bnd-%')");
    $mysqli->query("DELETE FROM invoices WHERE client_token LIKE 'tok-bnd-%'");
    $mysqli->query("DELETE FROM suppliers WHERE company_name LIKE 'T-BND-SUP-%'");
    $mysqli->query("DELETE FROM customers WHERE first_name LIKE 'T-BND-%'");
}

function t_make_product(mysqli $mysqli, string $code, string $name, string $type, $stock, $pp = '10', $sp = '20', $kind = 'standard'): int
{
    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, product_kind, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0, "")'
    );
    $unit = $type === 'service' ? 'خدمت' : 'عدد';
    $desc = '';
    $stmt->bind_param('sssssddd', $code, $name, $type, $kind, $unit, $pp, $sp, $stock);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    if ($type === 'product') {
        $u = $mysqli->prepare(
            'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order)
             VALUES (?, ?, 1, 1, ?, ?, 0)'
        );
        $u->bind_param('isdd', $id, $unit, $pp, $sp);
        $u->execute();
        $u->close();
    }
    return $id;
}

function t_make_customer(mysqli $mysqli): int
{
    $n = 'T-BND-' . uniqid('', true);
    $stmt = $mysqli->prepare('INSERT INTO customers (first_name, last_name, phone) VALUES (?, ?, "0")');
    $stmt->bind_param('ss', $n, $n);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function t_item(int $pid, $qty, string $price = '20', string $disc = '0'): array
{
    return ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price, 'discount' => $disc];
}

function t_invoice_input(string $type, int $partyId, array $items, string $token): array
{
    return [
        'type'           => $type,
        'party_id'       => $partyId,
        'payment_type'   => 'cash',
        'payment_status' => 'paid',
        'invoice_date'   => date('Y-m-d'),
        'discount'       => '0',
        'note'           => '',
        'client_token'   => $token,
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

function t_make_bundle(mysqli $mysqli, string $name, array $components, array $extra = []): array
{
    $in = ['name' => $name, 'components' => $components];
    foreach (['purchase_price', 'sale_price', 'code', 'barcode', 'category_id', 'min_stock', 'description', 'product_id'] as $k) {
        if (array_key_exists($k, $extra)) {
            $in[$k] = $extra[$k];
        }
    }
    return bundleSaveProduct($mysqli, $in);
}

/** اجرای یک Endpoint دلخواه در زیرپروسه (همان الگوی تست‌های موجود پروژه) */
function endpoint_run_child(string $file, array $params, string $method = 'GET'): array
{
    $root = realpath(__DIR__ . '/../../..');
    $code = "<?php\n"
        . "session_start();\n"
        . "if (session_status() === PHP_SESSION_ACTIVE) { \$_SESSION['auth_user'] = 'memar'; }\n"
        . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n"
        . "\$_SERVER['HTTP_ACCEPT'] = 'application/json';\n"
        . "\$p = json_decode((string) file_get_contents(\$argv[1] ?? ''), true);\n"
        . "if (is_array(\$p)) { " . ($method === 'GET' ? '$_GET' : '$_POST') . " = \$p; }\n"
        . "include " . var_export($root . '/assets/php/' . $file, true) . ";\n";
    return endpoint_run($code, $params);
}

function endpoint_run(string $child, array $params): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'tbn_') . '.php';
    file_put_contents($tmp, $child);
    $paramsFile = tempnam(sys_get_temp_dir(), 'tbnp_');
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

/* ============================================================
 * اجرای تست‌ها
 * ========================================================== */

echo "== Setup ==\n";
t_cleanup_test_data($mysqli);

echo "\n== 1) Bundle creation & validation ==\n";
$A = t_make_product($mysqli, 'T-BND-A', 'کالای جزء آ', 'product', 20, 10, 20);
$B = t_make_product($mysqli, 'T-BND-B', 'کالای جزء ب', 'product', 10, 5, 12);
$C = t_make_product($mysqli, 'T-BND-C', 'کالای جزء سی', 'product', 30, 1, 3);
$S = t_make_product($mysqli, 'T-BND-SVC', 'خدمت تستی', 'service', 0, '0', '0');

/* یک جزء */
$r = t_make_bundle($mysqli, 'بسته تک‌جزءی', [['product_id' => $A, 'quantity' => 2]]);
t_check('one-component bundle created', $r['ok'], $r['message'] ?? '');
$b1 = (int) $r['product_id'];
$row = $mysqli->query('SELECT type, product_kind, unit, stock FROM products WHERE id=' . $b1)->fetch_assoc();
t_check('bundle keeps type=product', ($row['type'] ?? '') === 'product');
t_check('bundle has product_kind=bundle', ($row['product_kind'] ?? '') === 'bundle');
t_check('bundle unit = بسته', ($row['unit'] ?? '') === 'بسته');
t_check('bundle physical stock stays 0', (float) $row['stock'] === 0.0, (string) $row['stock']);

/* چند جزء + قیمت محاسبه‌شده */
$r = t_make_bundle($mysqli, 'بسته سه‌جزءی', [
    ['product_id' => $A, 'quantity' => 2],
    ['product_id' => $B, 'quantity' => 1],
    ['product_id' => $C, 'quantity' => 1],
]);
t_check('multiple-component bundle created', $r['ok'], $r['message'] ?? '');
$b2 = (int) $r['product_id'];
$calc = getBundleCalculatedPrices($mysqli, $b2);
t_check('calculated purchase = 26', $calc['purchase'] === '26.00', $calc['purchase']);
t_check('calculated sale = 55', $calc['sale'] === '55.00', $calc['sale']);
$row2 = $mysqli->query('SELECT purchase_price, sale_price FROM products WHERE id=' . $b2)->fetch_assoc();
t_check('stored price = calculated (no override)', (string) $row2['purchase_price'] === '26.00' && (string) $row2['sale_price'] === '55.00');

/* validation */
$r = t_make_bundle($mysqli, 'x', [['product_id' => $A, 'quantity' => 1], ['product_id' => $A, 'quantity' => 2]]);
t_check('duplicate component rejected', !$r['ok'], $r['message'] ?? '');
$r = t_make_bundle($mysqli, 'x', [['product_id' => $A, 'quantity' => 0]]);
t_check('zero quantity rejected', !$r['ok'], $r['message'] ?? '');
$r = t_make_bundle($mysqli, 'x', [['product_id' => $B, 'quantity' => 99]]);
t_check('quantity above stock rejected', !$r['ok'], $r['message'] ?? '');
$r = t_make_bundle($mysqli, 'x', [['product_id' => $S, 'quantity' => 1]]);
t_check('service as component rejected', !$r['ok'], $r['message'] ?? '');
$r = t_make_bundle($mysqli, 'x', [['product_id' => $b1, 'quantity' => 1]]);
t_check('bundle as component rejected', !$r['ok'], $r['message'] ?? '');
$r = t_make_bundle($mysqli, 'x', [['product_id' => $b1, 'quantity' => 1]], ['product_id' => $b1]);
t_check('self-reference rejected', !$r['ok'], $r['message'] ?? '');
t_check('empty component list rejected', !bundleSaveProduct($mysqli, ['name' => 'x', 'components' => []])['ok']);
$r = t_make_bundle($mysqli, 'b3', [['product_id' => $A, 'quantity' => 1]], ['code' => 'T-BND-B']);
t_check('duplicate code rejected', !$r['ok'], $r['message'] ?? '');

echo "\n== 2) Derived availability ==\n";
$mysqli->query('UPDATE products SET stock = 10 WHERE id = ' . $A);
$mysqli->query('UPDATE products SET stock = 5 WHERE id = ' . $B);
t_check('availability = 5 (10/2, 5/1, 30/1)', getBundleAvailability($mysqli, $b2) === '5', getBundleAvailability($mysqli, $b2));
$mysqli->query('UPDATE products SET stock = 0 WHERE id = ' . $A);
t_check('availability = 0 when a component has 0', getBundleAvailability($mysqli, $b2) === '0', getBundleAvailability($mysqli, $b2));
$mysqli->query('UPDATE products SET stock = 20 WHERE id = ' . $A);
$mysqli->query('UPDATE products SET stock = 10 WHERE id = ' . $B);

echo "\n== 3) Sales invoice consumes components ==\n";
$cust = t_make_customer($mysqli);
$inv = createInvoice($mysqli, t_invoice_input('sales_invoice', $cust, [t_item($b2, 3, '55')], 'tok-bnd-' . uniqid('', true)));
t_check('bundle sales invoice created', $inv['ok'], $inv['message'] ?? '');
$iid = (int) $inv['invoice_id'];
$lines = $mysqli->query("SELECT COUNT(*) c FROM invoice_items WHERE invoice_id = $iid")->fetch_assoc()['c'];
t_check('one invoice line for bundle', (int) $lines === 1, "lines=$lines");
t_check('component A decreased 20->14', t_stock($mysqli, $A) === '14.00', t_stock($mysqli, $A));
t_check('component B decreased 10->7', t_stock($mysqli, $B) === '7.00', t_stock($mysqli, $B));
$snap = $mysqli->query("SELECT COUNT(*) c FROM invoice_bundle_items ibi JOIN invoice_items ii ON ii.id=ibi.invoice_item_id WHERE ii.invoice_id=$iid")->fetch_assoc()['c'];
t_check('historical snapshot rows = 3', (int) $snap === 3, "snap=$snap");
$sm = $mysqli->query("SELECT COUNT(*) c FROM stock_movements WHERE invoice_id=$iid AND product_id IN ($A,$B)")->fetch_assoc()['c'];
t_check('stock movements created for components', (int) $sm === 2, "movements=$sm");
t_check('derived availability = 7 after sale', getBundleAvailability($mysqli, $b2) === '7', getBundleAvailability($mysqli, $b2));

echo "\n== 4) Insufficient stock => full rollback ==\n";
$A0 = t_stock($mysqli, $A);
$B0 = t_stock($mysqli, $B);
$bad = createInvoice($mysqli, t_invoice_input('sales_invoice', $cust, [t_item($b2, 100, '55')], 'tok-bnd-' . uniqid('', true)));
t_check('oversell rejected', !$bad['ok'], $bad['message'] ?? '');
t_check('no partial stock change (A)', t_stock($mysqli, $A) === $A0, t_stock($mysqli, $A) . ' / ' . $A0);
t_check('no partial stock change (B)', t_stock($mysqli, $B) === $B0, t_stock($mysqli, $B));
$extraInvoices = $mysqli->query("SELECT COUNT(*) c FROM invoices WHERE client_token LIKE 'tok-bnd-%' AND id <> $iid")->fetch_assoc()['c'];
t_check('no partial invoice persisted', (int) $extraInvoices === 0, "count=$extraInvoices");

echo "\n== 5) Invoice edit — delta based ==\n";
$inv2 = editInvoice($mysqli, $iid, t_invoice_input('sales_invoice', $cust, [t_item($b2, 5, '55')], ''));
t_check('edit 3->5 accepted', $inv2['ok'], $inv2['message'] ?? '');
t_check('delta consumed: A 14->10', t_stock($mysqli, $A) === '10.00', t_stock($mysqli, $A));
t_check('delta consumed: B 7->5', t_stock($mysqli, $B) === '5.00', t_stock($mysqli, $B));
$snap2 = $mysqli->query("SELECT COUNT(*) c FROM invoice_bundle_items ibi JOIN invoice_items ii ON ii.id=ibi.invoice_item_id WHERE ii.invoice_id=$iid")->fetch_assoc()['c'];
t_check('snapshot preserved after edit', (int) $snap2 === 3, "snap2=$snap2");

echo "\n== 6) Historical snapshot on delete (master changed) ==\n";
$r = t_make_bundle($mysqli, 'بسته سه‌جزءی', [
    ['product_id' => $A, 'quantity' => 1],
    ['product_id' => $B, 'quantity' => 3],
    ['product_id' => $C, 'quantity' => 1],
], ['product_id' => $b2]);
t_check('master composition changed', $r['ok'], $r['message'] ?? '');
t_check('new availability = 1 (B limit: 5/3)', getBundleAvailability($mysqli, $b2) === '1', getBundleAvailability($mysqli, $b2));
$d = deleteInvoice($mysqli, $iid);
t_check('delete accepted', $d['ok'], $d['message'] ?? '');
/* فاکتور ۵ بسته با snapshot قدیمی (A2,B1,C1) بود → A+10، B+5 */
t_check('delete restores A via old snapshot (10->20)', t_stock($mysqli, $A) === '20.00', t_stock($mysqli, $A));
t_check('delete restores B via old snapshot (5->10)', t_stock($mysqli, $B) === '10.00', t_stock($mysqli, $B));

echo "\n== 7) Sales proforma + conversion (stored snapshot) ==\n";
$A1 = t_stock($mysqli, $A);
$pf = createInvoice($mysqli, t_invoice_input('sales_proforma', $cust, [t_item($b2, 2, '55')], 'tok-bnd-' . uniqid('', true)));
t_check('bundle proforma created', $pf['ok'], $pf['message'] ?? '');
$pfid = (int) $pf['invoice_id'];
t_check('proforma does not change stock', t_stock($mysqli, $A) === $A1, t_stock($mysqli, $A) . ' / ' . $A1);
$pfsn = $mysqli->query("SELECT COUNT(*) c FROM invoice_bundle_items ibi JOIN invoice_items ii ON ii.id=ibi.invoice_item_id WHERE ii.invoice_id=$pfid")->fetch_assoc()['c'];
t_check('proforma has historical snapshot', (int) $pfsn === 3, "snap=$pfsn");

$cv = convertInvoice($mysqli, $pfid);
t_check('convert accepted', $cv['ok'], $cv['message'] ?? '');
/* snapshot در زمان پیش‌فاکتور = A1,B3,C1 (ترکیب تغییرکرده) → A-2، B-6 */
t_check('convert consumes stored snapshot: A 20->18', t_stock($mysqli, $A) === '18.00', t_stock($mysqli, $A));
t_check('convert consumes stored snapshot: B 10->4', t_stock($mysqli, $B) === '4.00', t_stock($mysqli, $B));

echo "\n== 8) endpoints ==\n";
$res = endpoint_run_child('invoice_products.php', ['search' => 'بسته سه‌جزءی', 'in_stock' => '1']);
$bcode = $mysqli->query('SELECT code FROM products WHERE id=' . $b2)->fetch_assoc()['code'];
$codes = endpoint_codes($res['data'] ?? []);
t_check('sales picker includes bundle', ($res['success'] ?? false) && in_array($bcode, $codes, true));
$bundleData = null;
foreach (($res['data'] ?? []) as $p) {
    if ((int) ($p['id'] ?? 0) === $b2) { $bundleData = $p; break; }
}
t_check('sales picker shows derived bundle stock (1)', $bundleData !== null && (string) $bundleData['stock'] === '1', $bundleData['stock'] ?? 'n/a');

$res = endpoint_run_child('invoice_products.php', ['search' => 'بسته سه‌جزءی', 'exclude_bundles' => '1']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('purchase picker excludes bundles', !in_array($bcode, $codes, true));

$res = endpoint_run_child('bundle_get.php', ['id' => (string) $b2]);
t_check('bundle_get returns components', ($res['success'] ?? false) === true && count($res['data']['components'] ?? []) === 3);

$res = endpoint_run_child('bundle_products.php', ['search' => 'T-BND-A']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('bundle_products finds component candidate', ($res['success'] ?? false) && in_array('T-BND-A', $codes, true));

$res = endpoint_run_child('bundle_products.php', ['search' => 'بسته سه‌جزءی']);
$codes = endpoint_codes($res['data'] ?? []);
t_check('bundle_products excludes bundles', !in_array($bcode, $codes, true));

$res = endpoint_run_child('product_list.php', ['search' => 'بسته سه‌جزءی']);
t_check('product_list renders bundle row with edit-bundle', strpos($res['tableBody'] ?? '', 'edit-bundle') !== false);

/* حفاظت حذف (از طریق product_action.php) */
$res = endpoint_run_child('product_action.php', ['action' => 'delete', 'product_id' => (string) $A], 'POST');
t_check('component product delete blocked', ($res['success'] ?? true) === false, $res['message'] ?? '');
$res = endpoint_run_child('product_action.php', ['action' => 'delete', 'product_id' => (string) $b2], 'POST');
t_check('bundle delete blocked when used in invoices', ($res['success'] ?? true) === false, $res['message'] ?? '');
$FREE = t_make_product($mysqli, 'T-BND-FREE', 'کالای آزاد', 'product', 5, 1, 2);
$res = endpoint_run_child('product_action.php', ['action' => 'delete', 'product_id' => (string) $FREE], 'POST');
t_check('ordinary product delete still works', ($res['success'] ?? false) === true, $res['message'] ?? '');

echo "\n== 9) Sales return restores components via snapshot ==\n";
/* افزایش مصنوعی موجودی B تا امکان فروش دو بسته فراهم شود */
$mysqli->query('UPDATE products SET stock = 10 WHERE id = ' . $B);
$inv3 = createInvoice($mysqli, t_invoice_input('sales_invoice', $cust, [t_item($b2, 2, '55')], 'tok-bnd-' . uniqid('', true)));
t_check('sale for return test accepted', $inv3['ok'], $inv3['message'] ?? '');
$iid3 = (int) $inv3['invoice_id'];
t_check('A=16 after sale', t_stock($mysqli, $A) === '16.00', t_stock($mysqli, $A));
t_check('B=4 after sale', t_stock($mysqli, $B) === '4.00', t_stock($mysqli, $B));
$iiId = (int) $mysqli->query("SELECT id FROM invoice_items WHERE invoice_id=$iid3 LIMIT 1")->fetch_assoc()['id'];

$ret = createSalesReturn($mysqli, [
    'invoice_id'   => $iid3,
    'return_date'  => date('Y-m-d'),
    'note'         => '',
    'items'        => [['invoice_item_id' => $iiId, 'product_id' => $b2, 'quantity' => 1]],
]);
t_check('bundle sales return created', $ret['ok'], $ret['message'] ?? '');
$rid = (int) $ret['return_id'];
t_check('return restores A: 16->17', t_stock($mysqli, $A) === '17.00', t_stock($mysqli, $A));
t_check('return restores B: 4->7', t_stock($mysqli, $B) === '7.00', t_stock($mysqli, $B));

$ret2 = editSalesReturn($mysqli, $rid, [
    'invoice_id'   => $iid3,
    'return_date'  => date('Y-m-d'),
    'note'         => '',
    'items'        => [['invoice_item_id' => $iiId, 'product_id' => $b2, 'quantity' => 2]],
]);
t_check('return edit accepted', $ret2['ok'], $ret2['message'] ?? '');
t_check('return edit delta: A 17->18', t_stock($mysqli, $A) === '18.00', t_stock($mysqli, $A));
t_check('return edit delta: B 7->10', t_stock($mysqli, $B) === '10.00', t_stock($mysqli, $B));

/* تغییر ترکیب اصلی سپس حذف سند برگشت → باید snapshot قبلی معکوس شود */
$r = t_make_bundle($mysqli, 'بسته سه‌جزءی', [
    ['product_id' => $A, 'quantity' => 9],
    ['product_id' => $B, 'quantity' => 1],
    ['product_id' => $C, 'quantity' => 1],
], ['product_id' => $b2]);
t_check('master changed before return delete', $r['ok'], $r['message'] ?? '');
$delRet = deleteSalesReturn($mysqli, $rid);
t_check('return delete accepted', $delRet['ok'], $delRet['message'] ?? '');
t_check('return delete reverses snapshot: A 18->16', t_stock($mysqli, $A) === '16.00', t_stock($mysqli, $A));
t_check('return delete reverses snapshot: B 10->4', t_stock($mysqli, $B) === '4.00', t_stock($mysqli, $B));

echo "\n== 10) Ordinary product regression ==\n";
$r = createInvoice($mysqli, t_invoice_input('sales_invoice', $cust, [t_item($B, 1, '12')], 'tok-bnd-' . uniqid('', true)));
t_check('ordinary sales invoice still works', $r['ok'], $r['message'] ?? '');
$iOrd = (int) $r['invoice_id'];
t_check('ordinary product stock decreased B: 4->3', t_stock($mysqli, $B) === '3.00', t_stock($mysqli, $B));

$supN = 'T-BND-SUP-' . uniqid('', true);
$sstmt = $mysqli->prepare('INSERT INTO suppliers (company_name, first_name, last_name, phone) VALUES (?, ?, ?, ?)');
$sLast = 'تامین باندل';
$sPhone = '0';
$sstmt->bind_param('ssss', $supN, $supN, $sLast, $sPhone);
$sstmt->execute();
$supId = (int) $sstmt->insert_id;
$sstmt->close();
$rp = createInvoice($mysqli, t_invoice_input('purchase_invoice', $supId, [t_item($B, 3, '6')], 'tok-bnd-' . uniqid('', true)));
t_check('ordinary purchase invoice still works', $rp['ok'], $rp['message'] ?? '');
$iPur = (int) $rp['invoice_id'];
t_check('purchase increases stock B: 3->6', t_stock($mysqli, $B) === '6.00', t_stock($mysqli, $B));
$dp = deleteInvoice($mysqli, $iPur);
t_check('purchase invoice delete restores B: 6->3', $dp['ok'] && t_stock($mysqli, $B) === '3.00', t_stock($mysqli, $B));
$ds = deleteInvoice($mysqli, $iOrd);
t_check('sales invoice delete restores B: 3->4', $ds['ok'] && t_stock($mysqli, $B) === '4.00', t_stock($mysqli, $B));

echo "\n== Cleanup ==\n";
t_cleanup_test_data($mysqli);

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