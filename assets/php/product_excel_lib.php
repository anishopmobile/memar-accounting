<?php
/**
 * product_excel_lib.php
 *
 * منطق نرمال‌سازی، نگاشت هدر و اعتبارسنجی رکوردهای Excel برای «ورود از اکسل»
 * کالاها و خدمات. این فایل هیچ فراخوانی boot/db ندارد؛ اتصال DB به‌صورت پارامتر
 * به توابع داده می‌شود تا هم در HTTP و هم در CLI قابل تست باشد.
 *
 * سازگاری: PHP >= 7.0 (بدون توابع مخصوص PHP 8)
 */

if (!defined('PRODUCT_EXCEL_LIB_LOADED')) {
    define('PRODUCT_EXCEL_LIB_LOADED', true);
}

/**
 * تبدیل ارقام ۰-۹ فارسی/عربی به 0-9 انگلیسی
 */
function product_excel_to_latin_digits($s)
{
    static $map = null;
    if ($map === null) {
        $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $ar = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
        $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $map = array();
        foreach ($en as $k => $v) {
            $map[$fa[$k]] = $v;
            $map[$ar[$k]] = $v;
        }
    }
    return strtr((string) $s, $map);
}

/**
 * نرمال‌سازی متن برای مقایسه (مطابقت هدرها، نام دسته‌بندی، کد)
 */
