<?php
/**
 * current_affairs_test.php — Current Affairs module regression tests.
 *
 * Uses a throwaway database (memar_accounting_ca_test) patched from db.php
 * exactly like fresh_install_test.php, so the real database is untouched.
 * Run with:
 *   php -d extension=mysqli assets/php/tests/current_affairs_test.php
 */
date_default_timezone_set('Asia/Tehran');

$tests = [];
$failed = 0;

function t_check(string $name, bool $cond, string $detail = ''): void
{
    global $tests, $failed;
    $tests[] = $name;
    if (!$cond) {
        $failed++;
        echo "FAIL: {$name}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

function t_first(mysqli $m, string $sql): ?array
{
    $res = $m->query($sql);
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row !== null ? $row : null;
}

function t_count(mysqli $m, string $sql): int
{
    $row = t_first($m, $sql);
    return $row !== null ? (int) $row['c'] : -1;
}

/* ---------- throwaway database setup ---------- */
$root = new mysqli('127.0.0.1', 'root', '');
if ($root->connect_errno) {
    echo "NO DB\n";
    exit(1);
}
$root->query('DROP DATABASE IF EXISTS memar_accounting_ca_test');
$root->query('CREATE DATABASE memar_accounting_ca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->close();

$orig = file_get_contents(__DIR__ . '/../db.php');
$patched = str_replace(
    '$dbName = \'memar_accounting\';',
    '$dbName = \'memar_accounting_ca_test\';',
    (string) $orig
);
file_put_contents(__DIR__ . '/../db_ca_patch.php', $patched);
require __DIR__ . '/../db_ca_patch.php'; // defines global $mysqli
require __DIR__ . '/../current_affairs/ca_loader.php';

$migErrs = current_affairs_migrate($mysqli);
t_check('migration applied without errors', $migErrs === [], $migErrs !== [] ? implode(' ', $migErrs) : '');
foreach (['current_affairs_categories', 'current_affairs_fields', 'current_affairs_field_options', 'current_affairs_records', 'current_affairs_values'] as $tName) {
    t_check('table exists: ' . $tName, t_count($mysqli, "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $tName . "'") === 1);
}

/* ---------- helper to create a category + field through the API surface ---------- */
function t_cat(mysqli $m, array $opts): array
{
    return ca_cat_save($m, $opts['id'] ?? null, (string) $opts['name'], (string) ($opts['description'] ?? ''));
}

function t_field(mysqli $m, array $opts): array
{
    return ca_field_save(
        $m,
        $opts['id'] ?? null,
        (int) $opts['category_id'],
        (string) $opts['label'],
        (string) $opts['field_type'],
        (bool) ($opts['is_required'] ?? false),
        (bool) ($opts['show_in_table'] ?? true),
        (bool) ($opts['is_filterable'] ?? false),
        (bool) ($opts['include_in_totals'] ?? false),
        (int) ($opts['sort_order'] ?? 0),
        (bool) ($opts['is_active'] ?? true),
        (bool) ($opts['is_reporting_date'] ?? false),
        (array) ($opts['options'] ?? [])
    );
}

function t_record(mysqli $m, ?int $id, int $catId, array $values): array
{
    return ca_record_save($m, $id, $catId, $values);
}

/* ============================================================
 * 1) Categories
 * ========================================================== */
$resA = t_cat($mysqli, ['name' => 'هزینه‌ها', 'description' => 'دسته هزینه‌ها']);
$catA = (int) ($resA['data']['id'] ?? 0);
t_check('category A created', $resA['ok'] && $catA > 0);

$resC = t_cat($mysqli, ['name' => 'سپرده‌ها و برداشت‌های شخصی']);
$catC = (int) ($resC['data']['id'] ?? 0);
t_check('category C created', $resC['ok'] && $catC > 0);

t_check('empty category name rejected', !(t_cat($mysqli, ['name' => '   ']))['ok']);

$resB = t_cat($mysqli, ['id' => $catC, 'name' => 'سپرده/برداشت شخصی']);
t_check('category renamed', $resB['ok'] && (string) $resB['data']['name'] === 'سپرده/برداشت شخصی');

t_check('category deactivate', (ca_cat_set_active($mysqli, $catC, false))['ok']);
t_check('category activate again', (ca_cat_set_active($mysqli, $catC, true))['ok']);

/* delete rules */
$resDel = t_cat($mysqli, ['name' => 'دسته موقت']);
$tmpCat = (int) ($resDel['data']['id'] ?? 0);
t_check('temporary category created', $resDel['ok'] && $tmpCat > 0);
t_check('empty category deletable', (ca_cat_delete($mysqli, $tmpCat))['ok']);

$resProt = t_cat($mysqli, ['name' => 'دسته محافظت‌شده']);
$protCat = (int) ($resProt['data']['id'] ?? 0);
t_field($mysqli, ['category_id' => $protCat, 'label' => 'توضیح', 'field_type' => 'text']);
t_record($mysqli, null, $protCat, []);
t_check('category with records NOT deletable', !(ca_cat_delete($mysqli, $protCat))['ok']);
t_check('category with records can be deactivated', (ca_cat_set_active($mysqli, $protCat, false))['ok']);

/* ============================================================
 * 2) Custom fields (all seven types) + Reporting Date
 * ========================================================== */
$fDate1 = t_field($mysqli, ['category_id' => $catA, 'label' => 'تاریخ', 'field_type' => 'date', 'sort_order' => 1, 'is_filterable' => true]);
$fDate2 = t_field($mysqli, ['category_id' => $catA, 'label' => 'تاریخ دوم', 'field_type' => 'date', 'sort_order' => 2, 'is_filterable' => true]);
$fDate3 = t_field($mysqli, ['category_id' => $catA, 'label' => 'تاریخ سوم', 'field_type' => 'date', 'sort_order' => 3]);
$fDesc  = t_field($mysqli, ['category_id' => $catA, 'label' => 'شرح', 'field_type' => 'text', 'is_required' => true]);
$fAmount= t_field($mysqli, ['category_id' => $catA, 'label' => 'مبلغ', 'field_type' => 'money', 'include_in_totals' => true, 'is_filterable' => true]);
$fQty   = t_field($mysqli, ['category_id' => $catA, 'label' => 'تعداد', 'field_type' => 'number', 'include_in_totals' => true, 'is_filterable' => true]);
$fNotes = t_field($mysqli, ['category_id' => $catA, 'label' => 'یادداشت', 'field_type' => 'textarea']);
$fMemo  = t_field($mysqli, ['category_id' => $catA, 'label' => 'حافظه داخلی', 'field_type' => 'text', 'show_in_table' => false]);
$fType  = t_field($mysqli, ['category_id' => $catA, 'label' => 'نوع', 'field_type' => 'select', 'is_filterable' => true, 'options' => [['value' => 'الف'], ['value' => 'ب'], ['value' => 'ج']]]);
$fDone  = t_field($mysqli, ['category_id' => $catA, 'label' => 'انجام شد', 'field_type' => 'yesno', 'is_filterable' => true]);

$idF1 = (int) ($fDate1['data']['id'] ?? 0);
$idF2 = (int) ($fDate2['data']['id'] ?? 0);
$idF3 = (int) ($fDate3['data']['id'] ?? 0);
$idDesc = (int) ($fDesc['data']['id'] ?? 0);
$idAmount = (int) ($fAmount['data']['id'] ?? 0);
$idQty = (int) ($fQty['data']['id'] ?? 0);
$idNotes = (int) ($fNotes['data']['id'] ?? 0);
$idMemo = (int) ($fMemo['data']['id'] ?? 0);
$idType = (int) ($fType['data']['id'] ?? 0);
$idDone = (int) ($fDone['data']['id'] ?? 0);

t_check('all seven field types created', $idF1 > 0 && $idF2 > 0 && $idDesc > 0 && $idAmount > 0 && $idNotes > 0 && $idType > 0 && $idDone > 0);
t_check('select options persisted', count(ca_option_list($mysqli, $idType)) === 3);

/* invalid field payloads */
t_check('unknown field type rejected', !(t_field($mysqli, ['category_id' => $catA, 'label' => 'x', 'field_type' => 'weird']))['ok']);
t_check('include_in_totals on non numeric rejected', !(t_field($mysqli, ['category_id' => $catA, 'label' => 'x', 'field_type' => 'text', 'include_in_totals' => true]))['ok']);
t_check('select with no options rejected', !(t_field($mysqli, ['category_id' => $catA, 'label' => 'x', 'field_type' => 'select', 'options' => []]))['ok']);
t_check('select with duplicate options rejected', !(t_field($mysqli, ['category_id' => $catA, 'label' => 'x', 'field_type' => 'select', 'options' => [['value' => 'A'], ['value' => 'a']]]))['ok']);
t_check('cross-category field update rejected', !(t_field($mysqli, ['id' => $idF1, 'category_id' => $catC, 'label' => 'تاریخ', 'field_type' => 'date']))['ok']);

/* reporting date initial state */
t_check('no reporting date initially', ca_reporting_field($mysqli, $catA) === null);

/* explicit designation */
$rep1 = ca_set_reporting_date($mysqli, $catA, $idF1);
t_check('reporting date designated', $rep1['ok']);
$repRow = ca_reporting_field($mysqli, $catA);
t_check('designated field is f_date1', $repRow !== null && (int) $repRow['id'] === $idF1);

/* reassignment removes previous */
$rep2 = ca_set_reporting_date($mysqli, $catA, $idF2);
t_check('reassignment ok', $rep2['ok']);
$repRow = ca_reporting_field($mysqli, $catA);
t_check('reassignment moved to f_date2', $repRow !== null && (int) $repRow['id'] === $idF2);
$rowF1 = t_first($mysqli, "SELECT is_reporting_date FROM current_affairs_fields WHERE id = {$idF1}");
t_check('previous designation removed', $rowF1 !== null && $rowF1['is_reporting_date'] === null);

/* non-date rejected */
t_check('money field cannot be reporting date', !(ca_set_reporting_date($mysqli, $catA, $idAmount))['ok']);
t_check('text field cannot be reporting date', !(ca_set_reporting_date($mysqli, $catA, $idDesc))['ok']);

/* cross-category rejected */
$resBcat = t_cat($mysqli, ['name' => 'سفارشات آینده']);
$catB = (int) ($resBcat['data']['id'] ?? 0);
$fBDate = t_field($mysqli, ['category_id' => $catB, 'label' => 'تاریخ سفارش', 'field_type' => 'date']);
$idBDate = (int) ($fBDate['data']['id'] ?? 0);
$repCross = ca_set_reporting_date($mysqli, $catA, $idBDate);
t_check('cross-category reporting date rejected', !$repCross['ok']);
$repCross2 = ca_set_reporting_date($mysqli, $catB, $idDesc);
t_check('cross-category (cat B to field A) rejected', !$repCross2['ok']);

/* database-level one-per-category enforcement (raw SQL must fail loudly) */
$dupCaught = false;
try {
    $mysqli->query("UPDATE current_affairs_fields SET is_reporting_date = 1 WHERE id = {$idF1}");
} catch (Throwable $e) {
    $dupCaught = true;
}
t_check('DB unique key rejects a second active reporting date', $dupCaught);
$checkCaught = false;
try {
    $mysqli->query("UPDATE current_affairs_fields SET is_reporting_date = 1 WHERE id = {$idDesc}");
} catch (Throwable $e) {
    $checkCaught = true;
}
t_check('DB CHECK rejects Non-Date reporting date (raw)', $checkCaught);

/* deactivating the reporting date clears it */
$deact = ca_field_set_active($mysqli, $idF2, false);
t_check('deactivating reporting date clears designation', $deact['ok'] && ($deact['data']['is_reporting_date'] ?? null) === null);
t_check('no valid reporting date after deactivation', ca_reporting_field($mysqli, $catA) === null);

/* reactivating does NOT restore designation automatically */
ca_field_set_active($mysqli, $idF2, true);
t_check('reactivation does not auto-restore designation', ca_reporting_field($mysqli, $catA) === null);

/* designation inside field_save (create date field with is_reporting_date) */
$fNewRep = t_field($mysqli, ['category_id' => $catA, 'label' => 'تاریخ گزارش', 'field_type' => 'date', 'is_reporting_date' => true, 'sort_order' => 10]);
$idNewRep = (int) ($fNewRep['data']['id'] ?? 0);
t_check('field_save designates reporting date', $fNewRep['ok'] && $idNewRep > 0);
$repRow = ca_reporting_field($mysqli, $catA);
t_check('designate via field_save honors new field', $repRow !== null && (int) $repRow['id'] === $idNewRep);

/* field_save cannot designate an inactive field */
$fBadRep = t_field($mysqli, ['category_id' => $catA, 'label' => 'تاریخ بد', 'field_type' => 'date', 'is_active' => false, 'is_reporting_date' => true]);
t_check('inactive field cannot be designated', !$fBadRep['ok']);

/* field deletion rules */
$fTmp = t_field($mysqli, ['category_id' => $catA, 'label' => 'فیلد بدون مقدار', 'field_type' => 'text']);
$idTmpF = (int) ($fTmp['data']['id'] ?? 0);
t_check('empty field deletable', (ca_field_delete($mysqli, $idTmpF))['ok']);
t_check('field delete of missing id rejected', !(ca_field_delete($mysqli, 999999))['ok']);

/* ============================================================
 * 3) Records — CRUD + validation + value preservation
 * ========================================================== */
$dateStr = function (int $jy, int $jm, int $jd): string {
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
};

t_check('required field enforced', !(t_record($mysqli, null, $catA, [
    'payment' => '',
]))['ok']);

t_check('bad date rejected', !(t_record($mysqli, null, $catA, [
    (string) $idDesc => 'x',
    (string) $idF2 => '2026-13-40',
]))['ok']);

t_check('bad money rejected', !(t_record($mysqli, null, $catA, [
    (string) $idDesc => 'x',
    (string) $idAmount => 'abc',
]))['ok']);

t_check('bad select rejected', !(t_record($mysqli, null, $catA, [
    (string) $idDesc => 'x',
    (string) $idType => 'zzz',
]))['ok']);

t_check('bad yesno rejected', !(t_record($mysqli, null, $catA, [
    (string) $idDesc => 'x',
    (string) $idDone => '2',
]))['ok']);

t_check('missing required field rejected', !(t_record($mysqli, null, $catA, [
    (string) $idF2 => $dateStr(1404, 4, 15),
]))['ok']);

/* full record */
$fullValues = [
    (string) $idF1 => $dateStr(1404, 4, 10),
    (string) $idF2 => $dateStr(1404, 4, 15),
    (string) $idF3 => $dateStr(1404, 4, 20),
    (string) $idDesc => 'خرید لوازم',
    (string) $idAmount => '50000',
    (string) $idQty => '12',
    (string) $idNotes => 'یادداشت اولیه',
    (string) $idMemo => 'داخلی ۱',
    (string) $idType => 'الف',
    (string) $idDone => '1',
];
$r1 = t_record($mysqli, null, $catA, $fullValues);
$rec1 = (int) ($r1['data']['id'] ?? 0);
t_check('record created', $r1['ok'] && $rec1 > 0);

$g1 = ca_record_get($mysqli, $rec1);
t_check('record values round-trip (money)', $g1 !== null && ($g1['values'][$idAmount]['value_number'] ?? '') === '50000.00');
t_check('record values round-trip (date)', $g1 !== null && ($g1['values'][$idF2]['value_date'] ?? '') === $dateStr(1404, 4, 15));
t_check('record values round-trip (select)', $g1 !== null && ($g1['values'][$idType]['value_text'] ?? '') === 'الف');
t_check('record values round-trip (yesno)', $g1 !== null && ($g1['values'][$idDone]['value_text'] ?? '') === '1');

/* update */
$fullValues[(string) $idAmount] = '60000';
$r2 = t_record($mysqli, $rec1, $catA, $fullValues);
t_check('record updated', $r2['ok']);
$g2 = ca_record_get($mysqli, $rec1);
t_check('updated money persisted', $g2 !== null && ($g2['values'][$idAmount]['value_number'] ?? '') === '60000.00');

/* cross-category update rejected */
t_check('cross-category record update rejected', !(t_record($mysqli, $rec1, $catB, $fullValues))['ok']);

/* delete then recreate for later tests */
t_check('record deleted', (ca_record_delete($mysqli, $rec1))['ok']);
t_check('deleting missing record rejected', !(ca_record_delete($mysqli, 999999))['ok']);

/* type change blocked once values exist */
$tmpRec = t_record($mysqli, null, $catA, [(string) $idDesc => 'x', (string) $idQty => '3']);
t_check('temp record (qty=3) created', $tmpRec['ok']);
$typeChange = t_field($mysqli, ['id' => $idQty, 'category_id' => $catA, 'label' => 'تعداد', 'field_type' => 'text', 'is_active' => true]);
t_check('type change with historical values rejected', !$typeChange['ok']);

/* field with values not deletable */
t_check('field with values not deletable', !(ca_field_delete($mysqli, $idQty))['ok']);

/* historical values preserved when a field is deactivated + record edited */
$recPreserve = t_record($mysqli, null, $catA, [(string) $idDesc => 'نگهداشت', (string) $idNotes => 'n-hist', (string) $idQty => '7']);
$idPreserve = (int) ($recPreserve['data']['id'] ?? 0);
t_check('preserve-test record created', $recPreserve['ok'] && $idPreserve > 0);
ca_field_set_active($mysqli, $idNotes, false);
$editPreserve = t_record($mysqli, $idPreserve, $catA, [(string) $idDesc => 'نگهداشت ۲', (string) $idQty => '8']);
t_check('preserve-test record edited', $editPreserve['ok']);
$gP = ca_record_get($mysqli, $idPreserve);
t_check('deactivated field value preserved after edit', $gP !== null && ($gP['values'][$idNotes]['value_text'] ?? '') === 'n-hist');
$hasNotesInActive = false;
foreach (ca_record_fields($mysqli, $catA, true) as $af) {
    if ((int) $af['id'] === $idNotes) {
        $hasNotesInActive = true;
    }
}
t_check('deactivated field excluded from active forms', !$hasNotesInActive);

/* ============================================================
 * 4) Search / filter / sort / pagination / totals
 * ========================================================== */
$resL = t_cat($mysqli, ['name' => 'دسته لیست']);
$catL = (int) ($resL['data']['id'] ?? 0);
$lDate  = t_field($mysqli, ['category_id' => $catL, 'label' => 'تاریخ', 'field_type' => 'date', 'is_filterable' => true]);
$lLabel = t_field($mysqli, ['category_id' => $catL, 'label' => 'شرح', 'field_type' => 'text', 'is_required' => true, 'is_filterable' => true]);
$lAmount= t_field($mysqli, ['category_id' => $catL, 'label' => 'مبلغ', 'field_type' => 'money', 'include_in_totals' => true, 'is_filterable' => true]);
$lNumber= t_field($mysqli, ['category_id' => $catL, 'label' => 'شماره', 'field_type' => 'number', 'include_in_totals' => true, 'is_filterable' => true]);
$lKind  = t_field($mysqli, ['category_id' => $catL, 'label' => 'نوع', 'field_type' => 'select', 'is_filterable' => true, 'options' => [['value' => 'x'], ['value' => 'y']]]);
$lOk    = t_field($mysqli, ['category_id' => $catL, 'label' => 'وضعیت', 'field_type' => 'yesno', 'is_filterable' => true]);
$idLDate = (int) ($lDate['data']['id'] ?? 0);
$idLLabel = (int) ($lLabel['data']['id'] ?? 0);
$idLAmount = (int) ($lAmount['data']['id'] ?? 0);
$idLNumber = (int) ($lNumber['data']['id'] ?? 0);
$idLKind = (int) ($lKind['data']['id'] ?? 0);
$idLOk = (int) ($lOk['data']['id'] ?? 0);

$labels = [
    1 => 'خرید سوخت', 2 => 'فروش قلم', 3 => 'خرید کاغذ', 4 => 'فروش میز',
    5 => 'خرید سوخت', 6 => 'فروش قلم', 7 => 'خرید کاغذ', 8 => 'فروش میز',
    9 => 'خرید سوخت', 10 => 'فروش قلم', 11 => 'خرید کاغذ', 12 => 'فروش میز',
    13 => 'خرید سوخت', 14 => 'فروش قلم', 15 => 'خرید کاغذ',
];
$createdIds = [];
for ($n = 1; $n <= 15; $n++) {
    $res = t_record($mysqli, null, $catL, [
        (string) $idLDate => $dateStr(1405, 4, $n),
        (string) $idLLabel => $labels[$n],
        (string) $idLAmount => (string) ($n * 10),
        (string) $idLNumber => (string) $n,
        (string) $idLKind => ($n % 2 === 1 ? 'x' : 'y'),
        (string) $idLOk => ($n % 3 === 0 ? '1' : '0'),
    ]);
    $createdIds[] = (int) ($res['data']['id'] ?? 0);
}
t_check('15 list records created', count(array_filter($createdIds)) === 15);
$valueCounts = ca_field_values_counts($mysqli, [$idLAmount, $idLNumber, 999999]);
t_check('field values-count helper (15 money values)', ($valueCounts[$idLAmount] ?? -1) === 15);
t_check('field values-count helper (15 number values)', ($valueCounts[$idLNumber] ?? -1) === 15);
t_check('unknown field values-count yields no key', !array_key_exists(999999, $valueCounts));

$listAll = ca_records_result($mysqli, ['category_id' => $catL, 'page' => 1, 'per_page' => 10, 'sort' => '__id', 'dir' => 'asc']);
t_check('total = 15', $listAll['ok'] && $listAll['total'] === 15);
$totalAmount = null;
$totalNumber = null;
foreach (($listAll['totals'] ?? []) as $tt) {
    if ((int) $tt['field_id'] === $idLAmount) { $totalAmount = $tt['value']; }
    if ((int) $tt['field_id'] === $idLNumber) { $totalNumber = $tt['value']; }
}
t_check('amount total 1,200', $totalAmount === '1,200', (string) $totalAmount);
t_check('number total 120', $totalNumber === '120', (string) $totalNumber);
t_check('page 1 of 2 (10 rows)', count($listAll['rows'] ?? []) === 10 && $listAll['total_pages'] === 2);
t_check('range start/end', ($listAll['range_start'] ?? 0) === 1 && ($listAll['range_end'] ?? 0) === 10);

/* pagination is server-side: page 2 has 5 rows */
$listPage2 = ca_records_result($mysqli, ['category_id' => $catL, 'page' => 2, 'per_page' => 10]);
t_check('page 2 has 5 rows', $listPage2['ok'] && count($listPage2['rows'] ?? []) === 5);

/* totals cover the whole filtered set, not the page */
$totalAmountPage2 = null;
foreach (($listPage2['totals'] ?? []) as $tt) {
    if ((int) $tt['field_id'] === $idLAmount) { $totalAmountPage2 = $tt['value']; }
}
t_check('totals on page 2 still cover all 15 records', $totalAmountPage2 === '1,200', (string) $totalAmountPage2);

/* search */
$searchSookht = ca_records_result($mysqli, ['category_id' => $catL, 'search' => 'سوخت', 'per_page' => 50]);
t_check('word search "سوخت" → 4 records', $searchSookht['ok'] && $searchSookht['total'] === 4);
$searchGalam = ca_records_result($mysqli, ['category_id' => $catL, 'search' => 'قلم', 'per_page' => 50]);
t_check('word search "قلم" → 4 records', $searchGalam['ok'] && $searchGalam['total'] === 4);
$searchTwoWords = ca_records_result($mysqli, ['category_id' => $catL, 'search' => 'خرید کاغذ', 'per_page' => 50]);
t_check('multi-word search AND semantics → 4 records', $searchTwoWords['ok'] && $searchTwoWords['total'] === 4);

/* amount range filter */
$filterAmount = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => [(string) $idLAmount => ['from' => '50', 'to' => '100']], 'per_page' => 50]);
t_check('amount filter 50..100 → 6 records', $filterAmount['ok'] && $filterAmount['total'] === 6);
$ta = null;
foreach (($filterAmount['totals'] ?? []) as $tt) { if ((int) $tt['field_id'] === $idLAmount) { $ta = $tt['value']; } }
t_check('amount filter totals = 450', $ta === '450', (string) $ta);

/* text contains filter */
$filterText = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => [(string) $idLLabel => ['value' => 'قلم']], 'per_page' => 50]);
t_check('text contains filter → 4 records', $filterText['ok'] && $filterText['total'] === 4);

/* date range filter */
$filterDate = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => [(string) $idLDate => ['from' => $dateStr(1405, 4, 1), 'to' => $dateStr(1405, 4, 5)]], 'per_page' => 50]);
t_check('date range filter 1..5 → 5 records', $filterDate['ok'] && $filterDate['total'] === 5);

