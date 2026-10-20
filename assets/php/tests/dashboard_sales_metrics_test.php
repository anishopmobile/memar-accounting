<?php
/**
 * dashboard_sales_metrics_test.php — تست وضعیت کارت‌های «فروش امروز / فروش ماهانه».
 *
 * پوشش‌ها:
 *   1) تبدیل تاریخ شمسی ↔ میلادی (مکمل مورداستفاده داشبورد)
 *   2) رندر صفحه واقعی داشبورد و استخراج مقادیر کارت‌ها
 *   3) کیس‌های مرزی با فاکتورهای موقت داخل تراکنش برگشت‌خورده (ROLLBACK)
 *
 * اجرا:  php assets/php/tests/dashboard_sales_metrics_test.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jdf.php';
date_default_timezone_set('Asia/Tehran');

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  [OK]   ' : '  [FAIL] ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

/* ═════ ۱) تبدیل تاریخ ═════ */
echo "═ ۱) Date helpers ═\n";
$pairs = [
    [[1405, 7, 1],   '2026-09-23'],
    [[1405, 7, 9],   '2026-10-01'],
    [[1405, 7, 3],   '2026-09-25'],
    [[1405, 7, 8],   '2026-09-30'],
    [[1405, 1, 1],   '2026-03-21'],
    [[1403, 12, 30], '2025-03-20'], // اسفند کبیسه
];
$roundTripOk = true;
foreach ($pairs as $p) {
    $g = sprintf('%04d-%02d-%02d', ...jalali_to_gregorian(...$p[0]));
    if ($g !== $p[1]) {
        $roundTripOk = false;
        echo "  mismatch: " . implode('/', $p[0]) . " -> $g (expected {$p[1]})\n";
    }
}
for ($i = 0; $i < 800; $i++) {
    $gDate = date('Y-m-d', strtotime("2025-06-01 +$i day"));
    list($Y, $M, $D) = array_map('intval', explode('-', $gDate));
    list($jy, $jm, $jd) = gregorian_to_jalali($Y, $M, $D);
    if (sprintf('%04d-%02d-%02d', ...jalali_to_gregorian($jy, $jm, $jd)) !== $gDate) {
        $roundTripOk = false;
        echo "  round-trip mismatch at $gDate\n";
    }
}
check('Jalali↔Gregorian conversion & round trips', $roundTripOk);

/* ═════ ۲) رندر واقعی داشبورد ═════ */
echo "═ ۲) Real dashboard render ═\n";
$baseSales = 0;
$baseUnpaid = 0;
$monthStartGreg = '';
$monthEndGreg = '';
$todayGreg = date('Y-m-d');

list($cy, $cm, $cd) = gregorian_to_jalali((int) date('Y'), (int) date('m'), (int) date('d'));
$monthStartGreg = implode('-', jalali_to_gregorian($cy, $cm, 1));
$nxY = $cm === 12 ? $cy + 1 : $cy;
$nxM = $cm === 12 ? 1 : $cm + 1;
$monthEndGreg = date('Y-m-d', strtotime(implode('-', jalali_to_gregorian($nxY, $nxM, 1)) . ' -1 day'));

ob_start();
include __DIR__ . '/../../../pages/dashboard.php';
$html = ob_get_clean();
preg_match_all('/<span class="metric-sales-total">(.*?)<\/span>\s*<span class="metric-unpaid">(.*?)<\/span>/u', $html, $m, PREG_SET_ORDER);
check('Dashboard rendered with both metric cards', count($m) === 2);
check('Dashboard output has no PHP warning/fatal', !preg_match('/(Warning|Fatal error|Notice|Deprecated)/', $html));

