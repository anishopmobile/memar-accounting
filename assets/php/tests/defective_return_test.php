<?php
/**
 * Defective Goods Return — integration tests (CLI only)
 *
 * Run:
 *   php -d extension=mysqli assets/php/tests/defective_return_test.php
 *
 * Uses the real memar_accounting database but operates only on test products
 * (codes prefixed "T-DGR-") and cleans up after itself.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

session_start();
$_SESSION['auth_user'] = 'memar';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../defective_goods_common.php';
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

function t_product_stock(mysqli $mysqli, int $productId): float
{
    $r = $mysqli->query("SELECT stock FROM products WHERE id = $productId");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? (float) $row['stock'] : -1;
}

function t_defective_rows(mysqli $mysqli, int $productId): array
{
    $rows = [];
    $r = $mysqli->query("SELECT id, quantity FROM defective_goods WHERE product_id = $productId");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function t_last_movement(mysqli $mysqli, int $productId): array
{
    $r = $mysqli->query("SELECT type, direction, quantity, stock_before, stock_after FROM stock_movements WHERE product_id = $productId ORDER BY id DESC LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ?: [];
}

function t_dg_run_file(string $target, string $runner, string $outFile, array $get, array $post): array
{
    $code = '<?php' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["auth_user"] = "memar";' . "\n"
        . '$_SESSION["csrf_token"] = "tokreturn123";' . "\n"
        . '$_GET = ' . var_export($get, true) . ';' . "\n"
        . '$_POST = ' . var_export($post, true) . ';' . "\n"
        . 'require __DIR__ . "/../' . $target . '";' . "\n";
    @unlink($runner);
    file_put_contents($runner, $code);
    @unlink($outFile);
    shell_exec('php -d extension=mysqli ' . escapeshellarg($runner) . ' > ' . escapeshellarg($outFile) . ' 2>&1');
    $raw = (string) file_get_contents($outFile);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['success' => false, 'message' => 'no-json: ' . mb_substr($raw, 0, 120)];
}

function t_write_worker(int $defectiveId, string $qty, string $runner): void
{
    $code = '<?php' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["auth_user"] = "memar";' . "\n"
        . '$_SESSION["csrf_token"] = "tokconc123";' . "\n"
        . '$_POST = ' . var_export(['csrf_token' => 'tokconc123', 'defective_id' => (string) $defectiveId, 'quantity' => $qty], true) . ';' . "\n"
        . 'require __DIR__ . "/../defective_goods_return_action.php";' . "\n";
    @unlink($runner);
    file_put_contents($runner, $code);
}

/* ============================================================
 * 1) Partial return: 1 from 3
 * ========================================================== */
$today = date('Y-m-d');
$p1 = t_make_product($mysqli, 'T-DGR-1', 'کالای تستی برگشت یک', 10);

$reg = dg_register_defective($mysqli, $p1, '3', $today, 'تست برگشت', '');
t_check('ثبت ۳ معیوب موفق', (bool) ($reg['ok'] ?? false), $reg['message'] ?? '');
t_check('موجودی پس از ثبت = ۷', t_product_stock($mysqli, $p1) === 7.0, 'stock=' . t_product_stock($mysqli, $p1));

$rows = t_defective_rows($mysqli, $p1);
$defId = (int) $rows[0]['id'];

$r1 = dg_return_defective($mysqli, $defId, '1');
t_check('بازگشت ۱ از ۳ موفق', (bool) ($r1['ok'] ?? false), $r1['message'] ?? '');
t_check('پس از بازگشت ۱: موجودی = ۸', t_product_stock($mysqli, $p1) === 8.0, 'stock=' . t_product_stock($mysqli, $p1));
$rows = t_defective_rows($mysqli, $p1);
t_check('پس از بازگشت ۱: مقدار معیوب = ۲', (float) $rows[0]['quantity'] === 2.0, 'qty=' . ($rows[0]['quantity'] ?? 'none'));

$mv = t_last_movement($mysqli, $p1);
t_check(
    'گردش defective_return / in ثبت شد',
    ($mv['type'] ?? '') === 'defective_return' && ($mv['direction'] ?? '') === 'in' && (float) ($mv['quantity'] ?? 0) === 1.0,
    json_encode($mv)
);

/* ============================================================
 * 2) Full return: remaining 2
 * ========================================================== */
$r2 = dg_return_defective($mysqli, $defId, '2');
t_check('بازگشت ۲ از ۲ موفق', (bool) ($r2['ok'] ?? false), $r2['message'] ?? '');
t_check('fully_returned = true', ($r2['data']['fully_returned'] ?? false) === true, json_encode($r2['data'] ?? null));
t_check('موجودی پس از بازگشت کامل = ۱۰', t_product_stock($mysqli, $p1) === 10.0, 'stock=' . t_product_stock($mysqli, $p1));
t_check('رکورد معیوب کاملاً حذف شد', count(t_defective_rows($mysqli, $p1)) === 0);

