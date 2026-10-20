<?php
/**
 * current_affairs_export.php — Current Affairs Excel export (GET + auth).
 *
 * - type=category : the current category, honoring the same search / filters
 *                   used in the records list
 * - type=all      : all categories (wide matrix of all custom fields)
 * - type=monthly  : the complete monthly report dataset (never just the
 *                   current detail page)
 *
 * Every export contains the COMPLETE matching dataset, not the current page.
 * Numeric/money values are kept raw so they remain numeric in Excel.
 * Requires a valid Reporting Date for the monthly export.
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/current_affairs/ca_loader.php';
require_once __DIR__ . '/report_excel_lib.php';

$type = trim((string) ($_GET['type'] ?? 'category'));

if ($type === 'all') {
    $data = ca_export_all($mysqli);
    outputReportExcel(
        $data['title'],
        $data['columns'],
        $data['rows'],
        'current_affairs_all_' . date('Y-m-d_His') . '.xls',
        $data['number_keys']
    );
    exit;
}

if ($type === 'monthly') {
    $categoryId = ca_parse_id($_GET['category_id'] ?? '');
    $year = ca_int_clamp($_GET['year'] ?? 1400, 1300, 1600, 1400);
    $month = ca_int_clamp($_GET['month'] ?? 1, 1, 12, 1);
    $data = ca_export_monthly($mysqli, $categoryId, $year, $month);
    if (!$data['ok']) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $data['message']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    outputReportExcel(
        $data['title'],
        $data['columns'],
        $data['rows'],
        'current_affairs_monthly_' . $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.xls',
        $data['number_keys']
    );
    exit;
}

/* ---- category export (with the same filters as the list) ---- */
$filters = [];
$filtersRaw = (string) ($_GET['filters'] ?? '');
if ($filtersRaw !== '') {
    $decoded = json_decode($filtersRaw, true);
    if (is_array($decoded)) {
        $filters = (array) $decoded;
    }
}
$data = ca_export_category($mysqli, [
    'category_id' => ca_parse_id($_GET['category_id'] ?? ''),
    'search' => (string) ($_GET['search'] ?? ''),
    'filters' => $filters,
]);
if (!$data['ok']) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => $data['message']], JSON_UNESCAPED_UNICODE);
    exit;
}
outputReportExcel(
    $data['title'],
    $data['columns'],
    $data['rows'],
    'current_affairs_export_' . date('Y-m-d_His') . '.xls',
    $data['number_keys']
);