$stmt = $mysqli->prepare("SELECT COALESCE(SUM(payable_amount),0) AS total FROM invoices WHERE type='sales_invoice' AND invoice_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $monthStartGreg, $monthEndGreg);
$stmt->execute();
$baseSales = (float) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $mysqli->prepare("SELECT COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','partial') THEN payable_amount ELSE 0 END),0) AS total FROM invoices WHERE type='sales_invoice' AND invoice_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $monthStartGreg, $monthEndGreg);
$stmt->execute();
$baseUnpaid = (float) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$shownMonthly = (float) str_replace(',', '', $m[1][1]);
preg_match('/[0-9][0-9,]*/', $m[1][2], $mm2);
$shownUnpaid = (float) str_replace(',', '', $mm2[0] ?? '0');
check(
    'Monthly card shows actual sum (' . number_format($shownMonthly) . ' = ' . number_format($baseSales) . ')',
    abs($shownMonthly - $baseSales) < 0.005
);
check(
    'Monthly unpaid card shows actual outstanding (' . number_format($shownUnpaid) . ' = ' . number_format($baseUnpaid) . ')',
    abs($shownUnpaid - $baseUnpaid) < 0.005
);

/* ═════ ۳) کیس‌های مرزی در تراکنش موقت ═════ */
echo "═ ۳) Edge cases (rolled-back transaction) ═\n";
function q1($mysqli, $sql, $p1): float
{
    $s = $mysqli->prepare($sql);
    $s->bind_param('s', $p1);
    $s->execute();
    $v = (float) $s->get_result()->fetch_assoc()['total'];
    $s->close();
    return $v;
}
function q2($mysqli, $sql, $a, $b): float
{
    $s = $mysqli->prepare($sql);
    $s->bind_param('ss', $a, $b);
    $s->execute();
    $v = (float) $s->get_result()->fetch_assoc()['total'];
    $s->close();
    return $v;
}
$SQL_T = "SELECT COALESCE(SUM(payable_amount),0) AS total FROM invoices WHERE type='sales_invoice' AND invoice_date=?";
$SQL_M = "SELECT COALESCE(SUM(payable_amount),0) AS total FROM invoices WHERE type='sales_invoice' AND invoice_date BETWEEN ? AND ?";
$SQL_TU = "SELECT COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','partial') THEN payable_amount ELSE 0 END),0) AS total FROM invoices WHERE type='sales_invoice' AND invoice_date=?";
$SQL_MU = "SELECT COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','partial') THEN payable_amount ELSE 0 END),0) AS total FROM invoices WHERE type='sales_invoice' AND invoice_date BETWEEN ? AND ?";

$before = [
    q1($mysqli, $SQL_T, $todayGreg),
    q2($mysqli, $SQL_M, $monthStartGreg, $monthEndGreg),
    q1($mysqli, $SQL_TU, $todayGreg),
    q2($mysqli, $SQL_MU, $monthStartGreg, $monthEndGreg),
];

$mysqli->begin_transaction();
$ins = $mysqli->prepare(
    "INSERT INTO invoices (invoice_number, type, customer_id, supplier_id, payment_type, payment_status,
                           invoice_date, subtotal, discount, tax_rate, tax_amount, payable_amount)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
);
$prevDay = date('Y-m-d', strtotime($monthStartGreg . ' -1 day'));
$earlierDate = date('Y-m-d', strtotime($monthStartGreg . ' +2 day'));
if ($earlierDate === $todayGreg) {
    $earlierDate = date('Y-m-d', strtotime($monthStartGreg . ' +4 day'));
}
$rows = [
    ['sales_invoice',   1, null, 'paid',    $todayGreg, 555000.00],
    ['sales_invoice',   1, null, 'paid',    $todayGreg, 444000.00],
    ['sales_invoice',   1, null, 'unpaid',  $todayGreg, 333000.00],
    ['sales_invoice',   1, null, 'partial', $todayGreg, 222000.00],
    ['sales_invoice',   1, null, 'unpaid',  $earlierDate, 999000.00],
    ['sales_invoice',   1, null, 'paid',    $prevDay,   777000.00],
    ['sales_proforma',  1, null, 'paid',    $todayGreg, 888000.00],
    ['purchase_invoice', null, 1, 'paid',   $todayGreg, 111000.00],
];
$i = 0;
foreach ($rows as $r) {
    $i++;
    list($type, $cust, $sup, $status, $date, $payable) = $r;
    $num = 'DBG-' . time() . '-' . $i;
    $pos = 'pos';
    $zero = 0.0;
    $ins->bind_param('ssiisssddddd', $num, $type, $cust, $sup, $pos, $status, $date, $payable, $zero, $zero, $zero, $payable);
    $ins->execute();
}
$ins->close();

$after = [
    q1($mysqli, $SQL_T, $todayGreg),
    q2($mysqli, $SQL_M, $monthStartGreg, $monthEndGreg),
    q1($mysqli, $SQL_TU, $todayGreg),
    q2($mysqli, $SQL_MU, $monthStartGreg, $monthEndGreg),
];
$mysqli->rollback();

$dToday = $after[0] - $before[0];
$dMonth = $after[1] - $before[1];
$dTodayU = $after[2] - $before[2];
$dMonthU = $after[3] - $before[3];

check('Today sales delta = paid+unpaid+partial today only (', number_format($dToday, 0) . ') = 1,554,000', abs($dToday - 1554000) < 0.005);
check('Today unpaid delta = unpaid+partial only (', number_format($dTodayU, 0) . ') = 555,000', abs($dTodayU - 555000) < 0.005);
check('Monthly delta includes earlier-in-month, excludes prev-month/proforma/purchase (', number_format($dMonth, 0) . ') = 2,553,000', abs($dMonth - 2553000) < 0.005);
check('Monthly unpaid delta (', number_format($dMonthU, 0) . ') = 1,554,000', abs($dMonthU - 1554000) < 0.005);

/* تأیید اینکه بعد از ROLLBACK داده موقت باقی نمانده */
$leak = $mysqli->query("SELECT COUNT(*) c FROM invoices WHERE invoice_number LIKE 'DBG-%'")->fetch_assoc()['c'];
check('No temp rows leaked after rollback (count=' . $leak . ')', (int) $leak === 0);

echo $failures === 0 ? "\nRESULT: ALL DASHBOARD SALES METRIC TESTS PASSED\n" : "\nRESULT: $failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);