function product_excel_canonical($s)
{
    $s = product_excel_safe_utf8($s);
    $s = trim((string) $s);
    $s = product_excel_to_latin_digits($s);
    $s = strtr($s, array('ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه',
                         'ؤ' => 'و', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا'));
    $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{202A}\x{202B}\x{202C}\x{202D}\x{202E}\x{2060}\x{FEFF}]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower(trim($s), 'UTF-8');
}

/* ---------- حالت‌های ورود (تکراری) ---------- */
const PRODUCT_EXCEL_IMPORT_MODE_SKIP   = 'skip_duplicates';
const PRODUCT_EXCEL_IMPORT_MODE_UPDATE = 'update_existing';

/**
 * نرمال‌سازی حالت ورود.
 *
 * مقادیر مجاز: 'skip_duplicates' (پیش‌فرض) و 'update_existing'.
 * مقدار نامعتبر/خالی به‌صورت امن به 'skip_duplicates' برمی‌گردد.
 */
function product_excel_normalize_import_mode($mode)
{
    $m = trim((string) ($mode === null ? '' : $mode));
    if ($m === PRODUCT_EXCEL_IMPORT_MODE_UPDATE) {
        return PRODUCT_EXCEL_IMPORT_MODE_UPDATE;
    }
    return PRODUCT_EXCEL_IMPORT_MODE_SKIP;
}

/**
 * نام‌های مجاز هدر برای هر فیلد (پس از canonical)
 */
function product_excel_header_aliases()
{
    static $aliases = null;
    if ($aliases !== null) {
        return $aliases;
    }
    $raw = array(
        'code'           => array('کد کالا', 'کد', 'کدکالا', 'code'),
        'barcode'        => array('بارکد', 'barcode', 'bar code', 'کد بارکد'),
        'name'           => array('نام کالا', 'نام', 'کالا', 'name', 'نام کالا و خدمات'),
        'category'       => array('دسته‌بندی', 'دسته بندی', 'دسته', 'category', 'گروه کالا', 'گروه'),
        'type'           => array('نوع', 'type'),
        'unit'           => array('واحد', 'واحد اندازه‌گیری', 'unit'),
        'purchase_price' => array('قیمت خرید', 'قیمت خرید کالا', 'قیمت خريد', 'purchase price', 'purchaseprice'),
        'sale_price'     => array('قیمت فروش', 'قیمت فروش کالا', 'sale price', 'saleprice'),
        'stock'          => array('موجودی', 'stock', 'مقدار'),
        'min_stock'      => array('حداقل موجودی', 'حداقل', 'min stock', 'minstock', 'موجودی حداقل'),
        'description'    => array('توضیحات', 'توضیح', 'شرح', 'description'),
    );
    $aliases = array();
    foreach ($raw as $field => $list) {
        $aliases[$field] = array();
        foreach ($list as $a) {
            $aliases[$field][product_excel_canonical($a)] = true;
        }
    }
    return $aliases;
}

/**
 * تبدیل مقدار «نوع» به مقدار داخلی دیتابیس (محصول/خدمت)
 * @return string|null 'product' | 'service' | null (نامعتبر)
 */
function product_excel_parse_type($value)
{
    $c = product_excel_canonical($value);
    switch ($c) {
        case 'product':
        case 'محصول':
        case 'کالا':
            return 'product';
        case 'service':
        case 'خدمت':
        case 'خدمات':
            return 'service';
    }
    return null;
}

/**
 * واحدهای مجاز (بر اساس فرم فعلی کالا)
 */
function product_excel_allowed_units()
{
    return array('عدد', 'بسته', 'قرص');
}

/**
 * نرمال‌سازی رشته عددی: تبدیل ارقام فارسی، حذف جداکننده هزارگان
 *
 * @return array ['ok'=>bool, 'value'=>string|null, 'negative'=>bool, 'too_large'=>bool]
 */
function product_excel_normalize_number($value)
{
    $s = trim((string) ($value === null ? '' : $value));
    if ($s === '') {
        return array('ok' => true, 'value' => '0', 'negative' => false, 'too_large' => false);
    }
    $s = product_excel_to_latin_digits($s);
    $s = str_replace(array(',', '٬', '،'), '', $s);   // جداکننده هزارگان
    $s = preg_replace('/\s+/u', '', $s);
    if (!is_numeric($s)) {
        return array('ok' => false, 'value' => null, 'negative' => false, 'too_large' => false);
    }
    $f = (float) $s;
    if ($f < 0) {
        return array('ok' => false, 'value' => null, 'negative' => true, 'too_large' => false);
    }
    if ($f > 999999999999.99) {
        return array('ok' => false, 'value' => null, 'negative' => false, 'too_large' => true);
    }
    $out = number_format($f, 2, '.', '');
    $out = rtrim(rtrim($out, '0'), '.');
    if ($out === '' || $out === '-') {
        $out = '0';
    }
    return array('ok' => true, 'value' => $out, 'negative' => false, 'too_large' => false);
}
/**
 * تبدیل رشته به UTF-8 معتبر (برای جلوگیری از خطای json_encode روی داده‌های خراب)
 */
function product_excel_safe_utf8($s)
{
    $s = (string) $s;
    if (function_exists('iconv')) {
        $c = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($c !== false) {
            return $c;
        }
    }
    return $s;
}

/**
 * پیدا کردن سطر هدر در بین ردیف‌های خوانده‌شده
 *
 * @param array $rows خروجی ProductExcelReader::readFile
 * @return array ['found'=>bool, 'index'=>int, 'row_number'=>int]
 */
function product_excel_find_header_row(array $rows)
{
    $aliases = product_excel_header_aliases();
    foreach ($rows as $idx => $row) {
        $cells = isset($row['cells']) ? $row['cells'] : array();
        $hasCode = false;
        $hasName = false;
        foreach ($cells as $v) {
            $c = product_excel_canonical($v);
            if (isset($aliases['code'][$c])) {
                $hasCode = true;
            }
            if (isset($aliases['name'][$c])) {
                $hasName = true;
            }
        }
        if ($hasCode && $hasName) {
            return array('found' => true, 'index' => $idx, 'row_number' => (int) $row['row']);
        }
    }
    return array('found' => false, 'index' => -1, 'row_number' => 0);
}

/**
 * ساخت نگاشت ستون ← فیلد و تشخیص هدرهای ضروری
 *
 * @return array{map: array, missing: string[], found: array}
 */
function product_excel_build_mapping(array $headerCells)
{
    $aliases = product_excel_header_aliases();
    $map = array();
    $found = array();
    foreach ($aliases as $field => $aliasSet) {
        $found[$field] = false;
    }
    foreach ($headerCells as $col => $v) {
        $c = product_excel_canonical($v);
        foreach ($aliases as $field => $aliasSet) {
            if (isset($aliasSet[$c]) && !$found[$field]) {
                $map[$field] = (int) $col;
                $found[$field] = true;
                break;
            }
        }
    }
    $required = array('code', 'name');
    $missing = array();
    foreach ($required as $f) {
        if (!$found[$f]) {
            $missing[] = $f;
        }
    }
    return array('map' => $map, 'missing' => $missing, 'found' => $found);
}

/**
 * خواندن مقدار یک فیلد از ردیف بر اساس نقشه ستون‌ها
 */
function product_excel_cell_value(array $cells, array $map, $field)
{
    if (!isset($map[$field])) {
        return '';
    }
    $col = $map[$field];
    return isset($cells[$col]) ? $cells[$col] : '';
}

/**
 * پردازش کامل فایل: یافتن هدر، ساخت نگاشت، اعتبارسنجی رکوردها
 *
 * @param array $readerRows         خروجی ProductExcelReader::readFile
 * @param array $categoryByName     map: canonical(name) => id
 * @param array $existingCodes      map: canonical(code) => true
 * @param int   $maxRows            حداکثر تعداد ردیف داده
 * @param array $existingBarcodes   map: canonical(barcode) => true (اختیاری)
 *
 * @return array
 */
function product_excel_process_file(array $readerRows, array $categoryByName, array $existingCodes, $maxRows = 2000, array $existingBarcodes = array(), $importMode = 'skip_duplicates', $truncatePreview = true)
{
    $header = product_excel_find_header_row($readerRows);
    if (!$header['found']) {
        throw new ProductExcelReaderException(
            'ردیف هدر (شامل «کد کالا» و «نام کالا») در فایل پیدا نشد. ردیف اول فایل باید عنوان ستون‌ها باشد.'
        );
    }

    $mapping = product_excel_build_mapping($readerRows[$header['index']]['cells']);
    if (!empty($mapping['missing'])) {
        $labels = array('code' => 'کد کالا', 'name' => 'نام کالا');
        $parts = array();
        foreach ($mapping['missing'] as $f) {
            $parts[] = isset($labels[$f]) ? $labels[$f] : $f;
        }
        throw new ProductExcelReaderException(
            'عنوان ستون ضروری در فایل وجود ندارد: ' . implode('، ', $parts)
        );
    }

    $dataRows = array_slice($readerRows, $header['index'] + 1);
    // حذف ردیف‌های کاملاً خالی
    $dataRows = array_values(array_filter($dataRows, function ($row) {
        foreach ($row['cells'] as $v) {
            if (trim((string) $v) !== '') {
                return true;
            }
        }
        return false;
    }));

    if (count($dataRows) === 0) {
        throw new ProductExcelReaderException('فایل پس از هدر، هیچ ردیف داده‌ای ندارد.');
    }
    if (count($dataRows) > $maxRows) {
        throw new ProductExcelReaderException(
            'تعداد ردیف‌های داده (' . count($dataRows) . ') از حد مجاز (' . $maxRows . ') بیشتر است.'
        );
    }

    return product_excel_validate_rows($dataRows, $mapping['map'], $categoryByName, $existingCodes, $existingBarcodes, $importMode, $truncatePreview);
}
/**
 * اعتبارسنجی ردیف‌های داده و ساخت پیش‌نمایش.
 *
 * @param array $dataRows          ردیف‌های بعد از هدر (با شماره سطر اکسل)
 * @param array $map               نگاشت ستون ← فیلد
 * @param array $categoryByName    map: canonical(name) => id
 * @param array $existingCodes     map: canonical(code) => true
 * @param array $existingBarcodes  map: canonical(barcode) => true (اختیاری)
 *
 * @return array{
 *   total: int, valid: int, invalid: int,
 *   records: array, errors: string[], truncated: bool
 * }
 */
function product_excel_validate_rows(array $dataRows, array $map, array $categoryByName, array $existingCodes, array $existingBarcodes = array(), $importMode = 'skip_duplicates', $truncatePreview = true)
{
    $importModeKey = product_excel_normalize_import_mode($importMode);
    $allowedUnits = product_excel_allowed_units();
    $unitCanon = array();
    foreach ($allowedUnits as $u) {
        $unitCanon[product_excel_canonical($u)] = $u;
    }

    $records = array();
    $allErrors = array();
    $seenCodes = array();
    $seenBarcodes = array();
    $missingCategories = array();   // canonical(name) => اولین نام خام دیده‌شده
    $previewLimit = 500;
    $newCount = 0;
    $updateCount = 0;
    $duplicateCount = 0;
    $conflictCount = 0;

    foreach ($dataRows as $row) {
        $cells = isset($row['cells']) ? $row['cells'] : array();
        $rowNo = (int) $row['row'];
        $errors = array();
        $existingIdForCode = null;
        $existingIdForBarcode = null;

        // ---- کد کالا ----
        $code = trim(product_excel_cell_value($cells, $map, 'code'));
        $code = product_excel_to_latin_digits($code);
        if ($code === '') {
            $errors[] = 'کد کالا اجباری است.';
        } elseif (mb_strlen($code) > 50) {
            $errors[] = 'کد کالا حداکثر ۵۰ کاراکتر است.';
        } else {
            $codeKey = product_excel_canonical($code);
            if (isset($seenCodes[$codeKey])) {
                $errors[] = 'کد کالا «' . $code . '» در فایل تکراری است (ردیف قبلی: ' . $seenCodes[$codeKey] . ').';
            } else {
                $seenCodes[$codeKey] = $rowNo;
            }
            $existingIdForCode = isset($existingCodes[$codeKey]) ? (int) $existingCodes[$codeKey] : null;
        }

        // ---- نام کالا ----
        $name = trim(product_excel_cell_value($cells, $map, 'name'));
        if ($name === '') {
            $errors[] = 'نام کالا اجباری است.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'نام کالا حداکثر ۱۵۰ کاراکتر است.';
        }

        // ---- بارکد (اختیاری) ----
        $barcode = trim((string) product_excel_cell_value($cells, $map, 'barcode'));
        if ($barcode !== '') {
            // تبدیل ارقام فارسی/عربی به انگلیسی؛ بدون تبدیل به عدد تا صفرهای ابتدایی حفظ شوند
            $barcode = product_excel_to_latin_digits($barcode);
            $barcodeKey = product_excel_canonical($barcode);
            if (mb_strlen($barcode) > 100) {
                $errors[] = 'بارکد «' . $barcode . '» حداکثر ۱۰۰ کاراکتر است.';
            } elseif (isset($seenBarcodes[$barcodeKey])) {
                $errors[] = 'بارکد «' . $barcode . '» در فایل تکراری است (ردیف قبلی: ' . $seenBarcodes[$barcodeKey] . ').';
            } else {
                $seenBarcodes[$barcodeKey] = $rowNo;
            }
            $existingIdForBarcode = isset($existingBarcodes[$barcodeKey]) ? (int) $existingBarcodes[$barcodeKey] : null;
        } else {
            $barcode = null;
        }

        // ---- دسته‌بندی ----
        $categoryRaw = trim(product_excel_cell_value($cells, $map, 'category'));
        $categoryId = null;
        $categoryDisplay = '';
        $categoryMissing = false;
        if ($categoryRaw !== '' && $categoryRaw !== '-' && $categoryRaw !== '—') {
            $catKey = product_excel_canonical($categoryRaw);
            if (isset($categoryByName[$catKey])) {
                $categoryId = (int) $categoryByName[$catKey];
                $categoryDisplay = $categoryRaw;
            } else {
                $categoryMissing = true;
                $categoryDisplay = $categoryRaw;
                if (!isset($missingCategories[$catKey])) {
                    $missingCategories[$catKey] = $categoryRaw;
                }
            }
        }

        // ---- نوع ----
        $typeRaw = trim(product_excel_cell_value($cells, $map, 'type'));
        $type = 'product';
        if ($typeRaw !== '') {
            $parsedType = product_excel_parse_type($typeRaw);
            if ($parsedType === null) {
                $errors[] = 'نوع «' . $typeRaw . '» مجاز نیست (فقط «محصول» یا «خدمت»).';
            } else {
                $type = $parsedType;
            }
        }

        // ---- واحد ----
        $unitRaw = trim(product_excel_cell_value($cells, $map, 'unit'));
        $unit = 'عدد';
        if ($unitRaw !== '') {
            $uKey = product_excel_canonical($unitRaw);
            if (isset($unitCanon[$uKey])) {
                $unit = $unitCanon[$uKey];
            } else {
                $errors[] = 'واحد «' . $unitRaw . '» مجاز نیست (مقادیر مجاز: عدد، بسته، قرص).';
            }
        }

        // ---- اعداد ----
        $numbers = array(
            'purchase_price' => 'قیمت خرید',
            'sale_price'     => 'قیمت فروش',
            'stock'          => 'موجودی',
            'min_stock'      => 'حداقل موجودی',
        );
        $data = array(
            'code'          => $code,
            'barcode'       => $barcode,
            'name'          => $name,
            'category_id'   => $categoryId,
            'category_name' => $categoryDisplay,
            'category_missing' => $categoryMissing,
            'type'          => $type,
            'unit'          => $unit,
            'purchase_price' => '0',
            'sale_price'     => '0',
            'stock'          => '0',
            'min_stock'      => '0',
            'description'    => '',
        );
        foreach ($numbers as $field => $label) {
            $num = product_excel_normalize_number(product_excel_cell_value($cells, $map, $field));
            if (!$num['ok']) {
                if ($num['negative']) {
                    $errors[] = $label . ' باید عدد غیرمنفی باشد.';
                } elseif ($num['too_large']) {
                    $errors[] = $label . ' از حد مجاز (۱۲ رقم) بیشتر است.';
                } else {
                    $errors[] = $label . ' باید عدد معتبر باشد.';
                }
            } else {
                $data[$field] = $num['value'];
            }
        }

        // ---- توضیحات ----
        $data['description'] = trim(product_excel_cell_value($cells, $map, 'description'));

        // ---- تشخیص تکراری/تضاد بر اساس کد یا بارکد ----
        $conflict = false;
        $existingProductId = null;
        if ($existingIdForCode !== null && $existingIdForBarcode !== null) {
            if ($existingIdForCode === $existingIdForBarcode) {
                // کد و بارکد هر دو به یک کالای موجود اشاره می‌کنند
                $existingProductId = (int) $existingIdForCode;
            } else {
                $conflict = true;
            }
        } elseif ($existingIdForCode !== null) {
            $existingProductId = (int) $existingIdForCode;
        } elseif ($existingIdForBarcode !== null) {
            $existingProductId = (int) $existingIdForBarcode;
        }

        if ($conflict) {
            $errors[] = 'بین کد کالا «' . $code . '» و بارکد «' . (string) ($barcode === null ? '' : $barcode) . '» تضاد وجود دارد؛ آن‌ها به دو کالای متفاوت تعلق دارند و قابل ادغام نیستند.';
        }

        if ($conflict) {
            $status = 'conflict';
            $data['product_id'] = null;
        } elseif (!empty($errors)) {
            $status = 'error';
        } elseif ($existingProductId !== null) {
            $data['product_id'] = (int) $existingProductId;
            if ($importModeKey === PRODUCT_EXCEL_IMPORT_MODE_UPDATE) {
                $status = 'update';
                $data['import_action'] = 'update';
            } else {
                $status = 'duplicate';
                $data['import_action'] = 'skip';
            }
        } else {
            $status = 'ok';
            $data['import_action'] = 'insert';
        }

        $record = array(
            'row'    => $rowNo,
            'status' => $status,
            'errors' => $errors,
            'data'   => $data,
        );
        $records[] = $record;

        foreach ($errors as $e) {
            $allErrors[] = 'ردیف ' . $rowNo . ': ' . $e;
        }
    }

    $valid = 0;
    $invalid = 0;
    foreach ($records as $r) {
        switch ($r['status']) {
            case 'ok':
                $newCount++;
                $valid++;
                break;
            case 'update':
                $updateCount++;
                $valid++;
                break;
            case 'duplicate':
                $duplicateCount++;
                break;
            case 'conflict':
                $conflictCount++;
                $invalid++;
                break;
            default:
                $invalid++;
                break;
        }
    }

    $truncated = $truncatePreview && count($records) > $previewLimit;
    return array(
        'total'              => count($records),
        'valid'              => $valid,
        'invalid'            => $invalid,
        'new'                => $newCount,
        'updates'            => $updateCount,
        'duplicates'         => $duplicateCount,
        'conflicts'          => $conflictCount,
        'categories_missing' => count($missingCategories),
        'missing_categories' => array_values($missingCategories),
        'records'            => $truncated ? array_slice($records, 0, $previewLimit) : $records,
        'errors'             => $allErrors,
        'truncated'          => $truncated,
    );
}
/**
 * دریافت نگاشت نام دسته‌بندی ← id از دیتابیس
 */
function product_excel_category_map($mysqli)
{
    $map = array();
    $result = $mysqli->query('SELECT id, name FROM product_categories');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = product_excel_canonical($row['name']);
            if (!isset($map[$key])) {
                $map[$key] = (int) $row['id'];
            }
        }
        $result->free();
    }
    return $map;
}

