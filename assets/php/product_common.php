<?php
require_once __DIR__ . '/db.php';

/**
 * تولید خودکار کد بعدی کالا (بزرگ‌ترین کد موجود + ۱)
 */
function nextProductCode($mysqli)
{
    $result = $mysqli->query('SELECT code FROM products ORDER BY CAST(code AS UNSIGNED) DESC LIMIT 1');
    if ($result && $row = $result->fetch_assoc()) {
        return (string) ((int) $row['code'] + 1);
    }
    return '1';
}

/**
 * دریافت درخت دسته‌بندی‌ها
 * @return array [$tree, $children]
 */
function getCategoryTree($mysqli): array
{
    $items = [];
    $result = $mysqli->query('SELECT * FROM product_categories ORDER BY parent_id IS NULL DESC, parent_id, CAST(code AS UNSIGNED)');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $result->free();
    }

    $tree = [];
    $children = [];
    foreach ($items as $item) {
        if ($item['parent_id'] === null) {
            $tree[$item['id']] = $item;
        } else {
            $children[$item['parent_id']][] = $item;
        }
    }

    return [$tree, $children];
}

/**
 * ساخت آپشن‌های دسته‌بندی (درختی) برای select مودال کالا
 */
function buildCategoryOptionsHtml($mysqli, $selectedId = '')
{
    list($tree, $children) = getCategoryTree($mysqli);

    $html = '<option value="">بدون دسته‌بندی</option>';
    foreach ($tree as $node) {
        $html .= buildCategoryOption($node, $children, 0, $selectedId);
    }
    return $html;
}

/**
 * ساخت آپشن‌های فیلتر دسته‌بندی (همه + درخت دسته‌بندی‌ها)
 */
function buildCategoryFilterOptionsHtml($mysqli, $selectedId = '', $firstLabel = 'همه')
{
    list($tree, $children) = getCategoryTree($mysqli);

    $html = '<option value="">' . htmlspecialchars($firstLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    foreach ($tree as $node) {
        $html .= buildCategoryOption($node, $children, 0, $selectedId);
    }
    return $html;
}

function buildCategoryOption(array $node, array $children, int $level, $selectedId): string
{
    $prefix = str_repeat('— ', $level);
    $sel = ((string) $node['id'] === (string) $selectedId) ? ' selected' : '';
    $html = '<option value="' . $node['id'] . '"' . $sel . '>' . $prefix . htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    if (!empty($children[$node['id']])) {
        foreach ($children[$node['id']] as $child) {
            $html .= buildCategoryOption($child, $children, $level + 1, $selectedId);
        }
    }
    return $html;
}

/**
 * محاسبه تعداد کل کالاها و ارزش کل موجودی (فقط کالاهای نوع «محصول»)
 * ارزش هر کالا = موجودی × قیمت خرید
 */
function getInventorySummary($mysqli): array
{
    $total = 0;
    $value = 0.0;

    $countResult = $mysqli->query('SELECT COUNT(*) AS c FROM products');
    if ($countResult && $row = $countResult->fetch_assoc()) {
        $total = (int) $row['c'];
    }
    if ($countResult) {
        $countResult->free();
    }

    $valueResult = $mysqli->query("SELECT COALESCE(SUM(stock * purchase_price), 0) AS v FROM products WHERE type = 'product' AND product_kind = 'standard'");
    if ($valueResult && $row = $valueResult->fetch_assoc()) {
        $value = (float) $row['v'];
    }
    if ($valueResult) {
        $valueResult->free();
    }

    return ['total' => $total, 'value' => $value];
}

/**
 * دریافت واحدهای اندازه‌گیری یک کالا
 */
function getProductUnits(mysqli $mysqli, int $productId): array
{
    $stmt = $mysqli->prepare('SELECT * FROM product_units WHERE product_id = ? ORDER BY is_base DESC, sort_order ASC, id ASC');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $units = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $units[] = $row;
        }
        $res->free();
    }
    return $units;
}

/**
 * دریافت واحد پایه یک کالا
 */
function getBaseUnit(mysqli $mysqli, int $productId): ?array
{
    foreach (getProductUnits($mysqli, $productId) as $u) {
        if ((int) $u['is_base'] === 1) {
            return $u;
        }
    }
    return null;
}

/**
 * دریافت واحدهای چند کالا در یک کوئری (برای جلوگیری از N+1)
 *
 * @return array<int, array> map product_id => units[]
 */
