<?php
require_once __DIR__ . '/accounting/ledger.php';
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_excel_lib.php';

$search = reportParam('search');

$sql = "
    SELECT
        c.id,
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


// Resolve all party balances in one aggregate query, avoiding per-row SQL.
$ledgerBalances = acct_party_summaries($mysqli, 'customer', array_column($rows, 'id'));
foreach ($rows as &$entry) {
    $ledger = $ledgerBalances[(int)$entry['id']] ?? ['debt'=>'0.00','credit'=>'0.00','pending_legacy'=>0,'invoiced'=>'0.00'];
    $entry['debt'] = $ledger['debt'];
    $entry['credit'] = $ledger['credit'];
    $entry['pending_legacy'] = $ledger['pending_legacy'];
    $entry['total_purchases'] = $ledger['invoiced'];
}
unset($entry);

$columns = [
    'full_name'       => 'نام کامل',
    'phone'           => 'تلفن',
    'address'         => 'آدرس',
    'total_purchases' => 'مجموع خرید',
    'debt'            => 'مجموع بدهی',
    'credit' => 'اعتبار طرف حساب',
];

$exportRows = [];
foreach ($rows as $r) {
    $exportRows[] = [
        'full_name'       => $r['full_name'],
        'phone'           => $r['phone'],
        'address'         => $r['address'],
        'total_purchases' => formatMoneyNumber((string) $r['total_purchases']),
        'debt'            => formatMoneyNumber((string) $r['debt']),
        'credit' => formatMoneyNumber((string)$r['credit']),
    ];
}

outputReportExcel('گزارش بدهی مشتریان', $columns, $exportRows, 'Report_Customer_Debt_' . date('Y-m-d_His') . '.xls');