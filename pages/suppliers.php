<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/currency.php';
require_once __DIR__ . '/../assets/php/ledger/ledger_reports.php';
$suppliers = ledger_parties_report($mysqli, 'supplier');
?>
<div class="card">
    <div class="page-actions">
        <div class="toolbar-actions">
        <button class="button" id="openSupplierModal">+ تامین‌کننده جدید</button>
                </div>
        <div class="filter-panel">
            <input type="search" id="supplierSearch" placeholder="جستجو...">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="suppliersTable">
            <thead>
                <tr>
                    <th>نام / شرکت</th>
                    <th>کد ملی</th>
                    <th>تلفن</th>
                    <th>آدرس</th>
                    <th>مجموع خرید</th>
                    <th>پرداخت نشده</th><th>اعتبار نزد تأمین‌کننده</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($suppliers) === 0): ?>
                    <tr class="empty-state-row">
                        <td colspan="8" class="empty-state">تامین‌کننده‌ای یافت نشد</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $supplier): ?>
                        <?php
                        $displayName = trim($supplier['company_name'] ?? '') !== ''
                            ? $supplier['company_name']
                            : trim(($supplier['first_name'] ?? '') . ' ' . ($supplier['last_name'] ?? ''));
                        ?>
                        <tr class="supplier-row" data-id="<?php echo $supplier['id']; ?>"
                            data-company-name="<?php echo htmlspecialchars($supplier['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-first-name="<?php echo htmlspecialchars($supplier['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-last-name="<?php echo htmlspecialchars($supplier['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-national-code="<?php echo htmlspecialchars($supplier['national_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-economic-code="<?php echo htmlspecialchars($supplier['economic_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-registration-number="<?php echo htmlspecialchars($supplier['registration_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-address="<?php echo str_replace(["\r", "\n"], [' ', ' '], htmlspecialchars($supplier['address'] ?? '', ENT_QUOTES, 'UTF-8')); ?>"
                            data-postal-code="<?php echo htmlspecialchars($supplier['postal_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-note="<?php echo str_replace(["\r", "\n"], [' ', ' '], htmlspecialchars($supplier['note'] ?? '', ENT_QUOTES, 'UTF-8')); ?>">
                            <td><?php echo htmlspecialchars($displayName); ?></td>
                            <td><?php echo htmlspecialchars($supplier['national_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($supplier['phone'] ?? ''); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($supplier['address'] ?? '')); ?></td>
                            <td><?php echo formatMoneyNumber((string)$supplier['total_purchases']); ?></td>
                            <td><?php echo formatMoneyNumber((string)$supplier['unpaid']); ?><?php if ((int)$supplier['requires_reconciliation']>0): ?><small> (پرداخت تاریخی نامشخص)</small><?php endif; ?></td>
                            <td><?php echo formatMoneyNumber((string)$supplier['credit']); ?></td>
                            <td>
                                <a class="button-secondary small" href="?page=accounting-ledger&amp;kind=supplier&amp;party_id=<?php echo (int)$supplier['id']; ?>">صورت‌حساب</a>
                                <button class="button-secondary small edit-supplier" type="button">ویرایش</button>
                                <button class="button-danger small delete-supplier" type="button">حذف</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="supplierModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="supplierModalTitle">افزودن تامین‌کننده جدید</h2>
            <button class="modal-close" id="closeSupplierModal" type="button">×</button>
        </div>
        <form id="supplierForm" method="post" action="assets/php/save_supplier.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="supplier_id" id="supplierId" value="">
            <div class="form-grid">
                <div class="form-row" style="grid-column: span 2;"><label>نام شرکت</label><input type="text" name="company_name"></div>
                <div class="form-row"><label>نام</label><input type="text" name="first_name"></div>
                <div class="form-row"><label>نام خانوادگی</label><input type="text" name="last_name"></div>
                <div class="form-row"><label>کد ملی</label><input type="text" name="national_code"></div>
                <div class="form-row"><label>تلفن <span class="required">*</span></label><input type="text" name="phone" required></div>
                <div class="form-row"><label>کد اقتصادی</label><input type="text" name="economic_code"></div>
                <div class="form-row"><label>شماره ثبت</label><input type="text" name="registration_number"></div>
                <div class="form-row"><label>آدرس</label><textarea name="address" rows="2"></textarea></div>
                <div class="form-row"><label>کد پستی</label><input type="text" name="postal_code"></div>
                <div class="form-row" style="grid-column: span 2;"><label>یادداشت</label><textarea name="note" rows="2"></textarea></div>
            </div>
            <div class="form-actions modal-actions">
                <button type="button" class="button-secondary" id="cancelSupplierModal">لغو</button>
                <button type="submit" class="button">ذخیره</button>
            </div>
        </form>
    </div>
</div>
