<?php
require_once __DIR__ . '/product_common.php';
require_once __DIR__ . '/bundle_common.php';
header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'next_code') {
    echo json_encode(['success' => true, 'code' => nextProductCode($mysqli)]);
    exit;
}

if ($action === 'delete') {
    $product_id = trim($_POST['product_id'] ?? '');
    if ($product_id === '' || !ctype_digit($product_id)) {
        echo json_encode(['success' => false, 'message' => 'شناسه کالا نامعتبر است.']);
        exit;
    }
    $product_id = (int) $product_id;

    /* حفاظت: کالایی که جزء یک یا چند بسته است قابل حذف نیست (حفظ یکپارچگی رابطه) */
    $stmt = $mysqli->prepare('SELECT 1 FROM bundle_components WHERE component_product_id = ? LIMIT 1');
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $stmt->store_result();
    $isComponent = $stmt->num_rows > 0;
    $stmt->close();
    if ($isComponent) {
        echo json_encode(['success' => false, 'message' => 'این کالا در ترکیب یک یا چند بسته استفاده شده و قابل حذف نیست. ابتدا آن را از بسته‌ها حذف کنید.']);
        exit;
    }

    /* بسته: حذف با محافظت از سوابق تاریخی (invoice_bundle_items) */
    if (isBundleProduct($mysqli, $product_id)) {
        $res = bundleDeleteProduct($mysqli, $product_id);
        if ($res['ok']) {
            echo json_encode(['success' => true, 'action' => 'deleted', 'product_id' => $product_id]);
        } else {
            echo json_encode(['success' => false, 'message' => $res['message']]);
        }
        exit;
    }

    /* کالای عادی — رفتار قبلی بدون تغییر */
    $stmt = $mysqli->prepare('DELETE FROM products WHERE id = ?');
    $stmt->bind_param('i', $product_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'action' => 'deleted', 'product_id' => $product_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در حذف کالا.']);
    }
    $stmt->close();
    exit;
}

/**
 * نرمال‌سازی و اعتبارسنجی واحدهای اندازه‌گیری ارسال‌شده از فرم.
 *
 * @return array ['ok' => bool, 'message' => string, 'units' => array|null]
 */
