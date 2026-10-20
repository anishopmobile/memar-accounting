<?php
require_once __DIR__ . '/db.php';

/* ---------- دریافت لیست کامل محصولات به همراه نام دسته‌بندی ---------- */
$products = [];
$result = $mysqli->query('
    SELECT p.*, pc.name AS category_name
    FROM products p
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    ORDER BY CAST(p.code AS UNSIGNED)
');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $result->free();
}

/* ---------- واحدهای اندازه‌گیری هر کالا (برگه دوم) ---------- */
$unitRows = [];
$result = $mysqli->query('
    SELECT pu.id AS unit_id, pu.name AS unit_name, pu.conversion_factor, pu.is_base,
           pu.purchase_price AS pu_purchase_price, pu.sale_price AS pu_sale_price,
           pu.barcode AS pu_barcode, p.code AS product_code, p.name AS product_name
    FROM product_units pu
    JOIN products p ON p.id = pu.product_id
    ORDER BY CAST(p.code AS UNSIGNED), pu.is_base DESC, pu.sort_order ASC, pu.id ASC
');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $unitRows[] = $row;
    }
    $result->free();
}

$typeLabels = ['product' => 'محصول', 'service' => 'خدمت'];
$columns = [
    'code'           => 'کد کالا',
    'barcode'        => 'بارکد',
    'name'           => 'نام کالا',
    'category_name'  => 'دسته‌بندی',
    'type'           => 'نوع',
    'unit'           => 'واحد',
    'purchase_price' => 'قیمت خرید',
    'sale_price'     => 'قیمت فروش',
    'stock'          => 'موجودی',
    'min_stock'      => 'حداقل موجودی',
    'description'    => 'توضیحات',
];

