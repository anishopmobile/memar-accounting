<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * محاسبه قیمت جدید بر اساس نوع افزایش (درصدی / مبلغی)
 * اگر قیمت فعلی null/خالی باشد، null برمی‌گرداند تا آن محصول تغییر نکند.
 * قیمت‌ها در جدول DECIMAL(14,2) و NOT NULL هستند؛ به ۲ رقم اعشار گرد می‌شوند.
 */
function computeIncreasedPrice($current, $increaseType, $increaseValue)
{
    if ($current === null || $current === '') {
        return null;
    }
    $current = (float) $current;
    if ($increaseType === 'percent') {
        return round($current + ($current * $increaseValue / 100), 2);
    }
    return round($current + $increaseValue, 2);
}

/* ---------- دریافت و اعتبارسنجی ورودی‌ها ---------- */
$scope = trim($_POST['scope'] ?? '');
$priceType = trim($_POST['price_type'] ?? '');
$increaseType = trim($_POST['increase_type'] ?? '');
$increaseValueRaw = trim($_POST['increase_value'] ?? '');

$allowedPriceTypes = ['sale', 'purchase', 'both'];
$allowedIncreaseTypes = ['percent', 'amount'];

if (!in_array($priceType, $allowedPriceTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'نوع قیمت انتخاب‌شده نامعتبر است.']);
    exit;
}
if (!in_array($increaseType, $allowedIncreaseTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'نوع افزایش انتخاب‌شده نامعتبر است.']);
    exit;
}
if ($increaseValueRaw === '' || !is_numeric($increaseValueRaw) || (float) $increaseValueRaw <= 0) {
    echo json_encode(['success' => false, 'message' => 'میزان افزایش باید یک عدد مثبت باشد.']);
    exit;
}
$increaseValue = (float) $increaseValueRaw;

/* ---------- اعتبارسنجی و برچسب محدوده ---------- */
$scopeLabel = 'همه کالاها';
if ($scope !== '' && $scope !== 'all') {
    if (!ctype_digit($scope)) {
        echo json_encode(['success' => false, 'message' => 'محدوده انتخاب‌شده نامعتبر است.']);
        exit;
    }
    $stmt = $mysqli->prepare('SELECT name FROM product_categories WHERE id = ?');
    $stmt->bind_param('i', $scope);
    $stmt->execute();
    $stmt->bind_result($catName);
    if (!$stmt->fetch()) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'دسته‌بندی انتخاب‌شده معتبر نیست.']);
        exit;
    }
    $scopeLabel = $catName;
    $stmt->close();
}

$priceLabels = ['sale' => 'قیمت فروش', 'purchase' => 'قیمت خرید', 'both' => 'قیمت فروش و خرید'];
$increaseLabels = ['percent' => 'درصدی', 'amount' => 'مبلغ'];
$priceLabel = $priceLabels[$priceType];
$increaseLabel = $increaseLabels[$increaseType];

/* ---------- انتخاب محصولات در محدوده ---------- */
$products = [];
if ($scope === '' || $scope === 'all') {
    $result = $mysqli->query('SELECT id, purchase_price, sale_price FROM products');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $result->free();
    }
} else {
    $stmt = $mysqli->prepare('SELECT id, purchase_price, sale_price FROM products WHERE category_id = ?');
    $stmt->bind_param('i', $scope);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $result->free();
    }
    $stmt->close();
}

$reviewed = count($products);
$changed = 0;

/* ---------- اعمال افزایش قیمت داخل Transaction ---------- */
if ($reviewed > 0) {
    if ($priceType === 'sale') {
        $updSql = 'UPDATE products SET sale_price = ? WHERE id = ?';
        $updTypes = 'di';
    } elseif ($priceType === 'purchase') {
        $updSql = 'UPDATE products SET purchase_price = ? WHERE id = ?';
        $updTypes = 'di';
    } else {
        $updSql = 'UPDATE products SET purchase_price = ?, sale_price = ? WHERE id = ?';
        $updTypes = 'ddi';
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare($updSql);
        // به‌روزرسانی واحد پایه هر کالا (همگام با قیمت محصول)
        // فقط ستون‌هایی که افزایش می‌یابند به‌روزرسانی می‌شوند؛ هیچ‌وقت NULL به ستون‌های NOT NULL ارسال نمی‌شود.
        if ($priceType === 'sale') {
            $baseUpdSql = 'UPDATE product_units SET sale_price = ? WHERE product_id = ? AND is_base = 1';
            $baseUpdTypes = 'di';
        } elseif ($priceType === 'purchase') {
            $baseUpdSql = 'UPDATE product_units SET purchase_price = ? WHERE product_id = ? AND is_base = 1';
            $baseUpdTypes = 'di';
        } else {
            $baseUpdSql = 'UPDATE product_units SET purchase_price = ?, sale_price = ? WHERE product_id = ? AND is_base = 1';
            $baseUpdTypes = 'ddi';
        }
        $basePriceStmt = $mysqli->prepare($baseUpdSql);
        foreach ($products as $p) {
            $id = (int) $p['id'];
            $applyPurchase = in_array($priceType, ['purchase', 'both'], true);
            $applySale = in_array($priceType, ['sale', 'both'], true);

            $newPurchase = $applyPurchase ? computeIncreasedPrice($p['purchase_price'], $increaseType, $increaseValue) : null;
            $newSale = $applySale ? computeIncreasedPrice($p['sale_price'], $increaseType, $increaseValue) : null;

            if ($newPurchase === null && $newSale === null) {
                continue;
            }

            if ($priceType === 'sale') {
                $stmt->bind_param($updTypes, $newSale, $id);
            } elseif ($priceType === 'purchase') {
                $stmt->bind_param($updTypes, $newPurchase, $id);
            } else {
                $stmt->bind_param($updTypes, $newPurchase, $newSale, $id);
            }

            if (!$stmt->execute()) {
                throw new Exception('update failed: ' . $mysqli->error);
            }
            $changed++;

            // همگام‌سازی قیمت واحد پایه (همگام با قیمت محصول)
            if ($priceType === 'sale') {
                $basePriceStmt->bind_param($baseUpdTypes, $newSale, $id);
            } elseif ($priceType === 'purchase') {
                $basePriceStmt->bind_param($baseUpdTypes, $newPurchase, $id);
            } else {
                $basePriceStmt->bind_param($baseUpdTypes, $newPurchase, $newSale, $id);
            }
            if (!$basePriceStmt->execute()) {
                throw new Exception('base unit update failed: ' . $mysqli->error);
            }
        }
        $stmt->close();
        $basePriceStmt->close();
        $mysqli->commit();
    } catch (Exception $e) {
        if ($mysqli->errno) {
            $mysqli->rollback();
        }
        error_log('[price_increase] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'عملیات انجام نشد و هیچ تغییری در اطلاعات ایجاد نشده است.']);
        exit;
    }
}

$message = 'افزایش قیمت با موفقیت انجام شد.';
if ($reviewed === 0) {
    $message = 'هیچ کالایی در محدوده انتخاب‌شده یافت نشد.';
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'reviewed' => $reviewed,
    'changed' => $changed,
    'scope_label' => $scopeLabel,
    'price_type' => $priceType,
    'price_label' => $priceLabel,
    'increase_type' => $increaseType,
    'increase_label' => $increaseLabel,
    'increase_value' => $increaseValue,
]);
exit;