function product_action_normalize_units($raw): array
{
    $units = [];
    if ($raw === null || $raw === '') {
        // فرم قدیمی بدون واحدهای جداگانه → رفتار سازگار با قبل
        return ['ok' => true, 'message' => '', 'units' => null];
    }
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'ساختار واحدهای اندازه‌گیری نامعتبر است.', 'units' => []];
        }
        $raw = $decoded;
    }
    if (!is_array($raw)) {
        return ['ok' => false, 'message' => 'ساختار واحدهای اندازه‌گیری نامعتبر است.', 'units' => []];
    }

    $seenNames = [];
    foreach ($raw as $idx => $u) {
        if (!is_array($u)) {
            return ['ok' => false, 'message' => 'ساختار واحدهای اندازه‌گیری نامعتبر است.', 'units' => []];
        }
        $name = trim((string) ($u['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'نام واحد (ردیف ' . ($idx + 1) . ') اجباری است.', 'units' => []];
        }
        if (mb_strlen($name) > 50) {
            return ['ok' => false, 'message' => 'نام واحد حداکثر ۵۰ کاراکتر است.', 'units' => []];
        }
        $nameKey = mb_strtolower(trim($name), 'UTF-8');
        if (isset($seenNames[$nameKey])) {
            return ['ok' => false, 'message' => 'نام واحد «' . $name . '» تکراری است.', 'units' => []];
        }
        $seenNames[$nameKey] = true;

        $factorRaw = trim((string) ($u['conversion_factor'] ?? $u['factor'] ?? '1'));
        if ($factorRaw === '' || !is_numeric($factorRaw) || (float) $factorRaw <= 0) {
            return ['ok' => false, 'message' => 'ضریب تبدیل واحد «' . $name . '» باید عددی مثبت باشد.', 'units' => []];
        }
        $factor = sprintf('%.4F', (float) $factorRaw);

        $barcode = trim((string) ($u['barcode'] ?? ''));
        if ($barcode === '') {
            $barcode = null;
        } elseif (mb_strlen($barcode) > 100) {
            return ['ok' => false, 'message' => 'بارکد واحد «' . $name . '» حداکثر ۱۰۰ کاراکتر است.', 'units' => []];
        }

        $purchase = trim((string) ($u['purchase_price'] ?? '0'));
        $sale = trim((string) ($u['sale_price'] ?? '0'));
        if ($purchase !== '' && (!is_numeric($purchase) || (float) $purchase < 0)) {
            return ['ok' => false, 'message' => 'قیمت خرید واحد «' . $name . '» نامعتبر است.', 'units' => []];
        }
        if ($sale !== '' && (!is_numeric($sale) || (float) $sale < 0)) {
            return ['ok' => false, 'message' => 'قیمت فروش واحد «' . $name . '» نامعتبر است.', 'units' => []];
        }

        $id = trim((string) ($u['id'] ?? ''));
        $units[] = [
            'id'                => ($id !== '' && ctype_digit($id)) ? (int) $id : null,
            'name'              => $name,
            'conversion_factor' => $factor,
            'is_base'           => (!empty($u['is_base']) || $u['is_base'] === '1' || $u['is_base'] === 1 || $u['is_base'] === true) ? 1 : 0,
            'purchase_price'    => ($purchase === '') ? '0' : sprintf('%.2F', (float) $purchase),
            'sale_price'        => ($sale === '') ? '0' : sprintf('%.2F', (float) $sale),
            'barcode'           => $barcode,
            'sort_order'        => (int) ($u['sort_order'] ?? $idx),
        ];
    }

    if (count($units) === 0) {
        return ['ok' => false, 'message' => 'حداقل یک واحد اندازه‌گیری لازم است.', 'units' => []];
    }

    // تعیین واحد پایه
    $baseCount = 0;
    foreach ($units as $u) {
        if ($u['is_base'] === 1) {
            $baseCount++;
        }
    }
    if ($baseCount === 0) {
        foreach ($units as &$u) {
            if (bccomp($u['conversion_factor'], '1', 4) === 0) {
                $u['is_base'] = 1;
                $baseCount = 1;
                break;
            }
        }
        unset($u);
    }
    if ($baseCount !== 1) {
        return ['ok' => false, 'message' => 'هر کالا باید دقیقاً یک واحد پایه داشته باشد.', 'units' => []];
    }
    foreach ($units as $u) {
        if ($u['is_base'] === 1 && bccomp($u['conversion_factor'], '1', 4) !== 0) {
            return ['ok' => false, 'message' => 'ضریب تبدیل واحد پایه باید برابر ۱ باشد.', 'units' => []];
        }
    }

    return ['ok' => true, 'message' => '', 'units' => $units];
}

/**
 * شناسه واحدهایی که در اقلام فاکتورهای تاریخی استفاده شده‌اند.
 */
function product_action_referenced_unit_ids(mysqli $mysqli, int $productId): array
{
    $refs = [];
    $stmt = $mysqli->prepare('SELECT DISTINCT unit_id FROM invoice_items WHERE product_id = ? AND unit_id IS NOT NULL');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $refs[(int) $row['unit_id']] = true;
        }
        $res->free();
    }
    return $refs;
}

/**
 * ذخیره مجموعه واحدهای یک کالا (جایگزینی) با حفاظت از واحدهای مورد استفاده در تاریخ. 
 *
 * @param array $units       خروجی نرمال‌شده
 * @param array $refUnitIds  map: unit_id => true
 * @return string|null پیام خطا یا null در صورت موفقیت
 */
