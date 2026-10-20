<?php
require_once __DIR__ . '/../assets/php/db.php';

$customers = [];
$query = "
    SELECT 
        c.id,
        c.first_name,
        c.last_name,
        c.national_code,
        c.phone,
        c.economic_code,
        c.registration_number,
        c.address,
        c.postal_code,
        c.note,
        c.created_at,
        COALESCE(SUM(CASE WHEN i.type = 'sales_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_spent,
        COALESCE(SUM(CASE WHEN i.type = 'sales_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS debt
    FROM customers c
    LEFT JOIN invoices i ON c.id = i.customer_id
    GROUP BY c.id, c.first_name, c.last_name, c.national_code, c.phone, c.economic_code, c.registration_number, c.address, c.postal_code, c.note, c.created_at
    ORDER BY c.created_at DESC
";

$result = $mysqli->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    $result->free();
}
?>
<div class="card">
    <div class="page-actions">
        <button class="button" id="openCustomerModal">+ مشتری جدید</button>
        <div class="filter-panel">
            <input type="search" id="customerSearch" placeholder="جستجو...">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="customersTable">
            <thead>
                <tr>
                    <th>نام کامل</th>
                    <th>کد ملی</th>
                    <th>تلفن</th>
                    <th>آدرس</th>
                    <th>مجموع خرید</th>
                    <th>بدهی</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($customers) === 0): ?>
                    <tr class="empty-state-row">
                        <td colspan="7" class="empty-state">مشتری‌ای یافت نشد</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr class="customer-row" data-id="<?php echo $customer['id']; ?>"
                            data-first-name="<?php echo htmlspecialchars($customer['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-last-name="<?php echo htmlspecialchars($customer['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-national-code="<?php echo htmlspecialchars($customer['national_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-phone="<?php echo htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-economic-code="<?php echo htmlspecialchars($customer['economic_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-registration-number="<?php echo htmlspecialchars($customer['registration_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-address="<?php echo str_replace(["\r", "\n"], [' ', ' '], htmlspecialchars($customer['address'] ?? '', ENT_QUOTES, 'UTF-8')); ?>"
                            data-postal-code="<?php echo htmlspecialchars($customer['postal_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-note="<?php echo str_replace(["\r", "\n"], [' ', ' '], htmlspecialchars($customer['note'] ?? '', ENT_QUOTES, 'UTF-8')); ?>">
                            <td><?php echo htmlspecialchars(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($customer['national_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($customer['phone'] ?? ''); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($customer['address'] ?? '')); ?></td>
                            <td><?php echo number_format($customer['total_spent']); ?></td>
                            <td><?php echo number_format($customer['debt']); ?></td>
                            <td>
                                <button class="button-secondary small edit-customer" type="button">ویرایش</button>
                                <button class="button-danger small delete-customer" type="button">حذف</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="customerModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="customerModalTitle">افزودن مشتری جدید</h2>
            <button class="modal-close" id="closeCustomerModal" type="button">×</button>
        </div>
        <form id="customerForm" method="post" action="assets/php/save_customer.php">
            <input type="hidden" name="customer_id" id="customerId" value="">
            <div class="form-grid">
                <div class="form-row"><label>نام <span class="required">*</span></label><input type="text" name="first_name" required></div>
                <div class="form-row"><label>نام خانوادگی <span class="required">*</span></label><input type="text" name="last_name" required></div>
                <div class="form-row"><label>کد ملی</label><input type="text" name="national_code"></div>
                <div class="form-row"><label>تلفن <span class="required">*</span></label><input type="text" name="phone" required></div>
                <div class="form-row"><label>کد اقتصادی</label><input type="text" name="economic_code"></div>
                <div class="form-row"><label>شماره ثبت</label><input type="text" name="registration_number"></div>
                <div class="form-row"><label>آدرس</label><textarea name="address" rows="2"></textarea></div>
                <div class="form-row"><label>کد پستی</label><input type="text" name="postal_code"></div>
                <div class="form-row" style="grid-column: span 2;"><label>یادداشت</label><textarea name="note" rows="2"></textarea></div>
            </div>
            <div class="form-actions modal-actions">
                <button type="button" class="button-secondary" id="cancelCustomerModal">لغو</button>
                <button type="submit" class="button">ذخیره</button>
            </div>
        </form>
    </div>
</div>