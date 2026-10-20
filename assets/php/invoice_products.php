<?php
/**
 * invoice_products.php — جستجوی کالا برای مودال انتخاب (GET بدون CSRF)
 * بازگشت: محصولات شامل قیمت فروش/خرید و موجودی
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_common.php';
require_once __DIR__ . '/bundle_common.php';

$search = trim((string) ($_GET['search'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

$where = [];
$types = '';
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(code LIKE ? OR name LIKE ? OR barcode LIKE ? OR EXISTS (SELECT 1 FROM product_units pu2 WHERE pu2.product_id = products.id AND pu2.barcode = ?))';
    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $search;
}
if ($category !== '' && ctype_digit($category)) {
    $where[] = 'category_id = ?';
    $types .= 'i';
    $params[] = (int) $category;
}
/* فیلتر اختیاری نوع (برای نمونه فقط «کالا/محصول» در انتخاب کالای معیوب) — بدون پارامتر، رفتار قبلی کاملاً حفظ می‌شود */
$typeFilter = trim((string) ($_GET['type'] ?? ''));
if ($typeFilter !== '' && in_array($typeFilter, ['product', 'service'], true)) {
    $where[] = 'type = ?';
    $types .= 's';
    $params[] = $typeFilter;
}
/* فیلتر مودال «افزودن کالا» در فاکتور فروش: فقط کالاهای نوع «محصول» با موجودی فعلی > 0
   (برای بسته‌ها: موجودی مشتق‌شده > 0) */
if (($_GET['in_stock'] ?? '') === '1') {
    $where[] = "type = 'product'";
    $where[] = "(
        (product_kind = 'standard' AND stock > 0)
        OR
        (product_kind = 'bundle' AND COALESCE(
            (SELECT MIN(FLOOR(cp.stock / bc.quantity))
             FROM bundle_components bc
             INNER JOIN products cp ON cp.id = bc.component_product_id
             WHERE bc.bundle_product_id = products.id),
            0
        ) > 0)
    )";
}
/* فاکتور خرید: بسته‌ها (کالای مجازی فروش) در لیست انتخاب نمایش داده نشوند */
if (($_GET['exclude_bundles'] ?? '') === '1') {
    $where[] = "(product_kind IS NULL OR product_kind = 'standard')";
}

$sql = 'SELECT id, code, barcode, name, unit, type, product_kind, sale_price, purchase_price, stock
        FROM products';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
if ($search !== '') {
    /* مطابقت دقیق بارکد در صدر نتایج، سپس کد دقیق و سپس جستجوی معمولی */
    $sql .= ' ORDER BY CASE WHEN products.barcode = ? THEN 0 WHEN products.code = ? THEN 1 ELSE 2 END, CAST(code AS UNSIGNED) LIMIT 50';
    $types .= 'ss';
    $params[] = $search;
    $params[] = $search;
} else {
    $sql .= ' ORDER BY CAST(code AS UNSIGNED) LIMIT 50';
}

if ($types !== '') {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
} else {
    $res = $mysqli->query($sql);
}

$products = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
}

/* بارگذاری واحدهای اندازه‌گیری برای هر کالای بازگشتی */
if (count($products) > 0) {
    $unitsMap = getUnitsMapForProducts($mysqli, array_column($products, 'id'));
    foreach ($products as &$p) {
        $p['units'] = $unitsMap[(int) $p['id']] ?? [];
    }
    unset($p);
}

/* برای بسته‌ها، موجودی مشتق‌شده از ترکیب کالاهای جزء جایگزین موجودی فیزیکی می‌شود */
if (count($products) > 0) {
    $bundleIds = [];
    foreach ($products as $p) {
        if (($p['product_kind'] ?? 'standard') === 'bundle') {
            $bundleIds[] = (int) $p['id'];
        }
    }
    $stockMap = $bundleIds ? getBundleStockMap($mysqli, $bundleIds) : [];
    foreach ($products as &$p) {
        if (($p['product_kind'] ?? 'standard') === 'bundle') {
            $p['stock'] = $stockMap[(int) $p['id']] ?? '0';
            $p['is_bundle'] = true;
        }
    }
    unset($p);
}

respond_json(true, '', $products);
