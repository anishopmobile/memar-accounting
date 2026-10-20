<?php
/**
 * Defective Goods — integration tests (CLI only)
 *
 * Run:
 *   php assets/php/tests/defective_goods_test.php
 *
 * Uses the real memar_accounting database but operates only on test products
 * (codes prefixed "T-DG-") and cleans up after itself.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

session_start();
$_SESSION['auth_user'] = 'memar';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../defective_goods_common.php';
require_once __DIR__ . '/../report_excel_lib.php';
require_once __DIR__ . '/../jdf.php';

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
        echo "  [!!] $label -- " . ($detail !== '' ? $detail : 'failed') . "\n";
    }
}

function t_cleanup_product(mysqli $mysqli, int $productId, string $code): void
{
    $mysqli->query("DELETE FROM stock_movements WHERE product_id = $productId");
    $mysqli->query("DELETE FROM defective_goods WHERE product_id = $productId");
    $mysqli->query("DELETE FROM product_units WHERE product_id = $productId");
    $mysqli->query("DELETE FROM products WHERE id = $productId");
    $mysqli->query("DELETE FROM products WHERE code = '" . $mysqli->real_escape_string($code) . "' AND id != $productId");
}

function t_make_product(mysqli $mysqli, string $code, string $name, float $stock): int
{
    $esc = $mysqli->real_escape_string($code);
    $old = $mysqli->query("SELECT id FROM products WHERE code = '$esc' LIMIT 1");
    $oldRow = $old ? $old->fetch_assoc() : null;
    if ($oldRow) {
        t_cleanup_product($mysqli, (int) $oldRow['id'], $code);
    }
    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, NULL, ?, "product", "عدد", 0, 0, ?, 0, "")'
    );
    $stockVal = sprintf('%.2F', $stock);
    $stmt->bind_param('sss', $code, $name, $stockVal);
    $stmt->execute();
    $pid = (int) $stmt->insert_id;
    $stmt->close();
    $ins = $mysqli->prepare('INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, sort_order) VALUES (?, "عدد", 1, 1, 0, 0, 0)');
    $ins->bind_param('i', $pid);
    $ins->execute();
    $ins->close();
    return $pid;
}

function t_make_service(mysqli $mysqli, string $code): int
{
    $stmt = $mysqli->prepare(
        'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
         VALUES (?, NULL, "خدمت تستی", "service", "سرویس", 0, 0, 0, 0, "")'
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function t_product_stock(mysqli $mysqli, int $productId): float
{
    $r = $mysqli->query("SELECT stock FROM products WHERE id = $productId");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? (float) $row['stock'] : -1;
}

function t_defective_count_for(mysqli $mysqli, int $productId): int
{
    $r = $mysqli->query("SELECT COUNT(*) AS c FROM defective_goods WHERE product_id = $productId");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? (int) $row['c'] : 0;
}

echo "== Defective Goods Integration Tests ==\n";
$today = date('Y-m-d');

/* پاک‌سازی داده‌های احتمالی باقی‌مانده از اجراهای قبلی */
$old = $mysqli->query("SELECT id FROM products WHERE code IN ('T-DG-1','T-DG-2','T-DG-3','T-DG-DBG','T-DG-SVC')");
if ($old) {
    while ($row = $old->fetch_assoc()) {
        t_cleanup_product($mysqli, (int) $row['id'], '');
    }
}
@unlink(__DIR__ . '/dg_export_runner.php');
@unlink(__DIR__ . '/dg_export_out.txt');

/* ============================================================
 * 1) Inventory 100 ⇒ register 3 defective ⇒ stock 97
 * ========================================================== */
$p1 = t_make_product($mysqli, 'T-DG-1', 'کالای تستی ۱', 100);
$res = dg_register_defective($mysqli, $p1, '3', $today, 'آسیب‌دیده هنگام حمل', 'تست اتمیک');
t_check('ثبت ۳ عدد معیوب موفق است', (bool) ($res['ok'] ?? false), $res['message'] ?? 'no message');
t_check('دقیقاً یک رکورد تاریخی ثبت شده', t_defective_count_for($mysqli, $p1) === 1, 'count=' . t_defective_count_for($mysqli, $p1));
t_check('موجودی از ۱۰۰ به ۹۷ کاهش یافته', t_product_stock($mysqli, $p1) === 97.0, 'stock=' . t_product_stock($mysqli, $p1));
t_check('stock_before=100', (float) ($res['data']['stock_before'] ?? 0) === 100.0, (string) ($res['data']['stock_before'] ?? ''));
t_check('stock_after=97', (float) ($res['data']['stock_after'] ?? 0) === 97.0, (string) ($res['data']['stock_after'] ?? ''));