/**
 * دریافت مجموعه کدهای موجود در دیتابیس (کلید: canonical code، مقدار: شناسه کالا)
 */
function product_excel_existing_codes($mysqli)
{
    $codes = array();
    $result = $mysqli->query('SELECT id, code FROM products');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $codes[product_excel_canonical($row['code'])] = (int) $row['id'];
        }
        $result->free();
    }
    return $codes;
}

/**
 * دریافت مجموعه بارکدهای موجود در دیتابیس (کلید: canonical barcode، مقدار: شناسه کالا)
 * فقط بارکدهای غیر خالی. بارکد به‌صورت رشته حفظ می‌شود.
 */
function product_excel_existing_barcodes($mysqli)
{
    $barcodes = array();
    $result = $mysqli->query("SELECT id, barcode FROM products WHERE barcode IS NOT NULL AND TRIM(barcode) <> ''");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $barcodes[product_excel_canonical($row['barcode'])] = (int) $row['id'];
        }
        $result->free();
    }
    return $barcodes;
}

/**
 * ساخت دسته‌بندی ریشه هنگام ثبت نهایی (سازگار با مکانیزم موجود در category_action.php).
 *
 * @return int|null شناسه دسته‌بندی جدید یا null در صورت خطا
 */
function product_excel_create_category(mysqli $mysqli, string $name): ?int
{
    $code = '1';
    $res = $mysqli->query('SELECT code FROM product_categories WHERE parent_id IS NULL ORDER BY CAST(code AS UNSIGNED) DESC LIMIT 1');
    if ($res && $row = $res->fetch_assoc()) {
        $code = (string) ((int) $row['code'] + 1);
        $res->free();
    }
    $stmt = $mysqli->prepare('INSERT INTO product_categories (code, name, parent_id) VALUES (?, ?, NULL)');
    $stmt->bind_param('ss', $code, $name);
    $ok = $stmt->execute();
    $id = $ok ? (int) $stmt->insert_id : null;
    $stmt->close();
    return $id;
}

