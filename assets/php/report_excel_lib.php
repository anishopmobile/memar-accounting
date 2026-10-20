<?php
/**
 * report_excel_lib.php — ساخت فایل اکسل (فرمت SpreadsheetML 2003) سازگار با زبان فارسی
 *
 * خروجی همراه با BOM، فونت Tahoma، تراز راست‌به‌چپ، ردیف عنوان گزارش (تبدیل‌شده) و
 * ردیف عنوان ستون‌ها تولید می‌شود.
 */

/**
 * کدگذاری امن مقدار برای داخل XML
 */
function reportExcelEncode($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * تشخیص عددی بودن مقدار (برای چینش وسط/معمولی در اکسل)
 */
function reportExcelIsNumeric(string $value): bool
{
    if ($value === '') {
        return false;
    }
    return (bool) preg_match('/^-?[0-9][0-9,\.]*$/', $value);
}

/**
 * تولید و خروجی کامل فایل اکسل (فارسی/راست‌به‌چپ)
 *
 * @param string $title   عنوان گزارش (ردیف اول)
 * @param array  $columns ستون‌ها به شکل [مقدار => عنوان فارسی]
 * @param array  $rows    آرایه‌ای از آرایه‌های انجمنی [مقدار => مقدار سلول]
 * @param string $fileName نام فایل دانلودی
 * @param array  $numberKeys کلیدهای ستون‌های عددی (اختیاری؛ افزودنی سازگار با
 *        فراخوان‌های قبلی). وقتی کلید در این لیست باشد و مقدار عددی باشد، سلول
 *        با ss:Type="Number" صادر می‌شود تا عدد در اکسل عددی بماند.
 */
function outputReportExcel(string $title, array $columns, array $rows, string $fileName, array $numberKeys = []): void
{
    $typeLabels = ['product' => 'محصول', 'service' => 'خدمت'];
    $totalCols = count($columns);

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
    $out .= '  <Title>' . reportExcelEncode($title) . '</Title>' . "\n";
    $out .= '  <Author>' . reportExcelEncode('کوشا حساب') . '</Author>' . "\n";
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
    $out .= ' <Worksheet ss:Name="گزارش">' . "\n";
    $out .= '  <Table>' . "\n";

    foreach ($columns as $key => $label) {
        $out .= '   <Column/>' . "\n";
    }

    /* ردیف سرتیتر (عنوان گزارش) */
    $out .= '   <Row ss:Height="30">' . "\n";
    $out .= '    <Cell ss:MergeAcross="' . ($totalCols - 1) . '" ss:StyleID="Title"><Data ss:Type="String">' . reportExcelEncode($title) . '</Data></Cell>' . "\n";
    for ($i = 1; $i < $totalCols; $i++) {
        $out .= '    <Cell/>' . "\n";
    }
    $out .= '   </Row>' . "\n";

    /* ردیف عنوان ستون‌ها */
    $out .= '   <Row ss:Height="26">' . "\n";
    foreach ($columns as $label) {
        $out .= '    <Cell ss:StyleID="Header"><Data ss:Type="String">' . reportExcelEncode($label) . '</Data></Cell>' . "\n";
    }
    $out .= '   </Row>' . "\n";

    /* ردیف داده‌ها */
    foreach ($rows as $rowData) {
        $out .= '   <Row>' . "\n";
        $i = 0;
        foreach ($columns as $key => $label) {
            $raw = $rowData[$key] ?? '';
            $value = ($raw === '' || $raw === null) ? '' : $raw;
            // ترجمه مقدار نوع کالا اگر با کلید type ارسال شده باشد
            if ($key === 'type' && isset($typeLabels[$value])) {
                $value = $typeLabels[$value];
            }
            // ستون‌های اعلام‌شده عددی، عددی صادر می‌شوند (افزودنی؛ بدون تأثیر بر فراخوان‌های قبلی)
            if (in_array($key, $numberKeys, true) && reportExcelIsNumeric((string) $value)) {
                $out .= '    <Cell ss:StyleID="CellLTR"><Data ss:Type="Number">' . reportExcelEncode($value) . '</Data></Cell>' . "\n";
            } else {
                $style = reportExcelIsNumeric((string) $value) ? 'CellLTR' : 'CellRTL';
                $out .= '    <Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . reportExcelEncode($value) . '</Data></Cell>' . "\n";
            }
            $i++;
        }
        $out .= '   </Row>' . "\n";
    }

    $out .= '  </Table>' . "\n";
    $out .= ' </Worksheet>' . "\n";
    $out .= '</Workbook>' . "\n";

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Pragma: public');
    header('Cache-Control: max-age=0, must-revalidate');

    echo $out;
    exit;
}

/**
 * دریافت پارامتر متنی از query string به‌صورت امن
 */
function reportParam(string $name): string
{
    return trim($_GET[$name] ?? '');
}

/**
 * دریافت پارامتر دسته‌بندی (فقط اعداد معتبر)
 */
function reportCategoryParam(): string
{
    $v = trim($_GET['category'] ?? '');
    return ($v !== '' && ctype_digit($v)) ? $v : '';
}

/**
 * دریافت پارامتر نوع کالا (فقط مقادیر معتبر)
 */
function reportTypeParam(): string
{
    $v = trim($_GET['type'] ?? '');
    return in_array($v, ['product', 'service'], true) ? $v : '';
}