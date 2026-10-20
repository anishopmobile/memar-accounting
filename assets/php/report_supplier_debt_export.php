<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_excel_lib.php';

$search = reportParam('search');

$sql = "
    SELECT
        s.company_name,
        s.first_name,
        s.last_name,
        s.phone,
        s.address,
        COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_purchases,
        COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS unpaid
    FROM suppliers s
    LEFT JOIN invoices i ON s.id = i.supplier_id
";
if ($search !== '') {
    $sql .= ' WHERE s.company_name LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?';
}
$sql .= ' GROUP BY s.id, s.company_name, s.first_name, s.last_name, s.phone, s.address ORDER BY s.created_at DESC';

$rows = [];
if ($search !== '') {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $like = '%' . $search . '%';
        $stmt->bind_param('sss', $like, $like, $like);
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
    'name'            => 'نام / شرکت',
    'phone'           => 'تلفن',
    'address'         => 'آدرس',
    'total_purchases' => 'مجموع خرید',
    'unpaid'          => 'مبلغ پرداخت نشده',
];

$exportRows = [];
foreach ($rows as $r) {
    $displayName = trim($r['company_name']) !== ''
        ? $r['company_name']
        : trim($r['first_name'] . ' ' . $r['last_name']);
    $exportRows[] = [
        'name'            => $displayName,
        'phone'           => $r['phone'],
        'address'         => $r['address'],
        'total_purchases' => number_format((float) $r['total_purchases']),
        'unpaid'          => number_format((float) $r['unpaid']),
    ];
}

outputReportExcel('گزارش بدهی به تامین‌کنندگان', $columns, $exportRows, 'Report_Supplier_Debt_' . date('Y-m-d_His') . '.xls');