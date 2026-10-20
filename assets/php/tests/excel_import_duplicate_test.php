<?php
/**
 * Excel import — duplicate handling, update mode, and automatic category creation.
 *
 * Integration tests (CLI only). Uses the real memar_accounting database but
 * operates only on test records (codes prefixed "T-" / categories "T-CAT-")
 * and cleans up after itself.
 *
 * Run:
 *   php assets/php/tests/excel_import_duplicate_test.php
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../product_excel_reader.php';
require_once __DIR__ . '/../product_excel_lib.php';

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

/* ---------------- helpers ---------------- */

function t_cleanup(): void
{
    global $mysqli;
    // محصولات و دسته‌بندی‌های تستی
    $mysqli->query("DELETE FROM stock_movements WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-%')");
    $mysqli->query("DELETE FROM invoice_items WHERE product_id IN (SELECT id FROM products WHERE code LIKE 'T-%')");
    $mysqli->query("DELETE FROM products WHERE code LIKE 'T-%'");
    $mysqli->query("DELETE FROM product_categories WHERE name LIKE 'T-CAT-%'");
}

function t_make_product(string $code, string $name, string $barcode = ''): int
{
    global $mysqli;
    $bc = ($barcode === '') ? null : $barcode;
    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, ?, ?, "product", "عدد", 100, 100, 5, 0, "")'
    );
    $stmt->bind_param('sss', $code, $bc, $name);
    $stmt->execute();
    $pid = (int) $stmt->insert_id;
    $stmt->close();

    $uStmt = $mysqli->prepare(
        'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
         VALUES (?, "عدد", 1, 1, 100, 100, ?, 0)'
    );
    $uStmt->bind_param('is', $pid, $bc);
    $uStmt->execute();
    $uStmt->close();
    return $pid;
}

