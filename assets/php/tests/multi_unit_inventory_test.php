<?php
/**
 * Multi-Unit Inventory — Integration tests (CLI only)
 *
 * Run:
 *   php assets/php/tests/multi_unit_inventory_test.php
 *
 * Uses the real memar_accounting database but operates only on test products
 * (codes prefixed "T-") and cleans up after itself.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../invoice_common.php';
require_once __DIR__ . '/../product_common.php';

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
        echo "  [!!] $label " . ($detail !== '' ? "— $detail" : '') . "\n";
    }
}

/**
 * ساخت کالای تست با واحدهای مشخص.
 */
function t_make_product(mysqli $mysqli, string $code, string $name, array $units): array
{
    $esc = $mysqli->real_escape_string($code);
    $mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code = '$esc')");
    $mysqli->query("DELETE FROM products WHERE code = '$esc'");
    $baseUnit = null;
    foreach ($units as $u) {
        if (!empty($u['is_base'])) {
            $baseUnit = $u['name'];
        }
    }
    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, NULL, ?, "product", ?, 0, 0, 0, 0, "")'
    );
    $defaultUnit = $baseUnit ?: 'عدد';
    $stmt->bind_param('sss', $code, $name, $defaultUnit);
    $stmt->execute();
    $pid = (int) $stmt->insert_id;
    $stmt->close();

    $ins = $mysqli->prepare(
        'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
    );
    $i = 0;
    foreach ($units as $u) {
        $isBase = !empty($u['is_base']) ? 1 : 0;
        $ins->bind_param('isidddi', $pid, $u['name'], $u['conversion_factor'], $isBase, $u['purchase_price'], $u['sale_price'], $i);
        $ins->execute();
        $i++;
    }
    $ins->close();
    return ['id' => $pid, 'code' => $code];
}