/* select filter */
$filterKind = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => [(string) $idLKind => ['value' => 'x']], 'per_page' => 50]);
t_check('select filter kind=x → 8 records', $filterKind['ok'] && $filterKind['total'] === 8);

/* yesno filter */
$filterOk = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => [(string) $idLOk => ['value' => '1']], 'per_page' => 50]);
t_check('yesno filter ok=1 → 5 records', $filterOk['ok'] && $filterOk['total'] === 5);

/* combined search + filter */
$combo = ca_records_result($mysqli, ['category_id' => $catL, 'search' => 'خرید', 'filters' => [(string) $idLKind => ['value' => 'x']], 'per_page' => 50]);
t_check('combined search+filter "خرید" and kind=x → 8 records', $combo['ok'] && $combo['total'] === 8);

/* unsafe/unknown filter field ignored */
$unknownFilter = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => ['987654' => ['value' => 'x']], 'per_page' => 50]);
t_check('unknown filter field ignored', $unknownFilter['ok'] && $unknownFilter['total'] === 15);
$nonFilterable = ca_records_result($mysqli, ['category_id' => $catL, 'filters' => [(string) $idLAmount => ['value' => 'x']], 'per_page' => 50]);
t_check('non-filterable spec type ignored', $nonFilterable['ok'] && $nonFilterable['total'] === 15);

