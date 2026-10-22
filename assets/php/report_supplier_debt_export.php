<?php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_excel_lib.php';

require_once __DIR__ . '/ledger/ledger_reports.php';
$search = reportParam('search');
$rows = ledger_parties_report($mysqli, 'supplier', $search);

$columns = [
    'name'            => 'نام / شرکت',
    'phone'           => 'تلفن',
    'address'         => 'آدرس',
    'total_purchases' => 'مجموع خرید',
    'unpaid'          => 'مبلغ پرداخت نشده',
    'credit'          => 'اعتبار فروشگاه نزد تأمین‌کننده',
    'needs_check'     => 'تعداد فاکتورهای نیازمند تطبیق',
];

$exportRows = [];
foreach ($rows as $r) {
    $displayName = trim($r['company_name']) !== ''
        ? $r['company_name']
        : trim($r['first_name'] . ' ' . $r['last_name']);
    $exportRows[] = [
        'name'            => $displayName,
        'phone'           => $r['phone'],
        'address'         => $r['address'],
        'total_purchases' => formatMoneyNumber((string) $r['total_purchases']),
        'unpaid'          => formatMoneyNumber((string) $r['unpaid']),
        'credit'          => formatMoneyNumber((string) $r['credit']),
        'needs_check'     => (int)$r['requires_reconciliation'],
    ];
}

outputReportExcel('گزارش بدهی به تامین‌کنندگان', $columns, $exportRows, 'Report_Supplier_Debt_' . date('Y-m-d_His') . '.xls');