<?php
/**
 * Category Excel preview/import — same request contract as product_import.php.
 * Reads XLS/XLSX with the in-project ProductExcelReader (no new packages).
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_excel_reader.php';
require_once __DIR__ . '/category_excel_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_error('روش درخواست نامعتبر است.', 405);
}
require_csrf_or_fail();

const CATEGORY_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const CATEGORY_IMPORT_MAX_ROWS = 2000;

$action = trim((string) ($_POST['action'] ?? ''));
if ($action !== 'preview' && $action !== 'import') {
    respond_error('فقط پیش‌نمایش یا ثبت نهایی مجاز است.', 422);
}
$mode = (string) ($_POST['import_mode'] ?? 'skip_duplicates');
if ($mode !== 'skip_duplicates' && $mode !== 'update_existing') {
    respond_error('روش برخورد با تکراری‌ها نامعتبر است.', 422);
}

$upload = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    respond_error('فایل انتخاب نشده یا آپلود آن ناموفق بوده است.', 422);
}
$name = (string) ($upload['name'] ?? '');
$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($extension, array('xls', 'xlsx'), true)) {
    respond_error('فقط فایل‌های XLS یا XLSX پذیرفته می‌شوند.', 422);
}
$size = (int) ($upload['size'] ?? 0);
if ($size <= 0 || $size > CATEGORY_IMPORT_MAX_BYTES) {
    respond_error('حجم فایل نامعتبر است؛ حداکثر ۵ مگابایت مجاز است.', 422);
}
$source = $upload['tmp_name'] ?? null;
if (!is_string($source) || !is_uploaded_file($source)) {
    respond_error('فایل آپلودی معتبر نیست.', 422);
}

// Same temporary-copy pattern as the existing product import endpoint.
$tmpFile = @tempnam(sys_get_temp_dir(), 'cimx_');
if ($tmpFile === false) {
    respond_error('امکان ساخت فایل موقت وجود ندارد.', 500);
}
register_shutdown_function(function () use ($tmpFile) {
    if (is_file($tmpFile)) {
        @unlink($tmpFile);
    }
});
if (!@copy($source, $tmpFile)) {
    respond_error('امکان ذخیره فایل موقت وجود ندارد.', 500);
}

function category_import_existing(mysqli $db, $lock = false)
{
    $sql = 'SELECT id,code,name FROM product_categories ORDER BY id';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $res = $db->query($sql);
    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
    return category_excel_existing_map($rows);
}

$inTransaction = false;

try {
    $sheetRows = ProductExcelReader::readFile($tmpFile);
    $records = category_excel_parse($sheetRows, CATEGORY_IMPORT_MAX_ROWS);

    $existing = category_import_existing($mysqli);
    $plan = category_excel_plan($records, $existing, $mode);

    if ($action === 'preview') {
        // A successful preview must be HTTP 200 with *pure* JSON.
        respond_json(true, 'فایل با موفقیت خوانده شد.', $plan, 200);
    }

    // Ensure bad rows cannot silently produce a partial category hierarchy.
    if ($plan['invalid'] > 0) {
        respond_error(implode(' | ', $plan['errors']), 422);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli->begin_transaction();
    $inTransaction = true;

    // Re-evaluate against the locked current DB rows, not the old preview.
    $existing = category_import_existing($mysqli, true);
    $plan = category_excel_plan($records, $existing, $mode);
    if ($plan['invalid'] > 0) {
        throw new InvalidArgumentException(implode(' | ', $plan['errors']));
    }

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $unchanged = 0;
    $pending = array();

    $update = $mysqli->prepare('UPDATE product_categories SET name=? WHERE id=?');
    foreach ($plan['records'] as $record) {
        $status = $record['status'];
        $data = $record['data'];
        if ($status === 'duplicate') {
            $skipped++;
        } elseif ($status === 'update') {
            $key = category_excel_key($data['code']);
            $id = (int) $existing[$key]['id'];
            $newName = $data['name'];
            if ($existing[$key]['name'] === $newName) {
                $unchanged++;
            } else {
                $update->bind_param('si', $newName, $id);
                $update->execute();
                $existing[$key]['name'] = $newName;
                $updated++;
            }
        } elseif ($status === 'ok') {
            $pending[category_excel_key($data['code'])] = $data;
        }
    }
    $update->close();

    // Insert parents before children, even when spreadsheet rows are unordered.
    $insert = $mysqli->prepare('INSERT INTO product_categories (code,name,parent_id) VALUES (?,?,?)');
    while ($pending) {
        $progress = false;
        foreach ($pending as $key => $data) {
            $parentKey = category_excel_key($data['parent_code']);
            if ($parentKey !== '' && !isset($existing[$parentKey])) {
                continue;
            }
            $parentId = $parentKey === '' ? null : (int) $existing[$parentKey]['id'];
            $code = $data['code'];
            $name = $data['name'];
            $insert->bind_param('ssi', $code, $name, $parentId);
            $insert->execute();
            $existing[$key] = array('id' => (int) $insert->insert_id, 'code' => $code, 'name' => $name);
            unset($pending[$key]);
            $inserted++;
            $progress = true;
        }
        if (!$progress) {
            throw new InvalidArgumentException('ارتباط دسته‌های مادر و فرزند قابل ثبت نیست.');
        }
    }
    $insert->close();

    $mysqli->commit();
    $inTransaction = false;
    respond_json(true, 'ورود دسته‌بندی‌ها با موفقیت انجام شد.', array(
        'total' => $plan['total'], 'inserted' => $inserted,
        'updated' => $updated, 'skipped' => $skipped, 'unchanged' => $unchanged
    ), 200);
} catch (ProductExcelReaderException $e) {
    if ($inTransaction) {
        $mysqli->rollback();
    }
    respond_error($e->getMessage(), 422);
} catch (InvalidArgumentException $e) {
    if ($inTransaction) {
        $mysqli->rollback();
    }
    respond_error($e->getMessage(), 422);
} catch (Throwable $e) {
    if ($inTransaction) {
        $mysqli->rollback();
    }
    error_log('Category Excel import: ' . $e->getMessage());
    if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
        respond_error('کد گروه تکراری است؛ مجدداً پیش‌نمایش بگیرید.', 409);
    }
    respond_error('خطا در پردازش یا ذخیره دسته‌بندی‌ها.', 500);
}
