<?php
/**
 * product_import.php
 *
 * Endpoint «ورود از اکسل» برای کالاها و خدمات.
 *
 * اقدام‌ها:
 *   - preview : خواندن فایل، سردار هدر، نرمال‌سازی و اعتبارسنجی کامل
 *               (هیچ Insert انجام نمی‌شود)
 *   - import  : خواندن مجدد همان فایل، اعتبارسنجی نهایی سمت سرور و ثبت
 *               رکوردهای معتبر داخل transaction
 *
 * امنیت:
 *   - boot.php + require_csrf_or_fail()
 *   - محدودیت نوع (xlsx/xls) و حجم
 *   - کپی به فایل موقت با نام تصادفی و حذف پس از پردازش
 *   - خروجی JSON با respond_json()/respond_error()
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_excel_reader.php';
require_once __DIR__ . '/product_excel_lib.php';

require_csrf_or_fail();

header('Content-Type: application/json; charset=UTF-8');

/* ---------- مقادیر و محدودیت‌ها ---------- */
const PRODUCT_IMPORT_MAX_BYTES = 5 * 1024 * 1024; // ۵ مگابایت
const PRODUCT_IMPORT_MAX_ROWS  = 2000;            // حداکثر سطر داده
$allowedExts = array('xlsx', 'xls');

$action = trim($_POST['action'] ?? '');
if ($action !== 'preview' && $action !== 'import') {
    respond_error('عملیات نامعتبر است. (فقط preview یا import مجاز است.)');
}

/* حالت ورود: skip_duplicates (پیش‌فرض) یا update_existing */
$importMode = product_excel_normalize_import_mode($_POST['import_mode'] ?? '');

/* ---------- اعتبارسنجی فایل آپلودی ---------- */
$uploads = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
if (!$uploads || (isset($uploads['error']) && (int) $uploads['error'] === UPLOAD_ERR_NO_FILE)) {
    respond_error('فایلی انتخاب نشده است.');
}
$uploadError = (int) ($uploads['error'] ?? UPLOAD_ERR_OK);
if ($uploadError !== UPLOAD_ERR_OK) {
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        respond_error('حجم فایل بیش از حد مجاز است (حداکثر ' . (int) (PRODUCT_IMPORT_MAX_BYTES / 1024 / 1024) . ' مگابایت).');
    }
    respond_error('بارگذاری فایل با خطا مواجه شد (کد ' . $uploadError . ').');
}

$origName = (string) ($uploads['name'] ?? '');
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts, true)) {
    respond_error('فقط فایل‌های .xlsx و .xls پذیرفته می‌شوند.');
}

$size = (int) ($uploads['size'] ?? 0);
if ($size <= 0) {
    respond_error('فایل انتخاب‌شده خالی است.');
}
if ($size > PRODUCT_IMPORT_MAX_BYTES) {
    respond_error('حجم فایل بیشتر از حد مجاز (' . (int) (PRODUCT_IMPORT_MAX_BYTES / 1024 / 1024) . ' مگابایت) است.');
}

$src = $uploads['tmp_name'];
if (!is_string($src) || !is_file($src)) {
    respond_error('فایل موقت آپلود در دسترس نیست.');
}

/* ---------- کپی به فایل موقت با نام تصادفی ---------- */
$tmpFile = @tempnam(sys_get_temp_dir(), 'pimx_');
if ($tmpFile === false) {
    respond_error('امکان ساخت فایل موقت وجود ندارد.');
}
// اطمینان از حذف فایل موقت حتی در صورت خروج با exit/exception
register_shutdown_function(function () use ($tmpFile) {
    if (is_string($tmpFile) && is_file($tmpFile)) {
        @unlink($tmpFile);
    }
});
$copied = @copy($src, $tmpFile);
if (!$copied) {
    @unlink($tmpFile);
    respond_error('امکان ذخیره فایل موقت وجود نداشت.');
}