/* ============================================================
 * 3) Over-return + invalid inputs + unknown id
 * ========================================================== */
dg_register_defective($mysqli, $p1, '3', $today, 'تست برگشت دو', '');
$rows = t_defective_rows($mysqli, $p1);
$defId2 = (int) $rows[0]['id'];
$stockBefore = t_product_stock($mysqli, $p1);

$rBad = dg_return_defective($mysqli, $defId2, '4');
t_check('بازگشت ۴ از ۳ رد می‌شود', !($rBad['ok'] ?? true), $rBad['message'] ?? '');
t_check('رد بازگشت اضافی: موجودی تغییر نکرد', t_product_stock($mysqli, $p1) === $stockBefore, 'stock=' . t_product_stock($mysqli, $p1));
$rows = t_defective_rows($mysqli, $p1);
t_check('رد بازگشت اضافی: مقدار معیوب تغییر نکرد', (float) $rows[0]['quantity'] === 3.0, 'qty=' . ($rows[0]['quantity'] ?? 'none'));

foreach (['0', '-1', 'abc', '1.555', '', '1,5'] as $bad) {
    $res = dg_return_defective($mysqli, $defId2, $bad);
    t_check("ورودی نامعتبر '$bad' رد می‌شود", !($res['ok'] ?? true), $res['message'] ?? '');
    t_check("ورودی نامعتبر '$bad': موجودی تغییری نکرد", t_product_stock($mysqli, $p1) === $stockBefore, 'stock=' . t_product_stock($mysqli, $p1));
}
$rMissing = dg_return_defective($mysqli, 99999999, '1');
t_check('شناسه ناموجود رد می‌شود', !($rMissing['ok'] ?? true), $rMissing['message'] ?? '');
$rZeroId = dg_return_defective($mysqli, 0, '1');
t_check('شناسه صفر رد می‌شود', !($rZeroId['ok'] ?? true), $rZeroId['message'] ?? '');

/* ============================================================
 * 4) Sequential partial returns 1+1+1 from 3 (exact/idempotent)
 * ========================================================== */
$expectedStock = $stockBefore;
foreach (['1', '1', '1'] as $i => $q) {
    $res = dg_return_defective($mysqli, $defId2, $q);
    $expectedStock += 1;
    t_check("بازگشت مرحله‌ای $q موفق (گام " . ($i + 1) . ')', (bool) ($res['ok'] ?? false), $res['message'] ?? '');
    t_check("بازگشت مرحله‌ای: موجودی = $expectedStock", t_product_stock($mysqli, $p1) === (float) $expectedStock, 'stock=' . t_product_stock($mysqli, $p1));
}
t_check('پس از سه بازگشت، بازگشت دوباره رد می‌شود', !(dg_return_defective($mysqli, $defId2, '1')['ok'] ?? true));
t_check('پس از سه بازگشت رکورد حذف شده', count(t_defective_rows($mysqli, $p1)) === 0);

/* ============================================================
 * 5) Report data (filters / summary / pagination)
 * ========================================================== */
dg_register_defective($mysqli, $p1, '2', $today, 'تست گزارش', '');
$rows = t_defective_rows($mysqli, $p1);
$defId3 = (int) $rows[0]['id'];

$filtered = dg_report_data($mysqli, dg_extract_date_filters(['from' => $today, 'to' => $today]), 1, 25);
$idsToday = array_map('intval', array_column($filtered['rows'], 'id'));
t_check('گزارش امروز شامل رکورد تست است', in_array($defId3, $idsToday, true));
t_check('گزارش امروز جمع معیوب ≥ ۲', (float) ($filtered['summary']['total_quantity'] ?? 0) >= 2.0, json_encode($filtered['summary']));
t_check('گزارش امروز شمارنده رکوردها ≥ ۱', (int) ($filtered['summary']['records'] ?? 0) >= 1, json_encode($filtered['summary']));
t_check('گزارش دارای فیلد تاریخ شمسی است', ($filtered['rows'][0]['defective_date_jalali'] ?? '') !== '', json_encode($filtered['rows'][0] ?? null));

$excluded = dg_report_data($mysqli, dg_extract_date_filters(['from' => '2020-01-01', 'to' => '2020-12-31']), 1, 25);
$idsOld = array_map('intval', array_column($excluded['rows'], 'id'));
t_check('فیلتر بازه گذشته رکورد امروز را حذف می‌کند', !in_array($defId3, $idsOld, true));

/* ============================================================
 * 6) Real endpoints (CSRF + wiring)
 * ========================================================== */