function getUnitsMapForProducts(mysqli $mysqli, array $productIds): array
{
    $ids = array_values(array_unique(array_map('intval', $productIds)));
    $map = [];
    if (count($ids) === 0) {
        return $map;
    }
    foreach ($ids as $id) {
        $map[$id] = [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $mysqli->prepare("SELECT * FROM product_units WHERE product_id IN ({$in}) ORDER BY is_base DESC, sort_order ASC, id ASC");
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int) $row['product_id'];
            if (isset($map[$pid])) {
                $map[$pid][] = $row;
            }
        }
        $res->free();
    }
    return $map;
}

/**
 * دکمه عملیات «بارکد داخلی» در ستون «عملیات» جدول کالاها.
 *
 * فقط برای کالاهای فیزیکی (type = 'product') نمایش داده می‌شود:
 *   - بدون بارکد   → «ایجاد بارکد»
 *   - دارای بارکد  → «چاپ بارکد»
 *
 * برای خدمات (service) هیچ دکمه بارکدی نمایش داده نمی‌شود و رفتار فعلی
 * (ویرایش/حذف) کاملاً بدون تغییر می‌ماند.
 */
function productBarcodeActionHtml(array $p): string
{
    if (($p['type'] ?? '') !== 'product') {
        return '';
    }
    $esc = function ($v) {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    };
    $barcode = (string) ($p['barcode'] ?? '');
    if ($barcode === '') {
        return ' <button type="button" class="button-secondary small generate-barcode" title="ایجاد بارکد داخلی">ایجاد بارکد</button>';
    }
    return ' <button type="button" class="button-secondary small print-barcode" title="چاپ بارکد">چاپ بارکد</button>';
}

/**
 * ساخت ردیف جدول کالا (با data attributes برای ویرایش/حذف)
 *
 * @param array      $p     ردیف محصول
 * @param array|null $units واحدهای اندازه‌گیری محصول (اختیاری؛ در صورت نبود، data-units خالی می‌ماند)
 */
function buildProductRow(array $p, ?array $units = null): string
{
    $esc = function ($v) {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    };
    $isBundle = (($p['product_kind'] ?? 'standard') === 'bundle');
    $typeLabel = ($p['type'] === 'service') ? 'خدمت' : ($isBundle ? 'بسته' : 'محصول');
    $purchaseDisplay = number_format((float) ($p['purchase_price'] ?? 0));
    $saleDisplay = number_format((float) ($p['sale_price'] ?? 0));
    $unitsJson = $units !== null ? json_encode($units, JSON_UNESCAPED_UNICODE) : '';

    /* موجودی بسته مشتق‌شده است (در صورت ارائه از product_list) */
    $stockValue = $isBundle
        ? (string) ($p['display_stock'] ?? $p['stock'] ?? '0')
        : (string) ($p['stock'] ?? '0');
    $stockDisplay = rtrim(rtrim(number_format((float) $stockValue, 2, '.', ''), '0'), '.');

    $actions = '<button class="button-secondary small edit-product" type="button">ویرایش</button>';
    if ($isBundle) {
        $actions = '<button class="button-secondary small edit-bundle" type="button">ویرایش بسته</button>';
    }
    $actions .= ' <button class="button-secondary small delete-product" type="button">حذف</button>'
        . ' <button class="button-secondary small product-history" type="button" title="تاریخچه قیمت و فروش">تاریخچه</button>'
        . productBarcodeActionHtml($p);

    return '<tr class="product-row" data-id="' . $esc($p['id']) . '"'
        . ' data-code="' . $esc($p['code']) . '"'
        . ' data-barcode="' . $esc($p['barcode']) . '"'
        . ' data-name="' . $esc($p['name']) . '"'
        . ' data-category-id="' . $esc($p['category_id']) . '"'
        . ' data-type="' . $esc($p['type']) . '"'
        . ' data-product-kind="' . $esc($p['product_kind'] ?? 'standard') . '"'
        . ' data-unit="' . $esc($p['unit']) . '"'
        . ' data-purchase-price="' . $esc($p['purchase_price']) . '"'
        . ' data-sale-price="' . $esc($p['sale_price']) . '"'
        . ' data-stock="' . $esc($stockValue) . '"'
        . ' data-min-stock="' . $esc($p['min_stock']) . '"'
        . ' data-description="' . $esc($p['description']) . '"'
        . ' data-units="' . $esc($unitsJson) . '">'
        . '<td>' . $esc($p['code']) . '</td>'
        . '<td>' . $esc($p['barcode']) . '</td>'
        . '<td>' . $esc($p['name']) . '</td>'
        . '<td>' . $esc($typeLabel) . '</td>'
        . '<td>' . $esc($p['unit']) . '</td>'
        . '<td>' . $esc($purchaseDisplay) . '</td>'
        . '<td>' . $esc($saleDisplay) . '</td>'
        . '<td>' . $esc($stockDisplay) . '</td>'
        . '<td>' . $actions . '</td>'
        . '</tr>';
}