$mv = $mysqli->query("SELECT type, direction, quantity FROM stock_movements WHERE product_id = $p1 ORDER BY id DESC LIMIT 1");
$mvRow = $mv ? $mv->fetch_assoc() : null;
t_check(
    'گردش موجودی با type=defective ثبت شده',
    $mvRow && $mvRow['type'] === 'defective' && $mvRow['direction'] === 'out' && (float) $mvRow['quantity'] === 3.0,
    json_encode($mvRow)
);

/* ============================================================
 * 2) Quantity greater than stock is rejected (atomicity)
 * ========================================================== */
$before2 = t_product_stock($mysqli, $p1);
$res = dg_register_defective($mysqli, $p1, '98', $today, 'x', '');
t_check('تعداد بیشتر از موجودی رد می‌شود', !($res['ok'] ?? true) && str_contains($res['message'] ?? '', 'بیشتر'), $res['message'] ?? '');
t_check('موجودی پس از شکست تغییری نکرده', t_product_stock($mysqli, $p1) === $before2, 'stock=' . t_product_stock($mysqli, $p1));
t_check('رکورد معیوب اضافه نشده', t_defective_count_for($mysqli, $p1) === 1);

/* ============================================================
 * 3) Invalid quantities rejected / valid decimal accepted
 * ========================================================== */
t_check('تعداد صفر رد می‌شود', !(dg_register_defective($mysqli, $p1, '0', $today, 'x', '')['ok'] ?? true));
t_check('تعداد منفی رد می‌شود', !(dg_register_defective($mysqli, $p1, '-5', $today, 'x', '')['ok'] ?? true));
t_check('تعداد غیرعددی رد می‌شود', !(dg_register_defective($mysqli, $p1, 'abc', $today, 'x', '')['ok'] ?? true));
t_check('تعداد با سه رقم اعشار رد می‌شود', !(dg_register_defective($mysqli, $p1, '1.555', $today, 'x', '')['ok'] ?? true));
$resDec = dg_register_defective($mysqli, $p1, '0.50', $today, 'نیم واحد', '');
t_check('تعداد دو رقم اعشار پذیرفته می‌شود', (bool) ($resDec['ok'] ?? false), $resDec['message'] ?? '');
t_check('موجودی پس از ۰/۵ واحد = ۹۶/۵', t_product_stock($mysqli, $p1) === 96.5, 'stock=' . t_product_stock($mysqli, $p1));

/* ============================================================
 * 4) Missing reason rejected
 * ========================================================== */
$res = dg_register_defective($mysqli, $p1, '1', $today, '   ', '');
t_check('دلیل خالی رد می‌شود', !($res['ok'] ?? true) && str_contains($res['message'] ?? '', 'دلیل'), $res['message'] ?? '');

/* ============================================================
 * 5) Invalid calendar date rejected
 * ========================================================== */
$res = dg_register_defective($mysqli, $p1, '1', '2026-09-31', 'x', '');
t_check('تاریخ نامعتبر رد می‌شود', !($res['ok'] ?? true), $res['message'] ?? '');

/* ============================================================
 * 6) Services (non-stockable) rejected
 * ========================================================== */
$svc = t_make_service($mysqli, 'T-DG-SVC');
$res = dg_register_defective($mysqli, $svc, '1', $today, 'x', '');
t_check('ثبت معیوب برای خدمت رد می‌شود', !($res['ok'] ?? true) && str_contains($res['message'] ?? '', 'کالا'), $res['message'] ?? '');

/* ============================================================
 * 7) Non-existent product rejected
 * ========================================================== */
$res = dg_register_defective($mysqli, 99999999, '1', $today, 'x', '');
t_check('کالای ناموجود رد می‌شود', !($res['ok'] ?? true));

t_cleanup_product($mysqli, $p1, 'T-DG-1');
$mysqli->query("DELETE FROM products WHERE code='T-DG-SVC'");