/* unsafe sort keys fall back to a safe default */
$badSort = ca_records_result($mysqli, ['category_id' => $catL, 'sort' => 'label; DROP TABLE products', 'dir' => 'asc', 'per_page' => 50]);
t_check('unsafe sort key falls back safely', $badSort['ok'] && $badSort['total'] === 15);
$badSort2 = ca_records_result($mysqli, ['category_id' => $catL, 'sort' => "f'", 'dir' => 'asc', 'per_page' => 50]);
t_check('sort with bad field id falls back safely', $badSort2['ok'] && $badSort2['total'] === 15);

/* sort by money field asc/desc */
$sortAsc = ca_records_result($mysqli, ['category_id' => $catL, 'sort' => 'f' . $idLAmount, 'dir' => 'asc', 'per_page' => 50]);
$firstAsc = $sortAsc['rows'][0]['f' . $idLAmount]['v'] ?? '';
t_check('sort by amount asc → first is 10', $firstAsc === '10', $firstAsc);
$sortDesc = ca_records_result($mysqli, ['category_id' => $catL, 'sort' => 'f' . $idLAmount, 'dir' => 'desc', 'per_page' => 50]);
$firstDesc = $sortDesc['rows'][0]['f' . $idLAmount]['v'] ?? '';
t_check('sort by amount desc → first is 150', $firstDesc === '150', $firstDesc);