function exportExcelEncode($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* ---------- ساخت فایل اکسل (فرمت 2003 XML با پشتیبانی کامل از UTF-8 و فونت Tahoma) ---------- */
$out = '';
$out .= "\xEF\xBB\xBF"; // BOM برای تشخیص صحیح UTF-8 توسط اکسل
$out .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$out .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$out .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$out .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
$out .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$out .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$out .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
$out .= ' <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">' . "\n";
$out .= '  <Title>' . exportExcelEncode('گزارش کالاها و خدمات') . '</Title>' . "\n";
$out .= '  <Author>' . exportExcelEncode('کوشا حساب') . '</Author>' . "\n";
$out .= ' </DocumentProperties>' . "\n";
$out .= ' <Styles>' . "\n";
$out .= '  <Style ss:ID="Default">' . "\n";
$out .= '   <Font ss:FontName="Tahoma" ss:Size="11"/>' . "\n";
$out .= '   <Alignment ss:Vertical="Center"/>' . "\n";
$out .= '  </Style>' . "\n";
$out .= '  <Style ss:ID="Title">' . "\n";
$out .= '   <Font ss:FontName="Tahoma" ss:Size="14" ss:Bold="1" ss:Color="#0B3A8C"/>' . "\n";
$out .= '   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
$out .= '  </Style>' . "\n";
$out .= '  <Style ss:ID="Header">' . "\n";
$out .= '   <Font ss:FontName="Tahoma" ss:Size="11" ss:Bold="1" ss:Color="#0B3A8C"/>' . "\n";
$out .= '   <Interior ss:Color="#E7F0FF" ss:Pattern="Solid"/>' . "\n";
$out .= '   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
$out .= '  </Style>' . "\n";
$out .= '  <Style ss:ID="CellRTL">' . "\n";
$out .= '   <Font ss:FontName="Tahoma" ss:Size="11"/>' . "\n";
$out .= '   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . "\n";
$out .= '  </Style>' . "\n";
$out .= '  <Style ss:ID="CellLTR">' . "\n";
$out .= '   <Font ss:FontName="Tahoma" ss:Size="11"/>' . "\n";
$out .= '   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
$out .= '  </Style>' . "\n";
$out .= ' </Styles>' . "\n";
$out .= ' <Worksheet ss:Name="Products">' . "\n";
$out .= '  <Table>' . "\n";

foreach ($columns as $label) {
    $out .= '   <Column/>' . "\n";
}

/* ردیف سرتیتر (عنوان گزارش) */
$totalCols = count($columns);
$out .= '   <Row ss:Height="30">' . "\n";
$out .= '    <Cell ss:MergeAcross="' . ($totalCols - 1) . '" ss:StyleID="Title"><Data ss:Type="String">' . exportExcelEncode('گزارش کالاها و خدمات') . '</Data></Cell>' . "\n";
for ($i = 1; $i < $totalCols; $i++) {
    $out .= '    <Cell/>' . "\n";
}
$out .= '   </Row>' . "\n";

/* ردیف عنوان‌های ستون‌ها */
$out .= '   <Row ss:Height="26">' . "\n";
foreach ($columns as $label) {
    $out .= '    <Cell ss:StyleID="Header"><Data ss:Type="String">' . exportExcelEncode($label) . '</Data></Cell>' . "\n";
}
$out .= '   </Row>' . "\n";

/* ردیف داده‌ها */
foreach ($products as $p) {
    $rowData = [
        $p['code'],
        $p['barcode'],
        $p['name'],
        $p['category_name'] !== null ? $p['category_name'] : '-',
        $typeLabels[$p['type']] ?? 'محصول',
        $p['unit'],
        number_format((float) $p['purchase_price']),
        number_format((float) $p['sale_price']),
        number_format((float) $p['stock']),
        number_format((float) $p['min_stock']),
        $p['description'],
    ];
    $out .= '   <Row>' . "\n";
    foreach ($rowData as $i => $cell) {
        $style = (in_array($i, [2, 3, 5, 10], true)) ? 'CellRTL' : 'CellLTR';
        $value = ($cell === '' || $cell === null) ? '' : $cell;
        $out .= '    <Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . exportExcelEncode($value) . '</Data></Cell>' . "\n";
    }
    $out .= '   </Row>' . "\n";
}

$out .= '  </Table>' . "\n";
$out .= ' </Worksheet>' . "\n";

/* ---------- برگه دوم: واحدهای اندازه‌گیری ---------- */
$unitColumns = [
    'product_code'      => 'کد کالا',
    'product_name'      => 'نام کالا',
    'unit_id'           => 'شماره واحد',
    'unit_name'         => 'نام واحد',
    'conversion_factor' => 'ضریب تبدیل',
    'pu_purchase_price' => 'قیمت خرید واحد',
    'pu_sale_price'     => 'قیمت فروش واحد',
    'pu_barcode'        => 'بارکد واحد',
    'is_base'           => 'واحد پایه',
];
$unitCols = count($unitColumns);
$out .= ' <Worksheet ss:Name="ProductUnits">' . "\n";
$out .= '  <Table>' . "\n";
foreach ($unitColumns as $label) {
    $out .= '   <Column/>' . "\n";
}
/* ردیف عنوان‌ها */
$out .= '   <Row ss:Height="26">' . "\n";
foreach ($unitColumns as $label) {
    $out .= '    <Cell ss:StyleID="Header"><Data ss:Type="String">' . exportExcelEncode($label) . '</Data></Cell>' . "\n";
}
$out .= '   </Row>' . "\n";
foreach ($unitRows as $u) {
    $baseLabel = ((int) $u['is_base'] === 1) ? 'بله' : 'نخیر';
    $rowData = [
        $u['product_code'],
        $u['product_name'],
        $u['unit_id'],
        $u['unit_name'],
        $u['conversion_factor'],
        number_format((float) $u['pu_purchase_price']),
        number_format((float) $u['pu_sale_price']),
        $u['pu_barcode'],
        $baseLabel,
    ];
    $out .= '   <Row>' . "\n";
    foreach ($rowData as $i => $cell) {
        $style = (in_array($i, [0, 1, 3, 7], true)) ? 'CellRTL' : 'CellLTR';
        $value = ($cell === '' || $cell === null) ? '' : $cell;
        $out .= '    <Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . exportExcelEncode($value) . '</Data></Cell>' . "\n";
    }
    $out .= '   </Row>' . "\n";
}
$out .= '  </Table>' . "\n";
$out .= ' </Worksheet>' . "\n";
$out .= '</Workbook>' . "\n";

$filename = 'Products_Report_' . date('Y-m-d_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Cache-Control: max-age=0, must-revalidate');

echo $out;
exit;