/* ============================================================
 * 8) Persian date filters + server-side pagination (25/page)
 * ========================================================== */
$p2 = t_make_product($mysqli, 'T-DG-2', 'کالای تستی ۲', 0);
$stmt = $mysqli->prepare('INSERT INTO defective_goods (product_id, quantity, defective_date, reason, description) VALUES (?, 1, ?, ?, NULL)');
$stmt->bind_param('iss', $p2, $d, $reason);
for ($i = 1; $i <= 30; $i++) {
    $d = ($i % 2 === 0) ? '2026-08-02' : '2026-08-01';
    $reason = 'دلیل ' . $i;
    $stmt->execute();
}
$stmt->close();

$noFilter = ['from' => null, 'to' => null];
$p1list = dg_list($mysqli, $noFilter, 1, 25);
t_check('صفحه ۱ حداکثر ۲۵ رکورد دارد', count($p1list['rows']) === 25, 'rows=' . count($p1list['rows']));
t_check('تعداد کل ۳۰ و کل صفحات ۲', $p1list['total'] === 30 && $p1list['totalPages'] === 2, json_encode([$p1list['total'], $p1list['totalPages']]));
$p2list = dg_list($mysqli, $noFilter, 2, 25);
t_check('صفحه ۲ شامل ۵ رکورد است', count($p2list['rows']) === 5, 'rows=' . count($p2list['rows']));

$fromOnly = ['from' => '2026-08-02', 'to' => null];
t_check('فیلتر «از تاریخ» فقط رکوردهای آن روز', count(dg_list($mysqli, $fromOnly, 1, 25)['rows']) === 15);
$toOnly = ['from' => null, 'to' => '2026-08-01'];
t_check('فیلتر «تا تاریخ» فقط رکوردهای آن روز', dg_list($mysqli, $toOnly, 1, 25)['total'] === 15);
$both = ['from' => '2026-08-02', 'to' => '2026-08-02'];
t_check('فیلتر از/تا با بازه یک‌روزه', dg_list($mysqli, $both, 1, 25)['total'] === 15);

$sum = dg_summary($mysqli, $noFilter);
t_check('خلاصه بدون فیلتر: ۳۰ رکورد و ۳۰ واحد', $sum['records'] === 30 && (float) $sum['total_quantity'] === 30.0, json_encode($sum));
$sumF = dg_summary($mysqli, $both);
t_check('خلاصه با فیلتر: ۱۵ رکورد و ۱۵ واحد', $sumF['records'] === 15 && (float) $sumF['total_quantity'] === 15.0, json_encode($sumF));

/* بازه معکوس (از > تا) به‌درستی جابه‌جا می‌شود */
$swapped = dg_extract_date_filters(['from' => '2026-08-02', 'to' => '2026-08-01']);
t_check('بازه معکوس جابه‌جا می‌شود', $swapped['from'] === '2026-08-01' && $swapped['to'] === '2026-08-02', json_encode($swapped));
$bad = dg_extract_date_filters(['from' => 'bad-date', 'to' => '2026-99-99']);
t_check('تاریخ‌های نامعتبر فیلتر نادیده گرفته می‌شوند', $bad['from'] === null && $bad['to'] === null, json_encode($bad));

/* ============================================================
 * 9) Excel export: all rows / filtered rows / Persian content
 * ========================================================== */
$allRows = dg_all_for_export($mysqli, $noFilter);
t_check('خروجی اکسل بدون فیلتر شامل هر ۳۰ رکورد است', count($allRows) === 30, 'rows=' . count($allRows));
$filteredRows = dg_all_for_export($mysqli, $both);
t_check('خروجی اکسل با فیلتر فقط رکوردهای منطبق است', count($filteredRows) === 15, 'rows=' . count($filteredRows));

$runner = __DIR__ . '/dg_export_runner.php';
$tmpOut = __DIR__ . '/dg_export_out.txt';

function t_dg_run_export(string $runner, string $tmpOut, array $filters): string
{
    $code = '<?php' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["auth_user"] = "memar";' . "\n"
        . '$_GET = ' . var_export($filters, true) . ';' . "\n"
        . 'require __DIR__ . "/../defective_goods_export.php";' . "\n";
    @unlink($runner);
    file_put_contents($runner, $code);
    @unlink($tmpOut);
    shell_exec('php -d extension=mysqli ' . escapeshellarg($runner) . ' > ' . escapeshellarg($tmpOut) . ' 2>&1');
    return (string) file_get_contents($tmpOut);
}

