<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/payment/payment_loader.php';
$csrf = csrf_token();
?>
<input type="hidden" id="smsSettingsCsrfToken" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

<div class="card">
    <div class="page-actions">
        <h2 style="margin:0;">تنظیمات پیامک (فراز اس ام اس)</h2>
        <span class="setting-note">مستندات: <span dir="ltr">https://docs.farazsms.com/</span></span>
    </div>

    <div class="form-grid">
        <div class="form-row">
            <label>کلید API</label>
            <input type="password" id="smsApiKey" placeholder="کلید جدید (در صورت تمایل)">
            <p class="setting-note" id="smsKeyHint">—</p>
        </div>
        <div class="form-row">
            <label>خط ارسال (line)</label>
            <input type="text" id="smsSenderLine" dir="ltr" placeholder="مثلاً 90008361">
        </div>
        <div class="form-row">
            <label>کد پترن موفقیت مشتری</label>
            <input type="text" id="smsSuccessPattern" dir="ltr" placeholder="مثلاً 123456">
        </div>
        <div class="form-row">
            <label>کد پترن اطلاع مدیر</label>
            <input type="text" id="smsAdminPattern" dir="ltr" placeholder="مثلاً 654321">
        </div>
        <div class="form-row">
            <label>موبایل مدیر (گیرنده اطلاع)</label>
            <input type="text" id="smsAdminMobile" dir="ltr" placeholder="09xxxxxxxxx">
        </div>
    </div>

    <div class="form-row" style="margin-top:12px;">
        <label class="switch"><input type="checkbox" id="smsCustomerEnabled"><span class="slider"></span></label>
        <span class="toggle-text">ارسال پیامک موفقیت به مشتری</span>
    </div>
    <div class="form-row">
        <label class="switch"><input type="checkbox" id="smsAdminEnabled"><span class="slider"></span></label>
        <span class="toggle-text">ارسال اطلاع به مدیر پس از پرداخت موفق</span>
    </div>

    <div class="form-actions">
        <button type="button" class="button" id="smsSaveSettings">ذخیره تنظیمات</button>
        <button type="button" class="button-secondary" id="smsTestConnection">تست اتصال</button>
        <span class="sms-save-message" aria-live="polite"></span>
    </div>
</div>

<script src="assets/js/payment-sms.js" defer></script>