function product_action_save_units(mysqli $mysqli, int $productId, array $units, array $refUnitIds): ?string
{
    $existing = [];
    $res = $mysqli->query("SELECT id, name, conversion_factor FROM product_units WHERE product_id = {$productId}");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existing[(int) $row['id']] = $row;
        }
        $res->free();
    }

    $newById = [];
    foreach ($units as $u) {
        if ($u['id'] !== null) {
            $newById[$u['id']] = $u;
        }
    }

    // حفاظت از واحدهای دارای سابقه: حذف ممنوع، تغییر نام/ضریب ممنوع
    foreach ($refUnitIds as $uid => $_) {
        $uid = (int) $uid;
        if (!isset($newById[$uid])) {
            return 'واحد «' . ($existing[$uid]['name'] ?? '') . '» در فاکتورهای قبلی استفاده شده و قابل حذف نیست.';
        }
        $old = $existing[$uid] ?? null;
        $nu = $newById[$uid];
        if ($old !== null) {
            if (mb_strtolower($old['name'], 'UTF-8') !== mb_strtolower($nu['name'], 'UTF-8')) {
                return 'نام واحد «' . $old['name'] . '» در فاکتورهای قبلی استفاده شده و قابل تغییر نیست.';
            }
            if (bccomp((string) $old['conversion_factor'], $nu['conversion_factor'], 4) !== 0) {
                return 'ضریب تبدیل واحد «' . $old['name'] . '» در فاکتورهای قبلی استفاده شده و قابل تغییر نیست.';
            }
        }
    }

    // حذف واحدهایی که در مجموعه جدید نیستند (غیر از واحدهای دارای سابقه که بالا رد شدند)
    $toDelete = array_diff_key($existing, $newById);
    foreach (array_keys($toDelete) as $uid) {
        $stmt = $mysqli->prepare('DELETE FROM product_units WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();
    }

    // به‌روزرسانی / درج
    foreach ($units as $u) {
        $barcode = $u['barcode'];
        if ($u['id'] !== null && isset($existing[$u['id']])) {
            $stmt = $mysqli->prepare(
                'UPDATE product_units
                 SET name = ?, conversion_factor = ?, is_base = ?, purchase_price = ?, sale_price = ?, barcode = ?, sort_order = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sdisssii',
                $u['name'],
                $u['conversion_factor'],
                $u['is_base'],
                $u['purchase_price'],
                $u['sale_price'],
                $barcode,
                $u['sort_order'],
                $u['id']
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare(
                'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'isdisssi',
                $productId,
                $u['name'],
                $u['conversion_factor'],
                $u['is_base'],
                $u['purchase_price'],
                $u['sale_price'],
                $barcode,
                $u['sort_order']
            );
            $stmt->execute();
            $stmt->close();
        }
    }

    return null; // بدون خطا
}

$product_id = trim($_POST['product_id'] ?? '');
$code = trim($_POST['code'] ?? '');
$name = trim($_POST['name'] ?? '');
$category_id = trim($_POST['category_id'] ?? '');
$type = trim($_POST['type'] ?? 'product');
$unit = trim($_POST['unit'] ?? 'عدد');
$purchase_price = trim($_POST['purchase_price'] ?? '');
$sale_price = trim($_POST['sale_price'] ?? '');
$stock = trim($_POST['stock'] ?? '');
$min_stock = trim($_POST['min_stock'] ?? '');
$description = trim($_POST['description'] ?? '');
$barcode = trim($_POST['barcode'] ?? '');
if ($barcode === '') {
    $barcode = null;
} elseif (mb_strlen($barcode) > 100) {
    echo json_encode(['success' => false, 'message' => 'بارکد حداکثر ۱۰۰ کاراکتر است.']);
    exit;
}

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'نام کالا اجباری است.']);
    exit;
}

if ($code === '') {
    $code = nextProductCode($mysqli);
}

if ($category_id === '') {
    $category_id = null;
}
if ($type !== 'product' && $type !== 'service') {
    $type = 'product';
}

