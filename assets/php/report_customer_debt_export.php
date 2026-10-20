<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_excel_lib.php';

$search = reportParam('search');

$sql = "
    SELECT
        CONCAT(c.first_name, ' ', c.last_name) AS full_name,
        c.phone,
        c.address,
        COALESCE(SUM(CASE WHEN i.type = 'sales_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_purchases,
        COALESCE(SUM(CASE WHEN i.type = 'sales_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS debt
    FROM customers c
    LEFT JOIN invoices i ON c.id = i.customer_id
";
if ($search !== '') {
    $sql .= " WHERE CONCAT(c.first_name, ' ', c.last_name) LIKE ?";
}
$sql .= ' GROUP BY c.id, c.first_name, c.last_name, c.phone, c.address ORDER BY c.created_at DESC';

$rows = [];
if ($search !== '') {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $like = '%' . $search . '%';
        $stmt->bind_param('s', $like);
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
    'full_name'       => 'نام کامل',
    'phone'           => 'تلفن',
    'address'         => 'آدرس',
    'total_purchases' => 'مجموع خرید',
    'debt'            => 'مجموع بدهی',
];

$exportRows = [];
foreach ($rows as $r) {
    $exportRows[] = [
        'full_name'       => $r['full_name'],
        'phone'           => $r['phone'],
        'address'         => $r['address'],
        'total_purchases' => number_format((float) $r['total_purchases']),
        'debt'            => number_format((float) $r['debt']),
    ];
}

outputReportExcel('گزارش بدهی مشتریان', $columns, $exportRows, 'Report_Customer_Debt_' . date('Y-m-d_His') . '.xls');