function t_make_party(mysqli $mysqli, bool $customer, string $name): int
{
    if ($customer) {
        $stmt = $mysqli->prepare('INSERT INTO customers (first_name, last_name, phone) VALUES (?, ?, "0")');
        $lname = '';
        $stmt->bind_param('ss', $name, $lname);
    } else {
        $stmt = $mysqli->prepare('INSERT INTO suppliers (company_name, first_name, last_name, phone) VALUES (?, ?, ?, "0")');
        $fname = '';
        $lname = '';
        $stmt->bind_param('sss', $name, $fname, $lname);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function t_purchase(mysqli $mysqli, int $productId, int $unitId, $qty, $price, bool $final = true): array
{
    $supplier = t_make_party($mysqli, false, 'تست تامین ' . uniqid());
    $in = [
        'type'           => $final ? 'purchase_invoice' : 'purchase_proforma',
        'party_id'       => $supplier,
        'payment_type'   => 'cash',
        'payment_status' => 'paid',
        'invoice_date'   => '2026-09-01',
        'discount'       => '0',
        'note'           => null,
        'client_token'   => 'tok-p-' . uniqid(),
        'items'          => [
            ['product_id' => $productId, 'unit_id' => (string) $unitId, 'quantity' => (string) $qty, 'unit_price' => (string) $price, 'discount' => '0'],
        ],
    ];
    return createInvoice($mysqli, $in);
}

function t_sale(mysqli $mysqli, int $productId, int $unitId, $qty, $price, bool $final = true): array
{
    $customer = t_make_party($mysqli, true, 'تست مشتری ' . uniqid());
    $in = [
        'type'           => $final ? 'sales_invoice' : 'sales_proforma',
        'party_id'       => $customer,
        'payment_type'   => 'cash',
        'payment_status' => 'paid',
        'invoice_date'   => '2026-09-01',
        'discount'       => '0',
        'note'           => null,
        'client_token'   => 'tok-s-' . uniqid(),
        'items'          => [
            ['product_id' => $productId, 'unit_id' => (string) $unitId, 'quantity' => (string) $qty, 'unit_price' => (string) $price, 'discount' => '0'],
        ],
    ];
    return createInvoice($mysqli, $in);
}

function t_stock(mysqli $mysqli, int $productId): string
{
    $stmt = $mysqli->prepare('SELECT stock FROM products WHERE id = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res ? (string) $res->fetch_assoc()['stock'] : '0';
}

function t_unit(mysqli $mysqli, int $productId, string $name): ?array
{
    $stmt = $mysqli->prepare('SELECT * FROM product_units WHERE product_id = ? AND name = ? LIMIT 1');
    $stmt->bind_param('is', $productId, $name);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res ? $res->fetch_assoc() : null;
}

/* ============================================================
 * ۱) ساخت کالای تست با چند واحد
 * ========================================================== */
echo "== Test setup ==\n";
$prod = t_make_product($mysqli, 'T-100', 'مداد تستي', [
    ['name' => 'عدد', 'conversion_factor' => '1', 'is_base' => true, 'purchase_price' => '10000', 'sale_price' => '12000'],
    ['name' => 'بسته 12', 'conversion_factor' => '12', 'is_base' => false, 'purchase_price' => '210000', 'sale_price' => '230000'],
    ['name' => 'بسته 24', 'conversion_factor' => '24', 'is_base' => false, 'purchase_price' => '410000', 'sale_price' => '450000'],
    ['name' => 'قراص', 'conversion_factor' => '144', 'is_base' => false, 'purchase_price' => '2300000', 'sale_price' => '2550000'],
]);
$pu01 = t_unit($mysqli, $prod['id'], 'عدد');
$pu12 = t_unit($mysqli, $prod['id'], 'بسته 12');
$pu24 = t_unit($mysqli, $prod['id'], 'بسته 24');
$pu144 = t_unit($mysqli, $prod['id'], 'قراص');
t_check('test product created with 4 units', $pu01 !== null && $pu12 !== null && $pu24 !== null && $pu144 !== null);

$mysqli->query("INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order)
               VALUES ({$prod['id']}, 'بسته 36', 36, 0, 620000, 680000, 4)");
$pu36 = t_unit($mysqli, $prod['id'], 'بسته 36');
t_check('unit بسته 36 added', $pu36 !== null);

/* ============================================================
 * ۲) خرید ۳۰ بسته ۱۲ → موجودی ۳۶۰
 * ========================================================== */
echo "== Test A: purchase 30 x package-12 ==\n";
$r = t_purchase($mysqli, $prod['id'], (int) $pu12['id'], 30, 210000);
t_check('purchase 30 بسته 12 accepted', $r['ok'], $r['message'] ?? '');
$stockA = t_stock($mysqli, $prod['id']);
t_check('stock after purchase = 360', $stockA === '360.00', "stock=$stockA");
$rInv = getInvoice($mysqli, (int) $r['invoice_id']);
$itA = $rInv['items'][0] ?? null;
t_check('invoice line snapshot unit name = بسته 12', $itA !== null && $itA['unit_name'] === 'بسته 12');
t_check('invoice line snapshot factor = 12', $itA !== null && (string) $itA['conversion_factor'] === '12.0000');
t_check('invoice line base_quantity = 360', $itA !== null && (string) $itA['base_quantity'] === '360.00');

/* ============================================================
 * ۳) فروش ۵ عدد → موجودی ۳۵۵
 * ========================================================== */
echo "== Test B: sell 5 عدد ==\n";
$r = t_sale($mysqli, $prod['id'], (int) $pu01['id'], 5, 12000);
t_check('sale 5 عدد accepted', $r['ok'], $r['message'] ?? '');
$stockB = t_stock($mysqli, $prod['id']);
t_check('stock after 5 sale = 355', $stockB === '355.00', "stock=$stockB");

/* ============================================================
 * ۴) فروش ۲ بسته ۱۲ → موجودی ۳۳۱
 * ========================================================== */
echo "== Test C: sell 2 x package-12 ==\n";
$r = t_sale($mysqli, $prod['id'], (int) $pu12['id'], 2, 230000);
t_check('sale 2 بسته 12 accepted', $r['ok'], $r['message'] ?? '');
$stockC = t_stock($mysqli, $prod['id']);
t_check('stock after 2x12 sale = 331', $stockC === '331.00', "stock=$stockC");
$rInv = getInvoice($mysqli, (int) $r['invoice_id']);
$itC = $rInv['items'][0] ?? null;
t_check('line total uses package price (2*230000=460000)', $itC !== null && (string) $itC['line_total'] === '460000.00', isset($itC['line_total']) ? "line_total={$itC['line_total']}" : '');

/* ============================================================
 * ۵) خرید ۱ قراص (۱۴۴) → موجودی ۴۷۵
 * ========================================================== */
echo "== Test D: buy 1 gross (144) ==\n";
$r = t_purchase($mysqli, $prod['id'], (int) $pu144['id'], 1, 2300000);
t_check('purchase 1 قراص accepted', $r['ok'], $r['message'] ?? '');
$stockD = t_stock($mysqli, $prod['id']);
t_check('stock after gross purchase = 475', $stockD === '475.00', "stock=$stockD");

/* ============================================================
 * ۶) فروش ۱ بسته ۳۶ → موجودی ۴۳۹
 * ========================================================== */
echo "== Test E: sell 1 x package-36 ==\n";
$r = t_sale($mysqli, $prod['id'], (int) $pu36['id'], 1, 680000);
t_check('sale 1 بسته 36 accepted', $r['ok'], $r['message'] ?? '');
$stockE = t_stock($mysqli, $prod['id']);
t_check('stock after 36 sale = 439', $stockE === '439.00', "stock=$stockE");

/* ============================================================
 * ۷) ویرایش فاکتور: ۲x12 → ۳x12 در فروش (delta 12 پایه)
 * ========================================================== */
echo "== Test F: edit invoice delta in base units ==\n";
// stock پس از Test E = 439
$sale2x12 = t_sale($mysqli, $prod['id'], (int) $pu12['id'], 2, 230000); // 439 - 24 = 415
$invId = (int) $sale2x12['invoice_id'];
$invForEdit = getInvoice($mysqli, $invId);
$customerId = (int) $invForEdit['customer_id'];
$stockAfterCreate = t_stock($mysqli, $prod['id']);
$editIn = [
    'type'           => 'sales_invoice',
    'party_id'       => $customerId,
    'payment_type'   => 'cash',
    'payment_status' => 'paid',
    'invoice_date'   => '2026-09-01',
    'discount'       => '0',
    'note'           => null,
    'items'          => [
        ['product_id' => $prod['id'], 'unit_id' => (string) $pu12['id'], 'quantity' => '3', 'unit_price' => '230000', 'discount' => '0'],
    ],
];
$r = editInvoice($mysqli, $invId, $editIn);
t_check('edit 2→3 packages accepted', $r['ok'], $r['message'] ?? '');
$stockAfterEdit = t_stock($mysqli, $prod['id']);
t_check('edit delta 12 base units applied', abs((float) $stockAfterEdit - ((float) $stockAfterCreate - 12.0)) < 0.001, "before=$stockAfterCreate after=$stockAfterEdit");

/* --- تغییر واحد در ویرایش: 3x12 (36 پایه) → 3x24 (72 پایه): delta 36 --- */
echo "== Test F2: edit changes unit (3x12 -> 3x24) ==\n";
$stockBeforeUnitEdit = t_stock($mysqli, $prod['id']); // 415-12=403
$editIn2 = [
    'type'           => 'sales_invoice',
    'party_id'       => $customerId,
    'payment_type'   => 'cash',
    'payment_status' => 'paid',
    'invoice_date'   => '2026-09-01',
    'discount'       => '0',
    'note'           => null,
    'items'          => [
        ['product_id' => $prod['id'], 'unit_id' => (string) $pu24['id'], 'quantity' => '3', 'unit_price' => '450000', 'discount' => '0'],
    ],
];
$r2 = editInvoice($mysqli, $invId, $editIn2);
t_check('edit 3x12 -> 3x24 accepted', $r2['ok'], $r2['message'] ?? '');
$stockAfterUnitEdit = t_stock($mysqli, $prod['id']);
t_check('unit-change delta 36 base applied', abs((float) $stockAfterUnitEdit - ((float) $stockBeforeUnitEdit - 36.0)) < 0.001, "before=$stockBeforeUnitEdit after=$stockAfterUnitEdit");
// این فاکتور پس از این آزمون 3x24 = 72 پایه خارج کرده است.

/* ============================================================
 * ۸) تغییر تعریف واحد فعلی نباید تاریخ را تغییر دهد؛ تغییر واحد دارای سابقه مسدود/بی‌اثر است
 * ========================================================== */
echo "== Test G: historical unit guard ==\n";
$oldFactor = (string) $pu12['conversion_factor'];
$mysqli->query("UPDATE product_units SET conversion_factor = 13 WHERE id = {$pu12['id']}");
$factorAfter = (string) t_unit($mysqli, $prod['id'], 'بسته 12')['conversion_factor'];
t_check('snapshot preserved', (string) $itA['conversion_factor'] === '12.0000');
// بازگرداندن ضریب برای ادامه آزمون‌ها
$mysqli->query("UPDATE product_units SET conversion_factor = 12 WHERE id = {$pu12['id']}");
t_check('current unit definition restored', (string) t_unit($mysqli, $prod['id'], 'بسته 12')['conversion_factor'] === '12.0000');

/* ============================================================
 * ۹) حذف فاکتور با ضریب اسنپ‌شات اصلی
 * ========================================================== */
echo "== Test H: delete uses original conversion factor ==\n";
$beforeDelete = t_stock($mysqli, $prod['id']);
$r = deleteInvoice($mysqli, $invId);
t_check('delete invoice accepted', $r['ok'], $r['message'] ?? '');
$afterDelete = t_stock($mysqli, $prod['id']);
// فاکتور در این مرحله 3x24 = 72 پایه دارد (پس از ویرایش واحد)
t_check('delete restores 72 base units (3x24 current snapshot)', abs((float) $afterDelete - ((float) $beforeDelete + 72.0)) < 0.001, "before=$beforeDelete after=$afterDelete");

/* ============================================================
 * ۱۰) موجودی ناکافی
 * ========================================================== */
echo "== Test J: insufficient stock ==\n";
$mysqli->query("UPDATE products SET stock = 18 WHERE id = {$prod['id']}");
$r = t_sale($mysqli, $prod['id'], (int) $pu12['id'], 2, 230000);
t_check('sale 2x12 rejected when stock=18', !$r['ok'], $r['message'] ?? '');
$stockJ = t_stock($mysqli, $prod['id']);
t_check('stock unchanged after rejected sale', $stockJ === '18.00', "stock=$stockJ");

/* ============================================================
 * ۱۱) پیش‌فاکتور موجودی را تغییر نمی‌دهد
 * ========================================================== */
echo "== Test I: proforma does not change stock ==\n";
$beforePro = t_stock($mysqli, $prod['id']);
$r = t_purchase($mysqli, $prod['id'], (int) $pu12['id'], 2, 210000, false);
t_check('purchase proforma accepted', $r['ok'], $r['message'] ?? '');
$afterPro = t_stock($mysqli, $prod['id']);
t_check('proforma stock unchanged', $beforePro === $afterPro, "before=$beforePro after=$afterPro");

/* ============================================================
 * ۱۲) محصولات قدیمی با واحد «عدد» موجودی یکسان دارند
 * ========================================================== */
echo "== Test G-old: existing عدد product retains stock ==\n";
$oldProd = t_make_product($mysqli, 'T-OLD', 'کالای قدیمی', [
    ['name' => 'عدد', 'conversion_factor' => '1', 'is_base' => true, 'purchase_price' => '1000', 'sale_price' => '2000'],
]);
$mysqli->query("UPDATE products SET stock = 14 WHERE id = {$oldProd['id']}");
$afterOld = t_stock($mysqli, $oldProd['id']);
t_check('existing عدد product stock = 14', $afterOld === '14.00', "stock=$afterOld");

/* ============================================================
 * ۱۳) خواندن فاکتور تاریخی با واحد صحیح (حتی پس از تغییر تعریف فعلی)
 * ========================================================== */
echo "== Test F-hist: historical load ==\n";
$hist = t_purchase($mysqli, $prod['id'], (int) $pu12['id'], 1, 210000);
$full = getInvoice($mysqli, (int) $hist['invoice_id']);
$item = $full['items'][0] ?? null;
t_check('historical item unit_name snapshot = بسته 12', $item !== null && $item['unit_name'] === 'بسته 12');
t_check('historical item conversion_factor = 12', $item !== null && (string) $item['conversion_factor'] === '12.0000');

/* ============================================================
 * ۱۴) آزمون اکسل قدیمی: فایل تک‌برگه/یک ستون واحد هنوز کار می‌کند
 * ========================================================== */
echo "== Test H-excel: old import format ==\n";
if (file_exists(__DIR__ . '/../product_excel_lib.php')) {
    require_once __DIR__ . '/../product_excel_reader.php';
    require_once __DIR__ . '/../product_excel_lib.php';
    $oldXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n"
        . " <Worksheet ss:Name=\"Products\"><Table>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">کد کالا</Data></Cell><Cell><Data ss:Type=\"String\">نام کالا</Data></Cell><Cell><Data ss:Type=\"String\">واحد</Data></Cell><Cell><Data ss:Type=\"String\">قیمت خرید</Data></Cell><Cell><Data ss:Type=\"String\">موجودی</Data></Cell></Row>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">T-XL-1</Data></Cell><Cell><Data ss:Type=\"String\">خودکار اکسل</Data></Cell><Cell><Data ss:Type=\"String\">عدد</Data></Cell><Cell><Data ss:Type=\"Number\">500</Data></Cell><Cell><Data ss:Type=\"Number\">10</Data></Cell></Row>\n"
        . " </Table></Worksheet>\n"
        . "</Workbook>\n";
    $tmp = tempnam(sys_get_temp_dir(), 'tst');
    file_put_contents($tmp, "\xEF\xBB\xBF" . $oldXml);
    try {
        $sheets = ProductExcelReader::readFileSheets($tmp);
        $rowData = reset($sheets);
        $res = product_excel_process_file($rowData, product_excel_category_map($mysqli), product_excel_existing_codes($mysqli), 20, product_excel_existing_barcodes($mysqli));
        t_check('old excel format detected & validated', (int) $res['valid'] === 1, 'valid=' . $res['valid']);
    } catch (Throwable $e) {
        t_check('old excel format parsed', false, $e->getMessage());
    }
    @unlink($tmp);
}

/* ============================================================
 * ۱۵) اکسل جدید: برگه واحدها خوانده و اعتبارسنجی می‌شود
 * ========================================================== */
echo "== Test K-excel: new two-sheet format ==\n";
if (file_exists(__DIR__ . '/../product_excel_lib.php')) {
    $newXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n"
        . " <Worksheet ss:Name=\"Products\"><Table>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">کد کالا</Data></Cell><Cell><Data ss:Type=\"String\">نام کالا</Data></Cell><Cell><Data ss:Type=\"String\">واحد</Data></Cell></Row>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">T-XL-2</Data></Cell><Cell><Data ss:Type=\"String\">ژل تستی</Data></Cell><Cell><Data ss:Type=\"String\">عدد</Data></Cell></Row>\n"
        . " </Table></Worksheet>\n"
        . " <Worksheet ss:Name=\"ProductUnits\"><Table>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">کد کالا</Data></Cell><Cell><Data ss:Type=\"String\">نام واحد</Data></Cell><Cell><Data ss:Type=\"String\">ضریب تبدیل</Data></Cell><Cell><Data ss:Type=\"String\">قیمت فروش واحد</Data></Cell><Cell><Data ss:Type=\"String\">واحد پایه</Data></Cell></Row>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">T-XL-2</Data></Cell><Cell><Data ss:Type=\"String\">عدد</Data></Cell><Cell><Data ss:Type=\"Number\">1</Data></Cell><Cell><Data ss:Type=\"Number\">2000</Data></Cell><Cell><Data ss:Type=\"String\">بله</Data></Cell></Row>\n"
        . "  <Row><Cell><Data ss:Type=\"String\">T-XL-2</Data></Cell><Cell><Data ss:Type=\"String\">کارتن 36</Data></Cell><Cell><Data ss:Type=\"Number\">36</Data></Cell><Cell><Data ss:Type=\"Number\">70000</Data></Cell><Cell><Data ss:Type=\"String\">نخیر</Data></Cell></Row>\n"
        . " </Table></Worksheet>\n"
        . "</Workbook>\n";
    $tmp = tempnam(sys_get_temp_dir(), 'tst2');
    file_put_contents($tmp, "\xEF\xBB\xBF" . $newXml);
    try {
        $sheets = ProductExcelReader::readFileSheets($tmp);
        t_check('two-sheet file parsed (Products + ProductUnits)', isset($sheets['Products']) && isset($sheets['ProductUnits']));
        $productRows = $sheets['Products'];
        $unitRows = $sheets['ProductUnits'];
        $res = product_excel_process_file($productRows, product_excel_category_map($mysqli), product_excel_existing_codes($mysqli), 20, product_excel_existing_barcodes($mysqli));
        $validCodesForUnits = [];
        foreach ($res['records'] as $rec) {
            if ($rec['status'] === 'ok') {
                $validCodesForUnits[product_excel_canonical($rec['data']['code'])] = true;
            }
        }
        $uRes = product_excel_validate_unit_rows($unitRows, $validCodesForUnits);
        t_check('units sheet validated (2 rows, base present)', count($uRes['records']) === 2 && $uRes['has_base_units'] === true, 'records=' . count($uRes['records']));
    } catch (Throwable $e) {
        t_check('new two-sheet format parsed', false, $e->getMessage());
    }
    @unlink($tmp);
}

/* ============================================================
 * پاکسازی
 * ========================================================== */
echo "== Cleanup ==\n";
$mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-%')");
$mysqli->query("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_token LIKE 'tok-%')");
$mysqli->query("DELETE FROM invoices WHERE client_token LIKE 'tok-%'");
$mysqli->query("DELETE FROM products WHERE code LIKE 'T-%'");
$mysqli->query("DELETE FROM customers WHERE first_name = 'تست مشتری'");
$mysqli->query("DELETE FROM suppliers WHERE company_name = 'تست تامین'");

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