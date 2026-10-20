<?php
/**
 * bundle_products.php — جستجوی کالاهای جزء برای مودال «انتخاب کالا» (GET بدون CSRF)
 *
 * فقط:
 *  - کا.های نوع «محصول» (type=product)
 *  - کا.های استاندارد (product_kind=standard؛ نه بسته)
 *  - با موجودی فعلی > 0
 *
 * پارامترها:
 *   search     جستجو در name / code / barcode
 *   category   فیلتر دسته‌بندی (اختیاری)
 *   exclude_id شناسه بسته جاری (برای جلوگیری از self-reference) — اختیاری
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_common.php';

$search = trim((string) ($_GET['search'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$excludeId = trim((string) ($_GET['exclude_id'] ?? ''));

$where = [];
$types = '';
$params = [];

$where[] = "type = 'product'";
$where[] = "product_kind = 'standard'";
$where[] = 'stock > 0';

if ($excludeId !== '' && ctype_digit($excludeId)) {
    $where[] = 'id <> ?';
    $types .= 'i';
    $params[] = (int) $excludeId;
}

if ($search !== '') {
    /* جستجوی واژه‌ای (سازگار با product_list.php) */
    $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false) {
        $tokens = [$search];
    }
    foreach ($tokens as $token) {
        $likeVal = '%' . $token . '%';
        $where[] = '(code LIKE ? OR barcode LIKE ? OR name LIKE ?)';
        $types .= 'sss';
        $params[] = $likeVal;
        $params[] = $likeVal;
        $params[] = $likeVal;
    }
}
if ($category !== '' && ctype_digit($category)) {
    $where[] = 'category_id = ?';
    $types .= 'i';
    $params[] = (int) $category;
}

$sql = 'SELECT id, code, barcode, name, category_id, unit, type, product_kind,
               purchase_price, sale_price, stock
        FROM products';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
if ($search !== '') {
    $sql .= ' ORDER BY CASE WHEN products.barcode = ? THEN 0 WHEN products.code = ? THEN 1 ELSE 2 END, CAST(code AS UNSIGNED) LIMIT 50';
    $types .= 'ss';
    $params[] = $search;
    $params[] = $search;
} else {
    $sql .= ' ORDER BY CAST(code AS UNSIGNED) LIMIT 50';
}

$rows = [];
if ($types !== '') {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
} else {
    $res = $mysqli->query($sql);
}
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
}

respond_json(true, '', $rows);