/* ---- نرمال‌سازی و اعتبارسنجی واحدهای اندازه‌گیری ---- */
$unitsRaw = $_POST['units'] ?? null;
$normUnits = product_action_normalize_units($unitsRaw);
if (!$normUnits['ok']) {
    echo json_encode(['success' => false, 'message' => $normUnits['message']]);
    exit;
}
$unitsList = $normUnits['units'];
if ((string) $unit === '') {
    $unit = 'عدد';
}
if ($purchase_price === '') {
    $purchase_price = 0;
}
if ($sale_price === '') {
    $sale_price = 0;
}
if ($stock === '') {
    $stock = 0;
}
if ($min_stock === '') {
    $min_stock = 0;
}

/* ---- بررسی تکراری نبودن بارکد کالا (به جز خودِ کالا در ویرایش) ---- */
if ($barcode !== null) {
    $barcodeCheckId = ($product_id !== '' && ctype_digit($product_id)) ? (int) $product_id : 0;
    $dupStmt = $mysqli->prepare('SELECT id FROM products WHERE barcode = ? AND id <> ? LIMIT 1');
    $dupStmt->bind_param('si', $barcode, $barcodeCheckId);
    $dupStmt->execute();
    $dupRes = $dupStmt->get_result();
    $dupExists = $dupRes && $dupRes->fetch_assoc();
    $dupStmt->close();
    if ($dupExists) {
        echo json_encode(['success' => false, 'message' => 'بارکد «' . $barcode . '» قبلاً برای کالای دیگری ثبت شده است.']);
        exit;
    }
}

if ($product_id !== '') {
    /* ---- ویرایش ---- */
    if (!ctype_digit($product_id)) {
        echo json_encode(['success' => false, 'message' => 'شناسه کالا نامعتبر است.']);
        exit;
    }
    $product_id = (int) $product_id;

    /* حفاظت: بسته فقط از ویرایشگر بسته قابل ویرایش است */
    if (isBundleProduct($mysqli, $product_id)) {
        echo json_encode(['success' => false, 'message' => 'این کالا یک بسته است؛ برای ویرایش از دکمه «ویرایش بسته» در جدول کالاها استفاده کنید.']);
        exit;
    }

    /* حفاظت: کالایی که جزء یک بسته است نمی‌تواند به خدمت تبدیل شود */
    if ($type !== 'product') {
        $stmt = $mysqli->prepare('SELECT 1 FROM bundle_components WHERE component_product_id = ? LIMIT 1');
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $stmt->store_result();
        $isComponent = $stmt->num_rows > 0;
        $stmt->close();
        if ($isComponent) {
            echo json_encode(['success' => false, 'message' => 'این کالا جزء یک بسته است و نمی‌تواند به خدمت تبدیل شود.']);
            exit;
        }
    }

    /* همگام‌سازی قیمت‌ها و واحد پایه با واحد پایه انتخابی */
    if ($unitsList !== null) {
        foreach ($unitsList as $u) {
            if ($u['is_base'] === 1) {
                $purchase_price = $u['purchase_price'];
                $sale_price = $u['sale_price'];
                $unit = $u['name'];
                break;
            }
        }
    }

    $mysqli->begin_transaction();
    try {
        if ($category_id === null) {
            $stmt = $mysqli->prepare('UPDATE products SET code = ?, name = ?, barcode = ?, category_id = NULL, type = ?, unit = ?, purchase_price = ?, sale_price = ?, stock = ?, min_stock = ?, description = ? WHERE id = ?');
            $stmt->bind_param('sssssddddsi', $code, $name, $barcode, $type, $unit, $purchase_price, $sale_price, $stock, $min_stock, $description, $product_id);
        } else {
            $stmt = $mysqli->prepare('UPDATE products SET code = ?, name = ?, barcode = ?, category_id = ?, type = ?, unit = ?, purchase_price = ?, sale_price = ?, stock = ?, min_stock = ?, description = ? WHERE id = ?');
            $stmt->bind_param('sssissddddsi', $code, $name, $barcode, $category_id, $type, $unit, $purchase_price, $sale_price, $stock, $min_stock, $description, $product_id);
        }
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $stmt->close();

        if ($unitsList !== null) {
            $refs = product_action_referenced_unit_ids($mysqli, $product_id);
            $err = product_action_save_units($mysqli, $product_id, $unitsList, $refs);
            if ($err !== null) {
                throw new Exception($err);
            }
        } else {
            // فرم قدیمی: اطمینان از وجود واحد پایه با ضریب ۱ و همگام‌سازی قیمت
            $base = getBaseUnit($mysqli, $product_id);
            if ($base === null) {
                $stmt = $mysqli->prepare(
                    'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
                     VALUES (?, ?,1,1, ?, ?, ?,0)'
                );
                $stmt->bind_param('issss', $product_id, $unit, $purchase_price, $sale_price, $barcode);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $mysqli->prepare(
                    'UPDATE product_units SET name = ?, purchase_price = ?, sale_price = ?, barcode = ? WHERE id = ?'
                );
                $stmt->bind_param('ssssi', $unit, $purchase_price, $sale_price, $barcode, $base['id']);
                $stmt->execute();
                $stmt->close();
            }
        }

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'action' => 'edited',
        'product' => [
            'id' => $product_id,
            'code' => $code,
            'name' => $name,
            'barcode' => $barcode,
            'category_id' => $category_id,
            'type' => $type,
            'unit' => $unit,
            'purchase_price' => (string) $purchase_price,
            'sale_price' => (string) $sale_price,
            'stock' => (string) $stock,
            'min_stock' => (string) $min_stock,
            'description' => $description,
            'units' => getProductUnits($mysqli, $product_id),
        ],
    ]);
    exit;
}

