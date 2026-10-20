<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_excel_lib.php';

$search = reportParam('search');
$category = reportCategoryParam();
$type = reportTypeParam();

$conditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = '(p.name LIKE ? OR pc.name LIKE ? OR p.code LIKE ? OR pu.barcode LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ssss';

    $searchType = null;
    $low = mb_strtolower($search);
    if (in_array($low, ['محصول', 'product'], true)) {
        $searchType = 'product';
    } elseif (in_array($low, ['خدمت', 'service'], true)) {
        $searchType = 'service';
    }
    if ($searchType !== null) {
        $conditions[] = 'p.type = ?';
        $params[] = $searchType;
        $types .= 's';
    }
}
if ($category !== '') {
    $conditions[] = 'p.category_id = ?';
    $params[] = (int) $category;
    $types .= 'i';
}
if ($type !== '') {
    $conditions[] = 'p.type = ?';
    $params[] = $type;
    $types .= 's';
}

$sql = "
    SELECT p.code, p.name, pc.name AS category_name, p.type,
           COALESCE(pu.name, p.unit) AS unit_name,
           COALESCE(pu.purchase_price, p.purchase_price) AS purchase_price,
           COALESCE(pu.sale_price, p.sale_price) AS sale_price,
           COALESCE(pu.barcode, p.code) AS unit_barcode,
           ROUND(p.stock / COALESCE(pu.conversion_factor, 1), 2) AS unit_stock
    FROM products p
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    LEFT JOIN product_units pu ON pu.product_id = p.id
";
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY p.name, COALESCE(pu.sort_order, 0)';

$rows = [];
if ($conditions) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $bindParams = [$types];
        foreach ($params as $k => $v) {
            $bindParams[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
    }
} else {
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
}

$columns = [
    'name'          => 'نام کالا',
    'category_name' => 'نام دسته‌بندی',
    'type'          => 'نوع',
    'unit_name'     => 'واحد',
    'purchase_price'=> 'قیمت خرید',
    'sale_price'    => 'قیمت فروش',
    'unit_stock'    => 'موجودی',
];

$exportRows = [];
foreach ($rows as $r) {
    $exportRows[] = [
        'name'          => $r['name'],
        'category_name' => $r['category_name'] !== null ? $r['category_name'] : '-',
        'type'          => $r['type'],
        'unit_name'     => $r['unit_name'],
        'purchase_price'=> number_format((float) $r['purchase_price']),
        'sale_price'    => number_format((float) $r['sale_price']),
        'unit_stock'    => number_format((float) $r['unit_stock']),
    ];
}

outputReportExcel('گزارش موجودی کالاها', $columns, $exportRows, 'Report_Inventory_' . date('Y-m-d_His') . '.xls');