function t_get_product(string $code): ?array
{
    global $mysqli;
    $stmt = $mysqli->prepare('SELECT * FROM products WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function t_count_units(int $productId): int
{
    global $mysqli;
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM product_units WHERE product_id = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $c = 0;
    if ($res && $row = $res->fetch_assoc()) {
        $c = (int) $row['c'];
    }
    $stmt->close();
    return $c;
}

function t_count_category(string $name): int
{
    global $mysqli;
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM product_categories WHERE name = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $res = $stmt->get_result();
    $c = 0;
    if ($res && $row = $res->fetch_assoc()) {
        $c = (int) $row['c'];
    }
    $stmt->close();
    return $c;
}
function t_xml_sheet(array $header, array $rows): string
{
    $hd = '';
    foreach ($header as $h) {
        $hd .= '<Cell><Data ss:Type="String">' . $h . '</Data></Cell>';
    }
    $body = '';
    foreach ($rows as $r) {
        $cells = '';
        foreach ($r as $v) {
            $cells .= '<Cell><Data ss:Type="String">' . (string) $v . '</Data></Cell>';
        }
        $body .= '  <Row>' . $cells . '</Row>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . ' <Worksheet ss:Name="Products"><Table>'
        . '  <Row>' . $hd . '</Row>'
        . $body
        . ' </Table></Worksheet>'
        . '</Workbook>';
}

function t_read_products(array $header, array $rows): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'tst');
    file_put_contents($tmp, "\xEF\xBB\xBF" . t_xml_sheet($header, $rows));
    $sheets = [];
    try {
        $sheets = ProductExcelReader::readFileSheets($tmp);
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
    @unlink($tmp);
    return isset($sheets['Products']) ? $sheets['Products'] : [];
}

function t_preview(array $productRows, string $mode): array
{
    global $mysqli;
    return product_excel_process_file(
        $productRows,
        product_excel_category_map($mysqli),
        product_excel_existing_codes($mysqli),
        2000,
        product_excel_existing_barcodes($mysqli),
        $mode
    );
}

function t_import(array $records, string $mode, bool $defaultUnits = true): array
{
    global $mysqli;
    return product_excel_insert_rows(
        $mysqli,
        $records,
        $defaultUnits,
        product_excel_category_map($mysqli),
        $mode
    );
}

/* ---------------- setup ---------------- */

echo "== Excel Import - cleanup before ==\n";
t_cleanup();

$header = array('کد کالا', 'بارکد', 'نام کالا', 'دسته‌بندی', 'نوع', 'واحد', 'قیمت خرید', 'قیمت فروش', 'موجودی', 'حداقل موجودی', 'توضیحات');

/* ============================================================
 * Test 1 — only new products; missing categories once (skip mode default)
 * ========================================================== */
echo "== Test 1: new products + one missing category (created once) ==\n";
$rows = array(
    array('T-001', '', 'کالای تستی ۱', 'T-CAT-F1', '', '', '1000', '2000', '3', '0', ''),
    array('T-002', '', 'کالای تستی ۲', 'T-CAT-F1', '', '', '2000', '3000', '4', '0', ''),
);
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
t_check('preview: valid=2, new=2', (int) $pre['valid'] === 2 && (int) $pre['new'] === 2, 'valid=' . $pre['valid'] . ' new=' . $pre['new']);
t_check('preview reports 1 missing category', (int) $pre['categories_missing'] === 1, 'missing=' . $pre['categories_missing']);
t_check('preview did NOT create category', t_count_category('T-CAT-F1') === 0);
t_check('preview did NOT insert products', t_get_product('T-001') === null && t_get_product('T-002') === null);
$done = t_import($pre['records'], 'skip_duplicates');
t_check('import inserted 2 products (skip, no dupes)', (int) $done['inserted'] === 2, 'inserted=' . $done['inserted']);
t_check('import created 1 category', (int) $done['categories_created'] === 1, 'created=' . $done['categories_created']);
t_check('category row exists once', t_count_category('T-CAT-F1') === 1);
$p1 = t_get_product('T-001');
$p2 = t_get_product('T-002');
$catF1Id = 0;
$catName1 = 'T-CAT-F1';
$stmt = $mysqli->prepare('SELECT id FROM product_categories WHERE name = ? LIMIT 1');
$stmt->bind_param('s', $catName1);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row0 = $res->fetch_assoc()) { $catF1Id = (int) $row0['id']; }
$stmt->close();
t_check('both products linked to the same category', $p1 !== null && $p2 !== null
    && $catF1Id > 0 && (int) $p1['category_id'] === (int) $catF1Id
    && (int) $p2['category_id'] === (int) $catF1Id);
t_check('base units created for new products', $p1 !== null && t_count_units((int) $p1['id']) === 1 && t_count_units((int) $p2['id']) === 1);
t_check('stock written directly (products.stock preserved)', $p1 !== null && (string) $p1['stock'] === '3.00');
/* ============================================================
 * Test 2 — existing code: skip leaves unchanged, update updates
 * ========================================================== */
echo "== Test 2: existing code (skip vs update) ==\n";
$pid2 = t_make_product('T-101', 'نام اولیه', '');
$rows = array(array('T-101', '', 'نام به‌روزرسانی', '', '', '', '', '250', '', '', ''));
$sheet = t_read_products($header, $rows);
$preSkip = t_preview($sheet, 'skip_duplicates');
t_check('skip: status=duplicate', $preSkip['records'][0]['status'] === 'duplicate');
$doneSkip = t_import($preSkip['records'], 'skip_duplicates');
t_check('skip: inserted=0, skipped=1', (int) $doneSkip['inserted'] === 0 && (int) $doneSkip['skipped'] === 1, 'i=' . $doneSkip['inserted'] . ' s=' . $doneSkip['skipped']);
$pSkip = t_get_product('T-101');
t_check('skip: product unchanged (name)', $pSkip !== null && $pSkip['name'] === 'نام اولیه', 'name=' . ($pSkip['name'] ?? ''));
t_check('skip: sale_price remains 100', $pSkip !== null && (string) $pSkip['sale_price'] === '100.00', 'price=' . ($pSkip['sale_price'] ?? ''));

$preUpd = t_preview($sheet, 'update_existing');
t_check('update: status=update', $preUpd['records'][0]['status'] === 'update');
t_check('update: new=0, updates=1', (int) $preUpd['new'] === 0 && (int) $preUpd['updates'] === 1);
$doneUpd = t_import($preUpd['records'], 'update_existing');
t_check('update: updated=1, inserted=0', (int) $doneUpd['updated'] === 1 && (int) $doneUpd['inserted'] === 0, 'u=' . $doneUpd['updated']);
$pUpd = t_get_product('T-101');
t_check('update: product updated (name+price), no duplicate', $pUpd !== null && $pUpd['name'] === 'نام به‌روزرسانی' && (string) $pUpd['sale_price'] === '250.00', 'name=' . ($pUpd['name'] ?? '') . ' price=' . ($pUpd['sale_price'] ?? ''));

/* ============================================================
 * Test 3 — existing barcode: no duplicate barcode, no silent move
 * ========================================================== */
echo "== Test 3: existing barcode ==\n";
$pid3 = t_make_product('T-102', 'کالای بارکدی', 'BAR-102');
$rows = array(array('T-103', 'BAR-102', 'کالای بدون مجوز', '', '', '', '', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
t_check('skip: row maps to existing barcode owner => duplicate', $pre['records'][0]['status'] === 'duplicate');
$done = t_import($pre['records'], 'skip_duplicates');
t_check('no new product T-103 created', t_get_product('T-103') === null);
$pX = t_get_product('T-102');
t_check('barcode owner unchanged in skip', $pX !== null && (string) $pX['barcode'] === 'BAR-102');

$preU = t_preview($sheet, 'update_existing');
t_check('update: row maps to barcode owner product', $preU['records'][0]['status'] === 'update'
    && (int) $preU['records'][0]['data']['product_id'] === (int) $pid3);
$doneU = t_import($preU['records'], 'update_existing');
$pY = t_get_product('T-102');
t_check('update: barcode not moved/duplicated (stays on owner, no T-103)', t_get_product('T-103') === null
    && $pY !== null && (string) $pY['barcode'] === 'BAR-102');
t_check('update: owner updated (name)', $pY !== null && $pY['name'] === 'کالای بدون مجوز', 'name=' . ($pY['name'] ?? ''));
/* ============================================================
 * Test 4 — same code and barcode identify the same product
 * ========================================================== */
echo "== Test 4: same code+barcode ==\n";
$pid4 = t_make_product('T-104', 'قبل', 'BAR-104');
$rows = array(array('T-104', 'BAR-104', 'بعد', '', '', '', '500', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$preS = t_preview($sheet, 'skip_duplicates');
t_check('skip: duplicate', $preS['records'][0]['status'] === 'duplicate');
$doneS = t_import($preS['records'], 'skip_duplicates');
$pS = t_get_product('T-104');
t_check('skip: unchanged', $pS !== null && $pS['name'] === 'قبل');
$preU = t_preview($sheet, 'update_existing');
t_check('update: update', $preU['records'][0]['status'] === 'update');
$doneU = t_import($preU['records'], 'update_existing');
$pU = t_get_product('T-104');
t_check('update: updated once, no duplicate', (int) $doneU['updated'] === 1 && $pU !== null && $pU['name'] === 'بعد'
    && (string) $pU['barcode'] === 'BAR-104');

/* ============================================================
 * Test 5 — code identifies one product, barcode another => conflict
 * ========================================================== */
echo "== Test 5: conflict (code↔A, barcode↔B) ==\n";
$pidA = t_make_product('T-105', 'کالای A', '');
$pidB = t_make_product('T-106', 'کالای B', 'BAR-106');
$rows = array(array('T-105', 'BAR-106', 'تضاد', '', '', '', '', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'update_existing');
t_check('conflict status detected', $pre['records'][0]['status'] === 'conflict');
t_check('conflict counted', (int) $pre['conflicts'] === 1 && (int) $pre['valid'] === 0);
$done = t_import($pre['records'], 'update_existing');
t_check('conflict: nothing merged', (int) $done['conflicts'] === 1 && (int) $done['rejected'] === 1
    && (int) $done['updated'] === 0 && (int) $done['inserted'] === 0);
$pA = t_get_product('T-105');
$pB = t_get_product('T-106');
t_check('both products unchanged after conflict', $pA !== null && $pB !== null
    && $pA['name'] === 'کالای A' && $pB['name'] === 'کالای B'
    && (string) $pB['barcode'] === 'BAR-106');
t_check('no product created from conflict row', t_get_product('تضاد') === null);

/* ============================================================
 * Test 6 — missing category: preview reports w/o creating; import creates
 * ========================================================== */
echo "== Test 6: missing category lifecycle ==\n";
$rows = array(array('T-107', '', 'کالای دسته‌ای', 'T-CAT-F6', '', '', '', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
t_check('preview: row ok (not error)', $pre['records'][0]['status'] === 'ok', 'status=' . $pre['records'][0]['status']);
t_check('preview: missing category reported', (int) $pre['categories_missing'] === 1);
t_check('preview: category NOT created', t_count_category('T-CAT-F6') === 0);
$done = t_import($pre['records'], 'skip_duplicates');
t_check('import: category created + product inserted', (int) $done['categories_created'] === 1 && (int) $done['inserted'] === 1);
t_check('import: category exists once', t_count_category('T-CAT-F6') === 1);
$pF6 = t_get_product('T-107');
$catF6 = 0;
$catName6 = 'T-CAT-F6';
$stmt = $mysqli->prepare('SELECT id FROM product_categories WHERE name = ? LIMIT 1');
$stmt->bind_param('s', $catName6);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row0 = $res->fetch_assoc()) { $catF6 = (int) $row0['id']; }
$stmt->close();
t_check('import: product assigned to created category', $pF6 !== null && (int) $pF6['category_id'] === (int) $catF6);
/* ============================================================
 * Test 7 — many rows, one missing category => created once
 * ========================================================== */
echo "== Test 7: repeated missing category created once ==\n";
$rows = array(
    array('T-108', '', 'کالای ۱۰۸', 'T-CAT-F7', '', '', '', '', '', '', ''),
    array('T-109', '', 'کالای ۱۰۹', 'T-CAT-F7', '', '', '', '', '', '', ''),
);
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
$done = t_import($pre['records'], 'skip_duplicates');
t_check('only 1 category record created', t_count_category('T-CAT-F7') === 1 && (int) $done['categories_created'] === 1);
$p108 = t_get_product('T-108');
$p109 = t_get_product('T-109');
$catF7 = 0;
$catName7 = 'T-CAT-F7';
$stmt = $mysqli->prepare('SELECT id FROM product_categories WHERE name = ? LIMIT 1');
$stmt->bind_param('s', $catName7);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row0 = $res->fetch_assoc()) { $catF7 = (int) $row0['id']; }
$stmt->close();
t_check('both products use the same category id', $p108 !== null && $p109 !== null
    && (int) $p108['category_id'] === (int) $catF7 && (int) $p109['category_id'] === (int) $catF7);

/* ============================================================
 * Test 8 — skipped duplicate referencing a missing category
 * ========================================================== */
echo "== Test 8: skipped duplicate must not create category ==\n";
$pid8 = t_make_product('T-110', 'بدون تغییر', '');
$rows = array(array('T-110', '', 'نباید تغییر کند', 'T-CAT-F8', '', '', '', '999', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
t_check('skip: row is duplicate', $pre['records'][0]['status'] === 'duplicate');
$done = t_import($pre['records'], 'skip_duplicates');
t_check('skip: category NOT created for skipped row', t_count_category('T-CAT-F8') === 0 && (int) $done['categories_created'] === 0);
$p8 = t_get_product('T-110');
t_check('skip: product still unchanged', $p8 !== null && $p8['name'] === 'بدون تغییر' && (string) $p8['sale_price'] === '100.00');

echo "== Cleanup between phases ==\n";
t_cleanup();
/* ============================================================
 * Test 9 — updated product references missing category
 * ========================================================== */
echo "== Test 9: updated product references missing category ==\n";
$pid9 = t_make_product('T-111', 'نام قدیمی', '');
$rows = array(array('T-111', '', 'نام جدید', 'T-CAT-F9', '', '', '', '700', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'update_existing');
t_check('update: row status=update with product_id', $pre['records'][0]['status'] === 'update'
    && (int) $pre['records'][0]['data']['product_id'] === (int) $pid9);
$done = t_import($pre['records'], 'update_existing');
t_check('update: category created + product updated', (int) $done['categories_created'] === 1 && (int) $done['updated'] === 1);
t_check('update: category row exists once', t_count_category('T-CAT-F9') === 1);
$p9 = t_get_product('T-111');
$catF9 = 0;
$catName9 = 'T-CAT-F9';
$stmt = $mysqli->prepare('SELECT id FROM product_categories WHERE name = ? LIMIT 1');
$stmt->bind_param('s', $catName9);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row0 = $res->fetch_assoc()) { $catF9 = (int) $row0['id']; }
$stmt->close();
t_check('updated product assigned to created category + name', $p9 !== null && $p9['name'] === 'نام جدید'
    && (int) $p9['category_id'] === (int) $catF9 && (string) $p9['sale_price'] === '700.00');

/* ============================================================
 * Test 10 — failure during import rolls back products AND categories
 * ========================================================== */
echo "== Test 10: transaction rollback (category + product) ==\n";
// کالای از قبل موجود برای موجب شدن تخلف کلید یکتا هنگام درج ردیف دوم
$pid10 = t_make_product('T-ROWDUP', 'کالای مانع', '');
$rec1 = array(
    'row' => 1, 'status' => 'ok', 'errors' => array(), 'data' => array(
        'code' => 'T-RB1', 'barcode' => null, 'name' => 'کالای رولبک', 'category_id' => null,
        'category_name' => 'T-CAT-RB', 'type' => 'product', 'unit' => 'عدد',
        'purchase_price' => '1', 'sale_price' => '1', 'stock' => '1', 'min_stock' => '0',
        'description' => '', 'product_id' => null, 'import_action' => 'insert',
    ),
);
$rec2 = array(
    'row' => 2, 'status' => 'ok', 'errors' => array(), 'data' => array(
        'code' => 'T-ROWDUP', 'barcode' => null, 'name' => 'تکرار واقعی', 'category_id' => null,
        'category_name' => 'T-CAT-RB', 'type' => 'product', 'unit' => 'عدد',
        'purchase_price' => '2', 'sale_price' => '2', 'stock' => '2', 'min_stock' => '0',
        'description' => '', 'product_id' => null, 'import_action' => 'insert',
    ),
);
$rolledBack = false;
$rollbackMsg = '';
try {
    product_excel_insert_rows($mysqli, array($rec1, $rec2), true, product_excel_category_map($mysqli), 'skip_duplicates');
} catch (Exception $e) {
    $rolledBack = true;
    $rollbackMsg = $e->getMessage();
}
t_check('import threw exception on unique violation', $rolledBack, $rollbackMsg);
t_check('rollback: no partial product T-RB1', t_get_product('T-RB1') === null);
t_check('rollback: category T-CAT-RB NOT created', t_count_category('T-CAT-RB') === 0);
$pBlock = t_get_product('T-ROWDUP');
t_check('rollback: blocker product untouched', $pBlock !== null && $pBlock['name'] === 'کالای مانع');
/* ============================================================
 * Test 11 — ProductUnits sheet behavior preserved
 * ========================================================== */
echo "== Test 11: ProductUnits sheet ==\n";
$prodXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
    . ' <Worksheet ss:Name="Products"><Table>'
    . '  <Row><Cell><Data ss:Type="String">کد کالا</Data></Cell><Cell><Data ss:Type="String">نام کالا</Data></Cell><Cell><Data ss:Type="String">واحد</Data></Cell></Row>'
    . '  <Row><Cell><Data ss:Type="String">T-XL3</Data></Cell><Cell><Data ss:Type="String">کالای واحددار</Data></Cell><Cell><Data ss:Type="String">عدد</Data></Cell></Row>'
    . ' </Table></Worksheet>'
    . ' <Worksheet ss:Name="ProductUnits"><Table>'
    . '  <Row><Cell><Data ss:Type="String">کد کالا</Data></Cell><Cell><Data ss:Type="String">نام واحد</Data></Cell><Cell><Data ss:Type="String">ضریب تبدیل</Data></Cell><Cell><Data ss:Type="String">قیمت فروش واحد</Data></Cell><Cell><Data ss:Type="String">واحد پایه</Data></Cell></Row>'
    . '  <Row><Cell><Data ss:Type="String">T-XL3</Data></Cell><Cell><Data ss:Type="String">عدد</Data></Cell><Cell><Data ss:Type="Number">1</Data></Cell><Cell><Data ss:Type="Number">2000</Data></Cell><Cell><Data ss:Type="String">بله</Data></Cell></Row>'
    . '  <Row><Cell><Data ss:Type="String">T-XL3</Data></Cell><Cell><Data ss:Type="String">کارتن 36</Data></Cell><Cell><Data ss:Type="Number">36</Data></Cell><Cell><Data ss:Type="Number">70000</Data></Cell><Cell><Data ss:Type="String">نخیر</Data></Cell></Row>'
    . ' </Table></Worksheet>'
    . '</Workbook>';
$tmp11 = tempnam(sys_get_temp_dir(), 'tst11');
file_put_contents($tmp11, "\xEF\xBB\xBF" . $prodXml);
$sheets = ProductExcelReader::readFileSheets($tmp11);
@unlink($tmp11);
$prodRows = $sheets['Products'];
$unitRows = $sheets['ProductUnits'];
$pre = t_preview($prodRows, 'skip_duplicates');
$validCodes = [];
foreach ($pre['records'] as $rec) {
    if ($rec['status'] === 'ok') {
        $validCodes[product_excel_canonical($rec['data']['code'])] = true;
    }
}
$uRes = product_excel_validate_unit_rows($unitRows, $validCodes);
t_check('units sheet validated (2 rows, base present)', count($uRes['records']) === 2 && $uRes['has_base_units'] === true, 'n=' . count($uRes['records']));
$done = t_import($pre['records'], 'skip_duplicates', false);
t_check('product inserted without default units (createDefaultUnits=false)', (int) $done['inserted'] === 1);
$unitDone = product_excel_insert_units($mysqli, $uRes['records'], $done['id_map']);
t_check('units insertion reports 2 inserted', (int) $unitDone['inserted'] === 2, 'ins=' . $unitDone['inserted']);
$pXL = t_get_product('T-XL3');
t_check('units inserted for new product = 2', $pXL !== null && t_count_units((int) $pXL['id']) === 2, 'units=' . ($pXL ? t_count_units((int) $pXL['id']) : -1));
$unitBase = 0;
$pXLId = (int) ($pXL['id'] ?? 0);
$stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM product_units WHERE product_id = ? AND is_base = 1');
$stmt->bind_param('i', $pXLId);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $row0 = $res->fetch_assoc()) { $unitBase = (int) $row0['c']; }
$stmt->close();
t_check('exactly one base unit for new product', $unitBase === 1, 'base=' . $unitBase);
// update mode + units sheet: existing product must NOT get duplicate units
$pid11 = t_make_product('T-112', 'قبل واحد', '');
$countBefore = t_count_units((int) $pid11);
$rows = array(array('T-112', '', 'بعد واحد', '', '', '', '', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$preU = t_preview($sheet, 'update_existing');
$uCodes = [product_excel_canonical('T-112') => true];
$uRes2 = product_excel_validate_unit_rows($unitRows, $uCodes);
$doneU = t_import($preU['records'], 'update_existing', false);
t_check('update: product updated in units-file scenario', (int) $doneU['updated'] === 1);
// واحدهای برگه واحدها برای کالای موجود درج نمی‌شوند (حفظ رفتار ProductUnits فعلی)
$countAfter = t_count_units((int) $pid11);
t_check('update: no duplicate base units created for existing product', (int) $countAfter === (int) $countBefore, 'before=' . $countBefore . ' after=' . $countAfter);

/* ============================================================
 * Test 12 — in-file duplicate + re-check at final import + regression
 * ========================================================== */
echo "== Test 12: in-file dup, re-check, regression ==\n";
// الف) درون فایل تکراری همچنان خطا
$rows = array(
    array('T-201', '', 'اولین', '', '', '', '', '', '', '', ''),
    array('T-201', '', 'تکراری درون فایل', '', '', '', '', '', '', '', ''),
);
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
$sts = [];
foreach ($pre['records'] as $rec) { $sts[] = $rec['status']; }
t_check('in-file duplicate code => error on second row', in_array('ok', $sts, true) && in_array('error', $sts, true), 'sts=' . implode(',', $sts));
t_check('in-file duplicate counted as invalid', (int) $pre['invalid'] === 1);

// ب) بررسی مجدد در ثبت نهایی: کالایی که بین پیش‌نمایش و ثبت ایجاد می‌شود
$rows = array(array('T-202', '', 'کالای رقابتی', '', '', '', '', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre1 = t_preview($sheet, 'skip_duplicates');
t_check('re-check: قبل از ساخت، وضعیت ok است', $pre1['records'][0]['status'] === 'ok');
t_make_product('T-202', 'دستی', '');   // در فاصله، شخص دیگری کالا را می‌سازد
$catMap = product_excel_category_map($mysqli);
$codes = product_excel_existing_codes($mysqli);
$barcodes = product_excel_existing_barcodes($mysqli);
$reCheck = product_excel_process_file($sheet, $catMap, $codes, 2000, $barcodes, 'skip_duplicates', false);
t_check('re-check: پس از ساخت، وضعیت duplicate می‌شود (ثبت نمی‌شود)', $reCheck['records'][0]['status'] === 'duplicate');
$doneRC = t_import($reCheck['records'], 'skip_duplicates');
t_check('re-check: inserted=0 (duplicate skipped)', (int) $doneRC['inserted'] === 0 && (int) $doneRC['skipped'] === 1);

// ج) رگرسیون: حالت پیش‌فرض/نامعتبر به skip می‌رود
t_check('normalize: خالی => skip_duplicates', product_excel_normalize_import_mode('') === 'skip_duplicates');
t_check('normalize: نامعتبر => skip_duplicates', product_excel_normalize_import_mode('weird') === 'skip_duplicates');
t_check('normalize: update_existing حفظ می‌شود', product_excel_normalize_import_mode('update_existing') === 'update_existing');

// د) رگرسیون: قیمت نامعتبر همچنان خطا
$rows = array(array('T-203', '', 'قیمت خراب', '', '', '', 'abc', '', '', '', ''));
$sheet = t_read_products($header, $rows);
$pre = t_preview($sheet, 'skip_duplicates');
t_check('regression: invalid price => error', $pre['records'][0]['status'] === 'error');

/* ---------------- cleanup ---------------- */
echo "== Cleanup ==\n";
t_cleanup();

echo "\n==============================\n";
echo "PASS: $pass  FAIL: $fail\n";
if ($failures) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
}
exit($fail === 0 ? 0 : 1);