<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/currency.php';
require_once __DIR__ . '/../assets/php/ledger/ledger_reports.php';
$rows = ledger_parties_report($mysqli, 'supplier');
?>
<div class="card">
    <div class="page-actions">
        <div class="toolbar-actions">
        <a href="assets/php/report_supplier_debt_export.php" class="button-secondary" id="exportSupplierDebtBtn">خروجی اکسل</a>
                </div>
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
                    <th>مبلغ پرداخت نشده</th><th>اعتبار نزد تأمین‌کننده</th><th>صورت‌حساب</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr class="empty-state-row"><td colspan="7" class="empty-state">تامین‌کننده‌ای یافت نشد</td></tr>
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
                            <td><?php echo formatMoneyNumber((string) $r['total_purchases']); ?></td>
                            <td><?php echo formatMoneyNumber((string) $r['unpaid']); ?><?php if ((int)$r['requires_reconciliation']>0): ?> <small>(نیاز به تطبیق)</small><?php endif; ?></td>
                            <td><?php echo formatMoneyNumber((string)$r['credit']); ?></td>
                            <td><a class="button-secondary small" href="?page=accounting-ledger&amp;kind=supplier&amp;party_id=<?php echo (int)$r['id']; ?>">صورت‌حساب</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>