<?php
/**
 * Category Excel export. Uses the same UTF-8 XML Spreadsheet 2003 format
 * as export_products.php; no external spreadsheet dependency.
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

function category_export_escape($value)
{
    return htmlspecialchars((string) ($value === null ? '' : $value), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$result = $mysqli->query('SELECT c.code,c.name,p.code AS parent_code
    FROM product_categories c
    LEFT JOIN product_categories p ON p.id=c.parent_id
    ORDER BY c.id');

$out = "\xEF\xBB\xBF";
$out .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$out .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$out .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
      . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" '
      . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
      . 'xmlns:x="urn:schemas-microsoft-com:office:excel">' . "\n";
$out .= '<Styles>'
      . '<Style ss:ID="Default"><Font ss:FontName="Tahoma" ss:Size="11"/></Style>'
      . '<Style ss:ID="Header"><Font ss:FontName="Tahoma" ss:Bold="1" ss:Size="11"/>'
      . '<Interior ss:Color="#E7F0FF" ss:Pattern="Solid"/></Style></Styles>' . "\n";
$out .= '<Worksheet ss:Name="Categories"><Table>' . "\n";
$out .= '<Column ss:Width="130"/><Column ss:Width="260"/><Column ss:Width="150"/>' . "\n";
$out .= '<Row>';
foreach (array('کد گروه', 'نام گروه', 'کد گروه مادر') as $header) {
    $out .= '<Cell ss:StyleID="Header"><Data ss:Type="String">'
          . category_export_escape($header) . '</Data></Cell>';
}
$out .= '</Row>' . "\n";
while ($row = $result->fetch_assoc()) {
    $out .= '<Row>';
    foreach (array($row['code'], $row['name'], $row['parent_code']) as $value) {
        // Always use String to retain leading zeros and prevent Excel formula evaluation.
        $out .= '<Cell><Data ss:Type="String">'
              . category_export_escape($value) . '</Data></Cell>';
    }
    $out .= '</Row>' . "\n";
}
$result->free();
$out .= '</Table></Worksheet></Workbook>' . "\n";

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="Categories_' . date('Y-m-d_His') . '.xls"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: no-store');
echo $out;
exit;