/* ---------- پردازش با پاکسازی قطعی فایل موقت ---------- */
try {
    $readerSheets = ProductExcelReader::readFileSheets($tmpFile);   // ممکن است استثنا پرتاب کند
    $categoryByName = product_excel_category_map($mysqli);
    $existingCodes  = product_excel_existing_codes($mysqli);
    $existingBarcodes = product_excel_existing_barcodes($mysqli);

    // برگه اصلی کالاها (اولین برگه یا برگه‌ای که هدر کالا را دارد)
    $readerRows = array();
    $unitsSheetRows = array();
    foreach ($readerSheets as $sheetName => $sheetRows) {
        if ($sheetName === 'ProductUnits') {
            $unitsSheetRows = $sheetRows;
            continue;
        }
        if (count($readerRows) === 0) {
            $readerRows = $sheetRows;
        }
    }

    $result = product_excel_process_file($readerRows, $categoryByName, $existingCodes, PRODUCT_IMPORT_MAX_ROWS, $existingBarcodes, $importMode);

    /* پیش‌نمایش / اعتبارسنجی برگه واحدها (برای فایل‌های جدید) */
    $unitResult = array('records' => array(), 'errors' => array(), 'has_base_units' => false, 'ok' => true);
    $validCodesForUnits = array();
    foreach ($result['records'] as $rec) {
        if ($rec['status'] === 'ok') {
            $validCodesForUnits[product_excel_canonical($rec['data']['code'])] = true;
        }
    }
    // برای فایل‌های با محصولات موجود؛ کدهای تکراری با دیتابیس رد شده‌اند اما همچنان مرجع واحدها هستند
    foreach ($existingCodes as $code => $_) {
        $validCodesForUnits[$code] = true;
    }
    if (count($unitsSheetRows) > 0) {
        $unitResult = product_excel_validate_unit_rows($unitsSheetRows, $validCodesForUnits);
        if (!$unitResult['ok']) {
            respond_error($unitResult['message']);
        }
    }

    $allErrors = $result['errors'];
    foreach ($unitResult['errors'] as $e) {
        $allErrors[] = $e;
    }

    if ($action === 'preview') {
        respond_json(true, 'فایل با موفقیت خوانده و اعتبارسنجی شد.', array(
            'file_name'  => $origName,
            'import_mode'=> $importMode,
            'total'      => $result['total'],
            'valid'      => $result['valid'],
            'invalid'    => $result['invalid'],
            'new'        => $result['new'],
            'updates'    => $result['updates'],
            'duplicates' => $result['duplicates'],
            'conflicts'  => $result['conflicts'],
            'categories_missing' => $result['categories_missing'],
            'missing_categories' => $result['missing_categories'],
            'records'    => $result['records'],
            'errors'     => $allErrors,
            'truncated'  => $result['truncated'],
            'header_cols'=> array_keys($result['records'][0]['data'] ?? array('code')),
            'units'      => $unitResult['records'],
            'units_errors' => $unitResult['errors'],
        ));
    }

    // ---- import ----
    // اعتبارسنجی نهایی مجدد روی همان داده‌ها با آخرین وضعیت دیتابیس
    $categoryByName = product_excel_category_map($mysqli);
    $existingCodes  = product_excel_existing_codes($mysqli);
    $existingBarcodes = product_excel_existing_barcodes($mysqli);
    $result = product_excel_process_file($readerRows, $categoryByName, $existingCodes, PRODUCT_IMPORT_MAX_ROWS, $existingBarcodes, $importMode, false);

    if ($result['valid'] === 0) {
        if ($result['duplicates'] > 0) {
            respond_error('هیچ رکورد جدیدی برای ثبت وجود ندارد (همه ردیف‌ها تکراری یا نامعتبر هستند).', 422);
        }
        respond_error('هیچ رکورد معتبری در فایل وجود ندارد؛ ثبت انجام نشد.', 422);
    }

    $done = product_excel_insert_rows($mysqli, $result['records'], count($unitsSheetRows) === 0, $categoryByName, $importMode);

    /* درج واحدهای اندازه‌گیری در صورت وجود برگه واحدها */
    $unitInserted = 0;
    if (count($unitsSheetRows) > 0 && count($unitResult['records']) > 0) {
        $unitProcessed = product_excel_insert_units($mysqli, $unitResult['records'], $done['id_map']);
        $unitInserted = $unitProcessed['inserted'];
    }

    respond_json(true, $importMode === 'update_existing' ? 'ورود با موفقیت انجام شد.' : 'ثبت کالاها و خدمات با موفقیت انجام شد.', array(
        'file_name' => $origName,
        'total'     => $result['total'],
        'inserted'  => $done['inserted'],
        'updated'   => $done['updated'],
        'skipped'   => $done['skipped'],
        'conflicts' => $done['conflicts'],
        'rejected'  => $done['rejected'],
        'categories_created' => $done['categories_created'],
        'units_inserted' => $unitInserted,
    ));
} catch (ProductExcelReaderException $e) {
    respond_error($e->getMessage());
} catch (Exception $e) {
    respond_error('خطا در ثبت کالاها: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    respond_error('خطای پیش‌بینی‌نشده در پردازش: ' . $e->getMessage(), 500);
} finally {
    if (is_file($tmpFile)) {
        @unlink($tmpFile);
    }
}

/* این بخش هرگز اجرا نمی‌شود (respond_json خروج می‌کند) */
exit;