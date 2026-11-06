<?php
/**
 * Dependency-free category Excel import regressions.
 * Runs in CLI without authentication, MySQL or external spreadsheet packages.
 */
error_reporting(E_ALL);
$helper = dirname(__DIR__) . '/product_excel_lib.php';
$start = file_get_contents($helper, false, null, 0, 3);
if ($start !== '<?p') {
    throw new RuntimeException('Shared Excel helper must start with <?php without a BOM (JSON corruption)');
}

ob_start();
require_once dirname(__DIR__) . '/category_excel_lib.php';
require_once dirname(__DIR__) . '/product_excel_reader.php';
$noise = ob_get_clean();
if ($noise !== '') {
    throw new RuntimeException('Excel helper emitted bytes before JSON response');
}

function cat_test_assert($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

// Feed a real SpreadsheetML file, using the production reader (same as export).
$rows = array(
    array('کد گروه', 'نام گروه', 'کد گروه مادر'),
    array('28', 'کاغذ و مقوا', ''),
    array('29', 'زیر دسته لوازم اداری', ''),
    array('291', 'زیرگروه جدید', '29')
);
$escape = function ($v) { return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
$xml = '<?xml version="1.0" encoding="UTF-8"?>';
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
     . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Categories"><Table>';
foreach ($rows as $row) {
    $xml .= '<Row>';
    foreach ($row as $value) {
        $xml .= '<Cell><Data ss:Type="String">' . $escape($value) . '</Data></Cell>';
    }
    $xml .= '</Row>';
}
$xml .= '</Table></Worksheet></Workbook>';
$tmp = tempnam(sys_get_temp_dir(), 'category_excel_test_');
try {
    file_put_contents($tmp, "\xEF\xBB\xBF" . $xml);
    $parsed = category_excel_parse(ProductExcelReader::readFile($tmp));
    cat_test_assert(count($parsed) === 3, 'reader lost category rows');
    cat_test_assert($parsed[2]['parent_code'] === '29', 'reader lost parent category code');
    $existing = category_excel_existing_map(array(array('id' => 18, 'code' => '28', 'name' => 'عنوان قبلی')));

    $skip = category_excel_plan($parsed, $existing, 'skip_duplicates');
    cat_test_assert($skip['total'] === 3 && $skip['new'] === 2 &&
                    $skip['duplicates'] === 1 && $skip['updates'] === 0 &&
                    $skip['invalid'] === 0 && $skip['valid'] === 2,
                    'skip_duplicates classification mismatch');
    cat_test_assert($skip['records'][0]['status'] === 'duplicate', 'existing code not skipped');

    $update = category_excel_plan($parsed, $existing, 'update_existing');
    cat_test_assert($update['updates'] === 1 && $update['new'] === 2 &&
                    $update['valid'] === 3 && $update['invalid'] === 0,
                    'update_existing classification mismatch');
    cat_test_assert($update['records'][0]['status'] === 'update', 'existing code not marked as update');

    $childBeforeParent = array($parsed[2], $parsed[1], $parsed[0]);
    $reordered = category_excel_plan($childBeforeParent, $existing, 'skip_duplicates');
    cat_test_assert($reordered['new'] === 2 && $reordered['invalid'] === 0, 'unordered hierarchy wrongly rejected');

    $missing = array(array('row' => 8, 'code' => '300', 'name' => 'نامعلوم', 'parent_code' => '9999'));
    $invalid = category_excel_plan($missing, $existing, 'skip_duplicates');
    cat_test_assert($invalid['invalid'] === 1 && $invalid['valid'] === 0, 'missing parent accepted');

    $cycle = array(
        array('row' => 4, 'code' => '91', 'name' => 'یک', 'parent_code' => '92'),
        array('row' => 5, 'code' => '92', 'name' => 'دو', 'parent_code' => '91')
    );
    cat_test_assert(category_excel_plan($cycle, $existing, 'skip_duplicates')['invalid'] === 2, 'cycle accepted');

    try {
        category_excel_parse(array(
            array('row' => 1, 'cells' => array('کد گروه', 'نام گروه')),
            array('row' => 2, 'cells' => array('28', 'اول')),
            array('row' => 3, 'cells' => array('۲۸', 'دوم'))
        ));
        throw new RuntimeException('expected duplicate-in-file failure');
    } catch (InvalidArgumentException $expected) {
        // Canonical Persian/Latin digit matching must reject repeated codes.
    }

    cat_test_assert(category_excel_key('۲۸') === category_excel_key('28'), 'Persian digit matching broken');
    echo "PASS category Excel reader, BOM, mode, hierarchy, duplicate, cycle tests\n";
} finally {
    @unlink($tmp);
}
