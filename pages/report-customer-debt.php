<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/currency.php';
require_once __DIR__ . '/../assets/php/ledger/ledger_reports.php';
$rows = ledger_parties_report($mysqli, 'customer');
foreach ($rows as &$row) $row['full_name'] = trim($row['first_name'].' '.$row['last_name']);
unset($row);
?>
<div class="card">
    <div class="page-actions">
        <div class="toolbar-actions">
        <a href="assets/php/report_customer_debt_export.php" class="button-secondary" id="exportCustomerDebtBtn">خروجی اکسل</a>
                </div>
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
                    <th>مجموع بدهی</th><th>اعتبار مشتری</th><th>صورت‌حساب</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr class="empty-state-row"><td colspan="7" class="empty-state">مشتری‌ای یافت نشد</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr class="customer-debt-row"
                            data-name="<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($r['address'], ENT_QUOTES, 'UTF-8')); ?></td>
                            <td><?php echo formatMoneyNumber((string) $r['total_purchases']); ?></td>
                            <td><?php echo formatMoneyNumber((string) $r['debt']); ?><?php if ((int)$r['requires_reconciliation']>0): ?> <small>(نیاز به تطبیق)</small><?php endif; ?></td>
                            <td><?php echo formatMoneyNumber((string)$r['credit']); ?></td>
                            <td><a class="button-secondary small" href="?page=accounting-ledger&amp;kind=customer&amp;party_id=<?php echo (int)$r['id']; ?>">صورت‌حساب</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>