/* ---- افزودن ---- */
$mysqli->begin_transaction();
try {
    if ($unitsList !== null) {
        foreach ($unitsList as $u) {
            if ($u['is_base'] === 1) {
                $purchase_price = $u['purchase_price'];
                $sale_price = $u['sale_price'];
                $unit = $u['name'];
                break;
            }
        }
    }

    if ($category_id === null) {
        $stmt = $mysqli->prepare('INSERT INTO products (code, barcode, name, type, unit, purchase_price, sale_price, stock, min_stock, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssssdddds', $code, $barcode, $name, $type, $unit, $purchase_price, $sale_price, $stock, $min_stock, $description);
    } else {
        $stmt = $mysqli->prepare('INSERT INTO products (code, barcode, name, category_id, type, unit, purchase_price, sale_price, stock, min_stock, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssissdddds', $code, $barcode, $name, $category_id, $type, $unit, $purchase_price, $sale_price, $stock, $min_stock, $description);
    }
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    if ($unitsList !== null) {
        $err = product_action_save_units($mysqli, $id, $unitsList, []);
        if ($err !== null) {
            throw new Exception($err);
        }
    } else {
        $stmt = $mysqli->prepare(
            'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
             VALUES (?, ?, 1, 1, ?, ?, ?, 0)'
        );
        $stmt->bind_param('issss', $id, $unit, $purchase_price, $sale_price, $barcode);
        $stmt->execute();
        $stmt->close();
    }

    $mysqli->commit();
} catch (Exception $e) {
    $mysqli->rollback();
    echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی کالا: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'action' => 'added',
    'product' => [
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'barcode' => $barcode,
        'category_id' => $category_id,
        'type' => $type,
        'unit' => $unit,
        'purchase_price' => (string) $purchase_price,
        'sale_price' => (string) $sale_price,
        'stock' => (string) $stock,
        'min_stock' => (string) $min_stock,
        'description' => $description,
        'units' => getProductUnits($mysqli, $id),
    ],
]);