/**
 * ثبت نهایی رکوردها داخل یک transaction:
 *  - ساخت دسته‌بندی‌های مفقود (فقط برای ردیف‌های قابل ثبت/به‌روزرسانی و فقط یکبار برای هر نام)
 *  - درج کالاهای جدید
 *  - به‌روزرسانی کالاهای موجود (حالت update_existing)
 *  - رد ردیف‌های تکراری (skip_duplicates) بدون هیچ تغییری
 *
 * @param mysqli $mysqli  اتصال دیتابیس
 * @param array  $records خروجی product_excel_validate_rows
 * @param bool   $createDefaultUnits  اگر true باشد برای هر کالای جدید یک واحد پایه (ضریب ۱) ساخته می‌شود
 * @param array  $categoryByName نگاشت canonical(name) => id (آخرین وضعیت دیتابیس)
 * @param string $importMode 'skip_duplicates' | 'update_existing'
 *
 * @return array ['inserted'=>int, 'updated'=>int, 'skipped'=>int, 'rejected'=>int,
 *                'conflicts'=>int, 'categories_created'=>int, 'id_map'=>array]
 * @throws Exception در خطای غیرمنتظره (روی transaction rollback می‌شود)
 */
function product_excel_insert_rows(mysqli $mysqli, array $records, $createDefaultUnits = true, array $categoryByName = array(), $importMode = 'skip_duplicates')
{
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $rejected = 0;
    $conflicts = 0;
    $categoriesCreated = 0;
    $idMap = array();

    if (count($records) === 0) {
        return array('inserted' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0,
                     'conflicts' => 0, 'categories_created' => 0, 'id_map' => $idMap);
    }

    $importMode = product_excel_normalize_import_mode($importMode);

    if (!$mysqli->begin_transaction()) {
        throw new Exception('شروع transaction انجام نشد. هیچ رکوردی ثبت نشد.');
    }

    $catMap = array();
    foreach ($categoryByName as $k => $v) {
        $catMap[$k] = $v;
    }
    $createdCats = array();

    try {
        foreach ($records as $r) {
            if ($r['status'] === 'duplicate') {
                $skipped++;
                continue;
            }
            if ($r['status'] === 'conflict') {
                $conflicts++;
                $rejected++;
                continue;
            }
            if ($r['status'] !== 'ok' && $r['status'] !== 'update') {
                $rejected++;
                continue;
            }
            $d = $r['data'];

            /* دسته‌بندی: استفاده از موجود؛ در صورت مفقود بودن، ساخت (فقط یکبار برای هر نام) */
            $categoryId = null;
            $catRaw = trim((string) ($d['category_name'] ?? ''));
            if ($catRaw !== '' && $catRaw !== '-' && $catRaw !== '—') {
                $catKey = product_excel_canonical($catRaw);
                if (isset($createdCats[$catKey])) {
                    $categoryId = (int) $createdCats[$catKey];
                } elseif (isset($catMap[$catKey])) {
                    $categoryId = (int) $catMap[$catKey];
                } else {
                    $newCatId = product_excel_create_category($mysqli, $catRaw);
                    if ($newCatId === null) {
                        throw new Exception('خطا در ایجاد دسته‌بندی «' . $catRaw . '».');
                    }
                    $createdCats[$catKey] = $newCatId;
                    $catMap[$catKey] = $newCatId;
                    $categoryId = (int) $newCatId;
                    $categoriesCreated++;
                }
            }

            if ($r['status'] === 'update') {
                /* به‌روزرسانی کالای موجود؛ فقط فیلدهای داده (بدون پاک‌کردن داده‌های موجود).
                 * کد کالا شناسه کالاست و در حالت به‌روزرسانی هرگز تغییر نمی‌کند تا
                 * از تغییر/جابه‌جایی ناخواسته شناسه کالاها جلوگیری شود. */
                $productId = (int) ($d['product_id'] ?? 0);
                if ($productId <= 0) {
                    $rejected++;
                    continue;
                }
                $updated++;
                $setParts = array('name = ?');
                $types = 's';
                $vals = array((string) $d['name']);

                $barcodeVal = trim((string) ($d['barcode'] ?? ''));
                if ($barcodeVal !== '') {
                    $setParts[] = 'barcode = ?';
                    $types .= 's';
                    $vals[] = $barcodeVal;
                }
                if ($categoryId !== null) {
                    $setParts[] = 'category_id = ?';
                    $types .= 'i';
                    $vals[] = $categoryId;
                }
                if (trim((string) ($d['type'] ?? '')) !== '') {
                    $setParts[] = 'type = ?';
                    $types .= 's';
                    $vals[] = (string) $d['type'];
                }
                if (trim((string) ($d['unit'] ?? '')) !== '') {
                    $setParts[] = 'unit = ?';
                    $types .= 's';
                    $vals[] = (string) $d['unit'];
                }
                foreach (array('purchase_price', 'sale_price', 'stock', 'min_stock') as $f) {
                    if (trim((string) ($d[$f] ?? '')) !== '') {
                        $setParts[] = $f . ' = ?';
                        $types .= 'd';
                        $vals[] = (float) $d[$f];
                    }
                }
                $descVal = trim((string) ($d['description'] ?? ''));
                if ($descVal !== '') {
                    $setParts[] = 'description = ?';
                    $types .= 's';
                    $vals[] = $descVal;
                }

                $sql = 'UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id = ?';
                $types .= 'i';
                $vals[] = $productId;
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param($types, ...$vals);
                if (!$stmt->execute()) {
                    throw new Exception('خطا در به‌روزرسانی کالا (ردیف ' . $r['row'] . '): ' . $stmt->error);
                }
                $stmt->close();
                continue;
            }

            /* وضعیت ok → درج کالای جدید */
            $pp = (float) $d['purchase_price'];
            $sp = (float) $d['sale_price'];
            $st = (float) $d['stock'];
            $ms = (float) $d['min_stock'];

            if ($categoryId === null) {
                $sql = 'INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('sssssdddds', $d['code'], $d['barcode'], $d['name'], $d['type'],
                    $d['unit'], $pp, $sp, $st, $ms, $d['description']);
            } else {
                $sql = 'INSERT INTO products (code, barcode, name, category_id, type, unit, purchase_price, sale_price, stock, min_stock, description)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('sssissdddds', $d['code'], $d['barcode'], $d['name'], $categoryId, $d['type'],
                    $d['unit'], $pp, $sp, $st, $ms, $d['description']);
            }
            if ($stmt->execute()) {
                $inserted++;
                $newId = (int) $stmt->insert_id;
                $idMap[product_excel_canonical($d['code'])] = $newId;

                if ($createDefaultUnits) {
                    // واحد پایه پیش‌فرض از ستون واحد کالا (ضریب ۱)
                    $uStmt = $mysqli->prepare(
                        'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
                         VALUES (?, ?, 1, 1, ?, ?, ?, 0)'
                    );
                    $uStmt->bind_param('issss', $newId, $d['unit'], $pp, $sp, $d['barcode']);
                    $uStmt->execute();
                    $uStmt->close();
                }
            } else {
                throw new Exception('خطا در ثبت کالا (ردیف ' . $r['row'] . '): ' . $stmt->error);
            }
            $stmt->close();
        }
        $mysqli->commit();
    } catch (Throwable $e) {
        if (method_exists($mysqli, 'rollback')) {
            @$mysqli->rollback();
        }
        throw $e;
    }

    return array('inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped,
                 'rejected' => $rejected, 'conflicts' => $conflicts,
                 'categories_created' => $categoriesCreated, 'id_map' => $idMap);
}
/* ============================================================
 * Multi-Unit Inventory — برگه واحدهای اندازه‌گیری
 * ========================================================== */

