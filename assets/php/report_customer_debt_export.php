<?php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_excel_lib.php';

require_once __DIR__ . '/ledger/ledger_reports.php';
$search = reportParam('search');
$rows = ledger_parties_report($mysqli, 'customer', $search);

$columns = [
    'full_name'       => 'نام کامل',
    'phone'           => 'تلفن',
    'address'         => 'آدرس',
    'total_purchases' => 'مجموع خرید',
    'debt'            => 'مجموع بدهی',
    'credit'          => 'اعتبار مشتری',
    'needs_check'     => 'تعداد فاکتورهای نیازمند تطبیق',
];

$exportRows = [];
foreach ($rows as $r) {
    $exportRows[] = [
        'full_name'       => trim($r['first_name'].' '.$r['last_name']),
        'phone'           => $r['phone'],
        'address'         => $r['address'],
        'total_purchases' => formatMoneyNumber((string) $r['total_purchases']),
        'debt'            => formatMoneyNumber((string) $r['debt']),
        'credit'          => formatMoneyNumber((string) $r['credit']),
        'needs_check'     => (int)$r['requires_reconciliation'],
    ];
}

outputReportExcel('گزارش بدهی مشتریان', $columns, $exportRows, 'Report_Customer_Debt_' . date('Y-m-d_His') . '.xls');