/* ============================================================
 * 5) Monthly Reporting
 * ========================================================== */
/* 5a — month boundary math (via jdf, verified against known calendars) */
$bounds1405_4 = ca_jalali_month_bounds(1405, 4);
t_check('Farvardin month = 31 days', $bounds1405_4['days'] === 31 && $bounds1405_4['end_jalali'] === '۱۴۰۵/۰۴/۳۱', $bounds1405_4['end_jalali']);
$bounds1405_7 = ca_jalali_month_bounds(1405, 7);
t_check('Mehr month = 30 days', $bounds1405_7['days'] === 30 && $bounds1405_7['end_jalali'] === '۱۴۰۵/۰۷/۳۰', $bounds1405_7['end_jalali']);
$bounds1404_12 = ca_jalali_month_bounds(1404, 12);
t_check('Esfand 1404 (normal) = 29 days', $bounds1404_12['days'] === 29 && $bounds1404_12['end_jalali'] === '۱۴۰۴/۱۲/۲۹', $bounds1404_12['end_jalali']);
$bounds1403_12 = ca_jalali_month_bounds(1403, 12);
t_check('Esfand 1403 (leap) = 30 days', $bounds1403_12['days'] === 30 && $bounds1403_12['end_jalali'] === '۱۴۰۳/۱۲/۳۰', $bounds1403_12['end_jalali']);