function product_excel_unit_header_aliases()
{
    static $aliases = null;
    if ($aliases !== null) {
        return $aliases;
    }
    $raw = array(
        'product_code'      => array('کد کالا', 'کد', 'کدکالا', 'product code', 'productcode', 'code'),
        'product_name'      => array('نام کالا', 'نام', 'product name', 'name'),
        'unit_id'           => array('شماره واحد', 'شناسه واحد', 'unit id', 'unitid'),
        'unit_name'         => array('نام واحد', 'واحد', 'unit name', 'unit'),
        'conversion_factor' => array('ضریب تبدیل', 'ضریب', 'conversion factor', 'conversionfactor', 'factor'),
        'purchase_price'    => array('قیمت خرید واحد', 'قیمت خرید', 'purchase price', 'purchaseprice'),
        'sale_price'        => array('قیمت فروش واحد', 'قیمت فروش', 'sale price', 'saleprice'),
        'barcode'           => array('بارکد واحد', 'بارکد', 'barcode', 'bar code'),
        'is_base'           => array('واحد پایه', 'پایه', 'base', 'is base', 'isbase', 'is_base'),
    );
    $aliases = array();
    foreach ($raw as $field => $list) {
        $aliases[$field] = array();
        foreach ($list as $a) {
            $aliases[$field][product_excel_canonical($a)] = true;
        }
    }
    return $aliases;
}