$outAll = t_dg_run_export($runner, $tmpOut, $noFilter);
$dataRowsAll = (int) substr_count($outAll, '<Row>');
t_check('فایل اکسل بدون فیلتر ۳۰ سطر داده دارد', $dataRowsAll === 30, 'rows=' . $dataRowsAll);
t_check('سربرگ فارسی اکسل موجود است', str_contains($outAll, 'نام کالا') && str_contains($outAll, 'تاریخ (شمسی)'));
t_check('تاریخ شمسی فارسی (۱۴۰۵) در خروجی اکسل هست', str_contains($outAll, '۱۴۰۵'));
t_check('نام کالای فارسی در خروجی اکسل خواناست', str_contains($outAll, 'کالای تستی ۲'));

$outFiltered = t_dg_run_export($runner, $tmpOut, $both);
$dataRowsF = (int) substr_count($outFiltered, '<Row>');
t_check('فایل اکسل با فیلتر فقط ۱۵ سطر داده دارد', $dataRowsF === 15, 'rows=' . $dataRowsF);

@unlink($runner);
@unlink($tmpOut);
t_cleanup_product($mysqli, $p2, 'T-DG-2');

/* ============================================================
 * 10) Endpoint wiring: real action file via POST + CSRF
 * ========================================================== */
$p3 = t_make_product($mysqli, 'T-DG-3', 'کالای تستی ۳', 50);
$actionRunner = __DIR__ . '/dg_action_runner.php';
$actionOut = __DIR__ . '/dg_action_out.txt';

function t_dg_run_action(string $runner, string $outFile, array $post, bool $csrfMatch): array
{
    $postCode = var_export($post, true);
    $token = $csrfMatch ? 'tok12345678' : 'tok-wrong';
    $code = '<?php' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["auth_user"] = "memar";' . "\n"
        . '$_SESSION["csrf_token"] = "tok12345678";' . "\n"
        . '$_POST = ' . $postCode . ';' . "\n"
        . 'require __DIR__ . "/../defective_goods_action.php";' . "\n";
    @unlink($runner);
    file_put_contents($runner, $code);
    @unlink($outFile);
    shell_exec('php -d extension=mysqli ' . escapeshellarg($runner) . ' > ' . escapeshellarg($outFile) . ' 2>&1');
    $raw = (string) file_get_contents($outFile);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['success' => false, 'message' => 'no-json: ' . mb_substr($raw, 0, 120)];
}

$epPost = [
    'csrf_token' => 'tok-wrong',
    'product_id' => (string) $p3,
    'quantity' => '5',
    'defective_date' => $today,
    'reason' => 'تست endpoint',
    'description' => '',
];
$epBad = t_dg_run_action($actionRunner, $actionOut, $epPost, false);
t_check('Endpoint: CSRF نامعتبر رد می‌شود', !($epBad['success'] ?? true), $epBad['message'] ?? '');
t_check('Endpoint: با CSRF نامعتبر موجودی تغییر نکرد', t_product_stock($mysqli, $p3) === 50.0, 'stock=' . t_product_stock($mysqli, $p3));

$epPost['csrf_token'] = 'tok12345678';
$epGood = t_dg_run_action($actionRunner, $actionOut, $epPost, true);
t_check('Endpoint: ثبت موفق از طریق فایل اکشن', (bool) ($epGood['success'] ?? false), $epGood['message'] ?? '');
t_check('Endpoint: موجودی ۵۰ → ۴۵', t_product_stock($mysqli, $p3) === 45.0, 'stock=' . t_product_stock($mysqli, $p3));
t_check('Endpoint: یک رکورد معیوب ثبت شده', t_defective_count_for($mysqli, $p3) === 1, 'count=' . t_defective_count_for($mysqli, $p3));

@unlink($actionRunner);
@unlink($actionOut);
t_cleanup_product($mysqli, $p3, 'T-DG-3');

/* ============================================================
 * Summary
 * ========================================================== */
echo "\n== Result: $pass passed, $fail failed ==\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}
exit(0);