/* 5b — no reporting date: report refused */
if (ca_reporting_field($mysqli, $catA) !== null) {
    $curRep = ca_reporting_field($mysqli, $catA);
    ca_clear_reporting_date($mysqli, $catA, (int) $curRep['id']);
}
$monthlyNoRep = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 4]);
t_check('monthly refused when no reporting date', !$monthlyNoRep['ok'] && (bool) ($monthlyNoRep['no_reporting_date'] ?? false));

/* 5c — month reporting uses ONLY the designated date field */
ca_set_reporting_date($mysqli, $catA, $idF1);
$mRecords = [];
$mDefs = [
    [1405, 4, 5, 1405, 6, 5],   // M1 f_date1=1405/04, f_date2=1405/06
    [1405, 5, 5, 1405, 4, 5],   // M2 f_date1=1405/05, f_date2=1405/04
    [1403, 12, 30, 1404, 1, 10], // M3 (Esfand leap end)
    [1404, 1, 1, 1403, 12, 20],  // M4 (year boundary, Farvardin 1st)
    [1403, 12, 1, 1403, 12, 5],  // M5 (exact month start)
    [1403, 12, 25, 1404, 1, 2],  // M6
];
foreach ($mDefs as $i => $d) {
    $resM = t_record($mysqli, null, $catA, [
        (string) $idF1 => $dateStr($d[0], $d[1], $d[2]),
        (string) $idF2 => $dateStr($d[3], $d[4], $d[5]),
        (string) $idDesc => 'گزارش ' . ($i + 1),
    ]);
    $mRecords[] = (int) ($resM['data']['id'] ?? 0);
}
t_check('6 monthly records created', count(array_filter($mRecords)) === 6);