function product_excel_find_header_generic(array $rows, array $aliases, array $required)
{
    foreach ($rows as $idx => $row) {
        $cells = isset($row['cells']) ? $row['cells'] : array();
        $found = array();
        foreach ($aliases as $field => $aliasSet) {
            foreach ($cells as $v) {
                $c = product_excel_canonical($v);
                if (isset($aliasSet[$c])) {
                    $found[$field] = true;
                }
            }
        }
        $missing = array();
        foreach ($required as $f) {
            if (empty($found[$f])) {
                $missing[] = $f;
            }
        }
        if (empty($missing)) {
            return array('found' => true, 'index' => $idx, 'row_number' => (int) $row['row'], 'found_fields' => $found);
        }
    }
    return array('found' => false, 'index' => -1, 'row_number' => 0, 'found_fields' => array());
}

function product_excel_build_mapping_generic(array $headerCells, array $aliases)
{
    $map = array();
    $found = array();
    foreach ($aliases as $field => $aliasSet) {
        $found[$field] = false;
    }
    foreach ($headerCells as $col => $v) {
        $c = product_excel_canonical($v);
        foreach ($aliases as $field => $aliasSet) {
            if (isset($aliasSet[$c]) && !$found[$field]) {
                $map[$field] = (int) $col;
                $found[$field] = true;
                break;
            }
        }
    }
    return array('map' => $map, 'found' => $found);
}
/**
 * اعتبارسنجی ردیف‌های برگه واحدهای اندازه‌گیری.
 *
 * @param array $dataRows      خروجی برگه واحدها (با مرجع کد کالا)
 * @param array $validCodes    map: canonical(product_code) => true برای کالاهای این فایل
 *
 * @return array {ok, message, records, errors, has_base_units}
 */
