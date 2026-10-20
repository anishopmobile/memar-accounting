<?php
require_once __DIR__ . '/product_common.php';
require_once __DIR__ . '/bundle_common.php';
header('Content-Type: application/json; charset=UTF-8');

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
if ($category !== '' && !ctype_digit($category)) {
    $category = '';
}

/* ---------- اندازه صفحه (فقط مقادیر مجاز) ---------- */
$perPageOptions = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 25;
}

/* ---------- شماره صفحه ---------- */
$page = (int) ($_GET['page'] ?? 1);
if ($page < 1) {
    $page = 1;
}

/* ---------- مرتب‌سازی (allowlist امن؛ ورودی کاربر مستقیماً داخل ORDER BY قرار نمی‌گیرد) ---------- */
$sortColumns = [
    'code' => 'CAST(code AS UNSIGNED)',
    'barcode' => 'barcode',
    'name' => 'name',
    'type' => 'type',
    'unit' => 'unit',
    'purchase_price' => 'CAST(purchase_price AS DECIMAL(14,2))',
    'sale_price' => 'CAST(sale_price AS DECIMAL(14,2))',
    'stock' => 'CAST(stock AS DECIMAL(14,2))',
];
$sort = $_GET['sort'] ?? 'code';
if (!isset($sortColumns[$sort])) {
    $sort = 'code';
}
$direction = (($_GET['direction'] ?? '') === 'desc') ? 'DESC' : 'ASC';

/* ---------- شرط‌های جستجو و فیلتر دسته‌بندی ---------- */
$conditions = [];
$types = '';
$params = [];

if ($search !== '') {
    /*
     * جستجوی کلمه‌ای (word-based):
     * عبارت جستجو به واژه‌های جدا شده با فاصله (یونیکد) تبدیل می‌شود و هر واژه
     * به‌صورت مستقل با الگوی code / barcode / name مطابقت داده می‌شود. همه واژه‌ها
     * باید در محصول مطلوب حضور داشته باشند (AND)؛ ترتیب واژه‌ها مهم نیست و تعداد
     * واژه‌ها محدودیتی ندارد. برای هر واژه سه پارامتر %token% ساخته می‌شود.
     */
    $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false) {
        $tokens = [(string) $search];
    }
    foreach ($tokens as $token) {
        $likeVal = '%' . $token . '%';
        $conditions[] = '(code LIKE ? OR barcode LIKE ? OR name LIKE ?)';
        $types .= 'sss';
        $params[] = $likeVal;
        $params[] = $likeVal;
        $params[] = $likeVal;
    }
}
if ($category !== '') {
    $conditions[] = 'category_id = ?';
    $types .= 'i';
    $params[] = (int) $category;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/* ---------- تعداد کل رکوردهای منطبق با فیلتر (برای صفحه‌بندی) ---------- */
$total = 0;
$countStmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM products $where");
if ($countStmt) {
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $countStmt->close();
    if ($countRes && $row = $countRes->fetch_assoc()) {
        $total = (int) $row['c'];
    }
}

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

/* ---------- کوئری اصلی: فقط رکوردهای صفحه جاری ---------- */
$orderBy = $sortColumns[$sort];
if ($sort !== 'code') {
    $orderBy .= ', CAST(code AS UNSIGNED)';
}
$orderBy .= ' ' . $direction;

$sql = "SELECT * FROM products $where ORDER BY $orderBy LIMIT ? OFFSET ?";

$tableBody = '<tr class="empty-state-row"><td colspan="9" class="empty-state">کالایی یافت نشد</td></tr>';

$listStmt = $mysqli->prepare($sql);
if ($listStmt) {
    $typesSelect = $types . 'ii';
    $paramsSelect = $params;
    $paramsSelect[] = $perPage;
    $paramsSelect[] = $offset;
    $listStmt->bind_param($typesSelect, ...$paramsSelect);
    $listStmt->execute();
    $result = $listStmt->get_result();
    $listStmt->close();

    if ($result && $result->num_rows > 0) {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $unitsMap = getUnitsMapForProducts($mysqli, array_column($rows, 'id'));

        /* موجودی مشتق‌شده بسته‌ها (منبع حقیقت: کالاهای جزء) */
        $bundleIds = [];
        foreach ($rows as $row) {
            if (($row['product_kind'] ?? 'standard') === 'bundle') {
                $bundleIds[] = (int) $row['id'];
            }
        }
        $bundleStockMap = $bundleIds ? getBundleStockMap($mysqli, $bundleIds) : [];

        $tableBody = '';
        foreach ($rows as $row) {
            if (($row['product_kind'] ?? 'standard') === 'bundle') {
                $row['display_stock'] = $bundleStockMap[(int) $row['id']] ?? '0';
            }
            $tableBody .= buildProductRow($row, $unitsMap[(int) $row['id']] ?? null);
        }
    }
}

/* ---------- خلاصه کلی (تعداد کل و ارزش موجودی؛ همیشه کل داده‌ها، نه فقط صفحه جاری) ---------- */
$summary = getInventorySummary($mysqli);

$rangeStart = ($total === 0) ? 0 : $offset + 1;
$rangeEnd = min($page * $perPage, $total);

echo json_encode([
    'success' => true,
    'tableBody' => $tableBody,
    'totalProducts' => $summary['total'],
    'inventoryValue' => number_format($summary['value'], 0),
    'filteredTotal' => $total,
    'page' => $page,
    'perPage' => $perPage,
    'totalPages' => $totalPages,
    'rangeStart' => $rangeStart,
    'rangeEnd' => $rangeEnd,
    'sort' => $sort,
    'direction' => strtolower($direction),
]);