$rep1405_4 = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 4, 'per_page' => 10]);
t_check('monthly 1405/04 (reporting=f_date1) → 1 record', $rep1405_4['ok'] && $rep1405_4['total'] === 1);
$rep1405_5 = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 5, 'per_page' => 10]);
t_check('monthly 1405/05 → 1 record (M2)', $rep1405_5['ok'] && $rep1405_5['total'] === 1);
$rep1403_12 = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1403, 'month' => 12, 'per_page' => 10]);
t_check('monthly 1403/12 → 3 records (M3,M5,M6; not M4)', $rep1403_12['ok'] && $rep1403_12['total'] === 3);

/* year boundary: M4 is 1404/01/01, must NOT appear in 1403/12 */
$idsInRep = [];
foreach (($rep1403_12['rows'] ?? []) as $rr) {
    $idsInRep[] = (int) ($rr['id'] ?? 0);
}
t_check('year boundary excludes Farvardin day 1', !in_array((int) $mRecords[3], $idsInRep, true));
$rep1404_1 = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1404, 'month' => 1, 'per_page' => 10]);
t_check('monthly 1404/01 → exactly M4', $rep1404_1['ok'] && $rep1404_1['total'] === 1
    && isset($rep1404_1['rows'][0]) && (int) $rep1404_1['rows'][0]['id'] === (int) $mRecords[3]);

