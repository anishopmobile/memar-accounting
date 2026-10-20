<?php
require_once __DIR__ . '/../assets/php/db.php';

$rows = [];
$query = "
    SELECT
        c.id,
        CONCAT(c.first_name, ' ', c.last_name) AS full_name,
        c.phone,
        c.address,
        COALESCE(SUM(CASE WHEN i.type = 'sales_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_purchases,
        COALESCE(SUM(CASE WHEN i.type = 'sales_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS debt
    FROM customers c
    LEFT JOIN invoices i ON c.id = i.customer_id
    GROUP BY c.id, c.first_name, c.last_name, c.phone, c.address
    ORDER BY c.created_at DESC
";
$result = $mysqli->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
}
?>
<div class="card">
    <div class="page-actions">
        <a href="assets/php/report_customer_debt_export.php" class="button-secondary" id="exportCustomerDebtBtn">خروجی اکسل</a>
        <div class="filter-panel">
            <input type="search" id="customerDebtSearch" placeholder="جستجو بر اساس نام مشتری...">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="customerDebtReportTable">
            <thead>
                <tr>
                    <th>نام کامل</th>
                    <th>تلفن</th>
                    <th>آدرس</th>
                    <th>مجموع خرید</th>
                    <th>مجموع بدهی</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr class="empty-state-row"><td colspan="5" class="empty-state">مشتری‌ای یافت نشد</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr class="customer-debt-row"
                            data-name="<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($r['address'], ENT_QUOTES, 'UTF-8')); ?></td>
                            <td><?php echo number_format((float) $r['total_purchases']); ?></td>
                            <td><?php echo number_format((float) $r['debt']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>