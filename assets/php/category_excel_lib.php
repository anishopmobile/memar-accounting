<?php
/**
 * Pure parsing/validation for category Excel import.
 * Reuses ProductExcelReader and product_excel_lib; no DB, session or Composer.
 */
require_once __DIR__ . '/product_excel_lib.php';

function category_excel_key($value)
{
    return product_excel_canonical((string) $value);
}

function category_excel_aliases()
{
    $titles = array(
        'code' => array('کد گروه', 'کد دسته‌بندی', 'کد دسته بندی', 'code'),
        'name' => array('نام گروه', 'نام دسته‌بندی', 'نام دسته بندی', 'name'),
        'parent_code' => array('کد گروه مادر', 'کد دسته مادر', 'کد دسته‌بندی مادر', 'کد دسته بندی مادر', 'parent_code')
    );
    $aliases = array();
    foreach ($titles as $field => $values) {
        $aliases[$field] = array();
        foreach ($values as $title) {
            $aliases[$field][category_excel_key($title)] = true;
        }
    }
    return $aliases;
}

function category_excel_parse(array $sheetRows, $limit = 2000)
{
    $aliases = category_excel_aliases();
    $header = product_excel_find_header_generic($sheetRows, $aliases, array('code', 'name'));
    if (!$header['found']) {
        throw new InvalidArgumentException('ستون‌های «کد گروه» و «نام گروه» در فایل پیدا نشدند.');
    }
    $mapping = product_excel_build_mapping_generic($sheetRows[$header['index']]['cells'], $aliases);
    $dataRows = array_slice($sheetRows, $header['index'] + 1);
    if (count($dataRows) > $limit) {
        throw new InvalidArgumentException('تعداد ردیف‌های فایل بیش از حد مجاز است (' . $limit . ').');
    }

    $records = array();
    $seen = array();
    foreach ($dataRows as $row) {
        $cells = $row['cells'];
        $map = $mapping['map'];
        $line = (int) $row['row'];
        $code = trim(product_excel_to_latin_digits(product_excel_cell_value($cells, $map, 'code')));
        $name = trim((string) product_excel_cell_value($cells, $map, 'name'));
        $parent = trim(product_excel_to_latin_digits(product_excel_cell_value($cells, $map, 'parent_code')));
        if ($code === '' && $name === '' && $parent === '') {
            continue;
        }
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('ردیف ' . $line . ': کد و نام گروه اجباری است.');
        }
        if (mb_strlen($code, 'UTF-8') > 50 || mb_strlen($name, 'UTF-8') > 150 ||
            mb_strlen($parent, 'UTF-8') > 50) {
            throw new InvalidArgumentException('ردیف ' . $line . ': طول کد یا نام گروه بیش از حد مجاز است.');
        }
        $key = category_excel_key($code);
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('کد گروه «' . $code . '» در ردیف‌های '
                . $seen[$key] . ' و ' . $line . ' فایل تکرار شده است.');
        }
        $seen[$key] = $line;
        $records[] = array('row' => $line, 'code' => $code, 'name' => $name, 'parent_code' => $parent);
    }
    if (!$records) {
        throw new InvalidArgumentException('فایل هیچ ردیف دسته‌بندی معتبری ندارد.');
    }
    return $records;
}

/** Build a canonical code map. Never silently collapse multiple matching DB records. */
function category_excel_existing_map(array $rows)
{
    $existing = array();
    foreach ($rows as $row) {
        $key = category_excel_key($row['code']);
        if (isset($existing[$key])) {
            throw new RuntimeException('چند کد معادل در دیتابیس وجود دارد؛ ورود متوقف شد.');
        }
        $existing[$key] = array(
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name']
        );
    }
    return $existing;
}

/**
 * All-or-nothing preview. Existing categories only update their name;
 * parent_code is deliberately ignored for an existing category.
 */
function category_excel_plan(array $rows, array $existing, $mode)
{
    if ($mode !== 'skip_duplicates' && $mode !== 'update_existing') {
        throw new InvalidArgumentException('روش برخورد با کدهای تکراری نامعتبر است.');
    }

    $file = array();
    foreach ($rows as $row) {
        $key = category_excel_key($row['code']);
        if (isset($file[$key])) {
            throw new InvalidArgumentException('کد تکراری در فایل شناسایی شد.');
        }
        $file[$key] = $row;
    }

    $records = array();
    $errors = array();
    $counts = array('total' => count($rows), 'valid' => 0, 'new' => 0,
                    'updates' => 0, 'duplicates' => 0, 'invalid' => 0);

    foreach ($rows as $row) {
        $key = category_excel_key($row['code']);
        $parentKey = category_excel_key($row['parent_code']);
        $status = 'ok';
        $message = '';
        if (isset($existing[$key])) {
            $status = $mode === 'update_existing' ? 'update' : 'duplicate';
        } else {
            if ($parentKey !== '' && !isset($existing[$parentKey]) && !isset($file[$parentKey])) {
                $message = 'کد گروه مادر «' . $row['parent_code'] . '» وجود ندارد.';
            } else {
                $visited = array($key => true);
                $next = $parentKey;
                while ($next !== '' && !isset($existing[$next]) && isset($file[$next])) {
                    if (isset($visited[$next])) {
                        $message = 'وابستگی چرخه‌ای بین دسته‌بندی‌ها وجود دارد.';
                        break;
                    }
                    $visited[$next] = true;
                    $next = category_excel_key($file[$next]['parent_code']);
                }
            }
            if ($message !== '') {
                $status = 'error';
            }
        }
        if ($status === 'ok') {
            $counts['new']++;
            $counts['valid']++;
        } elseif ($status === 'update') {
            $counts['updates']++;
            $counts['valid']++;
        } elseif ($status === 'duplicate') {
            $counts['duplicates']++;
        } else {
            $counts['invalid']++;
            $errors[] = 'ردیف ' . $row['row'] . ': ' . $message;
        }
        $records[] = array(
            'row' => $row['row'],
            'data' => array('code' => $row['code'], 'name' => $row['name'], 'parent_code' => $row['parent_code']),
            'status' => $status,
            'message' => $message
        );
    }
    $counts['records'] = $records;
    $counts['errors'] = $errors;
    return $counts;
}