/* empty month */
$repEmpty = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 11, 'per_page' => 10]);
t_check('empty month → 0 records', $repEmpty['ok'] && $repEmpty['total'] === 0 && $repEmpty['rows'] === []);

/* reassignment changes the reporting source */
ca_set_reporting_date($mysqli, $catA, $idF2);
$repAfter_4 = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 4, 'per_page' => 10]);
t_check('after reassign to f_date2, 1405/04 → M2 (f_date2 April)', $repAfter_4['ok'] && $repAfter_4['total'] === 1
    && isset($repAfter_4['rows'][0]) && (int) $repAfter_4['rows'][0]['id'] === (int) $mRecords[1]);
$repAfter_6 = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 6, 'per_page' => 10]);
t_check('after reassign, 1405/06 → M1 (f_date2 June)', $repAfter_6['ok'] && $repAfter_6['total'] === 1
    && isset($repAfter_6['rows'][0]) && (int) $repAfter_6['rows'][0]['id'] === (int) $mRecords[0]);

/* monthly detail pagination + totals across pages (catL, 15 records in 1405/04) */
ca_set_reporting_date($mysqli, $catL, $idLDate);
$mCatL = ca_monthly_report($mysqli, ['category_id' => $catL, 'year' => 1405, 'month' => 4, 'per_page' => 10, 'page' => 1]);
t_check('monthly catL page1 → 10 rows of 15', $mCatL['ok'] && $mCatL['total'] === 15 && count($mCatL['rows']) === 10 && $mCatL['total_pages'] === 2);
$mCatLAmount = null;
foreach (($mCatL['totals'] ?? []) as $tt) { if ((int) $tt['field_id'] === $idLAmount) { $mCatLAmount = $tt['value']; } }
t_check('monthly totals (whole month) on page1 = 1,200', $mCatLAmount === '1,200', (string) $mCatLAmount);
$mCatLp2 = ca_monthly_report($mysqli, ['category_id' => $catL, 'year' => 1405, 'month' => 4, 'per_page' => 10, 'page' => 2]);
t_check('monthly page2 → 5 rows', $mCatLp2['ok'] && count($mCatLp2['rows']) === 5);
$mCatLAmountP2 = null;
foreach (($mCatLp2['totals'] ?? []) as $tt) { if ((int) $tt['field_id'] === $idLAmount) { $mCatLAmountP2 = $tt['value']; } }
t_check('monthly totals on page2 still = 1,200', $mCatLAmountP2 === '1,200', (string) $mCatLAmountP2);

/* deactivated reporting date blocks monthly reporting */
ca_field_set_active($mysqli, $idNewRep, true);            /* ensure an active date exists for designation */
ca_set_reporting_date($mysqli, $catA, $idF1);
ca_field_set_active($mysqli, $idF1, false);              /* deactivate the designee → clears */
$monthlyAfterDeact = ca_monthly_report($mysqli, ['category_id' => $catA, 'year' => 1405, 'month' => 4]);
t_check('monthly blocked after deactivating the designated date', !$monthlyAfterDeact['ok'] && (bool) ($monthlyAfterDeact['no_reporting_date'] ?? false));

/* ============================================================
 * 6) Excel export datasets (complete, never just the page)
 * ========================================================== */
$expL = ca_export_category($mysqli, ['category_id' => $catL]);
t_check('category export ok', $expL['ok']);
t_check('category export has all 15 rows', count($expL['rows'] ?? []) === 15);
t_check('category export has base columns', isset($expL['columns']['row']) && isset($expL['columns']['_id']) && isset($expL['columns']['_created']));
$nameAmount = (string) ($lAmount['data']['name'] ?? '');
t_check('category export includes money column', $expL['ok'] && isset($expL['columns'][$nameAmount]));
t_check('money column label mentions ریال', isset($expL['columns'][$nameAmount])
    && str_contains((string) $expL['columns'][$nameAmount], 'ریال'));
t_check('money column is numeric-keyed', in_array($nameAmount, $expL['number_keys'] ?? [], true));
t_check('money cell exported as raw number', ($expL['rows'][0][$nameAmount] ?? '') === '10', (string) ($expL['rows'][0][$nameAmount] ?? ''));

/* export includes deactivated fields (historical data completeness) */
$expA = ca_export_category($mysqli, ['category_id' => $catA]);
$nameNotes = (string) ($fNotes['data']['name'] ?? '');
t_check('category export includes deactivated field column', isset($expA['columns'][$nameNotes]));

