<?php
/**
 * defective_goods_export.php — خروجی اکسل گزارش کالاهای معیوب (GET + احراز هویت)
 *
 * فیلترهای from / to (میلادی Y-m-d از ورودی‌های شمسی) با احترام کامل اعمال می‌شوند.

 * این خروجی مستقل از صفحه‌بندی گزارش است و همه رکوردهای منطبق با فیلتر را
 * صادر می‌کند؛ نه فقط ۲۵ رکورد صفحه جاری.

 * متن فارسی با خروجی SpreadsheetML 2003 (UTF-8 با BOM، فونت Tahoma و تراز راست‌به‌چپ)
 * از طریق report_excel_lib.php حفظ می‌شود.

 * هیچ کلید یا داده‌ی حساسی در این خروجی وجود ندارد.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/defective_goods_common.php';
require_once __DIR__ . '/report_excel_lib.php';
require_once __DIR__ . '/jdf.php';

$filters = dg_extract_date_filters($_GET);
$rows = dg_all_for_export($mysqli, $filters);

$columns = [
    'row'             => 'ردیف',
    'defective_date'  => 'تاریخ (شمسی)',
    'product_name'     => 'نام کالا',
    'product_barcode' => 'بارکد',
    'product_code'    => 'کد کالا',
    'quantity'        => 'تعداد معیوب',
    'reason'           => 'دلیل',
    'description'      => 'توضیحات',
];

$exportRows = [];
$rowNo = 0;
foreach ($rows as $r) {
    $rowNo++;
    $exportRows[] = [
        'row'             => $rowNo,
        'defective_date'  => jdate('Y/m/d', strtotime((string) $r['defective_date'])),
        'product_name'     => $r['product_name'],
        'product_barcode' => $r['product_barcode'] !== null ? $r['product_barcode'] : '-',
        'product_code'    => $r['product_code'],
        'quantity'        => number_format((float) $r['quantity']),
        'reason'           => $r['reason'],
        'description'      => $r['description'] !== null && $r['description'] !== '' ? $r['description'] : '-',
    ];
}

outputReportExcel('گزارش کالاهای معیوب', $columns, $exportRows, 'Defective_Goods_Report_' . date('Y-m-d_His') . '.xls');