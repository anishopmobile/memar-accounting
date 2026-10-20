<?php
require_once __DIR__ . '/../assets/php/db.php';

$rows = [];
$query = "
    SELECT
        s.id,
        s.company_name,
        s.first_name,
        s.last_name,
        s.phone,
        s.address,
        COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_purchases,
        COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS unpaid
    FROM suppliers s
    LEFT JOIN invoices i ON s.id = i.supplier_id
    GROUP BY s.id, s.company_name, s.first_name, s.last_name, s.phone, s.address
    ORDER BY s.created_at DESC
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
        <a href="assets/php/report_supplier_debt_export.php" class="button-secondary" id="exportSupplierDebtBtn">خروجی اکسل</a>
        <div class="filter-panel">
            <input type="search" id="supplierDebtSearch" placeholder="جستجو بر اساس نام شرکت یا نام تامین‌کننده...">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="supplierDebtReportTable">
            <thead>
                <tr>
                    <th>نام / شرکت</th>
                    <th>تلفن</th>
                    <th>آدرس</th>
                    <th>مجموع خرید</th>
                    <th>مبلغ پرداخت نشده</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr class="empty-state-row"><td colspan="5" class="empty-state">تامین‌کننده‌ای یافت نشد</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $displayName = trim($r['company_name']) !== ''
                            ? $r['company_name']
                            : trim($r['first_name'] . ' ' . $r['last_name']);
                        ?>
                        <tr class="supplier-debt-row"
                            data-name="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($r['address'], ENT_QUOTES, 'UTF-8')); ?></td>
                            <td><?php echo number_format((float) $r['total_purchases']); ?></td>
                            <td><?php echo number_format((float) $r['unpaid']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>