$expAll = ca_export_all($mysqli);
t_check('all-category export ok', $expAll['ok']);
t_check('all-category export covers every category + base cols', isset($expAll['columns']['_category'])
    && count($expAll['rows'] ?? []) >= 15, (string) count($expAll['rows'] ?? []));

$expM = ca_export_monthly($mysqli, $catL, 1405, 4);
t_check('monthly export ok (15 rows)', $expM['ok'] && count($expM['rows'] ?? []) === 15);
$expMEmpty = ca_export_monthly($mysqli, $catL, 1405, 11);
t_check('monthly export of empty month = 0 rows', $expMEmpty['ok'] && count($expMEmpty['rows'] ?? []) === 0);
$expMNoRep = ca_export_monthly($mysqli, $catB, 1405, 4);
t_check('monthly export refused without reporting date', !$expMNoRep['ok'] && (bool) ($expMNoRep['no_reporting_date'] ?? false));

/* monthly export honors reassigned reporting date (catA rep=f_date2 after reassign) */
ca_set_reporting_date($mysqli, $catA, $idF2);
$expMAfter = ca_export_monthly($mysqli, $catA, 1405, 4);
t_check('monthly export uses reassigned date field (1 row)', $expMAfter['ok'] && count($expMAfter['rows'] ?? []) === 1);

/* ============================================================
 * 7) Fix-regression checks (audit hardening)
 * ========================================================== */
/* one save = one row (client duplicate-prevention is browser-side; this
 * proves the backend mutation itself inserts exactly one row per call) */
$resOnce = t_cat($mysqli, ['name' => 'یک‌تا']);
$onceCat = (int) ($resOnce['data']['id'] ?? 0);
if ($onceCat > 0) {
    t_check('single cat_save creates exactly one category row', t_count($mysqli, "SELECT COUNT(*) AS c FROM current_affairs_categories WHERE id = {$onceCat}") === 1);
}
$resFieldOnce = t_field($mysqli, ['category_id' => $onceCat, 'label' => 'قلم یک‌تا', 'field_type' => 'text']);
$onceFieldId = (int) ($resFieldOnce['data']['id'] ?? 0);
if ($onceFieldId > 0) {
    t_check('single field_save creates exactly one field row', t_count($mysqli, "SELECT COUNT(*) AS c FROM current_affairs_fields WHERE id = {$onceFieldId}") === 1);
}

/* 8a — the Reporting Date used simultaneously as a record filter for the
 * same query must not cause an alias collision (vr vs v<id>). */
$repLf = ca_reporting_field($mysqli, $catL);
if ($repLf !== null) {
    $collision = ca_records_result($mysqli, [
        'category_id' => $catL,
        'rep_field_id' => (int) $repLf['id'],
        'date_from' => $dateStr(1405, 4, 1),
        'date_to' => $dateStr(1405, 4, 31),
        'filters' => [(string) $repLf['id'] => ['from' => $dateStr(1405, 4, 1), 'to' => $dateStr(1405, 4, 5)]],
        'per_page' => 50,
    ]);
    t_check('rep date + same-field filter do not alias-collide', $collision['ok'] && $collision['total'] === 5);
} else {
    t_check('rep date + same-field filter do not alias-collide', false, 'no rep field on catL');
}

/* 8b — editing a record preserves a Select value whose option was removed. */
$resLegacyCat = t_cat($mysqli, ['name' => 'دسته Legacy']);
$legacyCat = (int) ($resLegacyCat['data']['id'] ?? 0);
$fLegacy = t_field($mysqli, ['category_id' => $legacyCat, 'label' => 'وضعیت سفارش', 'field_type' => 'select', 'options' => [['value' => 'جدید'], ['value' => 'ارسال شده']]]);
$idLegacy = (int) ($fLegacy['data']['id'] ?? 0);
$recLegacy = t_record($mysqli, null, $legacyCat, [(string) $idLegacy => 'جدید']);
$idRecLegacy = (int) ($recLegacy['data']['id'] ?? 0);
t_check('legacy select test record created', $recLegacy['ok'] && $idRecLegacy > 0);
$saveField2 = t_field($mysqli, ['id' => $idLegacy, 'category_id' => $legacyCat, 'label' => 'وضعیت سفارش', 'field_type' => 'select', 'options' => [['value' => 'ارسال شده']]]);
t_check('select field re-saved without old option', $saveField2['ok']);
$editLegacy = t_record($mysqli, $idRecLegacy, $legacyCat, [(string) $idLegacy => 'جدید']);
t_check('editing record with removed option value is allowed (unchanged legacy)', $editLegacy['ok']);
$badLegacy2 = t_record($mysqli, $idRecLegacy, $legacyCat, [(string) $idLegacy => 'ناموجود']);
t_check('editing to a non-existent option still rejected', !$badLegacy2['ok']);

/* ============================================================
 * 8) Teardown + summary
 * ========================================================== */
@unlink(__DIR__ . '/../db_ca_patch.php');
$mysqli->query('DROP DATABASE IF EXISTS memar_accounting_ca_test');
$mysqli->close();

echo "\n" . count($tests) . " assertions, " . $failed . " failed.\n";
if ($failed > 0) {
    echo "CURRENT AFFAIRS TESTS FAILED\n";
    exit(1);
}
echo "CURRENT AFFAIRS TESTS OK\n";
exit(0);