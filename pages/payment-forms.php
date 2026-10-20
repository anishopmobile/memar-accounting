<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/payment/payment_loader.php';

$repo = (new PaymentService($mysqli))->forms();
$forms = $repo->all();
$csrf = csrf_token();

$modeLabels = ['fixed' => 'ثابت', 'custom' => 'دلخواه', 'selectable' => 'انتخابی'];
$fieldTypes = PaymentFieldTypes::ALL;
?>
<input type="hidden" id="paymentFormsCsrfToken" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

<div class="card" id="paymentFormListView">
    <div class="page-actions">
        <button type="button" class="button" id="paymentFormNew">+ فرم پرداخت جدید</button>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="paymentFormsTable">
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>شناسه (slug)</th>
                    <th>نوع مبلغ</th>
                    <th>وضعیت</th>
                    <th>فیلدها</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($forms === []): ?>
                    <tr><td colspan="6" class="empty-state">فرم پرداختی تعریف نشده است.</td></tr>
                <?php else: foreach ($forms as $f): ?>
                    <tr data-id="<?php echo (int) $f->id; ?>" data-slug="<?php echo htmlspecialchars($f->slug, ENT_QUOTES, 'UTF-8'); ?>">
                        <td><?php echo htmlspecialchars($f->title, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars($f->slug, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($modeLabels[$f->amountMode] ?? $f->amountMode, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge<?php echo $f->isActive ? '' : ' badge-danger'; ?>"><?php echo $f->isActive ? 'فعال' : 'غیرفعال'; ?></span></td>
                        <td><?php echo count($f->fields); ?></td>
                        <td>
                            <button type="button" class="button-secondary small payment-edit">ویرایش</button>
                            <button type="button" class="button-secondary small payment-duplicate">کپی</button>
                            <button type="button" class="button-secondary small payment-preview">پیش‌نمایش</button>
                            <button type="button" class="button-secondary small payment-toggle"><?php echo $f->isActive ? 'غیرفعال' : 'فعال'; ?></button>
                            <button type="button" class="button-danger small payment-delete">حذف</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="paymentFormEditView" style="display:none;">
    <input type="hidden" id="paymentFormId" value="0">
    <div class="page-actions">
        <h2 id="paymentFormEditorTitle" style="margin:0;">فرم پرداخت جدید</h2>
        <button type="button" class="button-secondary" id="paymentFormCancel">بازگشت به فهرست</button>
    </div>

    <div class="form-grid">
        <div class="form-row"><label>عنوان *</label><input type="text" id="pfTitle" required></div>
        <div class="form-row"><label>شناسه (slug)</label><input type="text" id="pfSlug" dir="ltr" placeholder="خودکار از عنوان"></div>
        <div class="form-row" style="grid-column: span 2;"><label>توضیحات</label><textarea id="pfDescription" rows="3"></textarea></div>
        <div class="form-row"><label>موضوع پرداخت</label><input type="text" id="pfPurpose"></div>
        <div class="form-row"><label>قالب</label><select id="pfTheme"><option value="default">پیش‌فرض</option><option value="clean">ساده</option></select></div>
        <div class="form-row" style="grid-column: span 2;">
            <label>نوع مبلغ</label>
            <select id="pfAmountMode">
                <option value="fixed">مبلغ ثابت</option>
                <option value="custom">مبلغ دلخواه</option>
                <option value="selectable">مبلغ انتخابی</option>
            </select>
        </div>

        <div class="form-row amount-fixed"><label>مبلغ ثابت (ریال)</label><input type="text" id="pfFixedAmount" inputmode="numeric"></div>
        <div class="form-row amount-custom"><label>کمترین مبلغ (ریال)</label><input type="text" id="pfMinAmount" inputmode="numeric"></div>
        <div class="form-row amount-custom"><label>بیشترین مبلغ (ریال)</label><input type="text" id="pfMaxAmount" inputmode="numeric"></div>

        <div class="form-row amount-selectable" style="grid-column: span 2;">
            <label>گزینه‌های مبلغ (ریال) — هر گزینه در یک خط</label>
            <textarea id="pfAmountOptions" rows="3" placeholder="100000&#10;200000&#10;500000"></textarea>
        </div>

        <div class="form-row"><label>آدرس انتقال پس از پرداخت</label><input type="text" id="pfRedirectUrl" dir="ltr" placeholder="https://..."></div>
        <div class="form-row"><label>تأخیر انتقال (ثانیه)</label><input type="text" id="pfRedirectDelay" inputmode="numeric" value="0"></div>
        <div class="form-row"><label>تاریخ انقضا (اختیاری)</label><input type="text" id="pfExpiresAt" placeholder="YYYY-MM-DD"></div>
        <div class="form-row" style="grid-column: span 2;"><label>پیام موفقیت</label><textarea id="pfSuccessMessage" rows="2"></textarea></div>
    </div>

    <div class="form-row" style="margin-top:12px;">
        <label class="switch"><input type="checkbox" id="pfIsActive" checked><span class="slider"></span></label>
        <span class="toggle-text">فرم فعال / قابل پرداخت</span>
    </div>

    <h3>فیلدهای فرم</h3>
    <div class="table-wrapper">
        <table class="action-table" id="pfFieldsTable">
            <thead>
                <tr><th>کلید</th><th>برچسب</th><th>نوع</th><th>الزامی</th><th>گزینه‌ها</th><th></th></tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="form-actions">
        <button type="button" class="button-secondary" id="pfAddField">+ افزودن فیلد</button>
        <button type="button" class="button" id="pfSaveForm">ذخیره فرم</button>
        <button type="button" class="button-secondary" id="pfSaveCancel">لغو</button>
        <span class="payment-save-message" aria-live="polite"></span>
    </div>
</div>

<!-- Preview modal -->
<div class="modal" id="paymentPreviewModal">
    <div class="modal-backdrop" data-close="paymentPreviewModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>پیش‌نمایش فرم</h2>
            <button class="modal-close" data-close="paymentPreviewModal" type="button">×</button>
        </div>
        <div id="paymentPreviewBody"></div>
    </div>
</div>

<script>
    window.PAYMENT_FIELD_TYPES = <?php echo json_encode($fieldTypes); ?>;
</script>
