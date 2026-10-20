<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/payment/payment_loader.php';
$csrf = csrf_token();
?>
<input type="hidden" id="zarinpalSettingsCsrfToken" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

<div class="card">
    <div class="page-actions">
        <h2 style="margin:0;">تنظیمات درگاه زرین‌پال</h2>
        <span class="setting-note">Merchant ID فقط روی سرور ذخیره می‌شود و هرگز به‌صورت کامل نمایش داده نمی‌شود.</span>
    </div>

    <div class="form-grid">
        <div class="form-row">
            <label>Merchant ID</label>
            <input type="password" id="zarinpalMerchantId" dir="ltr" autocomplete="off" placeholder="Merchant ID درگاه (در صورت تغییر)">
            <p class="setting-note" id="zarinpalMerchantHint">—</p>
        </div>
        <div class="form-row">
            <label>محیط درگاه</label>
            <select id="zarinpalSandbox" dir="ltr">
                <option value="0">واقعی (Live)</option>
                <option value="1">آزمایشی (Sandbox)</option>
            </select>
            <p class="setting-note">در محیط آزمایشی، درخواست‌ها به سرور sandbox زرین‌پال ارسال می‌شود.</p>
        </div>
    </div>

    <div class="form-row" style="margin-top:12px;">
        <label class="switch"><input type="checkbox" id="zarinpalGatewayEnabled"><span class="slider"></span></label>
        <span class="toggle-text">فعال بودن درگاه زرین‌پال</span>
    </div>

    <div class="form-actions">
        <button type="button" class="button" id="zarinpalSaveSettings">ذخیره تنظیمات</button>
        <button type="button" class="button-secondary" id="zarinpalTestConfiguration">تست تنظیمات</button>
        <span class="zarinpal-save-message" aria-live="polite"></span>
    </div>
</div>

<script src="assets/js/payment-zarinpal.js" defer></script>