$runner  = __DIR__ . '/dgr_return_runner.php';
$outFile = __DIR__ . '/dgr_return_out.txt';

$rBadCsrf = t_dg_run_file('defective_goods_return_action.php', $runner, $outFile, [], [
    'csrf_token'   => 'wrong-token',
    'defective_id' => (string) $defId3,
    'quantity'     => '1',
]);
t_check('Endpoint بازگشت: CSRF نامعتبر رد می‌شود', !($rBadCsrf['success'] ?? true), $rBadCsrf['message'] ?? '');
$rows = t_defective_rows($mysqli, $p1);
t_check('Endpoint بازگشت: با CSRF نامعتبر تغییری اعمال نشد', (float) $rows[0]['quantity'] === 2.0, 'qty=' . ($rows[0]['quantity'] ?? 'none'));

$rGood = t_dg_run_file('defective_goods_return_action.php', $runner, $outFile, [], [
    'csrf_token'   => 'tokreturn123',
    'defective_id' => (string) $defId3,
    'quantity'     => '2',
]);
t_check('Endpoint بازگشت: بازگشت کامل موفق', (bool) ($rGood['success'] ?? false), $rGood['message'] ?? '');
t_check('Endpoint بازگشت: رکورد حذف شد', count(t_defective_rows($mysqli, $p1)) === 0);

$report = t_dg_run_file('defective_goods_report.php', $runner, $outFile, ['pageno' => 1, 'from' => $today, 'to' => $today], []);
t_check('Endpoint گزارش: JSON معتبر و موفق', (bool) ($report['success'] ?? false), $report['message'] ?? '');
t_check('Endpoint گزارش: داده گزارش وجود دارد', isset($report['data']['rows']) && isset($report['data']['summary']), 'no data');

/* ============================================================
 * 7) Concurrency: two parallel returns of 2 from qty 3
 *    فقط ۳ واحد باید قابل بازگشت باشد؛ یکی موفق و دیگری شکست.
 * ========================================================== */
dg_register_defective($mysqli, $p1, '3', $today, 'تست همزمانی', '');
$rows = t_defective_rows($mysqli, $p1);
$defIdC = (int) $rows[0]['id'];
$stockC = t_product_stock($mysqli, $p1);

$wA = __DIR__ . '/dgr_worker_a.php';
$wB = __DIR__ . '/dgr_worker_b.php';
$oA = __DIR__ . '/dgr_worker_a.json';
$oB = __DIR__ . '/dgr_worker_b.json';
t_write_worker($defIdC, '2', $wA);
t_write_worker($defIdC, '2', $wB);
@unlink($oA);
@unlink($oB);

$specA = [0 => ['file', 'NUL', 'r'], 1 => ['file', $oA, 'w'], 2 => ['file', 'NUL', 'w']];
$specB = [0 => ['file', 'NUL', 'r'], 1 => ['file', $oB, 'w'], 2 => ['file', 'NUL', 'w']];
$pA = proc_open('php -d extension=mysqli ' . escapeshellarg($wA), $specA, $pipesA);
$pB = proc_open('php -d extension=mysqli ' . escapeshellarg($wB), $specB, $pipesB);
if (is_resource($pA)) { proc_close($pA); }
if (is_resource($pB)) { proc_close($pB); }

function t_read_json_file(string $path): array
{
    $raw = (string) file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'no-json'];
}
$resA = t_read_json_file($oA);
$resB = t_read_json_file($oB);
$okCount   = ((int) ($resA['success'] ?? false)) + ((int) ($resB['success'] ?? false));
$failCount = 2 - $okCount;
t_check('همزمانی: دقیقاً یکی موفق و دیگری شکست', $okCount === 1 && $failCount === 1, 'ok=' . $okCount . ' A=' . json_encode($resA) . ' B=' . json_encode($resB));
t_check('همزمانی: موجودی فقط ۲ واحد افزایش یافت', t_product_stock($mysqli, $p1) === (float) ($stockC + 2), 'stock=' . t_product_stock($mysqli, $p1));
$rows = t_defective_rows($mysqli, $p1);
t_check('همزمانی: مقدار معیوب باقی‌مانده = ۱', (float) $rows[0]['quantity'] === 1.0, 'qty=' . ($rows[0]['quantity'] ?? 'none'));
t_check('همزمانی: جمع بازگشتی‌ها از ۳ بیشتر نشد', t_product_stock($mysqli, $p1) - $stockC <= 3, 'delta=' . (t_product_stock($mysqli, $p1) - $stockC));

@unlink($runner);
@unlink($outFile);
@unlink($wA);
@unlink($wB);
@unlink($oA);
@unlink($oB);
t_cleanup_product($mysqli, $p1, 'T-DGR-1');

echo "\n== Result: $pass passed, $fail failed ==\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}
exit(0);