function product_excel_validate_unit_rows(array $dataRows, array $validCodes)
{
    $aliases = product_excel_unit_header_aliases();
    $header = product_excel_find_header_generic($dataRows, $aliases, array('product_code', 'unit_name'));
    if (!$header['found']) {
        return array('ok' => false, 'message' => 'برگه واحدها باید هدر شامل «کد کالا» و «نام واحد» داشته باشد.', 'records' => array(), 'errors' => array(), 'has_base_units' => false);
    }
    $mapping = product_excel_build_mapping_generic($dataRows[$header['index']]['cells'], $aliases);
    $rows = array_slice($dataRows, $header['index'] + 1);

    $records = array();
    $allErrors = array();
    $seen = array();
    $baseCount = array();
    $hasBaseUnits = false;

    foreach ($rows as $row) {
        $cells = isset($row['cells']) ? $row['cells'] : array();
        $rowNo = (int) $row['row'];
        $errors = array();

        $code = trim(product_excel_cell_value($cells, $mapping['map'], 'product_code'));
        $codeKey = product_excel_canonical($code);
        $name = trim(product_excel_cell_value($cells, $mapping['map'], 'unit_name'));

        if ($code === '') {
            $errors[] = 'کد کالا اجباری است.';
        } elseif (!isset($validCodes[$codeKey])) {
            $errors[] = 'کد کالا «' . $code . '» در برگه کالاها وجود ندارد.';
        }
        if ($name === '') {
            $errors[] = 'نام واحد اجباری است.';
        } elseif (mb_strlen($name) > 50) {
            $errors[] = 'نام واحد حداکثر ۵۰ کاراکتر است.';
        }

        $factor = '1';
        $factorRaw = trim(product_excel_cell_value($cells, $mapping['map'], 'conversion_factor'));
        if ($factorRaw !== '') {
            $n = product_excel_normalize_number($factorRaw);
            if (!$n['ok'] || (float) $n['value'] <= 0) {
                $errors[] = 'ضریب تبدیل باید عددی مثبت باشد.';
            } else {
                $factor = $n['value'];
            }
        }

        $ppNum = product_excel_normalize_number(product_excel_cell_value($cells, $mapping['map'], 'purchase_price'));
        if (!$ppNum['ok']) {
            $errors[] = 'قیمت خرید واحد نامعتبر است.';
        }
        $spNum = product_excel_normalize_number(product_excel_cell_value($cells, $mapping['map'], 'sale_price'));
        if (!$spNum['ok']) {
            $errors[] = 'قیمت فروش واحد نامعتبر است.';
        }

        $isBase = 0;
        $baseRaw = trim(product_excel_cell_value($cells, $mapping['map'], 'is_base'));
        if (in_array(product_excel_canonical($baseRaw), array('بله', 'true', '1', 'base', 'پایه', 'واحد پایه'), true)) {
            $isBase = 1;
        }

        $barcode = trim(product_excel_cell_value($cells, $mapping['map'], 'barcode'));
        if ($barcode !== '') {
            if (mb_strlen($barcode) > 100) {
                $errors[] = 'بارکد واحد حداکثر ۱۰۰ کاراکتر است.';
            } else {
                $barcode = product_excel_to_latin_digits($barcode);
            }
        } else {
            $barcode = null;
        }

        $key = $codeKey . '|' . product_excel_canonical($name);
        if (isset($seen[$key])) {
            $errors[] = 'واحد «' . $name . '» برای کالای «' . $code . '» تکراری است.';
        } else {
            $seen[$key] = true;
        }

        if ($isBase === 1) {
            $baseCount[$codeKey] = ($baseCount[$codeKey] ?? 0) + 1;
            $hasBaseUnits = true;
        }

        $status = empty($errors) ? 'ok' : 'error';
        $records[] = array(
            'row'    => $rowNo,
            'status' => $status,
            'errors' => $errors,
            'data'   => array(
                'product_code'      => $code,
                'unit_name'         => $name,
                'conversion_factor' => $factor,
                'purchase_price'    => $ppNum['ok'] ? $ppNum['value'] : '0',
                'sale_price'        => $spNum['ok'] ? $spNum['value'] : '0',
                'barcode'           => $barcode,
                'is_base'           => $isBase,
            ),
        );
        foreach ($errors as $e) {
            $allErrors[] = 'ردیف ' . $rowNo . ': ' . $e;
        }
    }

    foreach ($baseCount as $codeKey => $cnt) {
        if ($cnt > 1) {
            $allErrors[] = 'کالای «' . $codeKey . '» بیش از یک واحد پایه دارد.';
        }
    }

    return array('ok' => true, 'message' => '', 'records' => $records, 'errors' => $allErrors, 'has_base_units' => $hasBaseUnits);
}
/**
 * درج واحدهای اندازه‌گیری پس از درج کالاها (داخل همان transaction).
 *
 * @return array ['inserted'=>int, 'rejected'=>int]
 */
function product_excel_insert_units(mysqli $mysqli, array $unitRecords, array $codeToIdMap)
{
    $inserted = 0;
    $rejected = 0;
    foreach ($unitRecords as $r) {
        if ($r['status'] !== 'ok') {
            $rejected++;
            continue;
        }
        $d = $r['data'];
        $codeKey = product_excel_canonical($d['product_code']);
        if (!isset($codeToIdMap[$codeKey])) {
            $rejected++;
            continue;
        }
        $pid = (int) $codeToIdMap[$codeKey];
        $factor = (float) $d['conversion_factor'];
        $isBase = ((int) $d['is_base'] === 1) ? 1 : 0;
        if ($isBase === 1 && $factor != 1) {
            $rejected++;
            continue;
        }
        $pp = (float) $d['purchase_price'];
        $sp = (float) $d['sale_price'];
        $sortOrder = 0;
        $stmt = $mysqli->prepare(
            'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isdisssi', $pid, $d['unit_name'], $d['conversion_factor'], $isBase, $pp, $sp, $d['barcode'], $sortOrder);
        $stmt->execute();
        $stmt->close();
        $inserted++;
    }
    return array('inserted' => $inserted, 'rejected' => $rejected);
}
